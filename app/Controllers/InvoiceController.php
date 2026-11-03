<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Page;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Filters;
use App\Services\LinkService;
use App\Services\PartyService;
use App\Services\ReminderService;
use App\Services\TxLinkService;
use App\Services\Version;

/**
 * Register piutang: Piutang Proyek (rekap "Tagihan PLTS - Fee") dan Piutang Lampu (rekap penjualan lampu), satu tabel `invoices` dengan `kind`.
 * Tidak memengaruhi saldo kas. Terhubung ke Proyeksi (penerimaan) dan ke transaksi Mutasi Masuk (kode bukti pembayaran).
 * Baris yang pembayarannya tidak terbaca di Excel (#REF!) berstatus "Perlu konfirmasi": tidak dihitung lunas maupun belum dibayar.
 */
final class InvoiceController extends Controller
{
    private const PER_PAGE = 50;
    private const KINDS = ['proyek' => 'Piutang Proyek', 'lampu' => 'Piutang Lampu'];

    private function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin';
    }

    private function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new HttpException('Anda tidak memiliki hak akses untuk tindakan ini.', 403);
        }
    }

    private function kind(?string $v = null): string
    {
        $v ??= $this->req->str('kind');
        return isset(self::KINDS[$v]) ? $v : 'proyek';
    }

    /** @return array{0:string,1:array} [where, params] */
    private function where(string $kind, string $q, string $status, string $today): array
    {
        $w = ['kind = ?'];
        $p = [$kind];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(customer LIKE ? OR project_name LIKE ? OR invoice_no LIKE ? OR description LIKE ? OR note LIKE ? OR pay_ref LIKE ? OR items LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like, $like);
        }
        switch ($status) {
            case 'open': $w[] = 'paid_known = 1 AND amount - paid > 0.004'; break;
            case 'paid': $w[] = 'paid_known = 1 AND amount - paid <= 0.004'; break;
            case 'unknown': $w[] = 'paid_known = 0'; break;
            case 'late': $w[] = 'paid_known = 1 AND amount - paid > 0.004 AND due_date IS NOT NULL AND due_date < ?'; $p[] = $today; break;
        }
        return [' WHERE ' . implode(' AND ', $w), $p];
    }

    public function index(): Response
    {
        $kind = $this->kind();
        $q = mb_substr($this->req->str('q'), 0, 100);
        $status = in_array($this->req->str('status'), ['open', 'paid', 'unknown', 'late'], true) ? $this->req->str('status') : '';
        $today = ReminderService::today();
        [$where, $p] = $this->where($kind, $q, $status, $today);
        $total = (int) Db::val('SELECT COUNT(*) FROM invoices' . $where, $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->req->int('page', 1)), $pages);
        $rows = Db::all('SELECT * FROM invoices' . $where . ' ORDER BY (invoice_date IS NULL), invoice_date DESC, id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p);
        $tot = Db::one(
            'SELECT COALESCE(SUM(amount),0) a,
                    COALESCE(SUM(CASE WHEN paid_known = 1 THEN paid ELSE 0 END),0) p,
                    COALESCE(SUM(CASE WHEN paid_known = 1 THEN GREATEST(amount - paid, 0) ELSE 0 END),0) o,
                    COALESCE(SUM(paid_known = 0),0) un, COALESCE(SUM(CASE WHEN paid_known = 0 THEN amount ELSE 0 END),0) ua,
                    COALESCE(SUM(paid_known = 1 AND amount - paid > 0.004 AND due_date IS NOT NULL AND due_date < ?),0) late_n,
                    COALESCE(SUM(CASE WHEN paid_known = 1 AND amount - paid > 0.004 AND due_date IS NOT NULL AND due_date < ? THEN amount - paid ELSE 0 END),0) late_a
             FROM invoices' . $where,
            array_merge([$today, $today], $p)
        );
        $fids = array_filter(array_map(static fn($r) => $r['forecast_item_id'], $rows));
        $proofs = $kind === 'proyek' ? TxLinkService::bankProofs($rows) : [];
        $data = ['proofs' => $proofs, 'rows' => $rows, 'tot' => $tot, 'q' => $q, 'status' => $status, 'kind' => $kind, 'isAdmin' => $this->isAdmin(), 'today' => $today,
            'page' => $page, 'pages' => $pages, 'total' => $total, 'links' => LinkService::info($fids)];
        if ($this->req->str('partial') === '1') {
            $this->releaseSession();
            return Response::html(\App\Core\View::partial('invoices/_list', $data));
        }
        $actions = export_button('piutang', ['kind' => $kind, 'q' => $q, 'status' => $status], 'btn btn-light')
            . ($this->isAdmin() ? '<a class="btn btn-orange" href="' . e(url('/invoices/new?kind=' . $kind)) . '"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah ' . ($kind === 'lampu' ? 'piutang lampu' : 'invoice') . '</a>' : '');
        $tabs = [];
        foreach (self::KINDS as $k => $label) {
            $tabs[] = ['href' => url('/invoices?kind=' . $k), 'label' => $label, 'active' => $k === $kind];
        }
        Page::set(['title' => 'Piutang', 'subtitle' => $kind === 'lampu' ? 'Rekap piutang penjualan lampu (tidak memengaruhi saldo kas)' : 'Register invoice & pembayaran proyek (tidak memengaruhi saldo kas)', 'actions' => $actions, 'tabs' => $tabs, 'scripts' => ['invoices.js']]);
        return $this->view('invoices/index', $data);
    }

    public function show(string $id): Response
    {
        $r = ctype_digit($id) ? Db::one('SELECT i.*, t.voucher_code AS tx_voucher, t.tx_date AS tx_date, t.debit AS tx_debit, t.voided_at AS tx_void FROM invoices i LEFT JOIN transactions t ON t.id = i.pay_tx_id WHERE i.id = ?', [(int) $id]) : null;
        if (!$r) {
            $this->flash('warn', 'Piutang tersebut tidak ditemukan.');
            return $this->redirect('/invoices');
        }
        $link = $r['forecast_item_id'] ? (LinkService::info([(int) $r['forecast_item_id']])[(int) $r['forecast_item_id']] ?? null) : null;
        $fc = $r['forecast_item_id'] ? Db::one('SELECT id, description, expected_date, amount, paid_at FROM forecast_items WHERE id = ?', [(int) $r['forecast_item_id']]) : null;
        $cand = [];
        if ($this->isAdmin() && !$fc) {
            $cand = Db::all("SELECT f.id, f.description, f.expected_date, f.amount FROM forecast_items f WHERE f.direction = 'in' AND f.is_active = 1 AND f.id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL) AND f.id NOT IN (SELECT forecast_item_id FROM invoices WHERE forecast_item_id IS NOT NULL) ORDER BY (f.expected_date IS NULL), f.expected_date, f.id LIMIT 200");
        }
        $pays = Db::all(
            'SELECT p.*, t.voucher_code AS tx_voucher, t.voided_at AS tx_void, a.name AS tx_account, u.full_name AS by_name
             FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = p.created_by
             WHERE p.invoice_id = ? ORDER BY p.pay_date, p.id',
            [(int) $r['id']]
        );
        Page::set(['title' => 'Detail piutang', 'subtitle' => $r['customer'], 'tabs' => [['href' => url('/invoices?kind=' . $r['kind']), 'label' => '‹ ' . self::KINDS[$r['kind']], 'active' => false]]]);
        return $this->view('invoices/show', ['r' => $r, 'link' => $link, 'fc' => $fc, 'cand' => $cand, 'pays' => $pays, 'proof' => $r['kind'] === 'proyek' ? (TxLinkService::bankProofs([$r])[(int) $r['id']] ?? null) : null, 'isAdmin' => $this->isAdmin(), 'today' => ReminderService::today()]);
    }

    private function form(array $t, array $errors, ?int $id, string $kind, int $status = 200): Response
    {
        $lampu = $kind === 'lampu';
        Page::set(['title' => $id ? 'Ubah piutang' : 'Tambah ' . ($lampu ? 'piutang lampu' : 'invoice'),
            'subtitle' => $lampu ? 'Total piutang = Σ Qty × Harga satuan; Tgl. jatuh tempo = Tgl. nota + jumlah hari' : 'Sisa dihitung otomatis: nominal − dibayar',
            'scripts' => $lampu ? ['invoice-form.js'] : []]);
        $r = $this->view('invoices/form', ['t' => $t, 'errors' => $errors, 'id' => $id, 'kind' => $kind,
            'parties' => $lampu ? PartyService::options((string) ($t['customer'] ?? '')) : [],
            'items' => $lampu ? self::itemsOf($t) : []]);
        $r->status = $status;
        return $r;
    }

    /**
     * Rincian barang Piutang Lampu dari baris tersimpan (kolom JSON `items`) atau dari isian formulir (item_name[]/item_qty[]/item_price[]).
     * @return list<array{name:string,qty:string,price:string,subtotal:string}>
     */
    public static function itemsOf(array $t): array
    {
        if (isset($t['item_name']) && is_array($t['item_name'])) {
            $out = [];
            foreach ($t['item_name'] as $i => $n) {
                $out[] = ['name' => (string) $n, 'qty' => (string) ($t['item_qty'][$i] ?? ''), 'price' => (string) ($t['item_price'][$i] ?? ''), 'subtotal' => ''];
            }
            return $out;
        }
        $j = json_decode((string) ($t['items'] ?? ''), true);
        return is_array($j) ? array_values(array_filter($j, 'is_array')) : [];
    }

    /** Usulan No. surat jalan berikutnya: awalan + angka (dengan nol di depan) dari nomor terbaru, mis. JL00001536 -> JL00001537. */
    private static function nextSj(): string
    {
        $last = Db::val("SELECT invoice_no FROM invoices WHERE kind = 'lampu' AND invoice_no REGEXP '^[A-Za-z]+[0-9]+$' ORDER BY (invoice_date IS NULL), invoice_date DESC, id DESC LIMIT 1");
        if (!$last || !preg_match('/^([A-Za-z]+)(\d+)$/', (string) $last, $m)) {
            return '';
        }
        $max = (string) Db::val("SELECT MAX(CAST(SUBSTRING(invoice_no, ?) AS UNSIGNED)) FROM invoices WHERE kind = 'lampu' AND invoice_no REGEXP ?", [strlen($m[1]) + 1, '^' . $m[1] . '[0-9]+$']);
        return $m[1] . str_pad(bcadd($max !== '' ? $max : $m[2], '1', 0), strlen($m[2]), '0', STR_PAD_LEFT);
    }

    public function create(): Response
    {
        $this->requireAdmin();
        $kind = $this->kind();
        $t = ['invoice_date' => date('Y-m-d'), 'paid_known' => 1];
        if ($kind === 'lampu') {
            $t += ['invoice_no' => self::nextSj(), 'due_days' => 30];
        }
        return $this->form($t, [], null, $kind);
    }

    public function edit(string $id): Response
    {
        $this->requireAdmin();
        $r = $this->find((int) $id);
        return $this->form($r, [], (int) $id, $r['kind']);
    }

    private function find(int $id): array
    {
        $r = Db::one('SELECT * FROM invoices WHERE id = ?', [$id]);
        if (!$r) {
            throw new HttpException('Piutang tidak ditemukan.', 404);
        }
        return $r;
    }

    private function validate(array $in, string $kind, ?array $existing = null): array
    {
        $e = [];
        $d = ['kind' => $kind];
        $d['customer'] = mb_substr(trim((string) ($in['customer'] ?? '')), 0, 160);
        if ($d['customer'] === '') { $e['customer'] = 'Nama customer wajib diisi.'; }
        $d['project_name'] = mb_substr(trim((string) ($in['project_name'] ?? '')), 0, 200) ?: null;
        $d['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null;
        $d['invoice_no'] = mb_substr(trim((string) ($in['invoice_no'] ?? '')), 0, 80) ?: null;
        foreach (['invoice_date' => 'Tanggal', 'due_date' => 'Jatuh tempo', 'paid_date' => 'Tanggal bayar'] as $k => $label) {
            $raw = trim((string) ($in[$k] ?? ''));
            $d[$k] = $raw === '' ? null : Filters::date($raw);
            if ($raw !== '' && $d[$k] === null) { $e[$k] = $label . ' tidak valid.'; }
        }
        $d['due_note'] = mb_substr(trim((string) ($in['due_note'] ?? '')), 0, 30) ?: null;
        $d['salesperson'] = mb_substr(trim((string) ($in['salesperson'] ?? '')), 0, 40) ?: null;
        $d['pay_ref'] = mb_substr(trim((string) ($in['pay_ref'] ?? '')), 0, 40) ?: null;
        $amount = parse_amount($in['amount'] ?? '');
        if ($amount === null) { $e['amount'] = 'Nominal tagihan tidak valid.'; }
        $paid = trim((string) ($in['paid'] ?? '')) === '' ? '0.00' : parse_amount($in['paid']);
        if ($paid === null) { $e['paid'] = 'Nominal dibayar tidak valid.'; }
        $d['amount'] = $amount ?? '0.00';
        $d['paid'] = $paid ?? '0.00';
        if ($existing !== null) {
            // total dibayar = historis (Excel) + penerimaan yang dicatat lewat Transaksi: tidak boleh lebih kecil dari penerimaan via Transaksi
            $viaTx = (string) Db::val('SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p JOIN transactions t ON t.id = p.transaction_id WHERE p.invoice_id = ? AND t.voided_at IS NULL', [(int) $existing['id']]);
            if ($paid !== null && bccomp($d['paid'], $viaTx, 2) < 0) {
                $e['paid'] = 'Total dibayar tidak boleh lebih kecil dari penerimaan yang sudah dicatat lewat Transaksi (' . money($viaTx) . ').';
            }
        }

        $d['paid_known'] = !empty($in['paid_known']) ? 1 : 0;
        $d['note'] = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null;
        if ($kind === 'lampu') {
            $this->validateLampu($in, $d, $e, $existing);
        }
        return [$d, $e];
    }

    /**
     * Aturan khusus Piutang Lampu: customer dari master Customer, No. surat jalan unik (wajib untuk data baru),
     * rincian barang (Total = Σ Qty × Harga satuan, dihitung server), jatuh tempo dalam hari (Tgl. jatuh tempo = Tgl. nota + hari).
     * Kolom lama yang tidak ada di formulir (keterangan jatuh tempo, sales, keterangan/proyek) dipertahankan.
     */
    private function validateLampu(array $in, array &$d, array &$e, ?array $existing): void
    {
        foreach (['project_name', 'description', 'due_note', 'salesperson'] as $k) {
            if (!array_key_exists($k, $in)) { unset($d[$k]); }
        }
        if ($d['customer'] !== '') {
            $canon = PartyService::canonical($d['customer']);
            if ($canon !== null) {
                $d['customer'] = mb_substr($canon, 0, 160);
            } elseif (!($existing && mb_strtolower((string) $existing['customer']) === mb_strtolower($d['customer']))) {
                $e['customer'] = 'Pilih customer dari daftar, atau tambahkan lewat tombol “Tambah” di dropdown (atau Master Data → Customer).';
            }
        }
        if ($d['invoice_no'] === null) {
            if ($existing === null || $existing['invoice_no'] !== null) { $e['invoice_no'] = 'No. surat jalan wajib diisi.'; }
        } elseif (Db::val("SELECT id FROM invoices WHERE kind = 'lampu' AND invoice_no = ? AND id <> ? LIMIT 1", [$d['invoice_no'], (int) ($existing['id'] ?? 0)])) {
            $e['invoice_no'] = 'No. surat jalan ' . $d['invoice_no'] . ' sudah dipakai piutang lain. Nomor tidak boleh dobel.';
        }

        // rincian barang; baris kosong diabaikan
        $items = [];
        $sum = '0.00';
        $names = (array) ($in['item_name'] ?? []);
        foreach ($names as $i => $n) {
            $n = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $n)), 0, 160);
            $qRaw = trim((string) ($in['item_qty'][$i] ?? ''));
            $pRaw = trim((string) ($in['item_price'][$i] ?? ''));
            if ($n === '' && $qRaw === '' && $pRaw === '') { continue; }
            $qty = parse_amount($qRaw);
            $price = parse_amount($pRaw);
            if ($n === '' || $qty === null || bccomp($qty, '0', 2) <= 0 || $price === null) {
                $e['items'] = 'Baris barang ke-' . ($i + 1) . ' belum lengkap: isi nama barang, Qty (> 0), dan harga satuan.';
                continue;
            }
            $sub = bcadd(bcmul($qty, $price, 4), '0.005', 2);   // pembulatan ke sen terdekat
            $items[] = ['name' => $n, 'qty' => $qty, 'price' => $price, 'subtotal' => $sub];
            $sum = bcadd($sum, $sub, 2);
        }
        if (count($items) > 100) { $e['items'] = 'Maksimal 100 baris barang per surat jalan.'; }
        if ($items) {
            $d['items'] = json_encode($items, JSON_UNESCAPED_UNICODE);
            $d['amount'] = $sum;
            unset($e['amount']);
            if (!isset($e['paid']) && $existing === null && bccomp($d['paid'], $sum, 2) > 0) {
                $e['paid'] = 'Sudah dibayar tidak boleh melebihi total piutang (' . money($sum) . ').';
            }
        } elseif ($existing === null || !empty(self::itemsOf($existing))) {
            $e['items'] ??= 'Isi minimal satu barang (nama barang, Qty, harga satuan).';
        } else {
            $d['items'] = null;   // data lama tanpa rincian: nominal tetap dari isian “Total piutang”
        }

        // jatuh tempo dalam hari
        $raw = trim((string) ($in['due_days'] ?? ''));
        if ($raw === '') {
            $d['due_days'] = null;
            // data lama yang hanya punya tanggal jatuh tempo (tanpa jumlah hari) dibiarkan
            $d['due_date'] = ($existing && $existing['due_days'] === null) ? $existing['due_date'] : null;
        } elseif (!ctype_digit($raw) || (int) $raw > 3650) {
            $e['due_days'] = 'Jatuh tempo diisi jumlah hari (0–3650).';
        } elseif ($d['invoice_date'] === null) {
            $e['invoice_date'] = 'Tanggal nota wajib diisi untuk menghitung tanggal jatuh tempo.';
        } else {
            $d['due_days'] = (int) $raw;
            $d['due_date'] = date('Y-m-d', strtotime($d['invoice_date'] . ' +' . (int) $raw . ' days'));
        }
    }

    public function store(): Response
    {
        $this->requireAdmin();
        $kind = $this->kind((string) ($this->req->post['kind'] ?? ''));
        [$d, $e] = $this->validate($this->req->post, $kind);
        if ($e) { return $this->form($this->req->post, $e, null, $kind, 422); }
        $newId = (int) Db::tx(function () use ($d) {
            $id = Db::insert('invoices', $d + ['created_by' => $this->user()['id']]);
            Audit::log('invoice_create', 'invoice', (string) $id, 'Tambah ' . ($d['kind'] === 'lampu' ? 'piutang lampu ' : 'invoice ') . ($d['invoice_no'] ?? '') . ' ' . $d['customer'], null, $d);
            Version::bump('invoices');
            return $id;
        });
        LinkService::autoLink();
        LinkService::ensureForecastForInvoice($newId);
        $this->flash('success', 'Piutang tersimpan.');
        return $this->redirect('/invoices/' . $newId);
    }

    public function update(string $id): Response
    {
        $this->requireAdmin();
        $old = $this->find((int) $id);
        $hadDue = $old['due_date'] !== null;
        [$d, $e] = $this->validate($this->req->post, $old['kind'], $old);
        if ($e) { return $this->form($this->req->post + ['id' => $id], $e, (int) $id, $old['kind'], 422); }
        unset($d['kind']);
        Db::tx(function () use ($id, $d, $old) {
            Db::update('invoices', (int) $id, $d);
            Audit::log('invoice_update', 'invoice', (string) $id, 'Ubah piutang ' . ($d['invoice_no'] ?? '') . ' ' . $d['customer'], array_intersect_key($old, $d), $d);
            LinkService::syncInvoice((int) $id);
            Version::bump('invoices');
        });
        LinkService::autoLink();
        if (!$hadDue) { LinkService::ensureForecastForInvoice((int) $id); }
        $this->flash('success', 'Perubahan tersimpan.');
        return $this->redirect('/invoices/' . (int) $id);
    }

    public function delete(string $id): Response
    {
        $this->requireAdmin();
        $old = $this->find((int) $id);
        if (Db::val('SELECT id FROM invoice_payments WHERE invoice_id = ? LIMIT 1', [(int) $id])) {
            $this->flash('error', 'Piutang ini punya penerimaan yang tercatat lewat menu Transaksi, sehingga tidak dapat dihapus. Lepaskan tautan penerimaannya di transaksi terkait lebih dulu.');
            return $this->redirect('/invoices/' . (int) $id);
        }
        Db::tx(function () use ($id, $old) {
            Db::q('DELETE FROM invoices WHERE id = ?', [(int) $id]);
            Audit::log('invoice_delete', 'invoice', (string) $id, 'Hapus piutang ' . ($old['invoice_no'] ?? '') . ' ' . $old['customer'], $old, null);
            Version::bump('invoices');
            Version::bump('forecast');
        });
        $this->flash('success', 'Piutang dihapus.');
        return $this->redirect('/invoices?kind=' . $old['kind']);
    }

    public function linkForecast(string $id): Response
    {
        $this->requireAdmin();
        try {
            LinkService::linkInvoice((int) $id, $this->req->int('forecast_id'));
            $this->flash('success', 'Piutang ditautkan ke item Proyeksi.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/invoices/' . (int) $id);
    }

    public function unlinkForecast(string $id): Response
    {
        $this->requireAdmin();
        try {
            LinkService::unlinkInvoice((int) $id);
            $this->flash('success', 'Tautan ke Proyeksi dilepas.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/invoices/' . (int) $id);
    }

    /** Tautkan manual ke transaksi Mutasi Masuk (ID) atau lepas (ID kosong). Tidak membuat/mengubah transaksi. */
    public function linkTx(string $id): Response
    {
        $this->requireAdmin();
        $inv = $this->find((int) $id);
        $raw = trim($this->req->str('tx_id'));
        try {
            if ($raw === '') {
                Db::tx(function () use ($id, $inv) {
                    Db::update('invoices', (int) $id, ['pay_tx_id' => null]);
                    Audit::log('unlink', 'invoice', (string) $id, 'Lepas tautan transaksi dari piutang ' . $inv['customer'], ['pay_tx_id' => $inv['pay_tx_id']], null);
                    Version::bump('invoices');
                });
                $this->flash('success', 'Tautan transaksi dilepas.');
            } else {
                $tx = ctype_digit($raw) ? Db::one('SELECT id, debit, voided_at FROM transactions WHERE id = ?', [(int) $raw]) : null;
                if (!$tx || $tx['voided_at'] !== null) {
                    throw new \RuntimeException('Transaksi tidak ditemukan atau sudah dibatalkan.');
                }
                if (bccomp((string) $tx['debit'], '0', 2) <= 0) {
                    throw new \RuntimeException('Transaksi bukan Mutasi Masuk, sehingga tidak dapat menjadi bukti penerimaan piutang.');
                }
                $other = TxLinkService::linkOf((int) $tx['id']);
                if ($other && !($other['legacy'] && $other['id'] === (int) $id)) {
                    throw new \RuntimeException('Transaksi #' . (int) $tx['id'] . ' sudah terhubung ke ' . TxLinkService::name($other['type']) . ' #' . $other['id'] . ' dan tidak dapat dipakai dua kali.');
                }
                Db::tx(function () use ($id, $inv, $tx) {
                    Db::update('invoices', (int) $id, ['pay_tx_id' => (int) $tx['id']]);
                    Audit::log('link', 'invoice', (string) $id, 'Tautkan piutang ' . $inv['customer'] . ' ke transaksi #' . $tx['id'], ['pay_tx_id' => $inv['pay_tx_id']], ['pay_tx_id' => (int) $tx['id']]);
                    Version::bump('invoices');
                });
                $this->flash('success', 'Piutang ditautkan ke transaksi #' . (int) $tx['id'] . '.');
            }
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/invoices/' . (int) $id);
    }
}
