<?php
declare(strict_types=1);

namespace App\Export;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Filters;
use App\Services\LedgerService;
use App\Services\ReportService;

/**
 * Menyusun dokumen cetak dari data aplikasi. Tata letak (judul, warna, urutan & lebar kolom, bulan berwarna kuning,
 * baris total biru muda, blok "DANA"/"SISA LIMIT") meniru buku Excel sumber (CASH FLOW HME).
 */
final class ExportBuilder
{
    public const DOCS = [
        'dashboard' => 'Ringkasan Dashboard',
        'transaksi' => 'Mutasi Bank',
        'rekening' => 'Rekening & Limit',
        'piutang' => 'Piutang',
        'hutang' => 'Hutang',
        'proyeksi' => 'Proyeksi Cashflow',
        'laporan' => 'Laporan',
        'bukti' => 'Bukti Bank (BK/BM)',   // cetak detail transaksi terpilih; hanya PDF, dibangun oleh buildBukti()
    ];

    private const MAX_ROWS = 20000;
    private const HDR = 'FDEADA';     // header buku bank (aksen oranye muda)
    private const YELLOW = 'FFFF00';  // judul bulan
    private const CYAN = '66FFFF';    // total
    private const GREEN = '66FF33';   // saldo rekening
    private const ORANGE = 'FFC000';  // sisa limit
    private const PINK = 'FF99FF';    // judul laporan & penerimaan proyeksi

