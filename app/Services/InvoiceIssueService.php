<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Invoice Belum Terbit (Piutang Proyek yang belum diterbitkan secara resmi).
 *
 * Satu sumber data: tabel `invoices`, kolom `issue_status` ('terbit' | 'belum_terbit'). Invoice belum terbit:
 *  - tidak punya jatuh tempo, tidak dibuatkan item Proyeksi, tidak melahirkan pengingat, tidak dihitung sebagai piutang;
 *  - tampil di Piutang (tab "Invoice Belum Terbit"), kartu ringkasan di halaman Piutang, dan bagian "Piutang Belum Terbit Invoice" di Proyeksi.
 * "Terbitkan Invoice" hanya mengubah status baris yang sama (ID, riwayat, nominal tetap) lalu membuat item Proyeksi seperti invoice biasa.
 */
final class InvoiceIssueService
{
    public const TERBIT = 'terbit';
    public const BELUM = 'belum_terbit';

    /** @return array{n:int,total:string} jumlah & total nominal invoice belum terbit */
    public static function summary(): array
    {
        $r = Db::one("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) a FROM invoices WHERE kind = 'proyek' AND issue_status = 'belum_terbit'");
        return ['n' => (int) ($r['n'] ?? 0), 'total' => bcadd((string) ($r['a'] ?? '0'), '0', 2)];
    }

    /** Daftar invoice belum terbit (terbaru di atas), langsung dari tabel invoices. */
    public static function rows(int $limit = 500): array
    {
        return Db::all(
            "SELECT id, customer, project_name, invoice_no, description, invoice_date, amount, note, created_at FROM invoices
             WHERE kind = 'proyek' AND issue_status = 'belum_terbit' ORDER BY (invoice_date IS NULL), invoice_date DESC, id DESC LIMIT " . max(1, $limit)
        );
    }

    /**
     * Validasi isian formulir "Terbitkan Invoice".
     * @return array{0:array,1:array} [data yang disimpan, galat per kolom]
     */
    public static function validateIssue(array $in): array
    {
        $e = [];
        $d = [];
        $d['invoice_no'] = mb_substr(trim((string) ($in['invoice_no'] ?? '')), 0, 80);
        if ($d['invoice_no'] === '') {
            $e['invoice_no'] = 'No. invoice wajib diisi untuk menerbitkan invoice.';
        }
        foreach (['invoice_date' => 'Tanggal invoice', 'due_date' => 'Tgl. jatuh tempo'] as $k => $label) {
            $raw = trim((string) ($in[$k] ?? ''));
            $d[$k] = $raw === '' ? null : Filters::date($raw);
            if ($raw === '') {
                $e[$k] = $label . ' wajib diisi.';
            } elseif ($d[$k] === null) {
                $e[$k] = $label . ' tidak valid.';
            }
        }
        if (!isset($e['invoice_date']) && !isset($e['due_date']) && $d['due_date'] < $d['invoice_date']) {
            $e['due_date'] = 'Tgl. jatuh tempo tidak boleh sebelum tanggal invoice.';
        }
        $d['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null;
        return [$d, $e];
    }

    /**
     * Terbitkan invoice: ubah status baris yang sama menjadi 'terbit' dan lengkapi No. invoice, tanggal, jatuh tempo.
     * Dalam satu transaksi DB dengan kunci baris (dua klik bersamaan tidak menerbitkan dua kali). Tidak membuat baris baru.
     * Item Proyeksi penerimaan dibuat sesudahnya oleh pemanggil (LinkService::ensureForecastForInvoice), persis seperti invoice biasa.
     * @throws RuntimeException bila invoice tak ada / bukan Piutang Proyek / sudah terbit
     */
    public static function issue(int $id, array $d, int $userId): array
    {
        return Db::tx(static function () use ($id, $d, $userId) {
            $old = Db::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$id]);
            if (!$old || $old['kind'] !== 'proyek') {
                throw new RuntimeException('Invoice tidak ditemukan.');
            }
            if ($old['issue_status'] !== self::BELUM) {
                throw new RuntimeException('Invoice ini sudah diterbitkan.');
            }
            $new = $d + ['issue_status' => self::TERBIT, 'issued_at' => date('Y-m-d H:i:s'), 'issued_by' => $userId];
            Db::update('invoices', $id, $new);
            Audit::log('invoice_issue', 'invoice', (string) $id, 'Terbitkan invoice ' . $d['invoice_no'] . ' ' . $old['customer'], array_intersect_key($old, $new), $new);
            Version::bump('invoices');
            Version::bump('forecast');
            return $old;
        });
    }
}
