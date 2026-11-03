<?php
declare(strict_types=1);

use App\Core\Env;

function env(string $key, $default = null)
{
    return Env::get($key, $default);
}

/** Escape untuk HTML. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    return rtrim((string) env('APP_BASE', ''), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

/**
 * Format Rupiah dari string desimal (tidak memakai float).
 * Contoh: "1250000" -> "Rp 1.250.000"; "-14798.51" -> "-Rp 14.798,51".
 */
function money($value, bool $prefix = true): string
{
    if ($value === null || $value === '') {
        $value = '0';
    }
    $v = bcadd((string) $value, '0', 2);
    $neg = $v[0] === '-';
    if ($neg) {
        $v = substr($v, 1);
    }
    [$int, $dec] = explode('.', $v);
    $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $int);
    $out = $int . ($dec !== '00' ? ',' . $dec : '');
    if ($neg && (float) $v == 0.0) {
        $neg = false;
    }
    return ($neg ? '-' : '') . ($prefix ? 'Rp ' : '') . $out;
}

function fmt_date(?string $date, string $format = 'd/m/Y'): string
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '-';
}

function month_name(int $m): string
{
    static $n = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $n[$m] ?? '';
}

/** Validasi nominal desimal (maks 16 digit bulat, 2 desimal). Mengembalikan string normal atau null. */
function parse_amount($raw, bool $allowNegative = false): ?string
{
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    $s = str_replace(['Rp', 'rp', ' '], '', $s);
    // format Indonesia 1.250.000,50 atau format biasa 1250000.50
    if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^-?\d+,\d{1,2}$/', $s)) {
        $s = str_replace(',', '.', $s);
    }
    $re = $allowNegative ? '/^-?\d{1,16}(\.\d{1,2})?$/' : '/^\d{1,16}(\.\d{1,2})?$/';
    if (!preg_match($re, $s)) {
        return null;
    }
    return bcadd($s, '0', 2);
}

function csrf_token(): string
{
    return \App\Core\Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function current_user(): ?array
{
    return \App\Core\Auth::user();
}

function is_admin(): bool
{
    $u = \App\Core\Auth::user();
    return $u !== null && $u['role'] === 'super_admin';
}

/**
 * Nomor rekening lengkap yang BOLEH dilihat pengguna saat ini, atau null (kosong / Viewer tanpa izin).
 * Satu-satunya pintu keluar nomor lengkap ke dashboard, halaman Rekening, dan API; Super Admin selalu boleh.
 */
function account_number_visible(array $a): ?string
{
    $no = trim((string) ($a['account_no'] ?? ''));
    if ($no === '') {
        return null;
    }
    return (is_admin() || !empty($a['viewer_can_see_no'])) ? $no : null;
}

/** Kelompokkan digit per 4 agar mudah dibaca: 1234567890 -> "1234 5678 90". */
function format_account_no(string $no): string
{
    return trim(chunk_split($no, 4, ' '));
}

function setting(string $key, string $default = ''): string
{
    return \App\Models\Setting::get($key, $default);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function app_log(string $message, string $file = 'app.log'): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents(APP_ROOT . '/storage/logs/' . $file, $line, FILE_APPEND | LOCK_EX);
}

/** Gabungkan parameter query: nilai null/'' menghapus kunci. */
function qs_merge(array $base, array $over = []): string
{
    $q = array_merge($base, $over);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '' && $v !== false);
    return http_build_query($q);
}

/**
 * Pil kode bukti bank (BM = Mutasi Masuk, BK = Mutasi Keluar) untuk kolom "Terhubung".
 * Satu pil per kode bukti; kode yang dipakai >1 transaksi (atau tanpa kode) menaut ke $moreHref.
 * @param ?array{list:array,sum:string} $proof
 */
function bank_pills(?array $proof, string $moreHref, string $kind = 'Mutasi Masuk'): string
{
    if (!$proof || empty($proof['list'])) {
        return '';
    }
    $groups = [];
    foreach ($proof['list'] as $t) {
        $code = trim((string) ($t['voucher_code'] ?? ''));
        $groups[$code !== '' ? mb_strtolower($code) : ''][] = $t;
    }
    $o = '';
    foreach ($groups as $g) {
        $t0 = $g[0];
        $label = trim((string) ($t0['voucher_code'] ?? '')) ?: 'Bank';
        $sum = '0';
        foreach ($g as $t) {
            $sum = bcadd($sum, (string) $t['amt'], 2);
        }
        if (count($g) === 1) {
            $title = 'Bukti bank: ' . $kind . ' #' . (int) $t0['id'] . ' · ' . fmt_date($t0['tx_date']) . ' · ' . money($t0['amt']);
            $href = url('/transactions/' . (int) $t0['id']);
        } else {
            $title = count($g) . ' transaksi ' . $kind . ' · total ' . money($sum);
            $href = $moreHref;
        }
        $o .= '<a class="pill pill-green" href="' . e($href) . '" title="' . e($title) . '">' . e($label) . '</a> ';
    }
    return $o;
}

/** Boleh mencetak/mengunduh? Super Admin selalu; Viewer mengikuti pengaturan "viewer_can_export". */
function can_export(): bool
{
    return is_admin() || setting('viewer_can_export', '1') === '1';
}

/**
 * Tombol "Cetak": membuka dialog pratinjau (PDF / Excel) untuk dokumen $doc dengan filter $query.
 * $live = true memakai query string halaman saat ini (dashboard, filter diperbarui lewat JS).
 */
function export_button(string $doc, array $query = [], string $class = 'btn btn-ghost btn-sm', string $label = 'Cetak', bool $live = false): string
{
    if (!can_export()) {
        return '';
    }
    $title = \App\Export\ExportBuilder::DOCS[$doc] ?? 'Dokumen';
    return '<button type="button" class="' . e($class) . '" data-export="' . e($doc) . '" data-export-title="' . e($title) . '" data-export-query="' . e(qs_merge($query)) . '"'
        . ($live ? ' data-export-live="1"' : '') . '><svg class="i i-sm"><use href="#i-print"/></svg>' . e($label) . '</button>';
}
