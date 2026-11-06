<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Perhitungan saldo & arus kas. SATU-SATUNYA tempat rumus saldo (tidak ada perhitungan uang di JavaScript).
 *
 * Konvensi:
 *  - akun 'asset'    : saldo = saldo_awal + Σdebit − Σkredit        (debit = uang masuk)
 *  - akun 'liability': pemakaian = saldo_awal + Σkredit − Σdebit    (Time Loan: kredit = penarikan, debit = pembayaran)
 *  - Arus kas usaha = transaksi pada akun 'asset' yang kategorinya BUKAN pembiayaan/transfer (agar tidak terhitung ganda).
 *  - Transaksi void (voided_at terisi) tidak pernah dihitung.
 * Seluruh aritmetika uang memakai BCMath (string), bukan float. Kueri agregat dirancang memakai indeks penutup
 * (ix_tx_agg / ix_tx_run) agar tetap cepat pada ratusan ribu transaksi.
 */
final class LedgerService
{
    /** Jenis kategori yang dikeluarkan dari "mutasi masuk/keluar usaha". */
    public const NON_OPERATING = ['pembiayaan', 'transfer'];

    private const SIGN_SQL = "CASE WHEN a.balance_mode = 'asset' THEN t.debit - t.credit ELSE t.credit - t.debit END";
    private const OPS = "COALESCE(c.kind,'lainnya') NOT IN ('pembiayaan','transfer')";

    /** @var array<int,array>|null metadata akun (4 baris) — cache per request */
    private static ?array $meta = null;

    public static function flushCache(): void
    {
        self::$meta = null;
    }

    /** Metadata akun aktif (tanpa saldo). */
    public static function meta(): array
    {
        if (self::$meta === null) {
            self::$meta = Db::all('SELECT * FROM accounts WHERE is_active = 1 ORDER BY sort_order, id');
        }
        return self::$meta;
    }

    /** ID akun aset (bank/fasilitas) yang ikut arus kas; opsional dibatasi satu akun / satu jenis. */
    public static function assetIds(?int $accId = null, ?string $kind = null): array
    {
        $ids = [];
        foreach (self::meta() as $a) {
            if ($a['balance_mode'] === 'asset' && ($accId === null || (int) $a['id'] === $accId) && ($kind === null || $a['kind'] === $kind)) {
                $ids[] = (int) $a['id'];
            }
        }
        return $ids;
    }

    /** Σ saldo akun aset terpilih pada akhir hari $day (null = hanya saldo pembukaan). */
    private static function assetBalance(array $ids, ?string $day): string
    {
        if (!$ids) {
            return '0.00';
        }
        $open = '0.00';
        foreach (self::meta() as $a) {
            if (in_array((int) $a['id'], $ids, true)) {
                $open = bcadd($open, (string) $a['opening_balance'], 2);
            }
        }
        if ($day === null) {
            return $open;
        }
        $delta = Db::val(
            'SELECT COALESCE(SUM(debit - credit), 0) FROM transactions WHERE voided_at IS NULL AND account_id IN (' . implode(',', $ids) . ') AND tx_date <= ?',
            [$day]
        );
        return bcadd($open, (string) $delta, 2);
    }

