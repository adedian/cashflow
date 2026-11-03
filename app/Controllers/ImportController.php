<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Page;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Import\ImportFields;
use App\Services\Import\ImportParser;
use App\Services\Import\ImportWizard;
use App\Services\Import\SheetSource;
use App\Services\ImportService;
use RuntimeException;

/** Impor Excel: unggah -> validasi -> pratinjau -> konfirmasi -> simpan atomik. Hanya Super Admin. */
final class ImportController extends Controller
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    private function dir(): string
    {
        $d = APP_ROOT . '/storage/uploads';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    public function index(): Response
    {
        // bersihkan sisa pratinjau yang tidak dikonfirmasi/dibatalkan (> 1 jam); berkas berisi data keuangan
        foreach (glob($this->dir() . '/import-*') ?: [] as $stale) {
            if (is_file($stale) && filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }
        ImportWizard::sweep();
        Page::set(['title' => 'Impor Excel', 'subtitle' => 'Unggah Excel/CSV, petakan kolom, periksa pratinjau, lalu konfirmasi', 'scripts' => ['import.js']]);
        $batches = Db::all('SELECT b.*, u.full_name FROM import_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 20');
        return $this->view('import/index', ['batches' => $batches]);
    }

    public function preview(): Response
    {
        $f = $this->req->files['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $this->flash('error', 'Pilih berkas .xlsx yang valid (gagal mengunggah).');
            return $this->redirect('/import');
        }
        $name = (string) $f['name'];
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->flash('error', 'Hanya berkas .xlsx yang diterima.');
            return $this->redirect('/import');
        }
        if ((int) $f['size'] <= 0 || (int) $f['size'] > self::MAX_BYTES) {
            $this->flash('error', 'Ukuran berkas harus di bawah 20 MB.');
            return $this->redirect('/import');
        }
        // tanda tangan ZIP ("PK") + MIME
        $head = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 4);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']);
        if (strncmp($head, "PK\x03\x04", 4) !== 0 || !in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'], true)) {
            $this->flash('error', 'Berkas bukan workbook .xlsx yang valid.');
            return $this->redirect('/import');
        }

        $token = bin2hex(random_bytes(16));
        $dest = $this->dir() . '/import-' . $token . '.xlsx';
        if (!move_uploaded_file((string) $f['tmp_name'], $dest)) {
            $this->flash('error', 'Gagal menyimpan berkas unggahan.');
            return $this->redirect('/import');
        }
        try {
            $parsed = (new ImportService($dest))->parse();
        } catch (\Throwable $e) {
            @unlink($dest);
            app_log('import parse gagal: ' . $e->getMessage());
            $this->flash('error', 'Berkas tidak dapat dibaca: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'format tidak dikenali.'));
            return $this->redirect('/import');
        }
        $sha = hash_file('sha256', $dest);
        $dupBatch = Db::val("SELECT id FROM import_batches WHERE sha256 = ? AND status = 'imported' AND kind = 'workbook'", [$sha]);
        file_put_contents($this->dir() . '/import-' . $token . '.json', json_encode(['user' => (int) $this->user()['id'], 'time' => time(), 'name' => mb_substr($name, 0, 200), 'sha' => $sha, 'parsed' => $parsed], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        Audit::log('import_preview', 'import', null, 'Pratinjau impor: ' . mb_substr($name, 0, 120), null, ['sha256' => $sha, 'rows' => count($parsed['transactions']), 'errors' => $parsed['errors']]);

        Page::set(['title' => 'Pratinjau Impor', 'subtitle' => $name]);
        $active = (int) Db::val("SELECT COUNT(*) FROM transactions t JOIN import_batches b ON b.id = t.import_batch_id WHERE b.status = 'imported' AND b.kind = 'workbook' AND t.voided_at IS NULL");
        return $this->view('import/preview', ['p' => $parsed, 'token' => $token, 'name' => $name, 'dupBatch' => $dupBatch, 'activeImported' => $active]);
    }

    public function commit(): Response
    {
        $token = $this->req->str('token');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $this->flash('error', 'Token impor tidak valid.');
            return $this->redirect('/import');
        }
        $json = $this->dir() . '/import-' . $token . '.json';
        $xlsx = $this->dir() . '/import-' . $token . '.xlsx';
        if (!is_file($json) || !is_file($xlsx)) {
            $this->flash('error', 'Sesi pratinjau tidak ditemukan atau sudah kedaluwarsa. Unggah ulang berkas.');
            return $this->redirect('/import');
        }
        $d = json_decode((string) file_get_contents($json), true);
        if (!is_array($d) || (int) $d['user'] !== (int) $this->user()['id'] || time() - (int) $d['time'] > 3600) {
            $this->flash('error', 'Sesi pratinjau tidak valid atau kedaluwarsa (maks. 1 jam). Unggah ulang berkas.');
            return $this->redirect('/import');
        }
        if (!hash_equals((string) $d['sha'], hash_file('sha256', $xlsx))) {
            $this->flash('error', 'Berkas berubah sejak pratinjau. Unggah ulang.');
            return $this->redirect('/import');
        }
        try {
            $batch = ImportService::commit($d['parsed'], (int) $this->user()['id'], (string) $d['name'], (string) $d['sha']);
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e, 'Impor gagal dan seluruh perubahan dibatalkan (rollback). Tidak ada data yang tersimpan.'));
            return $this->redirect('/import');
        }
        @unlink($json);
        @unlink($xlsx);
        $n = count($d['parsed']['transactions']);
        $this->flash('success', "Impor selesai: batch #$batch, $n transaksi.");
        return $this->redirect('/import');
    }

    public function cancel(): Response
    {
        $token = $this->req->str('token');
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            @unlink($this->dir() . '/import-' . $token . '.json');
            @unlink($this->dir() . '/import-' . $token . '.xlsx');
        }
        $this->flash('info', 'Impor dibatalkan. Berkas sementara dihapus.');
        return $this->redirect('/import');
    }

    public function voidBatch(string $id): Response
    {
        try {
            $n = ImportService::voidBatch((int) $id, (int) $this->user()['id']);
            $this->flash('success', "Batch dibatalkan: $n data di-void (riwayat tetap tersimpan).");
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/import');
    }

    /* =====================================================================================================
     * IMPOR EXCEL / CSV BERPEMETAAN (Revisi 2). Seluruh langkah hanya Super Admin (rute 'admin' + cek ulang di sini).
     * ===================================================================================================== */

    private function guardAdmin(): void
    {
        if (($this->user()['role'] ?? '') !== 'super_admin') {
            throw new \App\Core\HttpException('Anda tidak memiliki hak akses untuk tindakan ini.', 403);
        }
    }

    /** Langkah 1 — unggah, validasi isi berkas (bukan hanya ekstensi), simpan sementara di lokasi non-publik. */
    public function upload(): Response
    {
        $this->guardAdmin();
        ImportWizard::sweep();
        $f = $this->req->files['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $this->flash('error', 'Pilih berkas .xlsx atau .csv yang valid (gagal mengunggah).');
            return $this->redirect('/import');
        }
        $name = (string) $f['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ((int) $f['size'] <= 0 || (int) $f['size'] > self::MAX_BYTES) {
            $this->flash('error', 'Ukuran berkas harus di bawah 20 MB.');
            return $this->redirect('/import');
        }
        try {
            SheetSource::validate((string) $f['tmp_name'], $ext);
        } catch (RuntimeException $e) {
            Audit::log('import_reject', 'import', null, 'Berkas impor ditolak: ' . mb_substr($name, 0, 120) . ' — ' . $e->getMessage());
            $this->flash('error', $e->getMessage());
            return $this->redirect('/import');
        }
        try {
            $token = ImportWizard::stash((string) $f['tmp_name'], $name, $ext, (int) $this->user()['id']);
            $meta = ImportWizard::meta($token, (int) $this->user()['id']);
            $src = SheetSource::open(ImportWizard::path($token, $meta), $ext);   // pastikan benar-benar terbaca
            $src->sheets();
            $src->close();
        } catch (\Throwable $e) {
            if (isset($token)) {
                ImportWizard::discard($token);
            }
            app_log('import upload gagal: ' . $e->getMessage());
            $this->flash('error', 'Berkas tidak dapat dibaca: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'format tidak dikenali.'));
            return $this->redirect('/import');
        }
        Audit::log('import_upload', 'import', null, 'Unggah berkas impor: ' . mb_substr($name, 0, 120), null, ['sha256' => $meta['sha'], 'size' => $meta['size']]);
        $kind = $this->req->str('kind');
        return $this->redirect('/import/map?' . http_build_query(['t' => $token] + (isset(ImportFields::KINDS[$kind]) ? ['kind' => $kind] : [])));
    }

    /** Nama sheet persis; bila tak ada, cocokkan tanpa spasi ujung (nama sheet Excel sering berspasi di akhir). */
    private function pickSheet(array $sheets, string $want): string
    {
        if (isset($sheets[$want])) {
            return $want;
        }
        foreach (array_keys($sheets) as $n) {
            if ($want !== '' && trim((string) $n) === trim($want)) {
                return (string) $n;
            }
        }
        return '';
    }

    private function loadMeta(): array
    {
        $token = $this->req->str('t');
        $meta = ImportWizard::meta($token, (int) $this->user()['id']);
        if ($meta === null) {
            throw new \App\Core\HttpException('Sesi impor tidak ditemukan atau sudah kedaluwarsa (maks. 1 jam). Unggah ulang berkas.', 404);
        }
        return [$token, $meta];
    }

    /** Langkah 2–3 — pilih sheet, jenis data, baris header; pemetaan kolom (saran + koreksi manual). */
    public function map(): Response
    {
        $this->guardAdmin();
        try {
            [$token, $meta] = $this->loadMeta();
        } catch (\App\Core\HttpException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/import');
        }
        return $this->renderMap($token, $meta, [], []);
    }

    /**
     * @param array $posted pemetaan/pilihan pengguna yang sedang diperbaiki (map, account_id, ...)
     * @param string[] $errors
     */
    private function renderMap(string $token, array $meta, array $posted, array $errors, int $status = 200): Response
    {
        @set_time_limit(120);
        $path = ImportWizard::path($token, $meta);
        try {
            $src = SheetSource::open($path, $meta['ext']);
            $sheets = $src->sheets();
            $names = array_keys($sheets);
            $sheet = $this->pickSheet($sheets, (string) $this->req->input('sheet', (string) ($posted['sheet'] ?? '')));
            if ($sheet === '') {
                foreach ($sheets as $n => $state) {      // sheet tampil pertama yang berisi data
                    if ($state === 'visible') {
                        $sheet = $n;
                        break;
                    }
                }
                $sheet = $sheet !== '' ? $sheet : $names[0];
            }
            $rows = $src->rows($sheet);
            $src->close();
        } catch (\Throwable $e) {
            app_log('import map gagal: ' . $e->getMessage());
            $this->flash('error', 'Berkas tidak dapat dianalisis: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'format tidak dikenali.'));
            return $this->redirect('/import');
        }

        $det = ImportFields::detect($rows);
        $kindQ = $this->req->str('kind', (string) ($posted['kind'] ?? ''));
        $kind = isset(ImportFields::KINDS[$kindQ]) ? $kindQ : $det['kind'];
        if ($kindQ !== '' && isset(ImportFields::KINDS[$kindQ]) && $kindQ !== $det['kind']) {
            // jenis dipilih manual: cari baris header terbaik untuk jenis itu
            $best = [0, $det['header']];
            $i = 0;
            foreach ($rows as $r => $cells) {
                if (++$i > 40) {
                    break;
                }
                $sc = ImportFields::headerScore($kind, $cells);
                if ($sc > $best[0]) {
                    $best = [$sc, $r];
                }
            }
            $det['header'] = $best[0] >= 2 ? $best[1] : $det['header'];
        }
        $hdrQ = $this->req->int('hdr', (int) ($posted['hdr'] ?? 0));
        $hdr = ($hdrQ > 0 && isset($rows[$hdrQ])) ? $hdrQ : $det['header'];

        // kolom yang ada + contoh isi + jumlah sel terisi
        $letters = [];
        $filled = [];
        $samples = [];
        $dataRows = 0;
        foreach ($rows as $r => $cells) {
            foreach ($cells as $col => $c) {
                $letters[$col] = ImportParser::colIndex($col);
                if ($r > $hdr && trim($c['v']) !== '') {
                    $filled[$col] = ($filled[$col] ?? 0) + 1;
                    if (count($samples[$col] ?? []) < 3) {
                        $samples[$col][] = mb_substr(ImportParser::text($c['v']), 0, 40);
                    }
                }
            }
            if ($r > $hdr) {
                $dataRows++;
            }
        }
        asort($letters);
        $headers = [];
        foreach (array_keys($letters) as $col) {
            $headers[$col] = isset($rows[$hdr][$col]) ? ImportParser::text($rows[$hdr][$col]['v']) : '';
        }
        $suggest = ImportFields::suggest($kind, $headers, ImportWizard::templateMap($kind), $samples);
        $mapSel = [];
        foreach (array_keys($letters) as $col) {
            $mapSel[$col] = is_array($posted['map'] ?? null) && array_key_exists($col, $posted['map']) ? (string) $posted['map'][$col] : $suggest[$col]['field'];
        }
        // pratinjau mentah: sekitar baris header
        $prev = [];
        foreach ($rows as $r => $cells) {
            if ($r >= max(1, $hdr - 2) && count($prev) < 9) {
                $prev[$r] = $cells;
            }
        }
        Page::set(['title' => 'Pemetaan Kolom', 'subtitle' => $meta['name'], 'scripts' => ['import.js']]);
        $acc = Db::all('SELECT id, code, name, kind, balance_mode, opening_date FROM accounts WHERE is_active = 1 ORDER BY sort_order, id');
        $r = $this->view('import/map', [
            'token' => $token, 'meta' => $meta, 'sheets' => $sheets, 'sheet' => $sheet, 'kind' => $kind, 'hdr' => $hdr, 'det' => $det,
            'letters' => array_keys($letters), 'headers' => $headers, 'samples' => $samples, 'filled' => $filled, 'suggest' => $suggest, 'mapSel' => $mapSel,
            'fields' => ImportFields::labels($kind), 'prev' => $prev, 'dataRows' => $dataRows, 'accounts' => $acc,
            'accountId' => (int) ($posted['account_id'] ?? 0), 'errors' => $errors, 'posted' => $posted,
        ]);
        $r->status = $status;
        return $r;
    }

    /** Langkah 4 — bangun rencana (validasi + deteksi duplikat) lalu tampilkan pratinjau. Belum menyimpan apa pun. */
    public function review(): Response
    {
        $this->guardAdmin();
        try {
            [$token, $meta] = $this->loadMeta();
        } catch (\App\Core\HttpException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/import');
        }
        $kind = $this->req->str('kind');
        $sheet = (string) $this->req->input('sheet', '');   // nama sheet bisa berspasi di ujung (mis. "hutang "); jangan di-trim
        $hdr = $this->req->int('hdr');
        $map = is_array($this->req->post['map'] ?? null) ? $this->req->post['map'] : [];
        $posted = ['kind' => $kind, 'sheet' => $sheet, 'hdr' => $hdr, 'map' => $map, 'account_id' => $this->req->int('account_id'), 'debt_category' => $this->req->str('debt_category'),
            'save_template' => $this->req->bool('save_template'), 'template_name' => $this->req->str('template_name'), 'ack_ignored' => $this->req->bool('ack_ignored')];
        $err = [];
        if (!isset(ImportFields::KINDS[$kind])) {
            $err[] = 'Pilih jenis data yang diimpor.';
            $kind = 'transactions';
        }
        $clean = [];
        foreach ($map as $col => $field) {
            if (!is_string($col) || !preg_match('/^[A-Z]{1,3}$/', $col) || !is_string($field) || $field === '') {
                continue;
            }
            if (!ImportFields::valid($kind, $field)) {
                $err[] = 'Field tujuan tidak dikenal untuk kolom ' . $col . '.';
                continue;
            }
            $clean[$col] = $field;
        }
        $labels = ImportFields::labels($kind);
        $byField = [];
        foreach ($clean as $col => $field) {
            $byField[$field][] = $col;
        }
        foreach ($byField as $field => $cols) {
            if (count($cols) > 1) {
                $err[] = 'Field “' . $labels[$field]['label'] . '” dipetakan ke lebih dari satu kolom (' . implode(', ', $cols) . '). Pilih satu kolom saja.';
            }
        }
        foreach ($labels as $field => $d) {
            if ($d['req'] && !isset($byField[$field])) {
                $err[] = 'Kolom wajib belum dipetakan: ' . $d['label'] . '.';
            }
        }
        $acc = null;
        if ($kind === 'transactions') {
            if (!isset($byField['debit']) && !isset($byField['credit'])) {
                $err[] = 'Petakan minimal salah satu kolom Mutasi Masuk atau Mutasi Keluar.';
            }
            $acc = Db::one('SELECT * FROM accounts WHERE id = ? AND is_active = 1', [$posted['account_id']]);
            if (!$acc) {
                $err[] = 'Pilih rekening tujuan transaksi.';
            }
        }
        if (!$err) {
            // kolom yang tidak dikenali tetapi berisi data: wajib dikonfirmasi (tidak dibuang diam-diam)
            @set_time_limit(180);
            try {
                $src = SheetSource::open(ImportWizard::path($token, $meta), $meta['ext']);
                $sheet = $this->pickSheet($src->sheets(), $sheet);
                if ($sheet === '') {
                    throw new RuntimeException('Sheet tidak ditemukan.');
                }
                $rows = $src->rows($sheet);
                $src->close();
                $plan = ImportWizard::buildPlan($kind, $rows, $hdr, $clean, ['account' => $acc, 'debt_category' => $posted['debt_category']]);
            } catch (RuntimeException $e) {
                $err[] = $e->getMessage();
            } catch (\Throwable $e) {
                app_log('import review gagal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $err[] = 'Berkas tidak dapat diproses (format di luar dugaan).';
            }
        }
        if (!$err && !empty($plan['ignored']) && !$posted['ack_ignored']) {
            $names = [];
            foreach ($plan['ignored'] as $col => $n) {
                $h = ImportParser::text($rows[$hdr][$col]['v'] ?? '');
                $names[] = ($h !== '' ? $h : 'Kolom ' . $col) . ' (' . $n . ' sel terisi)';
            }
            $err[] = 'Kolom berikut berisi data tetapi belum dipetakan sehingga TIDAK akan diimpor: ' . implode('; ', array_slice($names, 0, 8)) . (count($names) > 8 ? '; …' : '') . '. Petakan ke field yang sesuai, atau centang konfirmasi “abaikan kolom ini”.';
        }
        if ($err) {
            return $this->renderMap($token, $meta, $posted, $err, 422);
        }

        $hdrs = [];
        foreach ($clean as $col => $_) {
            $hdrs[$col] = ImportParser::text($rows[$hdr][$col]['v'] ?? '');
        }
        $ignored = [];
        foreach ($plan['ignored'] as $col => $n) {
            $ignored[$col] = ['header' => ImportParser::text($rows[$hdr][$col]['v'] ?? ''), 'filled' => $n];
        }
        ImportWizard::classify($kind, $plan['rows'], ['account' => $acc]);
        $plan += ['sheet' => $sheet, 'header_row' => $hdr, 'mapping' => $clean, 'headers' => $hdrs, 'account_id' => $acc ? (int) $acc['id'] : null, 'debt_category' => $kind === 'debts' ? (isset(\App\Services\DebtService::CATEGORIES[$posted['debt_category']]) ? $posted['debt_category'] : 'usaha') : null, 'ignored_cols' => $ignored];
        unset($plan['ignored']);
        ImportWizard::savePlan($token, $plan);
        if ($posted['save_template']) {
            ImportWizard::saveTemplate($kind, $posted['template_name'], $clean, $hdrs, (int) $this->user()['id']);
        }
        $c = ImportWizard::counts($plan['rows']);
        Audit::log('import_preview', 'import', null, 'Pratinjau impor ' . ImportFields::KINDS[$kind] . ': ' . mb_substr($meta['name'], 0, 120), null, ['sha256' => $meta['sha'], 'sheet' => $sheet, 'counts' => $c]);
        return $this->redirect('/import/review?t=' . $token);
    }

    public function reviewShow(): Response
    {
        $this->guardAdmin();
        try {
            [$token, $meta] = $this->loadMeta();
        } catch (\App\Core\HttpException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/import');
        }
        $plan = ImportWizard::loadPlan($token);
        if (!$plan) {
            $this->flash('warn', 'Pratinjau belum dibuat. Lengkapi pemetaan kolom terlebih dahulu.');
            return $this->redirect('/import/map?t=' . $token);
        }
        $acc = $plan['account_id'] ? Db::one('SELECT * FROM accounts WHERE id = ?', [(int) $plan['account_id']]) : null;
        ImportWizard::classify($plan['kind'], $plan['rows'], ['account' => $acc]);   // segarkan terhadap database saat ini
        $counts = ImportWizard::counts($plan['rows']);
        $cat = [];
        if ($plan['kind'] === 'transactions') {
            $raws = [];
            foreach ($plan['rows'] as $r) {
                if ($r['st'] === 'ok') {
                    $raws[$r['d']['cat_raw']] = ($raws[$r['d']['cat_raw']] ?? 0) + 1;
                }
            }
            foreach (ImportWizard::categoryLookup(array_keys($raws)) as $raw => $m) {
                if ($m['src'] === 'uncat') {
                    $cat[$raw] = $raws[$raw];
                }
            }
            arsort($cat);
        }
        $sum = ['in' => '0.00', 'out' => '0.00', 'amount' => '0.00'];
        foreach ($plan['rows'] as $r) {
            if ($r['st'] === 'ok' && ($r['cl'] ?? 'new') === 'new') {
                if ($plan['kind'] === 'transactions') {
                    $sum['in'] = bcadd($sum['in'], $r['d']['debit'], 2);
                    $sum['out'] = bcadd($sum['out'], $r['d']['credit'], 2);
                } else {
                    $sum['amount'] = bcadd($sum['amount'], $r['d']['amount'], 2);
                }
            }
        }
        $dupBatch = Db::val("SELECT id FROM import_batches WHERE sha256 = ? AND status = 'imported' AND kind = ?", [$meta['sha'], $plan['kind']]);
        Page::set(['title' => 'Pratinjau Impor', 'subtitle' => $meta['name'], 'scripts' => ['import.js']]);
        return $this->view('import/review', [
            'token' => $token, 'meta' => $meta, 'p' => $plan, 'c' => $counts, 'cat' => $cat, 'sum' => $sum, 'acc' => $acc,
            'fields' => ImportFields::labels($plan['kind']), 'dupBatch' => $dupBatch,
        ]);
    }

    /** Langkah 5 — konfirmasi & simpan. Validasi ulang penuh di sisi server; satu transaksi DB (gagal = tidak ada yang tersimpan). */
    public function run(): Response
    {
        $this->guardAdmin();
        try {
            [$token, $meta] = $this->loadMeta();
        } catch (\App\Core\HttpException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/import');
        }
        $plan = ImportWizard::loadPlan($token);
        if (!$plan) {
            $this->flash('error', 'Pratinjau tidak ditemukan. Ulangi dari pemetaan kolom.');
            return $this->redirect('/import');
        }
        $c = ImportWizard::counts($plan['rows']);
        if ($c['error'] > 0 && !$this->req->bool('ack_errors')) {
            $this->flash('error', 'Centang konfirmasi bahwa ' . $c['error'] . ' baris bermasalah tidak akan diimpor.');
            return $this->redirect('/import/review?t=' . $token);
        }
        $dec = [];
        foreach ((is_array($this->req->post['dec'] ?? null) ? $this->req->post['dec'] : []) as $r => $v) {
            if (ctype_digit((string) $r) && in_array($v, ['import', 'skip'], true)) {
                $dec[(int) $r] = $v;
            }
        }
        $opts = ['policy' => $this->req->str('policy'), 'bulk' => $this->req->str('bulk'), 'dec' => $dec];

        // kunci berkas rencana: klik ganda / dua tab tidak dapat menyimpan dua kali
        $planFile = ImportWizard::dir() . '/wiz-' . $token . '.plan.json';
        $lock = $planFile . '.run';
        if (!@rename($planFile, $lock)) {
            $this->flash('error', 'Impor ini sedang diproses atau sudah selesai.');
            return $this->redirect('/import');
        }
        try {
            $res = ImportWizard::commit($plan, $opts, $meta, (int) $this->user()['id']);
        } catch (\Throwable $e) {
            @rename($lock, $planFile);
            $this->flash('error', $this->safeMessage($e, 'Impor gagal dan seluruh perubahan dibatalkan (rollback). Tidak ada data yang tersimpan.'));
            return $this->redirect('/import/review?t=' . $token);
        }
        ImportWizard::discard($token);   // berkas unggahan tidak disimpan permanen
        \App\Services\LinkService::autoLink();
        foreach (Db::all('SELECT id FROM debts WHERE import_batch_id = ? AND forecast_item_id IS NULL', [(int) $res['batch']]) as $nd) { \App\Services\LinkService::ensureForecastForDebt((int) $nd['id']); }
        $this->flash('success', 'Impor selesai: ' . $res['counts']['inserted'] . ' baris berhasil disimpan (batch #' . $res['batch'] . ').');
        return $this->redirect('/import/' . $res['batch'] . '/report');
    }

    public function abort(): Response
    {
        $this->guardAdmin();
        $token = $this->req->str('t');
        if (ImportWizard::validToken($token) && ImportWizard::meta($token, (int) $this->user()['id']) !== null) {
            ImportWizard::discard($token);
            Audit::log('import_abort', 'import', null, 'Impor dibatalkan sebelum disimpan; berkas sementara dihapus');
        }
        $this->flash('info', 'Impor dibatalkan. Tidak ada data yang diubah dan berkas sementara dihapus.');
        return $this->redirect('/import');
    }

    private function batch(string $id): array
    {
        $b = ctype_digit($id) ? Db::one('SELECT b.*, u.full_name FROM import_batches b LEFT JOIN users u ON u.id = b.created_by WHERE b.id = ?', [(int) $id]) : null;
        $st = $b ? json_decode((string) $b['stats_json'], true) : null;
        if (!$b || !is_array($st) || !isset($st['counts'])) {
            throw new \App\Core\HttpException('Laporan impor tidak ditemukan.', 404);
        }
        return [$b, $st];
    }

    /** Langkah 6 — laporan hasil impor. */
    public function report(string $id): Response
    {
        $this->guardAdmin();
        [$b, $st] = $this->batch($id);
        Page::set(['title' => 'Laporan Impor', 'subtitle' => $b['filename'] . ' · batch #' . (int) $b['id']]);
        return $this->view('import/report', ['b' => $b, 'st' => $st, 'fields' => ImportFields::labels($st['kind'] ?? 'transactions')]);
    }

    /** Unduh laporan baris yang tidak masuk (gagal/duplikat/dilewati) sebagai CSV; sel dinetralkan dari injeksi formula. */
    public function errors(string $id): Response
    {
        $this->guardAdmin();
        [$b, $st] = $this->batch($id);
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['Baris', 'Status', 'Alasan', 'Data (kolom terpetakan)'], ';', '"', '');
        foreach ($st['rows'] ?? [] as $r) {
            if (str_starts_with((string) $r['status'], 'diimpor') || $r['status'] === 'diperbarui') {
                continue;
            }
            fputcsv($h, [(int) $r['r'], ImportParser::csvSafe($r['status']), ImportParser::csvSafe($r['reason']), ImportParser::csvSafe($r['raw'] ?? '')], ';', '"', '');
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);
        Audit::log('export', 'import_batch', (string) $b['id'], 'Unduh laporan kesalahan impor batch #' . (int) $b['id']);
        return Response::download($csv, 'laporan-impor-batch-' . (int) $b['id'] . '.csv', 'text/csv; charset=utf-8');
    }
}