    /**
     * Bukti Bank Keluar/Masuk untuk transaksi terpilih (ids=1,2,3): satu bukti per halaman, urut tanggal.
     * Transaksi dibatalkan dilewati. @return array{0:Document,1:string} [dokumen, isi PDF]
     */
    public static function buildBukti(Request $req, array $user): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $req->str('ids'))), static fn(int $v) => $v > 0)));
        if (!$ids) {
            throw new HttpException('Pilih minimal satu transaksi untuk dicetak.', 422);
        }
        if (count($ids) > 200) {
            throw new HttpException('Maksimal 200 bukti sekali cetak.', 422);
        }
        $rows = Db::all('SELECT t.*, u.full_name AS created_name FROM transactions t LEFT JOIN users u ON u.id = t.created_by WHERE t.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND t.voided_at IS NULL ORDER BY t.tx_date, t.seq, t.id', $ids);
        if (!$rows) {
            throw new HttpException('Transaksi yang dipilih tidak ditemukan atau sudah dibatalkan.', 422);
        }
        $vouchers = [];
        foreach ($rows as $t) {
            $lines = \App\Services\TransactionService::lines($t);
            $net = bcsub((string) $t['debit'], (string) $t['credit'], 2);
            $total = '0.00';
            foreach ($lines as $l) {
                $total = bcadd($total, $l['amount'], 2);
            }
            $vouchers[] = [
                'kind' => bccomp($net, '0', 2) > 0 ? 'BM' : 'BK',
                'number' => (string) ($t['voucher_code'] ?? ''),
                'date' => self::longDate($t['tx_date']),
                'party' => (string) ($t['counterparty'] ?? ''),
                'by' => (string) ($t['created_name'] ?? ''),   // "Dibuat oleh" = pembuat transaksi (seperti Sistem Stok), bukan pencetak
                'lines' => $lines,
                'total' => $total,
            ];
        }
        $d = new Document(self::DOCS['bukti'], 'cashflow-bukti-bank', setting('company_name', 'PT Hexa Multi Energi'), (string) ($user['full_name'] ?? ''));
        return [$d, VoucherPdf::build($vouchers, $d, $req->str('paper'))];   // paper: legal (default) | folio | a4
    }

    public static function build(string $doc, Request $req, array $user): Document
    {
        if (!isset(self::DOCS[$doc]) || $doc === 'bukti') {
            throw new HttpException('Jenis dokumen tidak dikenal.', 404);
        }
        $d = new Document(self::DOCS[$doc], 'cashflow-' . ($doc === 'transaksi' ? 'mutasi-bank' : $doc), setting('company_name', 'PT Hexa Multi Energi'), (string) ($user['full_name'] ?? ''));
        $isAdmin = ($user['role'] ?? '') === 'super_admin';
        match ($doc) {
            'transaksi' => self::transactions($d, $req, $isAdmin),
            'laporan' => self::report($d, $req),
            'rekening' => self::accounts($d),
            'piutang' => self::invoices($d, $req),
            'hutang' => self::debts($d, $req),
            'proyeksi' => self::forecast($d, $req),
            default => self::dashboard($d, $req),
        };
        if (!$d->sheets) {
            $s = $d->add(new Sheet('Tidak ada data'));
            $s->widths([60])->set(1, 1, 'Tidak ada data yang cocok dengan filter.', ['italic' => true]);
        }
        return $d;
    }

    /* ================= util ================= */
    private static function longDate(?string $ymd): string
    {
        $ts = $ymd ? strtotime($ymd) : false;
        return $ts ? (int) date('j', $ts) . ' ' . month_name((int) date('n', $ts)) . ' ' . date('Y', $ts) : '-';
    }

    private static function period(array $f): string
    {
        return !empty($f['from']) || !empty($f['to'])
            ? self::longDate($f['from'] ?? null) . ' s/d ' . self::longDate($f['to'] ?? null)
            : 'seluruh data';
    }

    private static function monthLabel(string $ym): string
    {
        [$y, $m] = explode('-', $ym);
        return month_name((int) $m) . ' ' . $y;
    }

    private static function hdr(array $o = []): array
    {
        return $o + ['font' => 'Comic Sans MS', 'size' => 12, 'bold' => true, 'fill' => self::HDR, 'border' => 'lrtb', 'h' => 'center', 'wrap' => true];
    }

    /** Judul dokumen: baris perusahaan, judul berwarna (gaya CASH FLOW), baris keterangan. Mengembalikan baris berikutnya. */
    private static function banner(Sheet $s, Document $doc, int $lastCol, string $title, string $sub, int $r = 1, int $firstCol = 1): int
    {
        $s->set($r, $firstCol, $doc->company, ['size' => 12, 'bold' => true])->merge($r, $firstCol, $r, $lastCol)->height($r, 18);
        $s->set($r + 1, $firstCol, $title, ['size' => 18, 'bold' => true, 'fill' => self::PINK, 'h' => 'left'])->paint($r + 1, $firstCol, $lastCol, ['fill' => self::PINK])->merge($r + 1, $firstCol, $r + 1, $lastCol)->height($r + 1, 24);
        $s->set($r + 2, $firstCol, $sub, ['size' => 12, 'bold' => true, 'italic' => true, 'h' => 'left'])->merge($r + 2, $firstCol, $r + 2, $lastCol)->height($r + 2, 18);
        return $r + 3;
    }

    private static function fetchRows(array $f): array
    {
        $rows = [];
        $page = 1;
        do {
            $r = LedgerService::listTransactions($f, $page, 1000, 'date_asc', false, $page === 1);
            foreach ($r['rows'] as $t) {
                $rows[] = $t;
            }
            if (count($rows) > self::MAX_ROWS) {
                throw new HttpException('Data terlalu banyak untuk dicetak sekaligus (lebih dari ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' baris). Persempit filter tanggal atau rekening.', 422);
            }
            $page++;
        } while (count($r['rows']) === 1000);
        return $rows;
    }

    /* ================= BUKU KAS (transaksi) ================= */
    private static function transactions(Document $doc, Request $req, bool $isAdmin): void
    {
        set_time_limit(120);
        $f = Filters::transactions($req);
        $accounts = Db::all('SELECT * FROM accounts ORDER BY sort_order, id');
        foreach ($accounts as $a) {
            if (!empty($f['account']) && (int) $a['id'] !== (int) $f['account']) {
                continue;
            }
            if (empty($f['account']) && !$a['is_active']) {
                continue;
            }
            $rows = self::fetchRows(array_merge($f, ['account' => (int) $a['id']]));
            if (!$rows) {
                continue;
            }
            self::ledgerSheet($doc, $a, $rows, $f, $isAdmin);
        }
    }

    private static function ledgerSheet(Document $doc, array $a, array $rows, array $f, bool $isAdmin): void
    {
        $s = $doc->add(new Sheet(str_replace('-', ' ', (string) $a['code'])));
        $s->paper = 'legal';
        $s->widths([2.71, 12.57, 11.71, 40.29, 26.0, 44.71, 44.71, 21.71, 21.71, 21.71, 21.86, 24.29]);
        $s->set(1, 2, $doc->company . ' — Mutasi Bank', ['size' => 14, 'bold' => true])->merge(1, 2, 1, 12)->height(1, 21);
        $extra = [];
        if (!empty($f['q'])) { $extra[] = 'pencarian "' . $f['q'] . '"'; }
        if (!empty($f['dir'])) { $extra[] = $f['dir'] === 'in' ? 'hanya Mutasi Masuk' : 'hanya Mutasi Keluar'; }
        $s->set(2, 2, $a['name'] . ' · Periode: ' . self::period($f) . ($extra ? ' · ' . implode(', ', $extra) : ''), ['size' => 10, 'italic' => true])->merge(2, 2, 2, 12)->height(2, 15);

        foreach (['TGL', 'KODE', 'Dari Rekening & Rekening Tujuan', 'Project', 'Kategori Biaya', 'Keterangan', 'Catatan', 'No. Invoice & No. PO', 'DEBIT', 'KREDIT', 'SALDO AKHIR'] as $i => $h) {
            $s->set(3, 2 + $i, $h, self::hdr());
        }
        $s->height(3, 26.25);
        $s->freezeRow = 3;
        $s->freezeCol = 3;
        $s->repeatRows = [3, 3];

        $liab = $a['balance_mode'] === 'liability';
        $sumD = '0.00';
        $sumC = '0.00';
        $r = 4;
        $month = null;
        $lastDataRow = 3;
        $lastRun = null;
        $base = ['border' => 'lrtb'];
        foreach ($rows as $t) {
            $ym = substr((string) $t['tx_date'], 0, 7);
            if ($ym !== $month) {
                $month = $ym;
                $signed = $liab ? bcsub((string) $t['credit'], (string) $t['debit'], 2) : bcsub((string) $t['debit'], (string) $t['credit'], 2);
                $open = $t['running'] !== null ? bcsub((string) $t['running'], $signed, 2) : null;
                $ts = strtotime($t['tx_date']);
                $title = 'Bulan ' . month_name((int) date('n', $ts)) . ' ' . date('Y', $ts);
                $ty = ['font' => 'Comic Sans MS', 'size' => 14, 'bold' => true, 'fill' => self::YELLOW, 'border' => 'lrtb', 'h' => 'left'];
                $s->set($r, 4, $title, $ty)->set($r, 5, null, $ty);
                $s->paint($r, 2, 12, $base);
                $s->num($r, 12, $open, $base + ['fmt' => 'bal2']);
                $s->height($r, 22.5)->keep($r);
                $r++;
            }
            $party = (string) ($t['counterparty'] ?? '');
            if ($isAdmin && !empty($t['counterparty_detail'])) {
                $party .= ($party !== '' ? ' - ' : '') . $t['counterparty_detail'];
            }
            $cat = trim((string) ($t['category_raw'] ?? '')) !== '' ? $t['category_raw'] : (string) ($t['category_name'] ?? '');
            if (trim((string) $cat) === '' && !empty($t['project_name'])) {
                $cat = $t['project_name'];
            }
            $debit = bccomp((string) $t['debit'], '0', 2) !== 0 ? (string) $t['debit'] : null;
            $credit = bccomp((string) $t['credit'], '0', 2) !== 0 ? (string) $t['credit'] : null;
            $s->date($r, 2, $t['tx_date'], $base + ['h' => 'center']);
            $s->set($r, 3, $t['voucher_code'] ?? '', $base + ['h' => 'left']);
            $s->set($r, 4, $party, $base + ['wrap' => true, 'h' => 'left']);
            $s->set($r, 5, $t['project_name'] ?? '', $base + ['wrap' => true, 'h' => 'left']);
            $s->set($r, 6, $cat, $base + ['wrap' => true, 'h' => 'left']);
            $s->set($r, 7, $t['description'] ?? '', $base + ['wrap' => true, 'h' => 'left']);
            $s->set($r, 8, $t['note'] ?? '', $base + ['h' => 'left']); // seperti Excel: tidak dibungkus (teks panjang terpotong di batas sel)
            $s->set($r, 9, $t['ref_no'] ?? '', $base + ['wrap' => true, 'h' => 'center']);
            $s->num($r, 10, $debit, $base + ['fmt' => 'acc2']);
            $s->num($r, 11, $credit, $base + ['fmt' => 'acc2']);
            $s->num($r, 12, $t['running'], $base + ['fmt' => 'bal2']);
            if ($debit !== null) { $sumD = bcadd($sumD, $debit, 2); }
            if ($credit !== null) { $sumC = bcadd($sumC, $credit, 2); }
            if ($t['running'] !== null) { $lastRun = (string) $t['running']; }
            $lastDataRow = $r;
            $r++;
        }
        // total
        $r++;
        $tot = ['font' => 'Calibri', 'size' => 12, 'bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2'];
        $s->set($r, 7, $liab ? 'TOTAL (debit = pembayaran, kredit = penarikan)' : 'TOTAL', ['size' => 12, 'bold' => true, 'h' => 'right'])->merge($r, 7, $r, 9);
        $s->num($r, 10, $sumD, $tot, 'SUM(J4:J' . $lastDataRow . ')');
        $s->num($r, 11, $sumC, $tot, 'SUM(K4:K' . $lastDataRow . ')');
        $s->num($r, 12, $lastRun, ['size' => 14, 'bold' => true, 'border' => 'lrtb', 'fmt' => 'bal2'], $lastRun !== null ? 'L' . $lastDataRow : null);
        $s->height($r, 21);
        $s->set($r + 1, 12, $liab ? 'pemakaian akhir' : 'saldo akhir', ['size' => 9, 'italic' => true, 'h' => 'right']);
    }

    /* ================= LAPORAN ================= */
    private static function report(Document $doc, Request $req): void
    {
        [$type, $f] = ReportService::params($req);
        $rep = ReportService::build($type, $f);
        $doc->title = $rep['title'];
        $doc->slug = 'laporan-' . $type;
        $cols = array_values($rep['columns']);
        $keys = array_keys($rep['columns']);
        $n = count($cols);
        $s = $doc->add(new Sheet($rep['title']));
        $s->paper = 'legal';
        $w = [2.71];
        foreach ($cols as $i => [$label, $kind]) {
            $w[] = $i === 0 ? 36 : ($kind === 'money' ? 24 : ($kind === 'int' ? 12 : 18));
        }
        $s->widths($w);
        $accName = '';
        if (!empty($f['account'])) {
            $accName = (string) Db::val('SELECT name FROM accounts WHERE id = ?', [(int) $f['account']]);
        }
        $r = self::banner($s, $doc, $n + 1, strtoupper($rep['title']) . ' ' . strtoupper($doc->company), 'PERIODE: ' . (!empty($f['from']) ? strtoupper(self::longDate($f['from']) . ' SD ' . self::longDate($f['to'])) : 'SELURUH DATA') . ($accName !== '' ? ' · REKENING: ' . strtoupper($accName) : ''), 1, 2);
        $r++;
        $hr = $r;
        foreach ($cols as $i => [$label, $kind]) {
            $s->set($r, 2 + $i, $label, self::hdr(['font' => 'Calibri']));
        }
        $s->height($r, 24);
        $s->repeatRows = [$hr, $hr];
        $s->freezeRow = $hr;
        $r++;
        $first = $r;
        $base = ['border' => 'lrtb'];
        foreach ($rep['rows'] as $row) {
            foreach ($keys as $i => $k) {
                $kind = $cols[$i][1];
                $v = $row[$k] ?? null;
                if ($kind === 'money') {
                    $s->num($r, 2 + $i, $v === null || $v === '' ? null : bcadd((string) $v, '0', 2), $base + ['fmt' => 'acc2']);
                } elseif ($kind === 'int') {
                    $s->num($r, 2 + $i, $v === null ? null : (string) (int) $v, $base + ['fmt' => 'int', 'h' => 'center']);
                } elseif ($kind === 'date') {
                    $s->date($r, 2 + $i, (string) $v, $base + ['h' => 'center']);
                } else {
                    $s->set($r, 2 + $i, (string) ($v ?? ''), $base + ['wrap' => true]);
                }
            }
            $r++;
        }
        if (!empty($rep['totals']) && $rep['rows']) {
            $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb'];
            foreach ($keys as $i => $k) {
                $kind = $cols[$i][1];
                $col = self::colLetter(2 + $i);
                if ($i === 0) {
                    $s->set($r, 2, 'TOTAL', $tot);
                } elseif (isset($rep['totals'][$k]) && in_array($kind, ['money', 'int'], true)) {
                    $isSum = $k !== 'closing' && $first < $r;
                    $s->num($r, 2 + $i, $kind === 'int' ? (string) (int) $rep['totals'][$k] : bcadd((string) $rep['totals'][$k], '0', 2), $tot + ['fmt' => $kind === 'int' ? 'int' : 'acc2', 'h' => $kind === 'int' ? 'center' : null], $isSum ? 'SUM(' . $col . $first . ':' . $col . ($r - 1) . ')' : null);
                } else {
                    $s->set($r, 2 + $i, null, $tot);
                }
            }
            $s->height($r, 21);
            $r++;
        }
        if (!empty($rep['note'])) {
            $r++;
            $s->set($r, 2, 'Catatan: ' . $rep['note'], ['size' => 10, 'italic' => true, 'wrap' => true, 'v' => 'top'])->merge($r, 2, $r, $n + 1)->height($r, 30);
        }
    }

    private static function colLetter(int $c): string
    {
        $s = '';
        while ($c > 0) {
            $m = ($c - 1) % 26;
            $s = chr(65 + $m) . $s;
            $c = intdiv($c - 1, 26);
        }
        return $s;
    }

    /* ================= REKENING & LIMIT ================= */
    /** Blok "DANA / SISA LIMIT" seperti sheet CASH FLOW: saldo bank hijau, sisa limit oranye berhuruf merah. */
    private static function fundBlock(Sheet $s, int $r, int $c1, int $cLabelEnd, int $cVal, array $accounts): int
    {
        $s->set($r, $c1, 'DANA', ['size' => 20, 'bold' => true, 'fill' => self::GREEN, 'border' => 'lrtb'])->paint($r, $c1, $cVal, ['fill' => self::GREEN, 'border' => 'lrtb'])->merge($r, $c1, $r, $cVal)->height($r, 28);
        $r++;
        foreach ($accounts as $a) {
            if (!$a['is_active']) {
                continue;
            }
            $bank = $a['balance_mode'] === 'asset' && $a['kind'] === 'bank';
            if ($bank) {
                $label = 'REKENING ' . strtoupper($a['name']);
                $st = ['size' => 14, 'bold' => true, 'fill' => self::GREEN, 'border' => 'lrtb'];
                $val = $a['balance'];
            } elseif ($a['credit_limit'] !== null) {
                $label = 'SISA LIMIT ' . strtoupper($a['name']) . ' ( FULL LIMIT ' . money($a['credit_limit'], false) . ' )';
                $st = ['size' => 14, 'bold' => true, 'color' => 'FF0000', 'fill' => self::ORANGE, 'border' => 'lrtb'];
                $val = $a['remaining'];
            } else {
                continue;
            }
            $s->set($r, $c1, $label, $st)->paint($r, $c1, $cLabelEnd, $st)->merge($r, $c1, $r, $cLabelEnd);
            $s->num($r, $cVal, $val, $st + ['fmt' => 'bal2']);
            $s->height($r, 21);
            $r++;
        }
        return $r;
    }

    private static function accounts(Document $doc): void
    {
        $s = $doc->add(new Sheet('Rekening & Limit'));
        $s->paper = 'legal';
        $s->widths([2.71, 46, 26, 26, 28]);
        $acc = LedgerService::accounts(null, false);
        $last = LedgerService::lastTxDate();
        $r = self::banner($s, $doc, 5, 'REKENING & LIMIT FASILITAS', 'POSISI PER ' . strtoupper(self::longDate($last ?: date('Y-m-d'))), 1, 2);
        $r++;
        $r = self::fundBlock($s, $r, 2, 4, 5, $acc);
        $base = ['border' => 'lrtb'];
        foreach ($acc as $a) {
            $r += 1;
            $liab = $a['balance_mode'] === 'liability';
            $kind = $liab ? 'Pinjaman' : ($a['kind'] === 'facility' ? 'Fasilitas' : 'Bank');
            $ty = ['font' => 'Comic Sans MS', 'size' => 14, 'bold' => true, 'fill' => self::YELLOW, 'border' => 'lrtb'];
            $s->set($r, 2, strtoupper($a['name']) . ' (' . $kind . ')', $ty)->paint($r, 2, 5, $ty)->merge($r, 2, $r, 5)->height($r, 22.5)->keep($r);
            $r++;
            $s->set($r, 2, 'Saldo awal ' . self::longDate($a['opening_date']), $base + ['italic' => true]);
            $s->paint($r, 3, 4, $base)->num($r, 5, (string) $a['opening_balance'], $base + ['fmt' => 'bal2', 'italic' => true]);
            $r++;
            foreach (['BULAN', $liab ? 'PEMBAYARAN (DEBIT)' : 'MASUK (DEBIT)', $liab ? 'PENARIKAN (KREDIT)' : 'KELUAR (KREDIT)', $liab ? 'PEMAKAIAN AKHIR BULAN' : 'SALDO AKHIR BULAN'] as $i => $h) {
                $s->set($r, 2 + $i, $h, self::hdr(['font' => 'Calibri']));
            }
            $s->height($r, 24)->keep($r);
            $r++;
            $first = $r;
            $sd = '0.00';
            $sc = '0.00';
            $close = (string) $a['opening_balance'];
            foreach (LedgerService::monthEndBalances((int) $a['id']) as $m) {
                $s->set($r, 2, self::monthLabel($m['ym']), $base);
                $s->num($r, 3, $m['debit'], $base + ['fmt' => 'acc2']);
                $s->num($r, 4, $m['credit'], $base + ['fmt' => 'acc2']);
                $s->num($r, 5, $m['closing'], $base + ['fmt' => 'bal2']);
                $sd = bcadd($sd, $m['debit'], 2);
                $sc = bcadd($sc, $m['credit'], 2);
                $close = $m['closing'];
                $r++;
            }
            if ($r > $first) {
                $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2'];
                $s->set($r, 2, 'TOTAL', $tot);
                $s->num($r, 3, $sd, $tot, 'SUM(C' . $first . ':C' . ($r - 1) . ')');
                $s->num($r, 4, $sc, $tot, 'SUM(D' . $first . ':D' . ($r - 1) . ')');
                $s->num($r, 5, $close, $tot + ['fmt' => 'bal2'], 'E' . ($r - 1));
                $s->height($r, 21);
                $r++;
            }
        }
    }

    /* ================= PIUTANG PROYEK ================= */
    private static function invoices(Document $doc, Request $req): void
    {
        $q = mb_substr($req->str('q'), 0, 100);
        $status = in_array($req->str('status'), ['open', 'paid', 'unknown', 'late'], true) ? $req->str('status') : '';
        $kind = $req->str('kind') === 'lampu' ? 'lampu' : 'proyek';
        $w = ['kind = ?'];
        $p = [$kind];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(customer LIKE ? OR project_name LIKE ? OR invoice_no LIKE ? OR description LIKE ? OR note LIKE ? OR pay_ref LIKE ? OR items LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($status === 'open') { $w[] = 'paid_known = 1 AND amount - paid > 0.004'; }
        if ($status === 'paid') { $w[] = 'paid_known = 1 AND amount - paid <= 0.004'; }
        if ($status === 'unknown') { $w[] = 'paid_known = 0'; }
        if ($status === 'late') { $w[] = 'paid_known = 1 AND amount - paid > 0.004 AND due_date IS NOT NULL AND due_date < ?'; $p[] = date('Y-m-d'); }
        $rows = Db::all('SELECT * FROM invoices' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ($kind === 'lampu' ? ' ORDER BY (invoice_date IS NULL), invoice_date, id' : ' ORDER BY customer, (invoice_date IS NULL), invoice_date, id'), $p);   // Piutang Lampu: urut tanggal nota

        if ($kind === 'lampu') {
            self::lampuSheet($doc, $rows, $q, $status);
            return;
        }
        $s = $doc->add(new Sheet('Piutang Proyek'));
        $s->paper = 'legal';
        $s->widths([4.71, 27.43, 52.29, 14, 27.43, 19.57, 19.71, 18.14, 35, 16]);
        $s->set(1, 1, 'REKAP PIUTANG PROYEK ' . strtoupper($doc->company), ['size' => 14, 'bold' => true, 'h' => 'center'])->merge(1, 1, 1, 10)->height(1, 23.25);
        $note = ['Per ' . self::longDate(date('Y-m-d'))];
        if ($q !== '') { $note[] = 'pencarian "' . $q . '"'; }
        if ($status !== '') { $note[] = $status === 'open' ? 'masih ada sisa' : 'lunas'; }
        $s->set(2, 1, implode(' · ', $note), ['size' => 10, 'italic' => true, 'h' => 'center'])->merge(2, 1, 2, 10);
        $h = ['font' => 'Calibri', 'size' => 10, 'bold' => true, 'border' => 'lrtb', 'h' => 'center', 'fill' => self::HDR, 'wrap' => true];
        // header dua tingkat seperti sheet "Tagihan PLTS - Fee"
        $top = [1 => 'No', 2 => 'Customer', 3 => 'Nama Project / Uraian', 4 => 'TAGIHAN', 7 => 'Pembayaran', 8 => 'Kurang Bayar', 9 => 'Keterangan', 10 => 'Status'];
        foreach ($top as $c => $t) {
            $s->set(4, $c, $t, $h);
        }
        $s->paint(4, 5, 6, $h);
        $s->merge(4, 4, 4, 6);
        foreach ([4 => 'Tgl.', 5 => 'No. Invoice', 6 => 'Nominal'] as $c => $t) {
            $s->set(5, $c, $t, $h);
        }
        foreach ([1, 2, 3, 7, 8, 9, 10] as $c) {
            $s->paint(5, $c, $c, $h);
            $s->merge(4, $c, 5, $c);
        }
        $s->height(4, 15)->height(5, 15);
        $s->repeatRows = [4, 5];
        $s->freezeRow = 5;
        $r = 6;
        $first = $r;
        $sumA = '0.00';
        $sumP = '0.00';
        $sumR = '0.00';
        $base = ['border' => 'lrtb'];
        foreach ($rows as $i => $t) {
            $rem = bcsub((string) $t['amount'], (string) $t['paid'], 2);
            $paid = bccomp((string) $t['paid'], '0', 2) > 0;
            $stat = bccomp($rem, '0', 2) <= 0 ? 'Lunas' : ($paid ? 'Sebagian' : 'Belum dibayar');
            $s->set($r, 1, (string) ($i + 1), $base + ['h' => 'center']);
            $s->set($r, 2, $t['customer'], $base + ['wrap' => true]);
            $desc = trim(($t['project_name'] ?? '') . (($t['description'] ?? '') !== '' ? ' — ' . $t['description'] : ''), ' —');
            $s->set($r, 3, $desc, $base + ['wrap' => true]);
            $s->date($r, 4, $t['invoice_date'], $base + ['h' => 'center']);
            $s->set($r, 5, $t['invoice_no'] ?? '', $base + ['wrap' => true]);
            $s->num($r, 6, (string) $t['amount'], $base + ['fmt' => 'acc2']);
            $s->num($r, 7, (string) $t['paid'], $base + ['fmt' => 'acc2']);
            $s->num($r, 8, $rem, $base + ['fmt' => 'acc2']);
            $s->set($r, 9, $t['note'] ?? '', $base + ['wrap' => true]);
            $s->set($r, 10, $stat, $base + ['h' => 'center', 'bold' => $stat !== 'Lunas', 'color' => $stat === 'Lunas' ? '000000' : 'C00000']);
            $sumA = bcadd($sumA, (string) $t['amount'], 2);
            $sumP = bcadd($sumP, (string) $t['paid'], 2);
            $sumR = bcadd($sumR, $rem, 2);
            $r++;
        }
        if ($rows) {
            $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2', 'size' => 12];
            $s->set($r, 1, 'TOTAL', $tot + ['h' => 'center', 'fmt' => 'text'])->paint($r, 2, 5, $tot + ['fmt' => 'text'])->merge($r, 1, $r, 5);
            $s->num($r, 6, $sumA, $tot, 'SUM(F' . $first . ':F' . ($r - 1) . ')');
            $s->num($r, 7, $sumP, $tot, 'SUM(G' . $first . ':G' . ($r - 1) . ')');
            $s->num($r, 8, $sumR, $tot, 'SUM(H' . $first . ':H' . ($r - 1) . ')');
            $s->paint($r, 9, 10, $tot + ['fmt' => 'text']);
            $s->height($r, 21);
        }
    }

    /**
     * Rekap piutang penjualan lampu, layout A–P: No, Tgl., No. Surat Jalan, Customer, Uraian (Nama Barang | Qty | Harga Satuan),
     * Total Piutang (= Σ Qty × Harga), Jatuh Tempo (hari), Tgl. Jatuh Tempo, Tgl. Bayar, Kode Bukti, Dibayar, Sisa, Status, Keterangan.
     * Satu surat jalan dengan beberapa barang = beberapa baris; data surat jalan hanya di baris pertamanya.
     * Baris yang pembayarannya tak terbaca (paid_known = 0) tidak dihitung dalam total dibayar/sisa.
     */
    private static function lampuSheet(Document $doc, array $rows, string $q, string $status): void
    {
        $s = $doc->add(new Sheet('Piutang Lampu'));
        $s->paper = 'legal';
        $s->widths([4.71, 11, 15, 32, 26, 7, 14, 16, 12, 11, 11, 11, 16, 16, 15, 28]);
        $s->set(1, 1, 'REKAP PIUTANG PENJUALAN LAMPU ' . strtoupper($doc->company), ['size' => 14, 'bold' => true, 'h' => 'center'])->merge(1, 1, 1, 16)->height(1, 23.25);
        $note = ['Per ' . self::longDate(date('Y-m-d'))];
        if ($q !== '') { $note[] = 'pencarian "' . $q . '"'; }
        if ($status !== '') { $note[] = ['open' => 'masih ada sisa', 'paid' => 'lunas', 'unknown' => 'perlu konfirmasi', 'late' => 'lewat jatuh tempo'][$status] ?? ''; }
        $s->set(2, 1, implode(' · ', $note), ['size' => 10, 'italic' => true, 'h' => 'center'])->merge(2, 1, 2, 16);
        $h = ['font' => 'Calibri', 'size' => 10, 'bold' => true, 'border' => 'lrtb', 'h' => 'center', 'v' => 'center', 'fill' => self::HDR, 'wrap' => true];
        // header dua tingkat: "Uraian" membawahi Nama Barang / Qty / Harga Satuan
        $top = [1 => 'No', 2 => 'Tgl.', 3 => 'No. Surat Jalan', 4 => 'Customer', 5 => 'Uraian', 8 => 'Total Piutang', 9 => 'Jatuh Tempo (hari)', 10 => 'Tgl. Jatuh Tempo',
            11 => 'Tgl. Bayar', 12 => 'Kode Bukti', 13 => 'Dibayar', 14 => 'Sisa', 15 => 'Status', 16 => 'Keterangan'];
        foreach ($top as $c => $t) {
            $s->set(4, $c, $t, $h);
        }
        $s->paint(4, 6, 7, $h)->merge(4, 5, 4, 7);
        foreach ([5 => 'Nama Barang', 6 => 'Qty', 7 => 'Harga Satuan'] as $c => $t) {
            $s->set(5, $c, $t, $h);
        }
        foreach (array_keys($top) as $c) {
            if ($c !== 5) {
                $s->paint(5, $c, $c, $h);
                $s->merge(4, $c, 5, $c);
            }
        }
        $s->height(4, 15)->height(5, 15);
        $s->repeatRows = [4, 5];
        $s->freezeRow = 5;
        $r = 6;
        $first = $r;
        $sumA = $sumP = $sumR = '0.00';
        $unk = 0;
        $base = ['border' => 'lrtb', 'v' => 'top'];
        $today = date('Y-m-d');
        $payList = \App\Services\TxLinkService::invoicePayments(array_column($rows, 'id'));
        foreach ($rows as $i => $t) {
            $known = (int) $t['paid_known'] === 1;
            $rem = bcsub((string) $t['amount'], (string) $t['paid'], 2);
            $open = bccomp($rem, '0.004', 3) > 0;
            $stat = !$known ? 'Perlu konfirmasi' : (!$open ? 'Lunas' : (bccomp((string) $t['paid'], '0', 2) > 0 ? 'Sebagian' : 'Belum dibayar'));
            if ($known && $open && $t['due_date'] && $t['due_date'] < $today) { $stat .= ' · lewat tempo'; }
            $items = \App\Controllers\InvoiceController::itemsOf($t);
            $pays = self::lampuPays($t, $payList[(int) $t['id']] ?? []);
            $n = max(1, count($items), count($pays));
            $last = $r + $n - 1;

            $s->set($r, 1, (string) ($i + 1), $base + ['h' => 'center']);
            $s->date($r, 2, $t['invoice_date'], $base + ['h' => 'center']);
            $s->set($r, 3, $t['invoice_no'] ?? '', $base + ['wrap' => true]);
            $s->set($r, 4, $t['customer'], $base + ['wrap' => true]);
            if ($items) {
                foreach ($items as $k => $it) {
                    $whole = bccomp((string) $it['qty'], bcadd((string) $it['qty'], '0', 0), 2) === 0;
                    $s->set($r + $k, 5, $it['name'], $base + ['wrap' => true]);
                    $s->num($r + $k, 6, $it['qty'], $base + ['fmt' => $whole ? 'int' : 'acc2', 'h' => 'center']);
                    $s->num($r + $k, 7, $it['price'], $base + ['fmt' => 'acc2']);
                }
                $iLast = $r + count($items) - 1;
                $s->num($r, 8, (string) $t['amount'], $base + ['fmt' => 'acc2'], 'SUMPRODUCT(ROUND(F' . $r . ':F' . $iLast . '*G' . $r . ':G' . $iLast . ',2))');
            } else {
                $s->set($r, 5, (string) ($t['description'] ?? ''), $base + ['wrap' => true]);
                $s->paint($r, 6, 7, $base);
                $s->num($r, 8, (string) $t['amount'], $base + ['fmt' => 'acc2']);
            }
            if ($t['due_days'] !== null) { $s->num($r, 9, (string) $t['due_days'], $base + ['fmt' => 'int', 'h' => 'center']); } else { $s->set($r, 9, (string) ($t['due_note'] ?? ''), $base + ['h' => 'center']); }
            $s->date($r, 10, $t['due_date'], $base + ['h' => 'center']);
            // satu pembayaran = satu baris: tgl. bayar, kode bukti, dan nominalnya sendiri
            foreach ($pays as $k => $p) {
                $s->date($r + $k, 11, $p['date'], $base + ['h' => 'center']);
                $s->set($r + $k, 12, $p['code'], $base + ['h' => 'center', 'wrap' => true]);
                if ($known) {
                    $s->num($r + $k, 13, $p['amount'], $base + ['fmt' => 'acc2']);
                } else {
                    $s->set($r + $k, 13, $k === 0 ? '?' : '', $base + ['h' => 'center']);
                }
            }
            if (!$pays) {
                $s->paint($r, 11, 12, $base);
                $known ? $s->num($r, 13, (string) $t['paid'], $base + ['fmt' => 'acc2']) : $s->set($r, 13, '?', $base + ['h' => 'center']);
            }
            // sisa & status = keadaan setelah pembayaran terakhir, jadi ditulis di baris pembayaran terakhir
            $rp = $r + max(0, count($pays) - 1);
            if ($known) {
                $s->num($rp, 14, $open ? $rem : '0.00', $base + ['fmt' => 'acc2']);
                $sumP = bcadd($sumP, (string) $t['paid'], 2);
                $sumR = bcadd($sumR, $open ? $rem : '0.00', 2);
            } else {
                $s->set($rp, 14, '?', $base + ['h' => 'center']);
                $unk++;
            }
            $s->set($rp, 15, $stat, $base + ['h' => 'center', 'wrap' => true, 'bold' => $stat !== 'Lunas', 'color' => $stat === 'Lunas' ? '000000' : 'C00000']);
            $s->set($r, 16, $t['note'] ?? '', $base + ['wrap' => true]);
            // baris lanjutan (barang/pembayaran berikutnya): sel yang tak terisi tetap berbingkai
            for ($k = $r + 1; $k <= $last; $k++) {
                $s->paint($k, 1, 4, $base)->paint($k, 8, 10, $base)->paint($k, 14, 16, $base);
                if ($k - $r >= max(1, count($items))) { $s->paint($k, 5, 7, $base); }
                if ($k - $r >= count($pays)) { $s->paint($k, 11, 13, $base); }
            }
            for ($k = $r; $k < $last; $k++) { $s->keep($k); }
            $sumA = bcadd($sumA, (string) $t['amount'], 2);
            $r = $last + 1;
        }
        if ($rows) {
            // kolom tidak menyempit: lebar mengikuti isi terpanjang agar tanggal, nomor, dan kode tidak turun baris
            foreach ([2 => 14, 3 => 24, 4 => 46, 5 => 40, 6 => 10, 7 => 18, 8 => 18, 9 => 12, 10 => 14, 11 => 14, 12 => 28, 13 => 18, 14 => 18, 15 => 24, 16 => 40] as $c => $max) {
                $s->fit($c, $first, $r - 1, $max);
            }
            $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2', 'size' => 12];
            $s->set($r, 1, 'TOTAL', $tot + ['h' => 'center', 'fmt' => 'text'])->paint($r, 2, 7, $tot + ['fmt' => 'text'])->merge($r, 1, $r, 7);
            $s->num($r, 8, $sumA, $tot, 'SUM(H' . $first . ':H' . ($r - 1) . ')');
            $s->paint($r, 9, 12, $tot + ['fmt' => 'text']);
            $s->num($r, 13, $sumP, $tot, 'SUM(M' . $first . ':M' . ($r - 1) . ')');
            $s->num($r, 14, $sumR, $tot, 'SUM(N' . $first . ':N' . ($r - 1) . ')');
            $s->paint($r, 15, 16, $tot + ['fmt' => 'text']);
            $s->height($r, 21);
            if ($unk > 0) {
                $s->set($r + 2, 1, $unk . ' baris berstatus "Perlu konfirmasi" (pembayaran di Excel tidak terbaca, #REF!) tidak dihitung dalam total Dibayar dan Sisa.', ['size' => 10, 'italic' => true])->merge($r + 2, 1, $r + 2, 16);
            }
        }
    }

    /**
     * Rincian pembayaran satu piutang lampu, urut tanggal: penerimaan lewat menu Transaksi (invoice_payments) ditambah
     * sisa nilai "Dibayar" di luar itu (tautan langsung pay_tx_id / kode lama Excel / isian manual) dengan pay_ref & paid_date.
     * @param list<array{date:?string,code:string,amount:string}> $via
     * @return list<array{date:?string,code:string,amount:string}>
     */
    private static function lampuPays(array $t, array $via): array
    {
        $sumVia = '0.00';
        foreach ($via as $p) {
            $sumVia = bcadd($sumVia, $p['amount'], 2);
        }
        $other = bcsub((string) $t['paid'], $sumVia, 2);
        $ref = trim((string) ($t['pay_ref'] ?? ''));
        $refNew = $ref !== '' && !in_array($ref, array_column($via, 'code'), true);
        if (bccomp($other, '0.004', 3) > 0 || $refNew) {
            $via[] = ['date' => $t['paid_date'] ?: null, 'code' => $refNew ? $ref : '', 'amount' => bccomp($other, '0', 2) > 0 ? $other : '0.00'];
        }
        $idx = array_keys($via);
        usort($idx, static fn($a, $b) => [$via[$a]['date'] === null ? 0 : 1, (string) $via[$a]['date'], $a] <=> [$via[$b]['date'] === null ? 0 : 1, (string) $via[$b]['date'], $b]);
        return array_map(static fn($k) => $via[$k], $idx);
    }

    /* ================= HUTANG ================= */
    /**
     * Rekap satu jenis hutang (Usaha / Direksi / Lain-lain) sesuai tab & filter menu Hutang, layout A–N: No, Tgl., No. Ref, Supplier,
     * Keterangan, Rekening Sumber, Nominal, Jatuh Tempo, Tgl. Bayar, Kode Bukti, Dibayar, Sisa, Status, Catatan.
     * Hutang Direksi: F = Rekening Penerima, H = Lama Pinjaman ("N hari" sejak tanggal setoran sampai hari ini / pelunasan).
     * Satu pembayaran = satu baris (dicatat lewat menu Transaksi); sisa & status ditulis di baris pembayaran terakhir.
     */
    private static function debts(Document $doc, Request $req): void
    {
        $f = \App\Controllers\DebtController::filtersFrom($req);
        $rows = \App\Services\DebtService::listAll($f, self::MAX_ROWS);
        if (count($rows) > self::MAX_ROWS) {
            throw new HttpException('Data terlalu banyak untuk dicetak sekaligus. Persempit filter.', 422);
        }
        $cat = \App\Services\DebtService::CATEGORIES[$f['category']];
        $doc->title = $cat;
        $doc->slug = 'cashflow-' . str_replace(' ', '-', strtolower($cat));
        $who = ['usaha' => 'Supplier', 'direksi' => 'Direksi', 'lain' => 'Pihak'][$f['category']];
        $loan = \App\Services\DebtService::isLoan($f['category']);

        $s = $doc->add(new Sheet($cat));
        $s->paper = 'legal';
        $s->widths([4.71, 11, 14, 26, 32, 16, 16, 11, 11, 14, 16, 16, 14, 24]);
        $s->set(1, 1, 'REKAP ' . strtoupper($cat) . ' ' . strtoupper($doc->company), ['size' => 14, 'bold' => true, 'h' => 'center'])->merge(1, 1, 1, 14)->height(1, 23.25);
        $note = ['Per ' . self::longDate(\App\Services\ReminderService::today())];
        if ($f['q'] !== '') { $note[] = 'pencarian "' . $f['q'] . '"'; }
        if ($f['status'] !== '') {
            $note[] = ['open' => 'belum lunas', 'belum' => 'belum dibayar', 'sebagian' => 'dibayar sebagian', 'lunas' => 'lunas', 'terlambat' => 'terlambat', 'batal' => 'dibatalkan'][$f['status']];
        }
        if ($f['due_from'] !== '' || $f['due_to'] !== '') { $note[] = 'jatuh tempo ' . self::period(['from' => $f['due_from'], 'to' => $f['due_to']]); }
        if ($f['date_from'] !== '' || $f['date_to'] !== '') { $note[] = 'tanggal hutang ' . self::period(['from' => $f['date_from'], 'to' => $f['date_to']]); }
        $s->set(2, 1, implode(' · ', $note), ['size' => 10, 'italic' => true, 'h' => 'center'])->merge(2, 1, 2, 14);
        $h = ['font' => 'Calibri', 'size' => 10, 'bold' => true, 'border' => 'lrtb', 'h' => 'center', 'v' => 'center', 'fill' => self::HDR, 'wrap' => true];
        foreach (['No', 'Tgl.', 'No. Ref', $who, 'Keterangan', $loan ? 'Rekening Penerima' : 'Rekening Sumber', 'Nominal', $loan ? 'Lama Pinjaman' : 'Jatuh Tempo', 'Tgl. Bayar', 'Kode Bukti', 'Dibayar', 'Sisa', 'Status', 'Catatan'] as $i => $t) {
            $s->set(4, $i + 1, $t, $h);
        }
        $s->height(4, 30);
        $s->repeatRows = [4, 4];
        $s->freezeRow = 4;
        $r = 5;
        $first = $r;
        $sumA = $sumP = $sumR = '0.00';
        $base = ['border' => 'lrtb', 'v' => 'top'];
        $payList = self::debtPays(array_map(static fn($t) => (int) $t['id'], $rows));
        foreach ($rows as $i => $t) {
            $void = $t['voided_at'] !== null;
            $stat = $void ? 'Dibatalkan' : ['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum' => 'Belum dibayar'][$t['status']];
            if (!$void && !$loan && $t['due'] && $t['due']['status'] === 'late') { $stat .= ' · lewat tempo'; }
            $pays = $payList[(int) $t['id']] ?? [];
            $last = $r + max(1, count($pays)) - 1;

            $s->set($r, 1, (string) ($i + 1), $base + ['h' => 'center']);
            $s->date($r, 2, $t['debt_date'], $base + ['h' => 'center']);
            $s->set($r, 3, $t['ref_no'] ?? '', $base + ['wrap' => true]);
            $s->set($r, 4, $t['supplier'], $base + ['wrap' => true]);
            $s->set($r, 5, $t['description'] ?? '', $base + ['wrap' => true]);
            $s->set($r, 6, $t['source_account'] ?? '', $base + ['wrap' => true]);
            $s->num($r, 7, (string) $t['amount'], $base + ['fmt' => 'acc2']);
            if ($loan) {
                $s->set($r, 8, $t['loan_days'] !== null ? $t['loan_days'] . ' hari' : '', $base + ['h' => 'center']);
            } else {
                $s->date($r, 8, $t['due_date'], $base + ['h' => 'center']);
            }
            foreach ($pays as $k => $p) {
                $s->date($r + $k, 9, $p['date'], $base + ['h' => 'center']);
                $s->set($r + $k, 10, $p['code'], $base + ['h' => 'center', 'wrap' => true]);
                $s->num($r + $k, 11, $p['amount'], $base + ['fmt' => 'acc2']);
            }
            if (!$pays) {
                $s->paint($r, 9, 10, $base);
                $s->num($r, 11, $t['paid'], $base + ['fmt' => 'acc2']);
            }
            // sisa & status = keadaan setelah pembayaran terakhir
            $s->num($last, 12, $t['remaining'], $base + ['fmt' => 'acc2']);
            $s->set($last, 13, $stat, $base + ['h' => 'center', 'wrap' => true, 'bold' => $stat !== 'Lunas', 'color' => $stat === 'Lunas' ? '000000' : 'C00000']);
            $s->set($r, 14, $t['note'] ?? '', $base + ['wrap' => true]);
            for ($k = $r + 1; $k <= $last; $k++) {
                $s->paint($k, 1, 8, $base)->paint($k, 12, 14, $base);
            }
            for ($k = $r; $k < $last; $k++) { $s->keep($k); }
            $sumA = bcadd($sumA, (string) $t['amount'], 2);
            $sumP = bcadd($sumP, $t['paid'], 2);
            $sumR = bcadd($sumR, $t['remaining'], 2);
            $r = $last + 1;
        }
        if ($rows) {
            foreach ([2 => 14, 3 => 24, 4 => 40, 5 => 46, 6 => 24, 7 => 18, 8 => 14, 9 => 14, 10 => 28, 11 => 18, 12 => 18, 13 => 32, 14 => 40] as $c => $max) {
                $s->fit($c, $first, $r - 1, $max);
            }
            $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2', 'size' => 12];
            $s->set($r, 1, 'TOTAL', $tot + ['h' => 'center', 'fmt' => 'text'])->paint($r, 2, 6, $tot + ['fmt' => 'text'])->merge($r, 1, $r, 6);
            $s->num($r, 7, $sumA, $tot, 'SUM(G' . $first . ':G' . ($r - 1) . ')');
            $s->paint($r, 8, 10, $tot + ['fmt' => 'text']);
            $s->num($r, 11, $sumP, $tot, 'SUM(K' . $first . ':K' . ($r - 1) . ')');
            $s->num($r, 12, $sumR, $tot, 'SUM(L' . $first . ':L' . ($r - 1) . ')');
            $s->paint($r, 13, 14, $tot + ['fmt' => 'text']);
            $s->height($r, 21);
        }
    }

    /**
     * Pembayaran per hutang, urut tanggal: baris debt_payments yang dihitung dalam "Dibayar" (transaksinya tidak dibatalkan,
     * atau baris lama hasil impor tanpa transaksi). Kode bukti = kode voucher transaksi pembayarannya.
     * @return array<int, list<array{date:?string,code:string,amount:string}>>
     */
    private static function debtPays(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = Db::all(
                'SELECT p.debt_id, p.pay_date, p.amount, t.id AS tx_id, t.voucher_code FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id
                 WHERE p.debt_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND (p.transaction_id IS NULL OR t.voided_at IS NULL)
                 ORDER BY (p.pay_date IS NULL), p.pay_date, p.id',
                $chunk
            );
            foreach ($rows as $p) {
                $code = $p['tx_id'] !== null ? ((string) $p['voucher_code'] !== '' ? (string) $p['voucher_code'] : '#' . $p['tx_id']) : '';
                $out[(int) $p['debt_id']][] = ['date' => $p['pay_date'], 'code' => $code, 'amount' => (string) $p['amount']];
            }
        }
        return $out;
    }

    /* ================= PROYEKSI ================= */
    private static function forecast(Document $doc, Request $req): void
    {
        $bank = '0.00';
        $asOf = LedgerService::lastTxDate();
        $acc = LedgerService::accounts(null, true);
        foreach ($acc as $a) {
            if ($a['kind'] === 'bank' && $a['balance_mode'] === 'asset') {
                $bank = bcadd($bank, $a['balance'], 2);
            }
        }
        $items = \App\Services\TxLinkService::forecastItems();
        if ($req->str('riwayat') !== '1') {
            // item yang sisanya diakhiri tidak tampil di daftar/cetak (nilainya 0 di saldo); "Tampilkan riwayat" mencetaknya
            $items = array_values(array_filter($items, static fn($i) => ($i['status'] ?? null) !== 'diakhiri'));
        }
        $s = $doc->add(new Sheet('Proyeksi Cashflow'));
        $s->paper = 'legal';
        $s->widths([16, 78, 30, 24, 24, 28]);
        $dates = [];
        foreach ($items as $it) {
            $dates[] = $it['expected_date'] ?: $it['period_month'] . '-01';
        }
        sort($dates);
        $sub = $dates ? 'TGL. ' . strtoupper(self::longDate($dates[0])) . ' SD ' . strtoupper(self::longDate(date('Y-m-t', strtotime((string) end($dates))))) : 'BELUM ADA ITEM PROYEKSI';
        $r = self::banner($s, $doc, 6, 'ESTIMASI PENGELUARAN DAN DANA', $sub, 1, 1);
        $h = self::hdr(['font' => 'Calibri']);
        foreach (['Tanggal', 'KETERANGAN', 'REKENING PENDEBETAN', 'PENERIMAAN', 'PENGELUARAN', 'SALDO PROYEKSI'] as $i => $t) {
            $s->set($r, 1 + $i, $t, $h);
        }
        $s->height($r, 24);
        $s->repeatRows = [$r, $r];
        $s->freezeRow = $r;
        $r++;
        $r = self::fundBlock($s, $r, 1, 5, 6, $acc);
        if ($asOf) {
            $s->set($r, 1, 'Saldo bank per ' . self::longDate($asOf) . ' menjadi saldo awal proyeksi. Item di bawah adalah estimasi, bukan transaksi aktual.', ['size' => 10, 'italic' => true])->merge($r, 1, $r, 6);
            $r++;
        }
        $months = [];
        foreach ($items as $it) {
            $months[$it['period_month']][] = $it;
        }
        ksort($months);
        $bal = $bank;
        $base = ['border' => 'lrtb'];
        foreach ($months as $ym => $list) {
            $r++;
            $ty = ['size' => 16, 'bold' => true, 'fill' => self::YELLOW, 'border' => 'lrtb'];
            $s->set($r, 2, strtoupper(self::monthLabel($ym)), $ty)->paint($r, 1, 6, $ty)->height($r, 21)->keep($r);
            $r++;
            $first = $r;
            $in = '0.00';
            $out = '0.00';
            foreach ($list as $it) {
                $isIn = $it['direction'] === 'in';
                $act = (bool) $it['is_active'];
                $fill = $isIn ? self::PINK : null;
                $st = $base + ['fill' => $fill];
                $s->date($r, 1, $it['expected_date'], $st + ['h' => 'center']);
                $real = bccomp((string) $it['realized'], '0', 2) > 0 || ($it['status'] ?? '') === 'diakhiri'
                    ? ' (estimasi ' . money($it['amount']) . ', ' . ($isIn ? 'diterima ' : 'dibayar ') . money($it['realized']) . ', sisa ' . money($it['left'] ?? '0') . (($it['status'] ?? '') === 'diakhiri' ? ' — Selesai, Sisa Diakhiri ' . fmt_date($it['closed_at']) : '') . ')' : '';
                $s->set($r, 2, $it['description'] . $real . ($act ? '' : ' (nonaktif — tidak dihitung)'), $st + ['wrap' => true, 'italic' => !$act]);
                $s->set($r, 3, $it['account_hint'] ?? '', $st + ['h' => 'center', 'wrap' => true]);
                $s->num($r, 4, $isIn ? (string) $it['eff'] : null, $st + ['fmt' => 'acc2']);
                $s->num($r, 5, !$isIn ? (string) $it['eff'] : null, $st + ['fmt' => 'acc2']);
                $s->set($r, 6, null, $st);
                if ($act) {
                    if ($isIn) { $in = bcadd($in, (string) $it['eff'], 2); } else { $out = bcadd($out, (string) $it['eff'], 2); }
                }
                $r++;
            }
            $bal = bcadd(bcsub($bal, $out, 2), $in, 2);
            $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2', 'size' => 12];
            $s->set($r, 1, 'TOTAL ' . strtoupper(self::monthLabel($ym)), $tot + ['fmt' => 'text', 'h' => 'right'])->paint($r, 2, 3, $tot + ['fmt' => 'text'])->merge($r, 1, $r, 3);
            $s->num($r, 4, $in, $tot, 'SUM(D' . $first . ':D' . ($r - 1) . ')');
            $s->num($r, 5, $out, $tot, 'SUM(E' . $first . ':E' . ($r - 1) . ')');
            $s->num($r, 6, $bal, $tot + ['fmt' => 'bal2']);
            $s->height($r, 21);
            $r++;
        }
    }

    /* ================= DASHBOARD ================= */
    private static function dashboard(Document $doc, Request $req): void
    {
        $f = Filters::dashboard($req);
        $b = LedgerService::dashboardBundle($f, 8);
        $sum = $b['summary'];
        $s = $doc->add(new Sheet('Ringkasan'));
        $s->paper = 'legal';
        $s->widths([2.71, 46, 24, 24, 24, 26, 26]);
        $accName = !empty($f['account']) ? (string) Db::val('SELECT name FROM accounts WHERE id = ?', [(int) $f['account']]) : '';
        $r = self::banner($s, $doc, 7, 'RINGKASAN CASHFLOW ' . strtoupper($doc->company), 'PERIODE: ' . (!empty($f['from']) ? strtoupper(self::longDate($f['from']) . ' SD ' . self::longDate($f['to'])) : 'SELURUH DATA') . ($accName !== '' ? ' · REKENING: ' . strtoupper($accName) : ''), 1, 2);
        $base = ['border' => 'lrtb'];
        $hdr = self::hdr(['font' => 'Calibri']);
        $title = static function (Sheet $s, int $r, string $t): void {
            $ty = ['font' => 'Comic Sans MS', 'size' => 14, 'bold' => true, 'fill' => self::YELLOW, 'border' => 'lrtb'];
            $s->set($r, 2, $t, $ty)->paint($r, 2, 7, $ty)->merge($r, 2, $r, 7)->height($r, 22.5)->keep($r);
        };

        // 1. posisi rekening
        $r++;
        $title($s, $r++, 'POSISI REKENING (per ' . (!empty($f['to']) ? self::longDate($f['to']) : 'transaksi terakhir') . ')');
        foreach (['Rekening', 'Jenis', 'Saldo / Pemakaian', 'Limit', 'Sisa limit'] as $i => $t) {
            $s->set($r, 2 + $i, $t, $hdr);
        }
        $s->height($r, 24)->keep($r);
        $r++;
        foreach (LedgerService::accounts($f['to'] ?? null, true) as $a) {
            if (!empty($f['account']) && (int) $a['id'] !== (int) $f['account']) {
                continue;
            }
            $s->set($r, 2, $a['name'], $base);
            $s->set($r, 3, $a['balance_mode'] === 'liability' ? 'Pinjaman' : ($a['kind'] === 'facility' ? 'Fasilitas' : 'Bank'), $base + ['h' => 'center']);
            $s->num($r, 4, $a['balance'], $base + ['fmt' => 'bal2']);
            $s->num($r, 5, $a['credit_limit'] !== null ? (string) $a['credit_limit'] : null, $base + ['fmt' => 'acc2']);
            $s->num($r, 6, $a['remaining'] ?? null, $base + ['fmt' => 'bal2']);
            $r++;
        }

        // 2. rekonsiliasi
        $r++;
        $title($s, $r++, 'REKONSILIASI PERIODE');
        $recon = [
            ['Saldo awal periode', $sum['opening'], false],
            ['(+) Mutasi masuk usaha', $sum['ops_in'], false],
            ['(−) Mutasi keluar usaha', $sum['ops_out'], false],
            ['(=) Arus kas bersih usaha', $sum['ops_net'], true],
            ['(+/−) Pembiayaan & transfer (neto)', bcadd($sum['fin_net'], $sum['transfer_net'], 2), false],
            ['(=) SALDO AKHIR', $sum['closing'], true],
        ];
        foreach ($recon as [$label, $val, $bold]) {
            $st = $base + ['bold' => $bold, 'fill' => $bold ? self::CYAN : null];
            $s->set($r, 2, $label, $st)->paint($r, 3, 3, $st);
            $s->num($r, 4, $val, $st + ['fmt' => 'bal2']);
            $r++;
        }
        $s->set($r, 2, 'Pembiayaan (pinjaman/hutang bank) dan pindah dana antar rekening tidak dihitung sebagai mutasi masuk/keluar usaha agar tidak terhitung ganda.', ['size' => 10, 'italic' => true])->merge($r, 2, $r, 7);
        $r++;

        // 3. bulanan
        if ($b['monthly']) {
            $r++;
            $title($s, $r++, 'MUTASI MASUK vs MUTASI KELUAR PER BULAN');
            foreach (['Bulan', 'Mutasi masuk usaha', 'Mutasi keluar usaha', 'Arus kas bersih', 'Pembiayaan & transfer', 'Saldo akhir'] as $i => $t) {
                $s->set($r, 2 + $i, $t, $hdr);
            }
            $s->height($r, 24)->keep($r);
            $r++;
            $first = $r;
            $ti = $to = $tn = $tf = '0.00';
            foreach ($b['monthly'] as $m) {
                $s->set($r, 2, self::monthLabel($m['ym']), $base);
                $s->num($r, 3, $m['ops_in'], $base + ['fmt' => 'acc2']);
                $s->num($r, 4, $m['ops_out'], $base + ['fmt' => 'acc2']);
                $s->num($r, 5, $m['ops_net'], $base + ['fmt' => 'bal2']);
                $s->num($r, 6, $m['fin_net'], $base + ['fmt' => 'bal2']);
                $s->num($r, 7, $m['closing'], $base + ['fmt' => 'bal2']);
                $ti = bcadd($ti, $m['ops_in'], 2);
                $to = bcadd($to, $m['ops_out'], 2);
                $tn = bcadd($tn, $m['ops_net'], 2);
                $tf = bcadd($tf, $m['fin_net'], 2);
                $r++;
            }
            $tot = ['bold' => true, 'fill' => self::CYAN, 'border' => 'lrtb', 'fmt' => 'acc2'];
            $s->set($r, 2, 'TOTAL', $tot);
            $s->num($r, 3, $ti, $tot, 'SUM(C' . $first . ':C' . ($r - 1) . ')');
            $s->num($r, 4, $to, $tot, 'SUM(D' . $first . ':D' . ($r - 1) . ')');
            $s->num($r, 5, $tn, $tot + ['fmt' => 'bal2'], 'SUM(E' . $first . ':E' . ($r - 1) . ')');
            $s->num($r, 6, $tf, $tot + ['fmt' => 'bal2'], 'SUM(F' . $first . ':F' . ($r - 1) . ')');
            $s->num($r, 7, $sum['closing'], $tot + ['fmt' => 'bal2']);
            $s->height($r, 21);
            $r++;
        }

        // 4. komposisi pengeluaran
        if ($b['breakdown']) {
            $r++;
            $title($s, $r++, 'KOMPOSISI PENGELUARAN USAHA (per kategori)');
            foreach (['Kategori', 'Total mutasi keluar', '% dari total'] as $i => $t) {
                $s->set($r, 2 + $i, $t, $hdr);
            }
            $s->height($r, 24)->keep($r);
            $r++;
            $grand = '0.00';
            foreach ($b['breakdown'] as $x) {
                $grand = bcadd($grand, $x['total'], 2);
            }
            foreach ($b['breakdown'] as $x) {
                $pct = bccomp($grand, '0', 2) > 0 ? bcmul(bcdiv($x['total'], $grand, 6), '100', 2) : '0.00';
                $s->set($r, 2, $x['label'], $base);
                $s->num($r, 3, $x['total'], $base + ['fmt' => 'acc2']);
                $s->num($r, 4, $pct, $base + ['fmt' => 'acc2']);
                $r++;
            }
        }
    }
}
