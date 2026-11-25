<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Invoice Belum Terbit (rencana tagihan per project yang belum diterbitkan) dan penerbitannya secara BERTAHAP.
 *
 * Sumber = baris `invoices` berstatus 'belum_terbit' (nominal AWAL tidak pernah diubah oleh penerbitan). Satu project boleh punya banyak sumber.
 * Tiap aksi "Terbitkan Invoice" membuat SATU invoice normal baru (status 'terbit', masuk piutang/Proyeksi lewat mekanisme biasa) dan mencatat
 * baris `invoice_issuances` (ID sumber + ID invoice + nominal). Rumus:
 *     Sisa sumber = Nominal awal − Σ nominal penerbitan yang belum dibatalkan.
 * Sisa 0 → sumber berstatus 'selesai' (Diterbitkan Penuh); histori tetap. Invoice belum terbit bukan piutang: tanpa jatuh tempo, item Proyeksi, pengingat.
 * Satu penerbitan boleh menghabiskan beberapa sumber pada project yang sama (yang dipilih dulu, lalu yang tertua) — tiap sumber dipotong persis sekali.
 */
final class InvoiceIssueService
{
    public const TERBIT = 'terbit';
    public const BELUM = 'belum_terbit';
    public const SELESAI = 'selesai';

    /** Sub-query nominal yang sudah diterbitkan untuk sumber `i` (penerbitan yang dibatalkan tidak dihitung). */
    private const ISSUED = '(SELECT COALESCE(SUM(x.amount), 0) FROM invoice_issuances x WHERE x.source_id = i.id AND x.cancelled_at IS NULL)';

    /** Sumber (belum terbit / selesai) = bukan invoice/piutang. */
    public static function isSource(?string $status): bool
    {
        return $status === self::BELUM || $status === self::SELESAI;
    }

    /** @return array{n:int,total:string,projects:int} jumlah pencatatan yang masih punya sisa, total SISA, jumlah project */
    public static function summary(): array
    {
        $r = Db::one(
            "SELECT COUNT(*) n, COALESCE(SUM(i.amount - " . self::ISSUED . "), 0) a, COUNT(DISTINCT COALESCE(i.project_id, CONCAT('n', i.id))) pj
             FROM invoices i WHERE i.kind = 'proyek' AND i.issue_status = 'belum_terbit'"
        );
        return ['n' => (int) ($r['n'] ?? 0), 'total' => bcadd((string) ($r['a'] ?? '0'), '0', 2), 'projects' => (int) ($r['pj'] ?? 0)];
    }

    /** Sumber yang masih punya sisa (untuk bagian Proyeksi), dikelompokkan per project. */
    public static function rows(int $limit = 500): array
    {
        return Db::all(
            "SELECT i.id, i.customer, i.project_id, COALESCE(p.name, i.project_name) AS project, i.invoice_no, i.description, i.invoice_date, i.amount, i.note,
                    " . self::ISSUED . " AS issued, i.amount - " . self::ISSUED . " AS remaining
             FROM invoices i LEFT JOIN projects p ON p.id = i.project_id
             WHERE i.kind = 'proyek' AND i.issue_status = 'belum_terbit'
             ORDER BY (COALESCE(p.name, i.project_name) IS NULL), COALESCE(p.name, i.project_name), (i.invoice_date IS NULL), i.invoice_date, i.id LIMIT " . max(1, $limit)
        );
    }

