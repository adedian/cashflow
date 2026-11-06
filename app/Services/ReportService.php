<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Laporan keuangan. Semua angka berasal dari transaksi aktif di database (bukan perhitungan di JS).
 * Konsisten dengan LedgerService: kas usaha = di luar pembiayaan/transfer; akun liabilitas (Time Loan) tidak masuk arus kas.
 */
final class ReportService
{
    public const TYPES = [
        'daily' => 'Harian', 'monthly' => 'Bulanan', 'yearly' => 'Tahunan', 'category' => 'Per Kategori',
        'project' => 'Per Proyek', 'account' => 'Per Rekening', 'balance' => 'Rekap Saldo',
    ];

    /** Jenis laporan + filter dari query string (dipakai halaman laporan dan ekspor/cetak). @return array{0:string,1:array} */
    public static function params(\App\Core\Request $req): array
    {
        $type = array_key_exists($req->str('type'), self::TYPES) ? $req->str('type') : 'monthly';
        $f = Filters::dashboard($req);
        if ($type === 'daily' && !$req->str('from') && !$req->str('to') && $f['period'] !== 'all') {
            $last = LedgerService::lastTxDate();
            if ($last) {
                $f['from'] = substr($last, 0, 7) . '-01';
                $f['to'] = date('Y-m-t', strtotime($last));
            }
        }
        return [$type, $f];
    }

    private static function assetIds(?int $accId): array
    {
        return LedgerService::assetIds($accId);
    }

    /** @return array{title:string,columns:array,rows:array,totals:?array,note:?string} */
    public static function build(string $type, array $f): array
    {
        return match ($type) {
            'daily' => self::daily($f),
            'yearly' => self::yearly($f),
            'category' => self::category($f),
            'project' => self::project($f),
            'account' => self::account($f),
            'balance' => self::balance($f),
            default => self::monthly($f),
        };
    }

    private static function where(array $f, array $ids): array
    {
        $w = ' WHERE t.voided_at IS NULL AND t.account_id IN (' . ($ids ? implode(',', $ids) : '0') . ')';
        $p = [];
        if (!empty($f['from'])) { $w .= ' AND t.tx_date >= ?'; $p[] = $f['from']; }
        if (!empty($f['to'])) { $w .= ' AND t.tx_date <= ?'; $p[] = $f['to']; }
        return [$w, $p];
    }

    private const OPS = "COALESCE(c.kind,'lainnya') NOT IN ('pembiayaan','transfer')";

    private static function flowCols(): array
    {
        return [
            'ops_in' => ['Mutasi masuk usaha', 'money'], 'ops_out' => ['Mutasi keluar usaha', 'money'], 'ops_net' => ['Arus kas bersih usaha', 'money'],
            'fin_net' => ['Pembiayaan & transfer (neto)', 'money'], 'closing' => ['Saldo akhir', 'money'],
        ];
    }

