<?php
declare(strict_types=1);
/**
 * Impor Excel/CSV berpemetaan lewat baris perintah (padanan CLI dari menu Impor Excel; memakai mesin yang sama).
 * Berguna untuk memuat satu sheet (mis. "hutang") tanpa login web. Pemetaan HANYA diterima bila semua kolom wajib
 * dikenali otomatis dengan yakin (header persis / template); selain itu proses dihentikan dan kolom dilaporkan.
 *
 *   php database/tools/import-wizard-cli.php "<berkas.xlsx>" --kind=debts --sheet="hutang " [--user=USERNAME_ADMIN] [--commit]
 *   php database/tools/import-wizard-cli.php "<berkas.xlsx>" --kind=transactions --sheet="Data" --account=BCA-HR [--policy=skip|update] [--commit]
 *
 * Tanpa --commit hanya pratinjau (tidak ada yang tersimpan). Baris "Perlu Pemeriksaan" selalu DILEWATI (keputusan manual lewat web).
 * Dijalankan dengan CASHFLOW_ENV_FILE=... untuk memilih database (bawaan: .env).
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\Import\ImportFields;
use App\Services\Import\ImportParser;
use App\Services\Import\ImportWizard;
use App\Services\Import\SheetSource;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$args = array_slice($argv, 1);
$file = '';
$opt = ['kind' => 'debts', 'sheet' => '', 'user' => '', 'account' => '', 'policy' => 'skip', 'commit' => false];
foreach ($args as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $a, $m)) {
        $opt[$m[1]] = $m[1] === 'commit' ? true : ($m[2] ?? '');
    } elseif ($file === '') {
        $file = $a;
    }
}
if ($file === '' || !is_file($file) || !isset(ImportFields::KINDS[$opt['kind']])) {
    fwrite(STDERR, "Pemakaian: php import-wizard-cli.php <berkas.xlsx|.csv> --kind=debts|transactions [--sheet=NAMA] [--account=KODE] [--user=USERNAME_ADMIN] [--policy=skip|update] [--commit]\n");
    exit(2);
}
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
try {
    SheetSource::validate($file, $ext);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'DITOLAK: ' . $e->getMessage() . "\n");
    exit(2);
}
$kind = $opt['kind'];
$src = SheetSource::open($file, $ext);
$sheets = $src->sheets();
$sheet = '';
foreach (array_keys($sheets) as $n) {
    if ($opt['sheet'] === '' ? $sheets[$n] === 'visible' : (string) $n === $opt['sheet'] || trim((string) $n) === trim($opt['sheet'])) {
        $sheet = (string) $n;
        break;
    }
}
if ($sheet === '') {
    fwrite(STDERR, "Sheet tidak ditemukan. Tersedia: " . implode(' | ', array_map(static fn($n) => '"' . $n . '"', array_keys($sheets))) . "\n");
    exit(2);
}
$rows = $src->rows($sheet);
$src->close();
$det = ImportFields::detect($rows);
$hdr = $det['header'];
$headers = [];
foreach ($rows[$hdr] ?? [] as $c => $cell) {
    $headers[$c] = ImportParser::text($cell['v']);
}
$sg = ImportFields::suggest($kind, $headers, ImportWizard::templateMap($kind), []);
$map = [];
foreach ($sg as $c => $s) {
    if ($s['field'] !== '' && in_array($s['how'], ['exact', 'template'], true)) {
        $map[$c] = $s['field'];
    }
}
echo 'Sheet "' . $sheet . '" · baris header ' . $hdr . " · jenis " . ImportFields::KINDS[$kind] . "\n";
foreach ($headers as $c => $h) {
    echo '  ' . $c . ' "' . $h . '" -> ' . ($map[$c] ?? '(diabaikan)') . ' [' . $sg[$c]['how'] . "]\n";
}
$missing = [];
foreach (ImportFields::labels($kind) as $f => $d) {
    if ($d['req'] && !in_array($f, $map, true)) {
        $missing[] = $d['label'];
    }
}
if ($missing) {
    fwrite(STDERR, 'BERHENTI: kolom wajib tidak dikenali dengan yakin: ' . implode(', ', $missing) . ". Gunakan menu Impor Excel (pemetaan manual).\n");
    exit(3);
}
$acc = null;
if ($kind === 'transactions') {
    $acc = Db::one('SELECT * FROM accounts WHERE code = ? AND is_active = 1', [$opt['account']]);
    if (!$acc) {
        fwrite(STDERR, "BERHENTI: --account=KODE rekening wajib untuk transaksi.\n");
        exit(3);
    }
}
$plan = ImportWizard::buildPlan($kind, $rows, $hdr, $map, ['account' => $acc]);
ImportWizard::classify($kind, $plan['rows'], ['account' => $acc]);
$c = ImportWizard::counts($plan['rows']);
echo sprintf("Baris: %d · valid %d (baru %d, duplikat %d, perlu-periksa %d) · bermasalah %d · dilewati %d\n", $c['total'], $c['ok'], $c['new'], $c['dup'], $c['review'], $c['error'], $c['skip']);
foreach ($plan['rows'] as $r) {
    if ($r['st'] === 'error') {
        echo '  GAGAL baris ' . $r['r'] . ': ' . implode('; ', $r['err']) . "\n";
    } elseif ($r['st'] === 'ok') {
        echo '  ' . strtoupper($r['cl']) . ' baris ' . $r['r'] . ': ' . json_encode($r['d'], JSON_UNESCAPED_UNICODE) . (isset($r['why']) ? ' — ' . $r['why'] : '') . "\n";
    }
}
if ($c['error'] > 0) {
    fwrite(STDERR, "BERHENTI: ada baris bermasalah; perbaiki berkas atau gunakan menu Impor Excel.\n");
    exit(4);
}
if (!$opt['commit']) {
    echo "Pratinjau saja (tanpa --commit): tidak ada yang disimpan.\n";
    exit(0);
}
$u = Db::one('SELECT id FROM users WHERE username = ? AND role = ?', [$opt['user'], 'super_admin']);
if (!$u) {
    fwrite(STDERR, "BERHENTI: --user harus Super Admin yang ada.\n");
    exit(5);
}
$plan += ['sheet' => $sheet, 'header_row' => $hdr, 'mapping' => $map, 'account_id' => $acc ? (int) $acc['id'] : null];
$meta = ['name' => basename($file), 'sha' => hash_file('sha256', $file)];
$res = ImportWizard::commit($plan, ['policy' => $opt['policy'], 'bulk' => 'skip', 'dec' => []], $meta, (int) $u['id']);
echo 'SELESAI: batch #' . $res['batch'] . ' · ' . json_encode($res['counts']) . "\n";