    /** Daftar akun + saldo per tanggal tertentu (null = semua transaksi). */
    public static function accounts(?string $asOf = null, bool $onlyActive = true): array
    {
        $params = [];
        $dateSql = '';
        if ($asOf !== null) {
            $dateSql = ' AND t.tx_date <= ?';
            $params[] = $asOf;
        }
        $rows = Db::all(
            'SELECT a.*, COALESCE(SUM(' . self::SIGN_SQL . '), 0) AS delta, COUNT(t.id) AS tx_count,
                    COALESCE(SUM(t.debit), 0) AS sum_debit, COALESCE(SUM(t.credit), 0) AS sum_credit, MAX(t.tx_date) AS last_tx
             FROM accounts a
             LEFT JOIN transactions t ON t.account_id = a.id AND t.voided_at IS NULL' . $dateSql .
            ($onlyActive ? ' WHERE a.is_active = 1' : '') . ' GROUP BY a.id ORDER BY a.sort_order, a.id',
            $params
        );
        foreach ($rows as &$r) {
            $r['balance'] = bcadd((string) $r['opening_balance'], (string) $r['delta'], 2);
            $r['limit'] = $r['credit_limit'];
            if ($r['credit_limit'] !== null) {
                // aset: sisa = limit + saldo (saldo negatif = terpakai); liabilitas: sisa = limit − pemakaian
                $r['used'] = $r['balance_mode'] === 'asset'
                    ? (bccomp($r['balance'], '0', 2) < 0 ? bcmul($r['balance'], '-1', 2) : '0.00')
                    : $r['balance'];
                $r['remaining'] = $r['balance_mode'] === 'asset'
                    ? bcadd((string) $r['credit_limit'], $r['balance'], 2)
                    : bcsub((string) $r['credit_limit'], $r['balance'], 2);
                $r['used_pct'] = bccomp((string) $r['credit_limit'], '0', 2) > 0
                    ? (float) bcmul(bcdiv($r['used'], (string) $r['credit_limit'], 6), '100', 2) : 0.0;
            }
        }
        return $rows;
    }

    /** Tanggal transaksi terakhir (null bila belum ada). */
    public static function lastTxDate(): ?string
    {
        $v = Db::val('SELECT MAX(tx_date) FROM transactions WHERE voided_at IS NULL');
        return $v ?: null;
    }

    public static function firstTxDate(): ?string
    {
        $v = Db::val('SELECT MIN(tx_date) FROM transactions WHERE voided_at IS NULL');
        return $v ?: null;
    }

    /** Peta kategori id => [name, kind] (cache per request). */
    private static ?array $cats = null;

    private static function cats(): array
    {
        if (self::$cats === null) {
            self::$cats = [];
            foreach (Db::all('SELECT id, name, kind FROM categories') as $c) {
                self::$cats[(int) $c['id']] = $c;
            }
        }
        return self::$cats;
    }

    /**
     * Agregat arus kas per (bulan, akun, kategori) — SATU kueri di atas indeks penutup ix_tx_agg.
     * Semua angka ringkasan/bulanan/komposisi diturunkan dari hasil ini (jumlah baris kecil).
     * @return array<int,array{ym:string,account_id:int,category_id:?int,d:string,c:string,n:int}>
     */
    private static function flowRows(array $f, array $ids): array
    {
        [$w, $p] = self::flowWhere($f, $ids);
        return Db::all(
            "SELECT DATE_FORMAT(t.tx_date, '%Y-%m') AS ym, t.account_id, t.category_id, SUM(t.debit) AS d, SUM(t.credit) AS c, COUNT(DISTINCT t.id) AS n
             FROM " . self::LINES . ' t' . $w . ' GROUP BY ym, t.account_id, t.category_id',
            $p
        );
    }