    /**
     * Daftar tab "Invoice Belum Terbit".
     * @param string $status open (masih ada sisa) | done (diterbitkan penuh) | all
     * @return array{rows:array,total:int,sumAmount:string,sumRemaining:string}
     */
    public static function listing(string $status, string $q, int $page, int $per): array
    {
        $w = ["i.kind = 'proyek'"];
        $p = [];
        $w[] = match ($status) {
            'done' => "i.issue_status = 'selesai'",
            'all' => "i.issue_status IN ('belum_terbit','selesai')",
            default => "i.issue_status = 'belum_terbit'",
        };
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(i.customer LIKE ? OR i.project_name LIKE ? OR p.name LIKE ? OR i.invoice_no LIKE ? OR i.description LIKE ? OR i.note LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like);
        }
        $from = ' FROM invoices i LEFT JOIN projects p ON p.id = i.project_id WHERE ' . implode(' AND ', $w);
        $tot = Db::one('SELECT COUNT(*) n, COALESCE(SUM(i.amount), 0) a, COALESCE(SUM(i.amount - ' . self::ISSUED . '), 0) r' . $from, $p);
        $rows = Db::all(
            'SELECT i.*, COALESCE(p.name, i.project_name) AS project, ' . self::ISSUED . ' AS issued, i.amount - ' . self::ISSUED . ' AS remaining' . $from
            . ' ORDER BY (i.invoice_date IS NULL), i.invoice_date DESC, i.id DESC LIMIT ' . max(1, $per) . ' OFFSET ' . max(0, ($page - 1) * $per),
            $p
        );
        return ['rows' => $rows, 'total' => (int) $tot['n'], 'sumAmount' => bcadd((string) $tot['a'], '0', 2), 'sumRemaining' => bcadd((string) $tot['r'], '0', 2)];
    }

    /** Nominal yang sudah diterbitkan dari sumber ini (penerbitan aktif). */
    public static function issued(int $sourceId): string
    {
        return bcadd((string) Db::val('SELECT COALESCE(SUM(amount), 0) FROM invoice_issuances WHERE source_id = ? AND cancelled_at IS NULL', [$sourceId]), '0', 2);
    }

    /** Histori penerbitan sebuah sumber (termasuk yang dibatalkan), terlama dulu. */
    public static function history(int $sourceId): array
    {
        return Db::all(
            'SELECT s.*, i.invoice_no AS cur_no, i.customer, i.amount AS inv_amount, i.due_date, i.paid, u.full_name AS by_name
             FROM invoice_issuances s LEFT JOIN invoices i ON i.id = s.invoice_id LEFT JOIN users u ON u.id = s.issued_by
             WHERE s.source_id = ? ORDER BY s.issued_at, s.id',
            [$sourceId]
        );
    }

    /** Asal-usul sebuah invoice hasil penerbitan: sumber-sumber yang saldonya dipakai. */
    public static function origin(int $invoiceId): array
    {
        return Db::all(
            'SELECT s.source_id, s.amount, s.issued_at, i.invoice_no, i.customer, i.description, COALESCE(p.name, i.project_name) AS project, i.issue_status
             FROM invoice_issuances s JOIN invoices i ON i.id = s.source_id LEFT JOIN projects p ON p.id = i.project_id
             WHERE s.invoice_id = ? AND s.cancelled_at IS NULL ORDER BY s.id',
            [$invoiceId]
        );
    }

    /** Saldo (sisa) Invoice Belum Terbit per project: [project_id => ['id','name','remaining','sources']] hanya yang masih ada sisa. */
    public static function balances(): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT i.project_id AS pid, p.name, SUM(i.amount - " . self::ISSUED . ") AS remaining, COUNT(*) AS n
             FROM invoices i JOIN projects p ON p.id = i.project_id
             WHERE i.kind = 'proyek' AND i.issue_status = 'belum_terbit' GROUP BY i.project_id, p.name HAVING remaining > 0.004 ORDER BY p.name"
        ) as $r) {
            $out[(int) $r['pid']] = ['id' => (int) $r['pid'], 'name' => (string) $r['name'], 'remaining' => bcadd((string) $r['remaining'], '0', 2), 'sources' => (int) $r['n']];
        }
        return $out;
    }

    /** Hitung ulang status sumber dari nominal awal & penerbitan: sisa 0 → selesai, selain itu belum_terbit. Panggil di dalam transaksi DB. */
    public static function recalc(int $sourceId): void
    {
        $s = Db::one("SELECT id, amount, issue_status FROM invoices WHERE id = ? AND kind = 'proyek' FOR UPDATE", [$sourceId]);
        if (!$s || !self::isSource($s['issue_status'])) {
            return;
        }
        $rem = bcsub((string) $s['amount'], self::issued($sourceId), 2);
        $new = bccomp($rem, '0.004', 3) > 0 ? self::BELUM : self::SELESAI;
        if ($new !== $s['issue_status']) {
            Db::update('invoices', $sourceId, ['issue_status' => $new]);
        }
    }

    /**
     * Validasi isian formulir "Terbitkan Invoice" (bentuk saja; saldo diperiksa di issue() dalam kunci).
     * @return array{0:array,1:array} [data, galat per kolom]
     */
    public static function validateIssue(array $in): array
    {
        $e = [];
        $d = [];
        $pid = trim((string) ($in['project_id'] ?? ''));
        $d['project_id'] = ctype_digit($pid) ? (int) $pid : 0;
        $amount = parse_amount($in['amount'] ?? '');
        if ($amount === null || bccomp($amount, '0', 2) <= 0) {
            $e['amount'] = 'Nominal yang diterbitkan harus lebih dari 0.';
        }
        $d['amount'] = $amount ?? '0.00';
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
        $key = strtolower(trim((string) ($in['issue_key'] ?? '')));
        $d['key'] = preg_match('/^[a-f0-9]{32}$/', $key) ? $key : bin2hex(random_bytes(16));   // tanpa kunci dari formulir: tiap kiriman dianggap baru
        return [$d, $e];
    }

    /**
     * Terbitkan sebagian/seluruh saldo Invoice Belum Terbit sebuah project menjadi SATU invoice normal.
     * Seluruh baris sumber project itu dikunci (FOR UPDATE) sebelum saldo dihitung, sehingga dua permintaan bersamaan tidak bisa melebihi saldo,
     * dan kunci idempoten (`batch_key`) membuat klik ganda tidak menerbitkan dua kali.
     *
     * @param int   $sourceId sumber yang dipilih pengguna (dipotong lebih dulu); project penerbitan = $d['project_id'] (0 = sumber tanpa project)
     * @return array{invoice_id:int,duplicate:bool,allocations:list<array{source:int,amount:string}>}
     * @throws RuntimeException pesan siap tampil (saldo kurang, sumber tak ada, dst.)
     */
    public static function issue(int $sourceId, array $d, int $userId): array
    {
        return Db::tx(static function () use ($sourceId, $d, $userId) {
            $pid = (int) $d['project_id'];
            if ($pid > 0) {
                $group = Db::all("SELECT * FROM invoices WHERE kind = 'proyek' AND issue_status = 'belum_terbit' AND project_id = ? ORDER BY id FOR UPDATE", [$pid]);
            } else {   // sumber lama tanpa project: hanya dirinya sendiri
                $group = Db::all("SELECT * FROM invoices WHERE id = ? AND kind = 'proyek' AND issue_status = 'belum_terbit' AND project_id IS NULL FOR UPDATE", [$sourceId]);
            }
            $dup = Db::one('SELECT invoice_id FROM invoice_issuances WHERE batch_key = ? LIMIT 1', [$d['key']]);
            if ($dup) {
                if ($dup['invoice_id'] === null) {
                    throw new RuntimeException('Penerbitan dari formulir ini sudah pernah dilakukan lalu dibatalkan. Buka kembali formulir Terbitkan Invoice untuk mengulang.');
                }
                return ['invoice_id' => (int) $dup['invoice_id'], 'duplicate' => true, 'allocations' => []];   // klik ganda / kirim ulang: tidak memotong saldo lagi
            }
            if (!$group) {
                throw new RuntimeException('Tidak ada saldo Invoice Belum Terbit untuk project ini (mungkin sudah diterbitkan penuh).');
            }
            // urutan pemotongan: sumber yang dipilih lebih dulu, lalu yang tertua
            usort($group, static fn($a, $b) => [(int) $b['id'] === $sourceId, $a['invoice_date'] ?? '9999', (int) $a['id']] <=> [(int) $a['id'] === $sourceId, $b['invoice_date'] ?? '9999', (int) $b['id']]);
            $avail = [];
            $total = '0.00';
            foreach ($group as $g) {
                $rem = bcsub((string) $g['amount'], self::issued((int) $g['id']), 2);
                if (bccomp($rem, '0.004', 3) > 0) {
                    $avail[] = ['row' => $g, 'rem' => $rem];
                    $total = bcadd($total, $rem, 2);
                }
            }
            if (bccomp($d['amount'], $total, 2) > 0) {
                throw new RuntimeException('Nominal ' . money($d['amount']) . ' melebihi sisa Invoice Belum Terbit project ini (' . money($total) . ').');
            }
            $first = $avail[0]['row'];
            $pname = $pid > 0 ? (string) Db::val('SELECT name FROM projects WHERE id = ?', [$pid]) : (string) ($first['project_name'] ?? '');
            $invId = Db::insert('invoices', [
                'kind' => 'proyek', 'issue_status' => self::TERBIT, 'customer' => $first['customer'], 'project_name' => $pname !== '' ? $pname : null,
                'project_id' => $pid > 0 ? $pid : null, 'invoice_no' => $d['invoice_no'], 'invoice_date' => $d['invoice_date'], 'due_date' => $d['due_date'],
                'description' => $d['description'] ?? ($first['description'] ?? null), 'amount' => $d['amount'], 'paid' => '0.00', 'paid_known' => 1,
                'issued_at' => date('Y-m-d H:i:s'), 'issued_by' => $userId, 'created_by' => $userId,
            ]);
            $left = $d['amount'];
            $alloc = [];
            foreach ($avail as $a) {
                if (bccomp($left, '0.004', 3) <= 0) {
                    break;
                }
                $take = bccomp($left, $a['rem'], 2) < 0 ? $left : $a['rem'];
                Db::insert('invoice_issuances', ['batch_key' => $d['key'], 'source_id' => (int) $a['row']['id'], 'invoice_id' => $invId, 'invoice_no' => $d['invoice_no'],
                    'amount' => $take, 'issued_by' => $userId]);
                $alloc[] = ['source' => (int) $a['row']['id'], 'amount' => $take, 'before' => $a['rem']];
                $left = bcsub($left, $take, 2);
                self::recalc((int) $a['row']['id']);
            }
            $nominal = money($d['amount']);
            Audit::log('invoice_issue', 'invoice', (string) $invId, 'Terbitkan invoice ' . $d['invoice_no'] . ' ' . $nominal . ' dari Invoice Belum Terbit project ' . $pname . ' (sumber #' . implode(', #', array_column($alloc, 'source')) . ')',
                ['sumber' => array_map(static fn($x) => ['id' => $x['source'], 'sisa_sebelum' => $x['before']], $alloc)],
                ['invoice_id' => $invId, 'nominal' => $d['amount'], 'jatuh_tempo' => $d['due_date'], 'alokasi' => array_map(static fn($x) => ['id' => $x['source'], 'nominal' => $x['amount']], $alloc)]);
            Version::bump('invoices');
            Version::bump('forecast');
            return ['invoice_id' => $invId, 'duplicate' => false, 'allocations' => $alloc];
        });
    }

    /**
     * Pembatalan penerbitan: dipanggil saat invoice hasil penerbitan dihapus. Baris histori ditandai dibatalkan (bukan dihapus),
     * saldo sumber kembali, status sumber dihitung ulang. Dalam transaksi DB milik pemanggil.
     * @return list<int> ID sumber yang saldonya kembali
     */
    public static function cancelForInvoice(int $invoiceId, int $userId): array
    {
        $rows = Db::all('SELECT id, source_id FROM invoice_issuances WHERE invoice_id = ? AND cancelled_at IS NULL FOR UPDATE', [$invoiceId]);
        $src = [];
        foreach ($rows as $r) {
            Db::update('invoice_issuances', (int) $r['id'], ['cancelled_at' => date('Y-m-d H:i:s'), 'cancelled_by' => $userId, 'invoice_id' => null]);
            $src[(int) $r['source_id']] = true;
        }
        // baris yang sudah dibatalkan sebelumnya tidak punya invoice_id; yang masih menunjuk invoice ini (tak ada) dilepas oleh FK
        foreach (array_keys($src) as $sid) {
            self::recalc($sid);
        }
        return array_keys($src);
    }
}