    private static function sumTotals(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = '0.00';
            foreach ($rows as $r) {
                $t[$k] = bcadd($t[$k], (string) $r[$k], 2);
            }
        }
        return $t;
    }

    private static function daily(array $f): array
    {
        $ids = self::assetIds($f['account'] ?? null);
        $sum = LedgerService::summary($f);
        [$w, $p] = self::where($f, $ids);
        $rows = Db::all(
            "SELECT t.tx_date AS label, COUNT(DISTINCT t.id) AS n,
               COALESCE(SUM(CASE WHEN " . self::OPS . " THEN t.debit END),0) ops_in,
               COALESCE(SUM(CASE WHEN " . self::OPS . " THEN t.credit END),0) ops_out,
               COALESCE(SUM(CASE WHEN c.kind IN ('pembiayaan','transfer') THEN t.debit - t.credit END),0) fin_net
             FROM " . LedgerService::LINES . ' t LEFT JOIN categories c ON c.id = t.category_id' . $w . ' GROUP BY t.tx_date ORDER BY t.tx_date',
            $p
        );
        $bal = $sum['opening'];
        foreach ($rows as &$r) {
            $r['ops_net'] = bcsub((string) $r['ops_in'], (string) $r['ops_out'], 2);
            $bal = bcadd($bal, bcadd($r['ops_net'], (string) $r['fin_net'], 2), 2);
            $r['closing'] = $bal;
        }
        unset($r);
        $cols = ['label' => ['Tanggal', 'date'], 'n' => ['Transaksi', 'int']] + self::flowCols();
        return ['title' => 'Laporan Harian', 'columns' => $cols, 'rows' => $rows,
            'totals' => self::sumTotals($rows, ['n', 'ops_in', 'ops_out', 'ops_net', 'fin_net']) + ['closing' => $sum['closing']],
            'opening' => $sum['opening'], 'note' => 'Saldo awal periode ' . money($sum['opening']) . '. Saldo akhir = saldo awal + arus kas bersih usaha + pembiayaan & transfer.'];
    }

    private static function monthly(array $f): array
    {
        $sum = LedgerService::summary($f);
        $m = LedgerService::monthly($f);
        $rows = [];
        foreach ($m as $r) {
            [$y, $mo] = explode('-', $r['ym']);
            $rows[] = ['label' => month_name((int) $mo) . ' ' . $y] + $r;
        }
        $cols = ['label' => ['Bulan', 'text']] + self::flowCols();
        return ['title' => 'Laporan Bulanan', 'columns' => $cols, 'rows' => $rows,
            'totals' => self::sumTotals($rows, ['ops_in', 'ops_out', 'ops_net', 'fin_net']) + ['closing' => $sum['closing']],
            'opening' => $sum['opening'], 'note' => 'Saldo awal periode ' . money($sum['opening']) . '.'];
    }

    private static function yearly(array $f): array
    {
        $ids = self::assetIds($f['account'] ?? null);
        $f2 = $f;
        $f2['from'] = null;
        $f2['to'] = $f['to'] ?? null;
        $sum = LedgerService::summary($f2);
        [$w, $p] = self::where($f2, $ids);
        $rows = Db::all(
            "SELECT YEAR(t.tx_date) AS label, COUNT(DISTINCT t.id) AS n,
               COALESCE(SUM(CASE WHEN " . self::OPS . " THEN t.debit END),0) ops_in,
               COALESCE(SUM(CASE WHEN " . self::OPS . " THEN t.credit END),0) ops_out,
               COALESCE(SUM(CASE WHEN c.kind IN ('pembiayaan','transfer') THEN t.debit - t.credit END),0) fin_net
             FROM " . LedgerService::LINES . ' t LEFT JOIN categories c ON c.id = t.category_id' . $w . ' GROUP BY label ORDER BY label',
            $p
        );
        $bal = $sum['opening'];
        foreach ($rows as &$r) {
            $r['ops_net'] = bcsub((string) $r['ops_in'], (string) $r['ops_out'], 2);
            $bal = bcadd($bal, bcadd($r['ops_net'], (string) $r['fin_net'], 2), 2);
            $r['closing'] = $bal;
        }
        unset($r);
        return ['title' => 'Laporan Tahunan', 'columns' => ['label' => ['Tahun', 'text'], 'n' => ['Transaksi', 'int']] + self::flowCols(), 'rows' => $rows,
            'totals' => self::sumTotals($rows, ['n', 'ops_in', 'ops_out', 'ops_net', 'fin_net']) + ['closing' => $sum['closing']], 'opening' => $sum['opening'],
            'note' => 'Dihitung dari saldo awal pembukaan akun sampai tanggal akhir (filter tanggal awal diabaikan agar saldo akhir tahun benar).'];
    }

    private static function category(array $f): array
    {
        $ids = self::assetIds($f['account'] ?? null);
        [$w, $p] = self::where($f, $ids);
        $rows = Db::all(
            "SELECT COALESCE(c.name,'Belum Dikategorikan') AS label, COALESCE(c.kind,'lainnya') AS kind, COUNT(DISTINCT t.id) AS n,
               COALESCE(SUM(t.debit),0) AS din, COALESCE(SUM(t.credit),0) AS cout
             FROM " . LedgerService::LINES . ' t LEFT JOIN categories c ON c.id = t.category_id' . $w .
            " GROUP BY label, kind ORDER BY FIELD(kind,'pendapatan','biaya','pajak','lainnya','pembiayaan','transfer'), cout DESC, din DESC",
            $p
        );
        foreach ($rows as &$r) {
            $r['net'] = bcsub((string) $r['din'], (string) $r['cout'], 2);
        }
        unset($r);
        return ['title' => 'Laporan per Kategori', 'columns' => ['label' => ['Kategori', 'text'], 'kind' => ['Jenis', 'text'], 'n' => ['Transaksi', 'int'], 'din' => ['Mutasi Masuk', 'money'], 'cout' => ['Mutasi Keluar', 'money'], 'net' => ['Selisih', 'money']],
            'rows' => $rows, 'totals' => self::sumTotals($rows, ['n', 'din', 'cout', 'net']),
            'note' => 'Termasuk kategori pembiayaan & transfer (ditandai pada kolom Jenis) sehingga total = perubahan saldo rekening bank/fasilitas pada periode.'];
    }

    private static function project(array $f): array
    {
        $ids = self::assetIds($f['account'] ?? null);
        [$w, $p] = self::where($f, $ids);
        $rows = Db::all(
            "SELECT COALESCE(p.name,'(Tanpa proyek)') AS label, COUNT(DISTINCT t.id) AS n,
               COALESCE(SUM(t.debit),0) AS din, COALESCE(SUM(t.credit),0) AS cout
             FROM " . LedgerService::LINES . ' t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN projects p ON p.id = t.project_id' . $w .
            ' AND ' . self::OPS . ' GROUP BY label ORDER BY (p.id IS NULL), cout DESC',
            $p
        );
        foreach ($rows as &$r) {
            $r['net'] = bcsub((string) $r['din'], (string) $r['cout'], 2);
        }
        unset($r);
        return ['title' => 'Laporan per Proyek', 'columns' => ['label' => ['Proyek', 'text'], 'n' => ['Transaksi', 'int'], 'din' => ['Mutasi Masuk', 'money'], 'cout' => ['Mutasi Keluar', 'money'], 'net' => ['Selisih kas', 'money']],
            'rows' => $rows, 'totals' => self::sumTotals($rows, ['n', 'din', 'cout', 'net']),
            'note' => 'Hanya kas usaha (di luar pembiayaan & transfer). Selisih kas bukan laba: belum memuat piutang/tagihan yang belum dibayar.'];
    }

    private static function account(array $f): array
    {
        $to = $f['to'] ?? null;
        $from = $f['from'] ?? null;
        $before = $from ? LedgerService::accounts(date('Y-m-d', strtotime($from . ' -1 day')), true) : null;
        $end = LedgerService::accounts($to, true);
        $rows = [];
        foreach ($end as $i => $a) {
            if (!empty($f['account']) && (int) $a['id'] !== (int) $f['account']) {
                continue;
            }
            $open = $before ? $before[$i]['balance'] : (string) $a['opening_balance'];
            $where = ' WHERE t.voided_at IS NULL AND t.account_id = ?';
            $p = [(int) $a['id']];
            if ($from) { $where .= ' AND t.tx_date >= ?'; $p[] = $from; }
            if ($to) { $where .= ' AND t.tx_date <= ?'; $p[] = $to; }
            $x = Db::one('SELECT COUNT(*) n, COALESCE(SUM(t.debit),0) d, COALESCE(SUM(t.credit),0) c FROM transactions t' . $where, $p);
            $rows[] = ['label' => $a['name'], 'mode' => $a['balance_mode'] === 'liability' ? 'Pinjaman' : ($a['kind'] === 'facility' ? 'Fasilitas' : 'Bank'),
                'opening' => $open, 'din' => bcadd((string) $x['d'], '0', 2), 'cout' => bcadd((string) $x['c'], '0', 2), 'n' => (int) $x['n'], 'closing' => $a['balance']];
        }
        return ['title' => 'Laporan per Rekening', 'columns' => ['label' => ['Rekening', 'text'], 'mode' => ['Jenis', 'text'], 'n' => ['Transaksi', 'int'], 'opening' => ['Saldo awal', 'money'], 'din' => ['Debit', 'money'], 'cout' => ['Kredit', 'money'], 'closing' => ['Saldo akhir', 'money']],
            'rows' => $rows, 'totals' => null,
            'note' => 'Akun bank/fasilitas: saldo akhir = saldo awal + debit − kredit. Akun pinjaman (Time Loan): pemakaian = saldo awal + kredit (penarikan) − debit (pembayaran). Total antar-rekening tidak dijumlahkan karena transfer internal & pinjaman.'];
    }

    private static function balance(array $f): array
    {
        $to = $f['to'] ?? null;
        $rows = [];
        foreach (LedgerService::accounts($to, true) as $a) {
            if (!empty($f['account']) && (int) $a['id'] !== (int) $f['account']) {
                continue;
            }
            $rows[] = ['label' => $a['name'], 'mode' => $a['balance_mode'] === 'liability' ? 'Pinjaman' : ($a['kind'] === 'facility' ? 'Fasilitas' : 'Bank'),
                'balance' => $a['balance'], 'limit' => $a['credit_limit'], 'remaining' => $a['remaining'] ?? null];
        }
        $net = '0.00';
        foreach ($rows as $r) {
            $net = $r['mode'] === 'Pinjaman' ? bcsub($net, $r['balance'], 2) : bcadd($net, $r['balance'], 2);
        }
        return ['title' => 'Rekap Saldo', 'columns' => ['label' => ['Rekening', 'text'], 'mode' => ['Jenis', 'text'], 'balance' => ['Saldo / pemakaian', 'money'], 'limit' => ['Limit', 'money'], 'remaining' => ['Sisa limit', 'money']],
            'rows' => $rows, 'totals' => null,
            'note' => 'Posisi bersih (saldo bank + saldo fasilitas − pemakaian pinjaman) per ' . ($to ? fmt_date($to) : 'transaksi terakhir') . ': ' . money($net) . '.'];
    }
}