    /**
     * Tabel turunan "baris transaksi" untuk semua agregat per kategori/proyek: satu baris per uraian (transaction_items) dengan
     * kategori/proyek/jumlahnya sendiri; transaksi yang belum punya baris (data lama/impor) menjadi satu baris dari kolom
     * transactions. Arah (debit/credit) mengikuti transaksi. Kolom: id, account_id, tx_date, seq, voided_at, category_id,
     * project_id, debit, credit - sama dengan nama kolom transactions, sehingga cukup mengganti "transactions t" dengan LINES + " t".
     */
    public const LINES = '(SELECT t0.id, t0.account_id, t0.tx_date, t0.seq, t0.voided_at,
        IF(i.id IS NULL, t0.category_id, i.category_id) AS category_id,
        IF(i.id IS NULL, t0.project_id, i.project_id) AS project_id,
        IF(i.id IS NULL, t0.debit, IF(t0.debit <> 0, i.amount, 0)) AS debit,
        IF(i.id IS NULL, t0.credit, IF(t0.credit <> 0, i.amount, 0)) AS credit
        FROM transactions t0 LEFT JOIN transaction_items i ON i.transaction_id = t0.id)';

    /**
     * Tabel turunan untuk daftar Transaksi per baris uraian: satu baris per uraian (transaction_items), lengkap dengan data
     * tingkat transaksi yang dipakai filter. Transaksi tanpa baris tersimpan menjadi satu baris (line_no = 0).
     * debit/credit/category_id/project_id = milik baris; tx_debit/tx_credit = milik transaksi (arah masuk/keluar).
     */
    public const LINE_ROWS = '(SELECT t0.id, COALESCE(i.line_no, 0) AS line_no, t0.account_id, t0.tx_date, t0.seq, t0.voided_at,
        t0.voucher_code, t0.counterparty, t0.ref_no, t0.category_raw, t0.note, t0.flag, t0.debit AS tx_debit, t0.credit AS tx_credit,
        IF(i.id IS NULL, t0.description, i.description) AS line_desc,
        IF(i.id IS NULL, t0.category_id, i.category_id) AS category_id,
        IF(i.id IS NULL, t0.project_id, i.project_id) AS project_id,
        IF(i.id IS NULL, t0.debit, IF(t0.debit <> 0, i.amount, 0)) AS debit,
        IF(i.id IS NULL, t0.credit, IF(t0.credit <> 0, i.amount, 0)) AS credit
        FROM transactions t0 LEFT JOIN transaction_items i ON i.transaction_id = t0.id)';

    private static function kindOf(?int $catId): string
    {
        return $catId !== null ? (self::cats()[$catId]['kind'] ?? 'lainnya') : 'lainnya';
    }

    /**
     * Ringkasan periode untuk dashboard/laporan.
     * @param array $f from, to (Y-m-d, boleh null), account (id|null), project (id|null)
     */
    public static function summary(array $f, ?array $rows = null): array
    {
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;
        $accId = !empty($f['account']) ? (int) $f['account'] : null;
        $ids = self::assetIds($accId);
        if (!$ids) {
            return self::emptySummary($from, $to); // akun liabilitas dipilih: tidak ada arus kas
        }
        $opening = self::assetBalance($ids, $from !== null ? date('Y-m-d', strtotime($from . ' -1 day')) : null);
        $rows ??= self::flowRows($f, $ids);

        $s = ['ops_in' => '0.00', 'ops_out' => '0.00', 'fin_in' => '0.00', 'fin_out' => '0.00', 'tr_in' => '0.00', 'tr_out' => '0.00'];
        $n = 0;
        foreach ($rows as $r) {
            $k = self::kindOf($r['category_id'] !== null ? (int) $r['category_id'] : null);
            $n += (int) $r['n'];
            if ($k === 'pembiayaan') {
                $s['fin_in'] = bcadd($s['fin_in'], (string) $r['d'], 2);
                $s['fin_out'] = bcadd($s['fin_out'], (string) $r['c'], 2);
            } elseif ($k === 'transfer') {
                $s['tr_in'] = bcadd($s['tr_in'], (string) $r['d'], 2);
                $s['tr_out'] = bcadd($s['tr_out'], (string) $r['c'], 2);
            } else {
                $s['ops_in'] = bcadd($s['ops_in'], (string) $r['d'], 2);
                $s['ops_out'] = bcadd($s['ops_out'], (string) $r['c'], 2);
            }
        }
        $opsNet = bcsub($s['ops_in'], $s['ops_out'], 2);
        $finNet = bcsub($s['fin_in'], $s['fin_out'], 2);
        $trNet = bcsub($s['tr_in'], $s['tr_out'], 2);
        $change = bcadd(bcadd($opsNet, $finNet, 2), $trNet, 2);
        return [
            'from' => $from, 'to' => $to, 'tx_count' => $n,
            'opening' => $opening,
            'ops_in' => $s['ops_in'], 'ops_out' => $s['ops_out'], 'ops_net' => $opsNet,
            'fin_in' => $s['fin_in'], 'fin_out' => $s['fin_out'], 'fin_net' => $finNet,
            'transfer_net' => $trNet, 'change' => $change,
            'closing' => bcadd($opening, $change, 2),
        ];
    }

    /** WHERE untuk kueri arus kas (alias t). */
    private static function flowWhere(array $f, array $ids): array
    {
        $w = ' WHERE t.voided_at IS NULL AND t.account_id IN (' . implode(',', $ids) . ')';
        $p = [];
        if (!empty($f['from'])) { $w .= ' AND t.tx_date >= ?'; $p[] = $f['from']; }
        if (!empty($f['to'])) { $w .= ' AND t.tx_date <= ?'; $p[] = $f['to']; }
        if (!empty($f['project'])) { $w .= ' AND t.project_id = ?'; $p[] = (int) $f['project']; }
        return [$w, $p];
    }

    private static function emptySummary(?string $from, ?string $to): array
    {
        $z = '0.00';
        return ['from' => $from, 'to' => $to, 'tx_count' => 0, 'opening' => $z, 'ops_in' => $z, 'ops_out' => $z, 'ops_net' => $z,
            'fin_in' => $z, 'fin_out' => $z, 'fin_net' => $z, 'transfer_net' => $z, 'change' => $z, 'closing' => $z];
    }

    /**
     * Deret bulanan: mutasi masuk/keluar usaha, pembiayaan neto, saldo akhir bulan (semua rekening aset) dan saldo rekening bank saja.
     * @return array<int,array>
     */
    public static function monthly(array $f, ?array $rows = null): array
    {
        $accId = !empty($f['account']) ? (int) $f['account'] : null;
        $ids = self::assetIds($accId);
        if (!$ids) {
            return [];
        }
        $day = !empty($f['from']) ? date('Y-m-d', strtotime($f['from'] . ' -1 day')) : null;
        $bal = self::assetBalance($ids, $day);
        $bank = self::assetBalance(self::assetIds($accId, 'bank'), $day);
        $rows ??= self::flowRows($f, $ids);
        $kindAcc = [];
        foreach (self::meta() as $a) {
            $kindAcc[(int) $a['id']] = $a['kind'];
        }

        $by = [];
        foreach ($rows as $r) {
            if (!isset($by[$r['ym']])) {
                $by[$r['ym']] = ['ops_in' => '0.00', 'ops_out' => '0.00', 'fin_net' => '0.00', 'bank_net' => '0.00'];
            }
            $k = self::kindOf($r['category_id'] !== null ? (int) $r['category_id'] : null);
            if ($k === 'pembiayaan' || $k === 'transfer') {
                $by[$r['ym']]['fin_net'] = bcadd($by[$r['ym']]['fin_net'], bcsub((string) $r['d'], (string) $r['c'], 2), 2);
            } else {
                $by[$r['ym']]['ops_in'] = bcadd($by[$r['ym']]['ops_in'], (string) $r['d'], 2);
                $by[$r['ym']]['ops_out'] = bcadd($by[$r['ym']]['ops_out'], (string) $r['c'], 2);
            }
            if (($kindAcc[(int) $r['account_id']] ?? '') === 'bank') {
                $by[$r['ym']]['bank_net'] = bcadd($by[$r['ym']]['bank_net'], bcsub((string) $r['d'], (string) $r['c'], 2), 2);
            }
        }
        ksort($by);
        $out = [];
        foreach ($by as $ym => $m) {
            $net = bcsub($m['ops_in'], $m['ops_out'], 2);
            $bal = bcadd($bal, bcadd($net, $m['fin_net'], 2), 2);
            $bank = bcadd($bank, $m['bank_net'], 2);
            $out[] = ['ym' => $ym, 'ops_in' => $m['ops_in'], 'ops_out' => $m['ops_out'], 'ops_net' => $net, 'fin_net' => $m['fin_net'], 'closing' => $bal, 'closing_bank' => $bank];
        }
        return $out;
    }

    /** Komposisi pengeluaran usaha per kategori (turunan dari agregat arus kas) atau per proyek. */
    public static function expenseBreakdown(array $f, string $by = 'category', int $limit = 8, ?array $rows = null): array
    {
        $accId = !empty($f['account']) ? (int) $f['account'] : null;
        $ids = self::assetIds($accId);
        if (!$ids) {
            return [];
        }
        if ($by === 'project') {
            [$w, $p] = self::flowWhere($f, $ids);
            $w .= " AND COALESCE(c.kind, 'lainnya') NOT IN ('pembiayaan','transfer')";
            $rowsP = Db::all(
                "SELECT COALESCE(p.name, '(Tanpa proyek)') AS label, SUM(t.credit - t.debit) AS total
                 FROM " . self::LINES . ' t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN projects p ON p.id = t.project_id' . $w .
                ' GROUP BY label HAVING total > 0 ORDER BY total DESC',
                $p
            );
            return self::topN($rowsP, $limit);
        }
        $rows ??= self::flowRows($f, $ids);
        $tot = [];
        foreach ($rows as $r) {
            $cid = $r['category_id'] !== null ? (int) $r['category_id'] : null;
            if (in_array(self::kindOf($cid), self::NON_OPERATING, true)) {
                continue;
            }
            $label = $cid !== null ? (self::cats()[$cid]['name'] ?? 'Belum Dikategorikan') : 'Belum Dikategorikan';
            $tot[$label] = bcadd($tot[$label] ?? '0.00', bcsub((string) $r['c'], (string) $r['d'], 2), 2);
        }
        $list = [];
        foreach ($tot as $label => $v) {
            if (bccomp($v, '0', 2) > 0) {
                $list[] = ['label' => $label, 'total' => $v];
            }
        }
        usort($list, static fn($a, $b) => bccomp($b['total'], $a['total'], 2));
        return self::topN($list, $limit);
    }

    private static function topN(array $rows, int $limit): array
    {
        $top = array_slice($rows, 0, $limit);
        $rest = '0.00';
        foreach (array_slice($rows, $limit) as $r) {
            $rest = bcadd($rest, (string) $r['total'], 2);
        }
        $out = [];
        foreach ($top as $r) {
            $out[] = ['label' => $r['label'], 'total' => bcadd((string) $r['total'], '0', 2)];
        }
        if (bccomp($rest, '0', 2) > 0) {
            $out[] = ['label' => 'Lainnya', 'total' => $rest];
        }
        return $out;
    }

    /** Ringkasan + deret bulanan + komposisi dari SATU kueri agregat (dipakai dashboard). */
    public static function dashboardBundle(array $f, int $topN = 7): array
    {
        $ids = self::assetIds(!empty($f['account']) ? (int) $f['account'] : null);
        $rows = $ids ? self::flowRows($f, $ids) : [];
        return [
            'summary' => self::summary($f, $rows),
            'monthly' => self::monthly($f, $rows),
            'breakdown' => self::expenseBreakdown($f, 'category', $topN, $rows),
        ];
    }
    /**
     * Daftar transaksi dengan filter + saldo berjalan (bila SATU akun dipilih; atau semua akun bila $runningAllAccounts).
     * Strategi kinerja (tetap benar untuk ratusan ribu baris):
     *  1) ambil ID halaman saja (urut + LIMIT) dari indeks;
     *  2) ambil detail baris + nama kategori/proyek/akun untuk ID tersebut;
     *  3) saldo berjalan = saldo sebelum seq terkecil halaman (satu agregat) + jumlah kumulatif window function
     *     hanya pada rentang urutan-catat halaman, urut (seq, id).
     * @return array{rows:array,total:int,sum_debit:string,sum_credit:string}
     */
    public static function listTransactions(array $f, int $page = 1, int $perPage = 50, string $sort = 'date_desc', bool $includeVoid = false, bool $withTotals = true, bool $runningAllAccounts = false, bool $perLine = false): array
    {
        [$where, $p] = self::where($f, $includeVoid, $perLine);
        $order = match ($sort) {
            'date_asc' => 't.tx_date ASC, t.seq ASC, t.id ASC',
            'amount_desc' => 'GREATEST(ABS(t.debit), ABS(t.credit)) DESC, t.id DESC',
            'amount_asc' => 'GREATEST(ABS(t.debit), ABS(t.credit)) ASC, t.id ASC',
            default => 't.tx_date DESC, t.seq DESC, t.id DESC',
        };
        if ($perLine) {
            // baris uraian satu transaksi selalu berurutan (uraian 1, 2, 3 ...), apa pun urutan dasarnya
            $order .= ', t.line_no ASC';
        }
        // gabungan tabel hanya bila filter membutuhkannya
        $from = $perLine ? self::LINE_ROWS : 'transactions';
        $base = 'FROM ' . $from . ' t' . (!empty($f['kind']) ? ' LEFT JOIN categories c ON c.id = t.category_id' : '');

        $tot = ['n' => 0, 'sd' => '0', 'sc' => '0'];
        if ($withTotals) {
            $tot = Db::one("SELECT COUNT(*) n, COALESCE(SUM(t.debit),0) sd, COALESCE(SUM(t.credit),0) sc $base $where", $p);
        }

        $offset = max(0, ($page - 1) * $perPage);
        $pageRows = Db::all("SELECT t.id" . ($perLine ? ', t.line_no' : '') . " $base $where ORDER BY $order LIMIT $perPage OFFSET $offset", $p);
        $ids = array_values(array_unique(array_map('intval', array_column($pageRows, 'id'))));
        $rows = [];
        if ($ids) {
            $in = implode(',', $ids);
            $byId = [];
            foreach (Db::all(
                "SELECT t.*, a.name AS account_name, a.code AS account_code, a.balance_mode, c.name AS category_name, c.kind AS category_kind, p.name AS project_name
                 FROM transactions t JOIN accounts a ON a.id = t.account_id LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN projects p ON p.id = t.project_id
                 WHERE t.id IN ($in)"
            ) as $r) {
                $r['running'] = null;
                $byId[(int) $r['id']] = $r;
            }
            // kategori/proyek ada di tiap baris uraian: tampilkan nama-nama dari baris (unik, urut baris, dipisah koma)
            $names = [];
            foreach (Db::all(
                "SELECT i.transaction_id AS tid, c.name AS cn, p.name AS pn
                 FROM transaction_items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN projects p ON p.id = i.project_id
                 WHERE i.transaction_id IN ($in) ORDER BY i.transaction_id, i.line_no"
            ) as $r) {
                $t = (int) $r['tid'];
                $names[$t] ??= ['c' => [], 'p' => []];
                if ($r['cn'] !== null && !in_array($r['cn'], $names[$t]['c'], true)) { $names[$t]['c'][] = $r['cn']; }
                if ($r['pn'] !== null && !in_array($r['pn'], $names[$t]['p'], true)) { $names[$t]['p'][] = $r['pn']; }
            }
            foreach ($names as $t => $n) {
                if (isset($byId[$t])) {
                    $byId[$t]['category_name'] = $n['c'] ? implode(', ', $n['c']) : null;
                    $byId[$t]['project_name'] = $n['p'] ? implode(', ', $n['p']) : null;
                    $byId[$t]['category_kind'] = count($n['c']) === 1 ? $byId[$t]['category_kind'] : null;
                }
            }
            if ($perLine) {
                // satu baris daftar per uraian: salin data transaksi, timpa dengan uraian/kategori/proyek/jumlah baris itu
                $items = [];
                foreach (Db::all(
                    "SELECT i.transaction_id AS tid, i.line_no, i.description, i.amount, c.name AS cn, c.kind AS ck, p.name AS pn
                     FROM transaction_items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN projects p ON p.id = i.project_id
                     WHERE i.transaction_id IN ($in)"
                ) as $r) {
                    $items[(int) $r['tid']][(int) $r['line_no']] = $r;
                }
                foreach ($pageRows as $pr) {
                    $id = (int) $pr['id'];
                    $ln = (int) $pr['line_no'];
                    if (!isset($byId[$id])) {
                        continue;
                    }
                    $row = $byId[$id];
                    $row['line_no'] = $ln;
                    if ($ln > 0 && isset($items[$id][$ln])) {
                        $it = $items[$id][$ln];
                        $isIn = bccomp((string) $row['debit'], '0', 2) !== 0;
                        $row['tx_in'] = $isIn;
                        $row['description'] = $it['description'];
                        $row['category_name'] = $it['cn'];
                        $row['category_kind'] = $it['ck'];
                        $row['project_name'] = $it['pn'];
                        $row['debit'] = $isIn ? bcadd((string) $it['amount'], '0', 2) : '0.00';
                        $row['credit'] = $isIn ? '0.00' : bcadd((string) $it['amount'], '0', 2);
                    }
                    $rows[] = $row;
                }
            } else {
                foreach ($ids as $id) {
                    if (isset($byId[$id])) {
                        $rows[] = $byId[$id];
                    }
                }
            }
            if (!empty($f['account'])) {
                self::fillRunning($rows, (int) $f['account']);
            } elseif ($runningAllAccounts) {
                // tanpa filter rekening: saldo tiap baris = saldo rekeningnya sendiri (tidak bergantung pada filter lain)
                $byAcc = [];
                foreach ($rows as $i => $r) {
                    $byAcc[(int) $r['account_id']][] = $i;
                }
                foreach ($byAcc as $aid => $idx) {
                    $subset = [];
                    foreach ($idx as $i) {
                        $subset[] = &$rows[$i];
                    }
                    self::fillRunning($subset, $aid);
                    unset($subset);
                }
            }
        }
        return ['rows' => $rows, 'total' => (int) $tot['n'], 'sum_debit' => bcadd((string) $tot['sd'], '0', 2), 'sum_credit' => bcadd((string) $tot['sc'], '0', 2)];
    }

    /**
     * Isi kolom 'running' (saldo setelah baris tersebut) untuk baris-baris satu akun.
     * Urutan saldo berjalan = URUTAN CATAT (seq, id) — sama dengan urutan baris pada buku Excel — bukan urutan tanggal,
     * sehingga saldo tiap baris identik dengan Excel meskipun beberapa bukti dicatat tidak berurutan tanggal.
     * Void/edit transaksi historis tidak mengubah urutan baris lain; saldo akhir hari/bulan (berdasarkan tanggal) tetap benar.
     */
    private static function fillRunning(array &$rows, int $accountId): void
    {
        $acc = Db::one('SELECT balance_mode, opening_balance FROM accounts WHERE id = ?', [$accountId]);
        $live = array_filter($rows, static fn($r) => $r['voided_at'] === null);
        if (!$acc || !$live) {
            return;
        }
        $seqs = array_map('intval', array_column($live, 'seq'));
        $min = min($seqs);
        $max = max($seqs);
        $sign = $acc['balance_mode'] === 'asset' ? 'debit - credit' : 'credit - debit';
        $prefix = bcadd((string) $acc['opening_balance'], (string) Db::val(
            "SELECT COALESCE(SUM($sign), 0) FROM transactions WHERE account_id = ? AND voided_at IS NULL AND seq < ?",
            [$accountId, $min]
        ), 2);
        $cum = [];
        foreach (Db::all(
            "SELECT id, SUM($sign) OVER (ORDER BY seq, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cum
             FROM transactions WHERE account_id = ? AND voided_at IS NULL AND seq >= ? AND seq <= ?",
            [$accountId, $min, $max]
        ) as $r) {
            $cum[(int) $r['id']] = (string) $r['cum'];
        }
        // daftar per uraian: saldo setelah uraian ke-n = saldo setelah transaksi dikurangi pengaruh uraian sesudahnya
        $after = [];
        $lineIds = array_map('intval', array_unique(array_column(array_filter($live, static fn($r) => (int) ($r['line_no'] ?? 0) > 0), 'id')));
        if ($lineIds) {
            $acc1 = $acc['balance_mode'] === 'asset' ? 1 : -1;
            foreach (Db::all('SELECT i.transaction_id AS tid, i.line_no, i.amount, (t.debit <> 0) AS is_in FROM transaction_items i JOIN transactions t ON t.id = i.transaction_id WHERE i.transaction_id IN (' . implode(',', $lineIds) . ')') as $r) {
                $eff = bcmul((string) $r['amount'], (string) ($acc1 * ((int) $r['is_in'] === 1 ? 1 : -1)), 2);
                $after[(int) $r['tid']][(int) $r['line_no']] = $eff;
            }
        }
        foreach ($rows as &$r) {
            if ($r['voided_at'] === null && isset($cum[(int) $r['id']])) {
                $run = bcadd($prefix, $cum[(int) $r['id']], 2);
                $ln = (int) ($r['line_no'] ?? 0);
                if ($ln > 0 && isset($after[(int) $r['id']])) {
                    foreach ($after[(int) $r['id']] as $k => $eff) {
                        if ($k > $ln) {
                            $run = bcsub($run, $eff, 2);
                        }
                    }
                }
                $r['running'] = $run;
            }
        }
    }

    /** Bangun klausa WHERE dari filter (semua parameter terikat). Alias: t = transactions, c = categories. */
    public static function where(array $f, bool $includeVoid = false, bool $perLine = false): array
    {
        $w = [];
        $p = [];
        if (!$includeVoid) {
            $w[] = 't.voided_at IS NULL';
        }
        if (!empty($f['account'])) { $w[] = 't.account_id = ?'; $p[] = (int) $f['account']; }
        if (!empty($f['from'])) { $w[] = 't.tx_date >= ?'; $p[] = $f['from']; }
        if (!empty($f['to'])) { $w[] = 't.tx_date <= ?'; $p[] = $f['to']; }
        // kategori/proyek ada di tiap baris uraian: transaksi cocok bila kolomnya (data lama / nilai bersama) atau salah satu barisnya cocok
        if ($perLine) {
            // mode per baris uraian: t = tabel turunan LINE_ROWS; kategori/proyek/jumlah sudah milik baris itu sendiri
            if (!empty($f['category'])) { $w[] = 't.category_id = ?'; $p[] = (int) $f['category']; }
            if (!empty($f['kind'])) { $w[] = 'c.kind = ?'; $p[] = $f['kind']; }
            if (!empty($f['project'])) { $w[] = 't.project_id = ?'; $p[] = (int) $f['project']; }
        } elseif (!empty($f['category'])) {
            $w[] = '(t.category_id = ? OR EXISTS (SELECT 1 FROM transaction_items ti WHERE ti.transaction_id = t.id AND ti.category_id = ?))';
            array_push($p, (int) $f['category'], (int) $f['category']);
        }
        if (!$perLine && !empty($f['kind'])) {
            $w[] = '(c.kind = ? OR EXISTS (SELECT 1 FROM transaction_items ti JOIN categories tc ON tc.id = ti.category_id WHERE ti.transaction_id = t.id AND tc.kind = ?))';
            array_push($p, $f['kind'], $f['kind']);
        }
        if (!$perLine && !empty($f['project'])) {
            $w[] = '(t.project_id = ? OR EXISTS (SELECT 1 FROM transaction_items ti WHERE ti.transaction_id = t.id AND ti.project_id = ?))';
            array_push($p, (int) $f['project'], (int) $f['project']);
        }
        $dc = $perLine ? ['t.tx_debit', 't.tx_credit'] : ['t.debit', 't.credit'];
        if (($f['dir'] ?? '') === 'in') { $w[] = $dc[0] . ' <> 0'; }
        if (($f['dir'] ?? '') === 'out') { $w[] = $dc[1] . ' <> 0'; }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $w[] = '(' . ($perLine ? 't.line_desc' : 't.description') . ' LIKE ? OR t.voucher_code LIKE ? OR t.counterparty LIKE ? OR t.ref_no LIKE ? OR t.category_raw LIKE ? OR t.note LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like);
        }
        if (!empty($f['party'])) { $w[] = 't.counterparty = ?'; $p[] = (string) $f['party']; }
        if (!empty($f['flagged'])) { $w[] = 't.flag IS NOT NULL'; }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Saldo akhir tiap bulan per akun (untuk rekonsiliasi & laporan). */
    public static function monthEndBalances(int $accountId): array
    {
        $a = Db::one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
        if (!$a) {
            return [];
        }
        $rows = Db::all(
            "SELECT DATE_FORMAT(t.tx_date,'%Y-%m') ym, SUM(" . self::SIGN_SQL . ") d, COALESCE(SUM(t.debit),0) sd, COALESCE(SUM(t.credit),0) sc, COUNT(*) n
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.account_id = ? AND t.voided_at IS NULL GROUP BY ym ORDER BY ym",
            [$accountId]
        );
        $bal = (string) $a['opening_balance'];
        $out = [];
        foreach ($rows as $r) {
            $bal = bcadd($bal, (string) $r['d'], 2);
            $out[] = ['ym' => $r['ym'], 'debit' => bcadd((string) $r['sd'], '0', 2), 'credit' => bcadd((string) $r['sc'], '0', 2), 'closing' => $bal, 'count' => (int) $r['n']];
        }
        return $out;
    }
}
