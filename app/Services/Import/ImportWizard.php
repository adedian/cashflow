<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Db;
use App\Services\Audit;
use App\Services\CategoryMapper;
use App\Services\DebtService;
use App\Services\Version;
use RuntimeException;

/**
 * Impor Excel/CSV berpemetaan: berkas sementara non-publik -> rencana (validasi + deteksi duplikat) -> commit atomik.
 *
 * Prinsip integritas:
 *  - Baris yang gagal validasi TIDAK PERNAH disimpan; semua penyimpanan dalam satu transaksi DB (gagal = rollback total).
 *  - Duplikat dinilai ulang terhadap database tepat sebelum menyimpan (bukan memercayai pratinjau lama).
 *  - Kesamaan nominal saja bukan duplikat. Identitas kuat = rekening+tanggal+kode+nominal+uraian (transaksi) atau
 *    nomor/pihak/nominal(+jatuh tempo+uraian) (hutang). Kecocokan lemah / baris identik berulang = "Perlu Pemeriksaan".
 *  - "Perbarui" hanya menyentuh kolom deskriptif; tanggal, rekening, dan nominal tidak pernah diubah.
 *  - Hutang tidak membuat transaksi/mutasi apa pun (tidak ada penghitungan ganda dengan saldo).
 */
final class ImportWizard
{
    public const MAX_ROWS = 20000;
    public const TTL = 3600;
    private const MAX_REPORT = 5000;

    /* ------------------------------------------------------------ penyimpanan sementara ------------------------------------------------------------ */

