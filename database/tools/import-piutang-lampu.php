<?php
declare(strict_types=1);
/**
 * Muat sheet "Piutang Lampu" (rekap piutang penjualan lampu, baris 5–125) ke register Piutang (invoices.kind = 'lampu').
 * Pemetaan kolom EKSPLISIT sesuai sheet: A sales · B tgl nota · C no. surat jalan · D customer · E tgl jatuh tempo (atau CASH) ·
 * F piutang (Rp) · G tgl bayar · H kode bukti bayar (BM xx/xx) · I bayar (Rp) · J sisa piutang (rumus) · K info.
 * Aturan pembayaran (tidak menebak):
 *   - sisa Excel (J) terbaca angka  -> dibayar = F − max(J, 0); status pasti.
 *   - J galat (#REF!)               -> paid_known = 0 ("Perlu konfirmasi"): tidak dianggap lunas maupun belum dibayar.
 *   - J & I kosong                  -> belum ada pembayaran tercatat (belum dibayar).
 * Pemeriksaan integritas: Σ piutang (F) dibandingkan dengan total di sheet (F130).
 *
 *   php database/tools/import-piutang-lampu.php "<berkas.xlsx>" [--user=USERNAME_ADMIN] [--commit]
 * Tanpa --commit hanya pratinjau. Dijalankan dengan CASHFLOW_ENV_FILE=... untuk memilih database.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\Audit;
use App\Services\Import\ImportParser;
use App\Services\Import\SheetSource;
use App\Services\LinkService;
use App\Services\Version;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$file = '';
$opt = ['user' => '', 'commit' => false, 'force' => false];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $a, $m)) {
        $opt[$m[1]] = in_array($m[1], ['commit', 'force'], true) ? true : ($m[2] ?? '');
    } elseif ($file === '') {
        $file = $a;
    }
}
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Pemakaian: php import-piutang-lampu.php <berkas.xlsx> [--user=USERNAME_ADMIN] [--commit] [--force]\n");
    exit(2);
}
SheetSource::validate($file, 'xlsx');
$src = SheetSource::open($file, 'xlsx');
$name = null;
foreach (array_keys($src->sheets()) as $n) {
    if (trim((string) $n) === 'Piutang Lampu') { $name = (string) $n; }
}
if ($name === null) {
    fwrite(STDERR, "Sheet \"Piutang Lampu\" tidak ditemukan.\n");
    exit(2);
}
$rows = $src->rows($name);
$src->close();

$cell = static fn(array $r, string $c) => $r[$c] ?? null;
$text = static function (?array $c): string {
    return $c === null || $c['t'] === 'e' ? '' : ImportParser::text($c['v']);
};
$isErr = static fn(?array $c): bool => $c !== null && $c['t'] === 'e';
$num = static fn(?array $c): ?string => ($c !== null && $c['t'] !== 'e' && is_numeric($c['v'])) ? $c['v'] : null;
$dateOf = static function (?array $c): ?string {
    if ($c === null || $c['t'] === 'e' || trim($c['v']) === '') { return null; }
    return ImportParser::date($c['v'], !is_numeric($c['v']));
};

$out = [];
$skipped = [];
for ($r = 5; $r <= 125; $r++) {
    $c = $rows[$r] ?? null;
    if ($c === null) { continue; }
    $F = $num($cell($c, 'F'));
    if ($F === null) {
        $skipped[] = [$r, 'tanpa nominal piutang (baris kosong / "Trial")'];
        continue;
    }
    $amount = bcadd(number_format(round((float) $F, 2), 2, '.', ''), '0', 2);
    if (bccomp($amount, '0', 2) <= 0) { $skipped[] = [$r, 'nominal nol']; continue; }

    // jatuh tempo: tanggal, atau teks (CASH / Trial / "agt 24")
    $dueDate = $dateOf($cell($c, 'E'));
    $dueNote = null;
    $eRaw = $text($cell($c, 'E'));
    if ($dueDate === null && $eRaw !== '') { $dueNote = mb_substr($eRaw, 0, 30); }

    $note = [];
    $jErr = $isErr($cell($c, 'J'));
    $J = $num($cell($c, 'J'));
    $I = $num($cell($c, 'I'));
    $paidKnown = 1;
    $paid = '0.00';
    $gDate = $dateOf($cell($c, 'G'));
    $gTxt = $text($cell($c, 'G'));
    if ($J !== null) {
        $sisa = round((float) $J, 2);
        $paid = number_format((float) $amount - max($sisa, 0), 2, '.', '');
        if ($I !== null && abs((float) $I - (float) $paid) >= 0.5) {
            $note[] = 'Excel: dibayar ' . money(number_format((float) $I, 2, '.', '')) . ', sisa menurut Excel ' . money(number_format($sisa, 2, '.', ''));
        }
    } elseif ($jErr || $isErr($cell($c, 'I'))) {
        $paidKnown = 0;
        $note[] = 'Pembayaran di Excel tidak terbaca (#REF!)' . ($gDate ? '; tanggal bayar di Excel ' . fmt_date($gDate) : '') . ($I !== null ? '; nominal bayar di Excel ' . money(number_format((float) $I, 2, '.', '')) : '');
    } elseif ($I !== null) {
        $paid = number_format(min((float) $I, (float) $amount), 2, '.', '');
    }
    if ($gDate === null && $gTxt !== '' && !str_starts_with($gTxt, '#')) {
        $note[] = 'Info bayar: ' . $gTxt;
    }
    foreach (['K', 'N'] as $col) {
        $t = $text($cell($c, $col));
        if ($t !== '') { $note[] = $t; }
    }
    $m = $text($cell($c, 'M'));
    if ($m !== '' && !preg_match('/^\d+$/', $m)) { $note[] = $m; }

    $sales = $text($cell($c, 'A'));
    if ($sales === '' || is_numeric($sales)) {
        $l = $text($cell($c, 'L'));
        $sales = ($l !== '' && !is_numeric($l)) ? $l : '';
    }
    $customer = $text($cell($c, 'D'));
    $out[] = [
        'row' => $r, 'invoice_date' => $dateOf($cell($c, 'B')), 'invoice_no' => mb_substr($text($cell($c, 'C')), 0, 80) ?: null,
        'customer' => $customer !== '' ? mb_substr($customer, 0, 160) : '(tidak tercatat di Excel)', 'amount' => $amount, 'paid' => $paidKnown ? $paid : '0.00',
        'paid_known' => $paidKnown, 'paid_date' => ($paidKnown && $gDate && bccomp($paid, '0', 2) > 0) ? $gDate : null,
        'due_date' => $dueDate, 'due_note' => $dueNote, 'salesperson' => $sales !== '' ? mb_substr($sales, 0, 40) : null,
        'pay_ref' => ($h = $text($cell($c, 'H'))) !== '' ? mb_substr($h, 0, 40) : null, 'note' => $note ? mb_substr(implode(' | ', $note), 0, 255) : null,
    ];
}

$sumF = '0.00'; $known = 0; $unknown = 0; $open = 0; $paidN = 0; $sisa = '0.00'; $unkAmt = '0.00';
foreach ($out as $o) {
    $sumF = bcadd($sumF, $o['amount'], 2);
    if (!$o['paid_known']) { $unknown++; $unkAmt = bcadd($unkAmt, $o['amount'], 2); continue; }
    $known++;
    $rem = bcsub($o['amount'], $o['paid'], 2);
    if (bccomp($rem, '0.004', 3) > 0) { $open++; $sisa = bcadd($sisa, $rem, 2); } else { $paidN++; }
}
$excelTotal = isset($rows[130]['F']) && is_numeric($rows[130]['F']['v']) ? number_format((float) $rows[130]['F']['v'], 2, '.', '') : null;
echo 'Baris dimuat: ' . count($out) . ' · dilewati: ' . count($skipped) . "\n";
foreach ($skipped as [$r, $why]) { echo "  lewati baris $r: $why\n"; }
echo "Σ piutang (F) = $sumF" . ($excelTotal !== null ? " · total di sheet (F130) = $excelTotal · selisih " . bcsub($sumF, $excelTotal, 2) : '') . "\n";
echo "Status pasti: $known (lunas $paidN, masih ada sisa $open, Σ sisa $sisa) · Perlu konfirmasi (#REF!): $unknown (Σ $unkAmt)\n";
echo 'Dengan kode bukti bayar: ' . count(array_filter($out, static fn($o) => $o['pay_ref'])) . "\n";
if (!$opt['commit']) {
    echo "Pratinjau saja (tanpa --commit): tidak ada yang disimpan.\n";
    exit(0);
}
if ($excelTotal !== null && abs((float) bcsub($sumF, $excelTotal, 2)) > 1.0) {
    fwrite(STDERR, "BERHENTI: Σ piutang tidak cocok dengan total di sheet; periksa pemetaan.\n");
    exit(4);
}
$u = Db::one('SELECT id FROM users WHERE username = ? AND role = ?', [$opt['user'], 'super_admin']);
if (!$u) { fwrite(STDERR, "BERHENTI: --user harus Super Admin yang ada.\n"); exit(5); }
if ((int) Db::val("SELECT COUNT(*) FROM invoices WHERE kind = 'lampu'") > 0 && !$opt['force']) {
    fwrite(STDERR, "BERHENTI: sudah ada data Piutang Lampu. Batalkan batch lama atau gunakan --force (tidak disarankan).\n");
    exit(6);
}
$batch = Db::tx(static function () use ($out, $u, $file, $sumF, $known, $unknown) {
    $bid = Db::insert('import_batches', ['filename' => mb_substr(basename($file), 0, 255), 'kind' => 'receivables', 'sha256' => hash_file('sha256', $file), 'status' => 'imported', 'created_by' => (int) $u['id'],
        'stats_json' => json_encode(['kind' => 'receivables', 'counts' => ['inserted' => count($out), 'known' => $known, 'unknown' => $unknown, 'sum' => $sumF], 'rows' => []])]);
    foreach ($out as $o) {
        Db::insert('invoices', [
            'kind' => 'lampu', 'customer' => $o['customer'], 'invoice_date' => $o['invoice_date'], 'due_date' => $o['due_date'], 'due_note' => $o['due_note'],
            'salesperson' => $o['salesperson'], 'invoice_no' => $o['invoice_no'], 'amount' => $o['amount'], 'paid' => $o['paid'], 'paid_date' => $o['paid_date'],
            'paid_known' => $o['paid_known'], 'pay_ref' => $o['pay_ref'], 'note' => $o['note'], 'source_row' => $o['row'], 'import_batch_id' => $bid, 'created_by' => (int) $u['id'],
        ]);
    }
    $tx = LinkService::matchInvoiceTransactions(true);
    Version::bump('invoices');
    Audit::log('import_commit', 'import_batch', (string) $bid, 'Impor Piutang Lampu: ' . basename($file) . ' — ' . count($out) . ' baris, ' . $unknown . ' perlu konfirmasi, ' . count($tx) . ' pembayaran tertaut ke transaksi', null, ['sum' => $sumF], (int) $u['id']);
    return [$bid, count($tx)];
});
echo "SELESAI: batch #{$batch[0]} · " . count($out) . " baris · {$batch[1]} pembayaran tertaut ke transaksi.\n";