    public static function dir(): string
    {
        $d = APP_ROOT . '/storage/uploads';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function validToken(string $t): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $t);
    }

    /** Hapus sisa berkas wizard yang kedaluwarsa (berisi data keuangan; tidak boleh menumpuk). */
    public static function sweep(): void
    {
        foreach (glob(self::dir() . '/wiz-*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - self::TTL) {
                @unlink($f);
            }
        }
    }

    /** Pindahkan unggahan ke penyimpanan non-publik & catat metadata. Mengembalikan token. */
    public static function stash(string $tmp, string $origName, string $ext, int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        $dest = self::dir() . '/wiz-' . $token . '.' . $ext;
        if (!move_uploaded_file($tmp, $dest) && !@rename($tmp, $dest)) {
            throw new RuntimeException('Gagal menyimpan berkas unggahan.');
        }
        $meta = ['user' => $userId, 'time' => time(), 'name' => mb_substr(basename($origName), 0, 200), 'ext' => $ext, 'sha' => hash_file('sha256', $dest), 'size' => filesize($dest)];
        file_put_contents(self::dir() . '/wiz-' . $token . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
        return $token;
    }

    /** @return ?array meta bila token sah, milik pengguna ini, belum kedaluwarsa, dan berkas masih utuh */
    public static function meta(string $token, int $userId): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $f = self::dir() . '/wiz-' . $token . '.meta.json';
        $m = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($m) || (int) $m['user'] !== $userId || time() - (int) $m['time'] > self::TTL) {
            return null;
        }
        $p = self::path($token, $m);
        if (!is_file($p) || !hash_equals((string) $m['sha'], hash_file('sha256', $p))) {
            return null;
        }
        return $m;
    }

    public static function path(string $token, array $meta): string
    {
        return self::dir() . '/wiz-' . $token . '.' . ($meta['ext'] === 'csv' ? 'csv' : 'xlsx');
    }

    public static function discard(string $token): void
    {
        if (!self::validToken($token)) {
            return;
        }
        foreach (glob(self::dir() . '/wiz-' . $token . '.*') ?: [] as $f) {
            @unlink($f);
        }
    }

    public static function savePlan(string $token, array $plan): void
    {
        file_put_contents(self::dir() . '/wiz-' . $token . '.plan.json', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public static function loadPlan(string $token): ?array
    {
        $f = self::dir() . '/wiz-' . $token . '.plan.json';
        $p = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($p) ? $p : null;
    }

    /* ------------------------------------------------------------ template pemetaan ------------------------------------------------------------ */

    /** @return array<string,string> header ternormalisasi => field (template terbaru menang) */
    public static function templateMap(string $kind): array
    {
        $out = [];
        foreach (Db::all('SELECT mapping_json FROM import_templates WHERE kind = ? ORDER BY updated_at, id', [$kind]) as $t) {
            $m = json_decode((string) $t['mapping_json'], true);
            if (is_array($m)) {
                foreach ($m as $h => $f) {
                    if (is_string($h) && is_string($f) && ImportFields::valid($kind, $f)) {
                        $out[$h] = $f;
                    }
                }
            }
        }
        return $out;
    }

    public static function saveTemplate(string $kind, string $name, array $mapping, array $headers, int $userId): void
    {
        $byHeader = [];
        foreach ($mapping as $col => $field) {
            $h = ImportParser::norm($headers[$col] ?? '');
            if ($h !== '' && ImportFields::valid($kind, $field)) {
                $byHeader[$h] = $field;
            }
        }
        if (!$byHeader) {
            return;
        }
        $name = mb_substr(trim($name), 0, 80) ?: 'Template ' . date('Y-m-d H:i');
        Db::q('INSERT INTO import_templates (kind, name, mapping_json, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mapping_json = VALUES(mapping_json)',
            [$kind, $name, json_encode($byHeader, JSON_UNESCAPED_UNICODE), $userId]);
        Audit::log('import_template', 'import_template', null, 'Simpan template pemetaan: ' . $name . ' (' . $kind . ')', null, ['headers' => count($byHeader)]);
    }

    /* ------------------------------------------------------------ penyusunan rencana ------------------------------------------------------------ */

    private static function isText(array $cell): bool
    {
        return in_array($cell['t'], ['s', 'str', 'inlineStr'], true);
    }

    /**
     * Baca seluruh baris data, validasi tiap baris, kembalikan rencana (belum menyentuh database kecuali membaca master).
     * @param array<int,array<string,array>> $rows
     * @param array<string,string> $mapping kolom => field
     * @param array{account?:array,headers?:array<string,string>} $ctx
     */
    public static function buildPlan(string $kind, array $rows, int $headerRow, array $mapping, array $ctx): array
    {
        $inv = [];
        foreach ($mapping as $col => $field) {
            if (ImportFields::valid($kind, $field)) {
                $inv[$field] = $col;
            }
        }
        $out = [];
        $total = 0;
        $filledIgnored = [];
        foreach ($rows as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }
            $any = false;
            foreach ($cells as $c) {
                if (trim($c['v']) !== '') {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            if (++$total > self::MAX_ROWS) {
                throw new RuntimeException('Sheet memuat lebih dari ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' baris data. Pecah berkas menjadi beberapa bagian agar aman bagi memori dan batas waktu hosting.');
            }
            foreach ($cells as $col => $c) {
                if (!isset($mapping[$col]) && trim($c['v']) !== '') {
                    $filledIgnored[$col] = ($filledIgnored[$col] ?? 0) + 1;
                }
            }
            $out[] = $kind === 'transactions' ? self::rowTx($r, $cells, $inv, $ctx) : self::rowDebt($r, $cells, $inv, $ctx);
        }
        $warn = [];
        if ($kind === 'transactions' && isset($inv['balance'])) {
            $warn = array_merge($warn, self::checkBalance($out, $rows, $inv['balance'], $ctx['account']['balance_mode'] ?? 'asset'));
        }
        return ['kind' => $kind, 'rows' => $out, 'total' => $total, 'ignored' => $filledIgnored, 'warnings' => $warn];
    }

    private static function cellInfo(array $cells, array $inv, string $f): ?array
    {
        return isset($inv[$f], $cells[$inv[$f]]) ? $cells[$inv[$f]] : null;
    }

    /** @return array{0:?string,1:?string} [teks, galat] — galat bila sel berisi galat Excel (#REF! dst). */
    private static function txt(array $cells, array $inv, string $f, int $max, array &$warn): array
    {
        $c = self::cellInfo($cells, $inv, $f);
        if ($c === null) {
            return ['', null];
        }
        if ($c['t'] === 'e') {
            return ['', 'sel berisi galat Excel (' . $c['v'] . ')'];
        }
        $v = ImportParser::text($c['v']);
        $v = ImportParser::limit($v, $max, $cut);
        if ($cut) {
            $warn[] = 'Teks dipotong (maks. ' . $max . ' karakter): ' . $f;
        }
        return [$v, null];
    }

    private static function money(array $cells, array $inv, string $f, string $label, array &$err): ?string
    {
        $c = self::cellInfo($cells, $inv, $f);
        if ($c === null) {
            return null;
        }
        if ($c['t'] === 'e') {
            $err[] = $label . ': sel berisi galat Excel (' . $c['v'] . ')';
            return null;
        }
        $a = ImportParser::amount($c['v'], self::isText($c));
        if (!$a['ok']) {
            $err[] = $label . ' bukan angka yang valid: “' . mb_substr($c['v'], 0, 40) . '”';
            return null;
        }
        return $a['val'];
    }

    private static function dateOf(array $cells, array $inv, string $f, string $label, array &$err): ?string
    {
        $c = self::cellInfo($cells, $inv, $f);
        if ($c === null || trim($c['v']) === '') {
            return null;
        }
        if ($c['t'] === 'e') {
            $err[] = $label . ': sel berisi galat Excel (' . $c['v'] . ')';
            return null;
        }
        $d = ImportParser::date($c['v'], self::isText($c));
        if ($d === null) {
            $err[] = $label . ' tidak valid: “' . mb_substr($c['v'], 0, 40) . '” (gunakan tanggal Excel, dd/mm/yyyy, yyyy-mm-dd, atau “15 Mei 2026”)';
        }
        return $d;
    }

    private static function rawLine(array $cells, array $inv): string
    {
        $p = [];
        foreach ($inv as $f => $col) {
            if (isset($cells[$col]) && trim($cells[$col]['v']) !== '') {
                $p[] = $f . '=' . mb_substr(trim($cells[$col]['v']), 0, 60);
            }
        }
        return mb_substr(implode('; ', $p), 0, 300);
    }

    private static function rowTx(int $r, array $cells, array $inv, array $ctx): array
    {
        $err = [];
        $warn = [];
        $row = ['r' => $r, 'st' => 'ok', 'raw' => self::rawLine($cells, $inv)];
        $amtErr = [];
        $debit = self::money($cells, $inv, 'debit', 'Mutasi Masuk', $amtErr);
        $credit = self::money($cells, $inv, 'credit', 'Mutasi Keluar', $amtErr);
        if (!$amtErr && $debit === null && $credit === null) {
            // tanpa nominal sama sekali = baris judul / subtotal kosong ("Bulan November 2026"), bukan transaksi
            return ['r' => $r, 'st' => 'skip', 'why' => 'Baris judul / tanpa nominal Mutasi Masuk/Keluar', 'raw' => $row['raw']];
        }
        $date = self::dateOf($cells, $inv, 'date', 'Tanggal', $err);
        $err = array_merge($err, $amtErr);
        $hasAmt = ($debit !== null && bccomp($debit, '0', 2) !== 0) || ($credit !== null && bccomp($credit, '0', 2) !== 0);

        $t = [];
        foreach (['voucher' => 30, 'party' => 255, 'party_detail' => 255, 'category' => 190, 'description' => 500, 'ref' => 190] as $f => $max) {
            [$t[$f], $e] = self::txt($cells, $inv, $f, $max, $warn);
            if ($e) {
                $err[] = $f . ': ' . $e;
            }
        }
        [$note] = self::txt($cells, $inv, 'note', 5000, $warn);

        if (!$err && $date === null && !$hasAmt) {
            return ['r' => $r, 'st' => 'skip', 'why' => 'Baris judul / tanpa tanggal dan tanpa nominal', 'raw' => $row['raw']];
        }
        if (!$err && $date === null) {
            $err[] = 'Tanggal kosong padahal ada nominal';
        }
        if (!$err && !$hasAmt) {
            return ['r' => $r, 'st' => 'skip', 'why' => 'Tidak ada nominal Mutasi Masuk/Keluar', 'raw' => $row['raw']];
        }
        if ($date !== null && ($acc = $ctx['account'] ?? null)) {
            if ($date < $acc['opening_date']) {
                $err[] = 'Tanggal ' . fmt_date($date) . ' sebelum tanggal saldo awal rekening (' . fmt_date($acc['opening_date']) . ')';
            }
            if ($date > date('Y-m-d', strtotime('+366 days'))) {
                $err[] = 'Tanggal terlalu jauh di masa depan: ' . fmt_date($date);
            }
        }
        if ($err) {
            return ['r' => $r, 'st' => 'error', 'err' => $err, 'raw' => $row['raw']];
        }
        $dv = $debit ?? '0.00';
        $cv = $credit ?? '0.00';
        $flags = [];
        if (bccomp($dv, '0', 2) !== 0 && bccomp($cv, '0', 2) !== 0) {
            $flags[] = 'Baris memuat Mutasi Masuk dan Keluar sekaligus';
        }
        foreach ($warn as $w) {
            $flags[] = $w;
        }
        return $row + ['d' => [
            'date' => $date, 'voucher' => $t['voucher'], 'party' => $t['party'], 'party_detail' => $t['party_detail'], 'cat_raw' => $t['category'],
            'desc' => $t['description'], 'note' => $note ?? '', 'ref' => $t['ref'], 'debit' => $dv, 'credit' => $cv,
        ], 'flags' => $flags];
    }

    private static function rowDebt(int $r, array $cells, array $inv, array $ctx = []): array
    {
        $err = [];
        $warn = [];
        $raw = self::rawLine($cells, $inv);
        $t = [];
        foreach (['supplier' => 160, 'ref_no' => 80, 'description' => 500, 'source_account' => 80] as $f => $max) {
            [$t[$f], $e] = self::txt($cells, $inv, $f, $max, $warn);
            if ($e) {
                $err[] = $f . ': ' . $e;
            }
        }
        [$note] = self::txt($cells, $inv, 'note', 255, $warn);
        [$status] = self::txt($cells, $inv, 'status', 60, $warn);
        // jenis hutang: dari kolom Excel bila dipetakan & dikenali, selain itu pilihan umum pada langkah "Tujuan & opsi" (bawaan: Usaha)
        $catDefault = isset(DebtService::CATEGORIES[$ctx['debt_category'] ?? '']) ? $ctx['debt_category'] : 'usaha';
        $category = $catDefault;
        [$catRaw] = self::txt($cells, $inv, 'category', 60, $warn);
        if ($catRaw !== '') {
            $cn = mb_strtolower($catRaw);
            if (str_contains($cn, 'direks') || str_contains($cn, 'direktur')) {
                $category = 'direksi';
            } elseif (str_contains($cn, 'lain')) {
                $category = 'lain';
            } elseif (str_contains($cn, 'usaha') || str_contains($cn, 'supplier') || str_contains($cn, 'vendor')) {
                $category = 'usaha';
            } else {
                $warn[] = 'Jenis hutang “' . $catRaw . '” tidak dikenali; dipakai ' . DebtService::CATEGORIES[$catDefault];
            }
        }
        $amount = self::money($cells, $inv, 'amount', 'Nominal hutang', $err);
        $paid = self::money($cells, $inv, 'paid', 'Sudah dibayar', $err);
        $remaining = self::money($cells, $inv, 'remaining', 'Sisa hutang', $err);
        $due = self::dateOf($cells, $inv, 'due_date', 'Tanggal jatuh tempo', $err);
        $dd = self::dateOf($cells, $inv, 'debt_date', 'Tanggal transaksi', $err);
        $seq = null;
        $sc = self::cellInfo($cells, $inv, 'seq_no');
        if ($sc !== null && trim($sc['v']) !== '') {
            if (preg_match('/^\d{1,9}(\.0+)?$/', trim($sc['v']))) {
                $seq = (int) $sc['v'];
            } else {
                $warn[] = 'No. urut bukan bilangan bulat; diabaikan';
            }
        }
        if ($t['supplier'] === '' && !$err) {
            // baris tanpa pemasok & tanpa nominal = baris kosong/judul/total
            if ($amount === null) {
                return ['r' => $r, 'st' => 'skip', 'why' => 'Baris judul / kosong (tanpa nama supplier dan nominal)', 'raw' => $raw];
            }
            $err[] = 'Nama supplier kosong';
        }
        if ($amount === null && !$err) {
            $err[] = 'Nominal hutang kosong';
        }
        if ($amount !== null && bccomp($amount, '0', 2) <= 0) {
            $err[] = 'Nominal hutang harus lebih dari nol';
        }
        if ($paid !== null && bccomp($paid, '0', 2) < 0) {
            $err[] = 'Nominal dibayar tidak boleh negatif';
        }
        if ($amount !== null && $paid !== null && bccomp($paid, $amount, 2) > 0) {
            $err[] = 'Nominal dibayar melebihi nominal hutang';
        }
        if ($err) {
            return ['r' => $r, 'st' => 'error', 'err' => $err, 'raw' => $raw];
        }
        $paidV = $paid ?? '0.00';
        if ($remaining !== null && abs((float) bcsub(bcsub($amount, $paidV, 2), $remaining, 2)) > 1.0) {
            $warn[] = 'Sisa di Excel (' . $remaining . ') tidak sama dengan nominal − dibayar; sistem memakai hasil hitung sendiri';
        }
        if ($status !== '' && preg_match('/lunas/i', $status) && bccomp($paidV, $amount, 2) < 0) {
            $warn[] = 'Status Excel “' . $status . '” tetapi nominal dibayar belum penuh; pembayaran tidak dicatat otomatis';
        }
        return ['r' => $r, 'st' => 'ok', 'raw' => $raw, 'd' => [
            'category' => $category, 'seq_no' => $seq, 'ref_no' => $t['ref_no'], 'supplier' => $t['supplier'], 'description' => $t['description'], 'debt_date' => $dd, 'due_date' => $due,
            'source_account' => $t['source_account'], 'amount' => $amount, 'paid' => $paidV, 'note' => $note ?? '',
        ], 'flags' => $warn];
    }

    /** Bandingkan selisih kolom Saldo antar-baris dengan mutasi (hanya verifikasi; saldo tidak diimpor). */
    private static function checkBalance(array $rows, array $src, string $col, string $mode): array
    {
        $prev = null;
        $bad = [];
        $n = 0;
        foreach ($rows as $row) {
            if ($row['st'] !== 'ok') {
                continue;
            }
            $c = $src[$row['r']][$col] ?? null;
            $a = $c ? ImportParser::amount($c['v'], self::isText($c)) : ['ok' => false, 'val' => null];
            if (!$a['ok'] || $a['val'] === null) {
                $prev = null;
                continue;
            }
            $d = $row['d'];
            $delta = $mode === 'liability' ? bcsub($d['credit'], $d['debit'], 2) : bcsub($d['debit'], $d['credit'], 2);
            if ($prev !== null) {
                $n++;
                if (abs((float) bcsub(bcsub($a['val'], $prev, 2), $delta, 2)) > 1.0) {
                    $bad[] = $row['r'];
                }
            }
            $prev = $a['val'];
        }
        if (!$bad) {
            return $n ? ['Verifikasi kolom Saldo: ' . $n . ' selisih antar-baris cocok dengan Mutasi Masuk/Keluar.'] : [];
        }
        return ['Kolom Saldo tidak konsisten dengan mutasi pada ' . count($bad) . ' baris (mis. baris ' . implode(', ', array_slice($bad, 0, 8)) . '). Saldo tidak diimpor; periksa urutan baris atau nominalnya.'];
    }

    /* ------------------------------------------------------------ deteksi duplikat ------------------------------------------------------------ */

    /**
     * Nilai ulang tiap baris "ok" terhadap database: cl = new | dup | review ; why ; match (id) ; upd (boleh diperbarui).
     * Cocok secara multiset: baris ke-k yang identik hanya dianggap duplikat bila database memuat >= k baris identik.
     */
    public static function classify(string $kind, array &$rows, array $ctx): void
    {
        $kind === 'transactions' ? self::classifyTx($rows, (int) ($ctx['account']['id'] ?? 0)) : self::classifyDebt($rows);
    }

    private static function nk(?string $s): string
    {
        return ImportParser::norm($s ?? '');
    }

    private static function classifyTx(array &$rows, int $accId): void
    {
        $dates = [];
        foreach ($rows as $r) {
            if ($r['st'] === 'ok') {
                $dates[] = $r['d']['date'];
            }
        }
        $strong = [];
        $weak = [];
        if ($dates) {
            foreach (Db::all('SELECT id, tx_date, voucher_code, debit, credit, description FROM transactions WHERE account_id = ? AND voided_at IS NULL AND tx_date BETWEEN ? AND ? ORDER BY id', [$accId, min($dates), max($dates)]) as $e) {
                $sk = md5(implode('|', [$e['tx_date'], self::nk($e['voucher_code']), $e['debit'], $e['credit'], self::nk($e['description'])]));
                $strong[$sk][] = (int) $e['id'];
                $weak[md5(implode('|', [$e['tx_date'], $e['debit'], $e['credit']]))][] = ['id' => (int) $e['id'], 'v' => self::nk($e['voucher_code']), 'sk' => $sk];
            }
        }
        $seen = [];
        foreach ($rows as &$row) {
            if ($row['st'] !== 'ok') {
                continue;   // baris skip/error membawa 'why'/'err' sendiri; jangan dihapus
            }
            unset($row['cl'], $row['why'], $row['match'], $row['upd']);
            $d = $row['d'];
            $v = self::nk($d['voucher']);
            $sk = md5(implode('|', [$d['date'], $v, $d['debit'], $d['credit'], self::nk($d['desc'])]));
            $k = $seen[$sk] = ($seen[$sk] ?? 0) + 1;
            if (isset($strong[$sk][$k - 1])) {
                $row['cl'] = 'dup';
                $row['match'] = $strong[$sk][$k - 1];
                $row['upd'] = $v !== '';
                $row['why'] = 'Sama persis dengan transaksi #' . $row['match'] . ' (rekening, tanggal, kode, nominal, uraian)';
                continue;
            }
            if ($k > 1) {
                $row['cl'] = 'review';
                $row['why'] = 'Baris identik ke-' . $k . ' dalam berkas' . (isset($strong[$sk]) ? ' (database baru memuat ' . count($strong[$sk]) . ')' : '') . ' — mungkin transaksi sah yang berulang';
                continue;
            }
            $wk = md5(implode('|', [$d['date'], $d['debit'], $d['credit']]));
            $cand = null;
            foreach ($weak[$wk] ?? [] as $w) {
                if ($v !== '' && $w['v'] !== '' && $v !== $w['v']) {
                    continue;   // kode bukti berbeda = transaksi berbeda
                }
                if (isset($seen['w' . $w['id']])) {
                    continue;
                }
                $cand = $w;
                break;
            }
            if ($cand !== null) {
                $seen['w' . $cand['id']] = true;
                $row['cl'] = 'review';
                $row['match'] = $cand['id'];
                $row['why'] = 'Mirip transaksi #' . $cand['id'] . ' (tanggal & nominal sama) tetapi uraian/kode bukti berbeda atau kosong';
                continue;
            }
            $row['cl'] = 'new';
        }
        unset($row);
    }

    private static function debtKeys(array $d): array
    {
        $ref = self::nk($d['ref_no'] ?? '');
        $sup = self::nk($d['supplier']);
        $strong = $ref !== ''
            ? md5('R|' . $ref . '|' . $sup . '|' . $d['amount'])
            : md5('N|' . $sup . '|' . $d['amount'] . '|' . ($d['due_date'] ?? '') . '|' . self::nk($d['description'] ?? ''));
        return [$strong, md5('W|' . $sup . '|' . $d['amount']), $ref];
    }

    private static function classifyDebt(array &$rows): void
    {
        $strong = [];
        $weak = [];
        $byRef = [];
        foreach (Db::all('SELECT id, ref_no, supplier, description, due_date, amount FROM debts WHERE voided_at IS NULL ORDER BY id') as $e) {
            [$sk, $wk, $ref] = self::debtKeys($e);
            $strong[$sk][] = (int) $e['id'];
            $weak[$wk][] = (int) $e['id'];
            if ($ref !== '') {
                $byRef[$ref][] = (int) $e['id'];
            }
        }
        $seen = [];
        foreach ($rows as &$row) {
            if ($row['st'] !== 'ok') {
                continue;   // baris skip/error membawa 'why'/'err' sendiri; jangan dihapus
            }
            unset($row['cl'], $row['why'], $row['match'], $row['upd']);
            [$sk, $wk, $ref] = self::debtKeys($row['d']);
            $k = $seen[$sk] = ($seen[$sk] ?? 0) + 1;
            if (isset($strong[$sk][$k - 1])) {
                $row['cl'] = 'dup';
                $row['match'] = $strong[$sk][$k - 1];
                $row['upd'] = $ref !== '';
                $row['why'] = 'Sama dengan hutang #' . $row['match'] . ($ref !== '' ? ' (nomor, supplier, nominal)' : ' (supplier, nominal, jatuh tempo, keterangan)');
                continue;
            }
            if ($k > 1) {
                $row['cl'] = 'review';
                $row['why'] = 'Baris identik ke-' . $k . ' dalam berkas — mungkin hutang sah yang berulang';
                continue;
            }
            if ($ref !== '' && isset($byRef[$ref])) {
                $row['cl'] = 'review';
                $row['match'] = $byRef[$ref][0];
                $row['why'] = 'Nomor ' . $row['d']['ref_no'] . ' sudah ada pada hutang #' . $byRef[$ref][0] . ' tetapi supplier/nominal berbeda';
                continue;
            }
            if (isset($weak[$wk])) {
                $row['cl'] = 'review';
                $row['match'] = $weak[$wk][0];
                $row['why'] = 'Supplier & nominal sama dengan hutang #' . $weak[$wk][0] . ' tetapi jatuh tempo/keterangan berbeda';
                continue;
            }
            $row['cl'] = 'new';
        }
        unset($row);
    }

    /* ------------------------------------------------------------ ringkasan & kategori ------------------------------------------------------------ */

    public static function counts(array $rows): array
    {
        $c = ['total' => count($rows), 'ok' => 0, 'error' => 0, 'skip' => 0, 'new' => 0, 'dup' => 0, 'review' => 0];
        foreach ($rows as $r) {
            $c[$r['st']]++;
            if ($r['st'] === 'ok') {
                $c[$r['cl'] ?? 'new']++;
            }
        }
        return $c;
    }

    /** @return array<string,array> teks kategori Excel => ['category','kind','project','src'] (tanpa menulis ke database) */
    public static function categoryLookup(array $raws): array
    {
        $master = [];
        foreach (Db::all('SELECT m.raw_text, c.name AS cat, c.kind, p.name AS proj FROM category_map m LEFT JOIN categories c ON c.id = m.category_id LEFT JOIN projects p ON p.id = m.project_id') as $m) {
            if ($m['cat'] !== null) {
                $master[mb_strtolower((string) $m['raw_text'])] = ['category' => $m['cat'], 'kind' => $m['kind'], 'project' => $m['proj'], 'src' => 'master'];
            }
        }
        $out = [];
        foreach (array_unique($raws) as $raw) {
            $k = mb_strtolower($raw);
            if (isset($master[$k])) {
                $out[$raw] = $master[$k];
                continue;
            }
            $m = CategoryMapper::map($raw);
            $out[$raw] = $m + ['src' => $m['category'] === CategoryMapper::UNCAT ? 'uncat' : 'rule'];
        }
        return $out;
    }

    /* ------------------------------------------------------------ commit ------------------------------------------------------------ */

    /**
     * Simpan hasil impor dalam SATU transaksi DB.
     * @param array $opts policy: skip|update|review ; bulk: skip|import|each ; dec: [baris => import|skip]
     * @return array{batch:int,counts:array,report:array}
     */
    public static function commit(array $plan, array $opts, array $meta, int $userId): array
    {
        @set_time_limit(300);
        $kind = $plan['kind'];
        $policy = in_array($opts['policy'] ?? '', ['skip', 'update', 'review'], true) ? $opts['policy'] : 'skip';
        $bulk = in_array($opts['bulk'] ?? '', ['skip', 'import', 'each'], true) ? $opts['bulk'] : 'skip';
        $dec = is_array($opts['dec'] ?? null) ? $opts['dec'] : [];
        $rows = $plan['rows'];

        return Db::tx(function () use ($plan, $kind, $policy, $bulk, $dec, $meta, $userId, $rows) {
            $acc = null;
            if ($kind === 'transactions') {
                $acc = Db::one('SELECT * FROM accounts WHERE id = ? AND is_active = 1 FOR UPDATE', [(int) $plan['account_id']]);
                if (!$acc) {
                    throw new RuntimeException('Rekening tujuan tidak ditemukan / nonaktif.');
                }
            }
            self::classify($kind, $rows, ['account' => $acc]);

            if ($policy === 'review' || $bulk === 'each') {
                $undecided = 0;
                foreach ($rows as $r) {
                    if ($r['st'] === 'ok' && $r['cl'] === 'review' && !in_array($dec[$r['r']] ?? '', ['import', 'skip'], true)) {
                        $undecided++;
                    }
                }
                if ($undecided > 0) {
                    throw new RuntimeException('Masih ada ' . $undecided . ' baris “Perlu Pemeriksaan” yang belum Anda putuskan (impor atau lewati).');
                }
            }

            $batchId = Db::insert('import_batches', [
                'filename' => mb_substr($meta['name'], 0, 255), 'kind' => $kind, 'sha256' => $meta['sha'], 'status' => 'imported',
                'stats_json' => '{}', 'created_by' => $userId,
            ]);

            $c = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'dup_found' => 0, 'dup_skipped' => 0, 'review_found' => 0, 'review_imported' => 0, 'review_skipped' => 0, 'blank' => 0, 'failed' => 0, 'unchanged' => 0, 'linked' => 0, 'payments' => 0, 'categories_new' => 0];
            $report = [];
            $rep = static function (int $r, string $status, string $reason, string $raw) use (&$report): void {
                if (count($report) < self::MAX_REPORT) {
                    $report[] = ['r' => $r, 'status' => $status, 'reason' => mb_substr($reason, 0, 400), 'raw' => $raw];
                }
            };

            $ctx = ['batch' => $batchId, 'user' => $userId, 'acc' => $acc, 'seq' => null, 'cat' => [], 'catInfo' => []];
            if ($kind === 'transactions') {
                $ctx['catInfo'] = self::categoryLookup(array_map(static fn($r) => $r['d']['cat_raw'], array_filter($rows, static fn($r) => $r['st'] === 'ok')));
                $ctx['seq'] = (int) Db::val('SELECT COALESCE(MAX(seq), 0) FROM transactions WHERE account_id = ?', [(int) $acc['id']]);
            }

            foreach ($rows as $row) {
                $c['total']++;
                if ($row['st'] === 'skip') {
                    $c['blank']++;
                    $rep($row['r'], 'dilewati', $row['why'], $row['raw'] ?? '');
                    continue;
                }
                if ($row['st'] === 'error') {
                    $c['failed']++;
                    $rep($row['r'], 'gagal', implode('; ', $row['err']), $row['raw'] ?? '');
                    continue;
                }
                $cl = $row['cl'];
                if ($cl === 'new') {
                    self::insertRow($kind, $row, $ctx, $c, '');
                    continue;
                }
                if ($cl === 'dup') {
                    $c['dup_found']++;
                    if ($policy === 'update' && !empty($row['upd'])) {
                        $changed = self::updateRow($kind, $row, $ctx);
                        if ($changed) {
                            $c['updated']++;
                            $rep($row['r'], 'diperbarui', 'Kolom deskriptif diperbarui: ' . implode(', ', $changed), $row['raw'] ?? '');
                        } else {
                            $c['unchanged']++;
                            $c['dup_skipped']++;
                            $rep($row['r'], 'duplikat', $row['why'] . ' — tidak ada perubahan', $row['raw'] ?? '');
                        }
                        continue;
                    }
                    $c['dup_skipped']++;
                    $rep($row['r'], 'duplikat', $row['why'] . ($policy === 'update' ? ' — identitas tidak cukup kuat untuk memperbarui, dilewati' : ''), $row['raw'] ?? '');
                    continue;
                }
                // review
                $c['review_found']++;
                $d = $dec[$row['r']] ?? ($bulk === 'import' ? 'import' : 'skip');
                if ($d === 'import') {
                    $c['review_imported']++;
                    self::insertRow($kind, $row, $ctx, $c, 'Impor: ' . mb_substr($row['why'], 0, 120) . ' (diputuskan pengguna)');
                    $rep($row['r'], 'diimpor (diperiksa)', $row['why'], $row['raw'] ?? '');
                } else {
                    $c['review_skipped']++;
                    $rep($row['r'], 'perlu pemeriksaan (dilewati)', $row['why'], $row['raw'] ?? '');
                }
            }

            $c['categories_new'] = $ctx['cat_new'] ?? 0;
            Db::update('import_batches', $batchId, ['stats_json' => json_encode([
                'kind' => $kind, 'sheet' => $plan['sheet'], 'header_row' => $plan['header_row'], 'mapping' => $plan['mapping'],
                'account' => $acc ? ['id' => (int) $acc['id'], 'code' => $acc['code']] : null, 'debt_category' => $plan['debt_category'] ?? null, 'policy' => $policy, 'bulk' => $bulk,
                'counts' => $c, 'rows' => $report,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);

            if ($kind === 'transactions') {
                \App\Services\PartyService::syncFromTransactions();   // pihak baru dari impor masuk ke master (dropdown Pihak)
                Version::bump('transactions');
                if ($c['categories_new'] > 0) {
                    Version::bump('master');
                }
            } else {
                Version::bump('debts');
            }
            Audit::log('import_commit', 'import_batch', (string) $batchId,
                'Impor ' . (ImportFields::KINDS[$kind] ?? $kind) . ': ' . $meta['name'] . ' — berhasil ' . ($c['inserted']) . ', diperbarui ' . $c['updated'] . ', duplikat ' . $c['dup_skipped'] . ', gagal ' . $c['failed'],
                null, ['sha256' => $meta['sha'], 'counts' => $c, 'policy' => $policy], $userId);
            return ['batch' => $batchId, 'counts' => $c, 'report' => $report];
        });
    }

    private static function insertRow(string $kind, array $row, array &$ctx, array &$c, string $flag): void
    {
        $d = $row['d'];
        if ($kind === 'debts') {
            $res = DebtService::create($d + ['source_sheet' => null, 'source_row' => $row['r']], $ctx['user'], $ctx['batch']);
            $c['inserted']++;
            $c['linked'] += $res['linked'] ? 1 : 0;
            $c['payments'] += $res['payment'] ? 1 : 0;
            return;
        }
        $info = $ctx['catInfo'][$d['cat_raw']] ?? ['category' => CategoryMapper::UNCAT, 'kind' => 'lainnya', 'project' => null];
        [$catId, $projId] = self::resolveCategory($d['cat_raw'], $info, $ctx);
        $flags = array_filter(array_merge($row['flags'] ?? [], $flag !== '' ? [$flag] : []));
        if ($d['cat_raw'] === '') {
            $flags[] = 'Kategori kosong di berkas';
        }
        $ctx['seq']++;
        Db::insert('transactions', [
            'account_id' => (int) $ctx['acc']['id'], 'tx_date' => $d['date'], 'seq' => $ctx['seq'], 'voucher_code' => $d['voucher'] ?: null,
            'counterparty' => $d['party'] ?: null, 'counterparty_detail' => $d['party_detail'] ?: null, 'category_id' => $catId, 'project_id' => $projId,
            'category_raw' => $d['cat_raw'] !== '' ? $d['cat_raw'] : null, 'description' => $d['desc'] !== '' ? $d['desc'] : null,
            'note' => $d['note'] !== '' ? $d['note'] : null, 'ref_no' => $d['ref'] !== '' ? $d['ref'] : null,
            'debit' => $d['debit'], 'credit' => $d['credit'], 'flag' => $flags ? mb_substr(implode('; ', $flags), 0, 190) : null,
            'source_sheet' => null, 'source_row' => $row['r'], 'import_batch_id' => $ctx['batch'], 'created_by' => $ctx['user'],
        ]);
        $c['inserted']++;
    }

    /** @return array{0:int,1:?int} [id kategori, id proyek]; membuat kategori/proyek baru + catatan pemetaan bila belum ada. */
    private static function resolveCategory(string $raw, array $info, array &$ctx): array
    {
        $key = mb_strtolower($raw);
        if (isset($ctx['cat'][$key])) {
            return $ctx['cat'][$key];
        }
        $cat = Db::one('SELECT id FROM categories WHERE name = ?', [$info['category']]);
        if (!$cat) {
            $catId = Db::insert('categories', ['name' => $info['category'], 'kind' => $info['kind']]);
            $ctx['cat_new'] = ($ctx['cat_new'] ?? 0) + 1;
        } else {
            $catId = (int) $cat['id'];
        }
        $projId = null;
        if (!empty($info['project'])) {
            $p = Db::one('SELECT id FROM projects WHERE name = ?', [$info['project']]);
            $projId = $p ? (int) $p['id'] : Db::insert('projects', ['name' => $info['project']]);
        }
        Db::q('INSERT IGNORE INTO category_map (raw_text, category_id, project_id) VALUES (?,?,?)', [mb_substr($raw, 0, 190), $catId, $projId]);
        return $ctx['cat'][$key] = [$catId, $projId];
    }

    /** Perbarui hanya kolom deskriptif pada baris yang cocok pasti. @return string[] nama kolom yang berubah */
    private static function updateRow(string $kind, array $row, array &$ctx): array
    {
        $d = $row['d'];
        $id = (int) $row['match'];
        if ($kind === 'debts') {
            $old = Db::one('SELECT * FROM debts WHERE id = ? AND voided_at IS NULL FOR UPDATE', [$id]);
            if (!$old) {
                return [];
            }
            $new = [];
            foreach (['description' => 'description', 'source_account' => 'source_account', 'note' => 'note', 'due_date' => 'due_date', 'debt_date' => 'debt_date', 'seq_no' => 'seq_no'] as $f => $col) {
                $v = $d[$f] ?? null;
                if ($v !== null && $v !== '' && (string) $old[$col] !== (string) $v) {
                    $new[$col] = $v;
                }
            }
            if (!$new) {
                return [];
            }
            Db::update('debts', $id, $new + ['updated_by' => $ctx['user']]);
            Audit::log('debt_update', 'debt', (string) $id, 'Perbarui hutang via impor #' . $ctx['batch'], array_intersect_key($old, $new), $new);
            return array_keys($new);
        }
        $old = Db::one('SELECT * FROM transactions WHERE id = ? AND voided_at IS NULL FOR UPDATE', [$id]);
        if (!$old) {
            return [];
        }
        $new = [];
        foreach (['party' => 'counterparty', 'party_detail' => 'counterparty_detail', 'note' => 'note', 'ref' => 'ref_no'] as $f => $col) {
            $v = $d[$f] ?? '';
            if ($v !== '' && (string) $old[$col] !== (string) $v) {
                $new[$col] = $v;
            }
        }
        if ($d['cat_raw'] !== '') {
            $info = $ctx['catInfo'][$d['cat_raw']] ?? null;
            if ($info && $info['src'] !== 'uncat') {
                [$catId, $projId] = self::resolveCategory($d['cat_raw'], $info, $ctx);
                if ((int) $old['category_id'] !== $catId) {
                    $new['category_id'] = $catId;
                }
                if ($projId !== null && (int) $old['project_id'] !== $projId) {
                    $new['project_id'] = $projId;
                }
            }
        }
        if (!$new) {
            return [];
        }
        Db::update('transactions', $id, $new + ['updated_by' => $ctx['user'], 'row_version' => (int) $old['row_version'] + 1]);
        Audit::log('tx_update', 'transaction', (string) $id, 'Perbarui via impor #' . $ctx['batch'] . ' (kolom deskriptif saja)', array_intersect_key($old, $new), $new);
        return array_keys($new);
    }
}
