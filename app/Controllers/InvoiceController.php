<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Page;
use App\Core\Response;
use App\Models\Lookup;
use App\Services\Audit;
use App\Services\Filters;
use App\Services\InvoiceIssueService;
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
        $w = ['kind = ?', 'issue_status = ?'];   // daftar piutang normal hanya memuat invoice terbit; Invoice Belum Terbit punya tab sendiri
        $p = [$kind, InvoiceIssueService::TERBIT];
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
        $unissued = $kind === 'proyek' && $this->req->str('issue') === InvoiceIssueService::BELUM;   // tab "Invoice Belum Terbit"
        $q = mb_substr($this->req->str('q'), 0, 100);
        $status = !$unissued && in_array($this->req->str('status'), ['open', 'paid', 'unknown', 'late'], true) ? $this->req->str('status') : '';
        if ($unissued && in_array($this->req->str('status'), ['done', 'all'], true)) {
            $status = $this->req->str('status');   // tab Belum Terbit: '' = masih ada sisa | done = diterbitkan penuh | all
        }
        $today = ReminderService::today();
        if ($unissued) {
            $L = InvoiceIssueService::listing($status, $q, 1, self::PER_PAGE);
            $total = $L['total'];
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min(max(1, $this->req->int('page', 1)), $pages);
            if ($page > 1) {
                $L = InvoiceIssueService::listing($status, $q, $page, self::PER_PAGE);
            }
            $rows = $L['rows'];
            $tot = ['a' => $L['sumAmount'], 'r' => $L['sumRemaining']];
            $proofs = [];
            $fids = [];
        } else {
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
        $proofs = TxLinkService::bankProofs($rows);
        }
        $data = ['proofs' => $proofs, 'rows' => $rows, 'tot' => $tot, 'q' => $q, 'status' => $status, 'kind' => $kind, 'isAdmin' => $this->isAdmin(), 'today' => $today,
            'page' => $page, 'pages' => $pages, 'total' => $total, 'links' => LinkService::info($fids),
            'unissued' => $unissued, 'ub' => $kind === 'proyek' ? InvoiceIssueService::summary() : null, 'issue' => $unissued ? InvoiceIssueService::BELUM : ''];
        if ($this->req->str('partial') === '1') {
            $this->releaseSession();
            return Response::html(\App\Core\View::partial($unissued ? 'invoices/_unissued' : 'invoices/_list', $data));
        }
        $actions = ($unissued ? '' : export_button('piutang', ['kind' => $kind, 'q' => $q, 'status' => $status], 'btn btn-light'))
            . ($this->isAdmin() ? '<a class="btn btn-orange" href="' . e(url('/invoices/new?kind=' . $kind . ($unissued ? '&issue=' . InvoiceIssueService::BELUM : ''))) . '"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah ' . ($kind === 'lampu' ? 'piutang lampu' : 'invoice') . '</a>' : '');
        $tabs = [];
        foreach (self::KINDS as $k => $label) {
            $tabs[] = ['href' => url('/invoices?kind=' . $k), 'label' => $label, 'active' => $k === $kind && !$unissued];
        }
        $tabs[] = ['href' => url('/invoices?kind=proyek&issue=' . InvoiceIssueService::BELUM), 'label' => 'Invoice Belum Terbit', 'active' => $unissued];
        Page::set(['title' => 'Piutang', 'subtitle' => $unissued ? 'Invoice yang belum diterbitkan: belum menjadi piutang, tanpa jatuh tempo dan tanpa pengingat' : ($kind === 'lampu' ? 'Rekap piutang penjualan lampu (tidak memengaruhi saldo kas)' : 'Register invoice & pembayaran proyek (tidak memengaruhi saldo kas)'), 'actions' => $actions, 'tabs' => $tabs, 'scripts' => ['invoices.js']]);
        return $this->view('invoices/index', $data);
    }

    /**
     * Formulir "Terbitkan Invoice" dari sebuah Invoice Belum Terbit: pilih project (saldo tersedia tampil), isi nominal yang diterbitkan
     * (bertahap; tidak boleh melebihi sisa), No. invoice, tanggal, dan jatuh tempo (wajib).
     */
    public function issueForm(string $id): Response
    {
        $this->requireAdmin();
        $src = $this->find((int) $id);
        if ($src['kind'] !== 'proyek' || $src['issue_status'] !== InvoiceIssueService::BELUM) {
            $this->flash('warn', 'Invoice Belum Terbit ini sudah diterbitkan penuh atau bukan Invoice Belum Terbit.');
            return $this->redirect('/invoices/' . (int) $id);
        }
        $rem = bcsub((string) $src['amount'], InvoiceIssueService::issued((int) $id), 2);
        return $this->issueView(array_replace($src, ['project_id' => (int) ($src['project_id'] ?? 0), 'amount' => $rem, 'invoice_no' => '', 'invoice_date' => date('Y-m-d'),
            'due_date' => '', 'issue_key' => bin2hex(random_bytes(16))]), [], $src);
    }

    private function issueView(array $t, array $errors, array $src, int $status = 200): Response
    {
        Page::set(['title' => 'Terbitkan invoice', 'subtitle' => $src['customer'], 'tabs' => [['href' => url('/invoices?kind=proyek&issue=' . InvoiceIssueService::BELUM), 'label' => '‹ Invoice Belum Terbit', 'active' => false]], 'scripts' => ['invoices.js']]);
        $r = $this->view('invoices/issue', ['t' => $t, 'errors' => $errors, 'src' => $src, 'balances' => InvoiceIssueService::balances(),
            'srcIssued' => InvoiceIssueService::issued((int) $src['id']), 'srcProject' => (string) (Db::val('SELECT name FROM projects WHERE id = ?', [(int) ($src['project_id'] ?? 0)]) ?: ($src['project_name'] ?? ''))]);
        $r->status = $status;
        return $r;
    }

    public function issue(string $id): Response
    {
        $this->requireAdmin();
        $src = $this->find((int) $id);
        [$d, $e] = InvoiceIssueService::validateIssue($this->req->post);
        $res = null;
        if (!$e) {
            try {
                $res = InvoiceIssueService::issue((int) $id, $d, (int) $this->user()['id']);
            } catch (\RuntimeException $ex) {
                $e['amount'] = $ex->getMessage();
            }
        }
        if ($e || $res === null) {
            return $this->issueView(array_replace($src, $this->req->post, ['project_id' => $d['project_id']]), $e, $src, 422);
        }
        $invId = $res['invoice_id'];
        if (!$res['duplicate']) {
            LinkService::autoLink();
            LinkService::ensureForecastForInvoice($invId);   // invoice hasil penerbitan = invoice resmi: masuk Proyeksi (penerimaan) pada jatuh tempo
        }
        $left = (string) (InvoiceIssueService::balances()[(int) $d['project_id']]['remaining'] ?? '0');
        $this->flash('success', $res['duplicate'] ? 'Invoice ini sudah diterbitkan sebelumnya (kiriman ganda diabaikan; saldo tidak dipotong dua kali).'
            : 'Invoice diterbitkan ' . money($d['amount']) . '. Sisa Invoice Belum Terbit project ini: ' . money($left) . '.');
        return $this->redirect('/invoices/' . $invId);
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
        $isSrc = InvoiceIssueService::isSource($r['issue_status'] ?? null);
        Page::set(['title' => $isSrc ? 'Detail Invoice Belum Terbit' : 'Detail piutang', 'subtitle' => $r['customer'], 'tabs' => [['href' => url('/invoices?kind=' . $r['kind'] . ($isSrc ? '&issue=' . InvoiceIssueService::BELUM : '')), 'label' => '‹ ' . ($isSrc ? 'Invoice Belum Terbit' : self::KINDS[$r['kind']]), 'active' => false]]]);
        $r['project'] = (string) (($r['project_id'] ?? null) ? Db::val('SELECT name FROM projects WHERE id = ?', [(int) $r['project_id']]) : '') ?: ($r['project_name'] ?? '');
        return $this->view('invoices/show', ['issuances' => $isSrc ? InvoiceIssueService::history((int) $r['id']) : [], 'origin' => !$isSrc && $r['kind'] === 'proyek' ? InvoiceIssueService::origin((int) $r['id']) : [],
            'issuedAmt' => $isSrc ? InvoiceIssueService::issued((int) $r['id']) : '0.00', 'r' => $r, 'link' => $link, 'fc' => $fc, 'cand' => $cand, 'pays' => $pays, 'proof' => $r['kind'] === 'proyek' ? (TxLinkService::bankProofs([$r])[(int) $r['id']] ?? null) : null, 'isAdmin' => $this->isAdmin(), 'today' => ReminderService::today()]);
    }

    private function form(array $t, array $errors, ?int $id, string $kind, int $status = 200): Response
    {
        $lampu = $kind === 'lampu';
        Page::set(['title' => $id ? 'Ubah piutang' : 'Tambah ' . ($lampu ? 'piutang lampu' : 'invoice'),
            'subtitle' => $lampu ? 'Total piutang = Σ Qty × Harga satuan; Tgl. jatuh tempo = Tgl. nota + jumlah hari' : 'Sisa dihitung otomatis: nominal − dibayar',
            'scripts' => $lampu ? ['invoice-form.js'] : ['invoices.js']]);
        $r = $this->view('invoices/form', ['t' => $t, 'errors' => $errors, 'id' => $id, 'kind' => $kind,
            'parties' => PartyService::options((string) ($t['customer'] ?? '')),
            'projects' => array_column(Lookup::projects(), 'name'),
            'items' => $lampu ? self::itemsOf($t) : [],
            'products' => $lampu ? Db::all('SELECT name, unit, price FROM products WHERE is_active = 1 ORDER BY name') : [],
            'payTxs' => $lampu ? self::payTxOptions((int) ($t['pay_tx_id'] ?? 0)) : [],
            'srcIssued' => $id && InvoiceIssueService::isSource($t['issue_status'] ?? null) ? InvoiceIssueService::issued($id) : '0.00',   // Invoice Belum Terbit: nominal yang sudah diterbitkan
            'derived' => $id && !$lampu && (bool) Db::val('SELECT id FROM invoice_issuances WHERE invoice_id = ? AND cancelled_at IS NULL LIMIT 1', [$id])]);   // invoice hasil penerbitan: nominal dikunci
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

    /**
     * Pilihan "Kode bukti" Piutang Lampu: transaksi Mutasi Masuk aktif yang belum terhubung ke piutang/hutang/proyeksi mana pun,
     * ditambah transaksi yang sedang tertaut ke piutang ini. Terbaru di atas.
     */
    private static function payTxOptions(int $current): array
    {
        return Db::all(
            'SELECT t.id, t.voucher_code, t.tx_date, t.debit, t.counterparty, t.description, a.name AS account
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.voided_at IS NULL AND t.debit > 0
               AND (t.id = ? OR (t.id NOT IN (SELECT transaction_id FROM invoice_payments)
                 AND t.id NOT IN (SELECT transaction_id FROM debt_payments WHERE transaction_id IS NOT NULL)
                 AND t.id NOT IN (SELECT transaction_id FROM forecast_realizations)
                 AND t.id NOT IN (SELECT pay_tx_id FROM invoices WHERE pay_tx_id IS NOT NULL)))
             ORDER BY t.tx_date DESC, t.id DESC LIMIT 500',
            [$current]
        );
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
        $t = ['invoice_date' => date('Y-m-d'), 'paid_known' => 1, 'issue_status' => $kind === 'proyek' && $this->req->str('issue') === InvoiceIssueService::BELUM ? InvoiceIssueService::BELUM : InvoiceIssueService::TERBIT];
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
        $projRow = null;
        if ($kind === 'proyek') {   // relasi ID ke master Project (nama disamakan dengan master)
            $projRow = $d['project_name'] !== null ? Db::one('SELECT id, name FROM projects WHERE name = ?', [$d['project_name']]) : null;
            $d['project_id'] = $projRow ? (int) $projRow['id'] : null;
            if ($projRow) { $d['project_name'] = (string) $projRow['name']; }
        }
        $d['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null;
        $d['invoice_no'] = mb_substr(trim((string) ($in['invoice_no'] ?? '')), 0, 80) ?: null;
        foreach (['invoice_date' => 'Tanggal', 'due_date' => 'Jatuh tempo', 'paid_date' => 'Tanggal bayar'] as $k => $label) {
            $raw = trim((string) ($in[$k] ?? ''));
            $d[$k] = $raw === '' ? null : Filters::date($raw);
            if ($raw !== '' && $d[$k] === null) { $e[$k] = $label . ' tidak valid.'; }
        }
        // Jenis: Invoice (terbit) | Invoice Belum Terbit. Hanya Piutang Proyek; dipilih saat tambah, sesudahnya hanya berubah lewat "Terbitkan Invoice".
        $issue = InvoiceIssueService::TERBIT;
        if ($kind === 'proyek') {
            $issue = $existing !== null ? (string) $existing['issue_status'] : (($in['issue_status'] ?? '') === InvoiceIssueService::BELUM ? InvoiceIssueService::BELUM : InvoiceIssueService::TERBIT);
        }
        $d['issue_status'] = $issue;
        $isSrc = InvoiceIssueService::isSource($issue);   // Invoice Belum Terbit (masih ada sisa) atau sudah diterbitkan penuh
        if ($isSrc) {
            $d['due_date'] = null;   // belum terbit: tanpa jatuh tempo (tidak ada kewajiban bayar / pengingat)
            unset($e['due_date']);
            if (!$projRow) {
                $e['project_name'] = 'Project wajib dipilih dari master Project untuk Invoice Belum Terbit.';
            }
        } elseif ($kind === 'proyek' && $d['due_date'] === null && !isset($e['due_date']) && ($existing === null || $existing['due_date'] !== null)) {
            // Invoice terbit: jatuh tempo wajib untuk data baru (agar masuk Proyeksi); data lama tanpa jatuh tempo boleh tetap kosong
            $e['due_date'] = 'Tgl. jatuh tempo wajib diisi.';
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
        if ($isSrc) {   // belum diterbitkan = belum bisa dibayar
            $d['paid'] = '0.00';
            $d['paid_known'] = 1;
            unset($e['paid']);
            if ($amount !== null && bccomp($amount, '0', 2) <= 0) {
                $e['amount'] = 'Nominal Invoice Belum Terbit harus lebih dari 0.';
            }
            if ($existing !== null && $amount !== null) {
                $iss = InvoiceIssueService::issued((int) $existing['id']);
                if (bccomp($amount, $iss, 2) < 0) {
                    $e['amount'] = 'Nominal tidak boleh di bawah nominal yang sudah diterbitkan (' . money($iss) . ').';
                }
                if (bccomp($iss, '0', 2) > 0 && $projRow && (int) $projRow['id'] !== (int) ($existing['project_id'] ?? 0)) {
                    $e['project_name'] = 'Project tidak dapat diubah karena sebagian nominal sudah diterbitkan.';
                }
            }
        } elseif ($existing !== null && $amount !== null && bccomp($amount, (string) $existing['amount'], 2) !== 0
            && Db::val('SELECT id FROM invoice_issuances WHERE invoice_id = ? AND cancelled_at IS NULL LIMIT 1', [(int) $existing['id']])) {
            $e['amount'] = 'Nominal invoice hasil penerbitan Invoice Belum Terbit tidak dapat diubah (terkait saldo sumber). Hapus invoice ini untuk membatalkan penerbitannya, lalu terbitkan ulang.';
        }
        $d['note'] = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null;
        $this->validateCustomer($d, $e, $existing);
        if ($kind === 'lampu') {
            $this->validateLampu($in, $d, $e, $existing);
        }
        return [$d, $e];
    }

    /**
     * Customer (Piutang Proyek & Lampu) dipilih dari master Customer; ejaan disamakan dengan master.
     * Nama lama yang belum ada di master tetap boleh disimpan selama tidak diubah.
     */
    private function validateCustomer(array &$d, array &$e, ?array $existing): void
    {
        if ($d['customer'] === '') {
            return;
        }
        $canon = PartyService::canonical($d['customer']);
        if ($canon !== null) {
            $d['customer'] = mb_substr($canon, 0, 160);
        } elseif (!($existing && mb_strtolower((string) $existing['customer']) === mb_strtolower($d['customer']))) {
            $e['customer'] = 'Pilih customer dari daftar, atau tambahkan lewat tombol “Tambah” di dropdown (atau Master Data → Customer).';
        }
    }

    /**
     * Kode bukti Piutang Lampu = transaksi Mutasi Masuk yang dipilih (invoices.pay_tx_id). Kode bukti, tgl. bayar, dan (bila kosong) dibayar
     * diambil dari transaksi itu. Satu transaksi hanya boleh menjadi bukti satu piutang/hutang/proyeksi.
     * Tanpa pilihan: tautan dilepas; kode bukti lama dari Excel yang belum pernah cocok dibiarkan.
     */
    private function validatePayTx(array $in, array &$d, array &$e, ?array $existing): void
    {
        if (!array_key_exists('pay_tx_id', $in)) {
            return;
        }
        $raw = trim((string) $in['pay_tx_id']);
        if ($raw === '') {
            $d['pay_tx_id'] = null;
            if ($existing && $existing['pay_tx_id'] !== null) { $d['pay_ref'] = null; }   // kode berasal dari transaksi yang dilepas
            return;
        }
        $tx = ctype_digit($raw) ? Db::one('SELECT id, voucher_code, tx_date, debit, voided_at FROM transactions WHERE id = ?', [(int) $raw]) : null;
        if (!$tx || $tx['voided_at'] !== null || bccomp((string) $tx['debit'], '0', 2) <= 0) {
            $e['pay_tx_id'] = 'Kode bukti harus transaksi Mutasi Masuk yang aktif.';
            return;
        }
        $other = TxLinkService::linkOf((int) $tx['id']);
        if ($other && !($other['type'] === 'invoice' && $existing && $other['id'] === (int) $existing['id'])) {
            $e['pay_tx_id'] = 'Transaksi ' . ($tx['voucher_code'] ?: '#' . $tx['id']) . ' sudah terhubung ke ' . TxLinkService::name($other['type']) . ' #' . $other['id'] . ' dan tidak dapat dipakai dua kali.';
            return;
        }
        $d['pay_tx_id'] = (int) $tx['id'];
        $d['pay_ref'] = mb_substr((string) ($tx['voucher_code'] ?: '#' . $tx['id']), 0, 40);
        $d['paid_date'] ??= $tx['tx_date'];
        if (!isset($e['paid']) && bccomp($d['paid'], '0', 2) === 0) {
            $d['paid'] = bcadd((string) $tx['debit'], '0', 2);   // Dibayar kosong: diisi nominal transaksi
        }
    }

    /**
     * Aturan khusus Piutang Lampu: No. surat jalan unik (wajib untuk data baru),
     * rincian barang (Total = Σ Qty × Harga satuan, dihitung server), jatuh tempo dalam hari (Tgl. jatuh tempo = Tgl. nota + hari).
     * Kolom lama yang tidak ada di formulir (keterangan jatuh tempo, sales, keterangan/proyek) dipertahankan.
     */
    private function validateLampu(array $in, array &$d, array &$e, ?array $existing): void
    {
        foreach (['project_name', 'description', 'due_note', 'salesperson', 'pay_ref'] as $k) {
            if (!array_key_exists($k, $in)) { unset($d[$k]); }
        }
        $this->validatePayTx($in, $d, $e, $existing);
        if ($d['invoice_no'] === null) {
            if ($existing === null || $existing['invoice_no'] !== null) { $e['invoice_no'] = 'No. surat jalan wajib diisi.'; }
        } elseif (Db::val("SELECT id FROM invoices WHERE kind = 'lampu' AND invoice_no = ? AND id <> ? LIMIT 1", [$d['invoice_no'], (int) ($existing['id'] ?? 0)])) {
            $e['invoice_no'] = 'No. surat jalan ' . $d['invoice_no'] . ' sudah dipakai piutang lain. Nomor tidak boleh dobel.';
        }

        // rincian barang; baris kosong diabaikan
        $items = [];
        $sum = '0.00';
        $names = (array) ($in['item_name'] ?? []);
        // nama barang harus dari master Barang (ejaan disamakan); nama lama pada piutang ini yang belum/tidak aktif di master tetap boleh bila tidak diganti
        $oldNames = $existing ? array_map(static fn($it) => mb_strtolower((string) ($it['name'] ?? '')), self::itemsOf($existing)) : [];
        foreach ($names as $i => $n) {
            $n = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $n)), 0, 160);
            if ($n !== '') {
                $p = Db::one('SELECT name, is_active FROM products WHERE name = ?', [$n]);
                if ($p && (int) $p['is_active']) {
                    $n = $p['name'];
                } elseif (!in_array(mb_strtolower($n), $oldNames, true)) {
                    $e['items'] = 'Baris barang ke-' . ($i + 1) . ': “' . $n . '” ' . ($p ? 'nonaktif di master Barang.' : 'belum ada di master Barang. Pilih dari daftar atau tambahkan lewat “Tambah” di dropdown (atau Master Data → Barang).');
                    continue;
                }
            }
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
            Audit::log('invoice_create', 'invoice', (string) $id, 'Tambah ' . ($d['kind'] === 'lampu' ? 'piutang lampu ' : (InvoiceIssueService::isSource($d['issue_status']) ? 'invoice belum terbit ' : 'invoice ')) . ($d['invoice_no'] ?? '') . ' ' . $d['customer'], null, $d);
            Version::bump('invoices');
            if ($d['issue_status'] === InvoiceIssueService::BELUM) {
                Version::bump('forecast');   // bagian "Piutang Belum Terbit Invoice" di Proyeksi ikut diperbarui
            }
            return $id;
        });
        LinkService::autoLink();
        LinkService::ensureForecastForInvoice($newId);   // invoice belum terbit dilewati oleh fungsi ini
        $this->flash('success', $d['issue_status'] === InvoiceIssueService::BELUM ? 'Invoice belum terbit tersimpan.' : 'Piutang tersimpan.');
        return $this->redirect('/invoices/' . $newId);
    }

    public function update(string $id): Response
    {
        $this->requireAdmin();
        $old = $this->find((int) $id);
        [$d, $e] = $this->validate($this->req->post, $old['kind'], $old);
        if ($e) { return $this->form($this->req->post + ['id' => $id, 'pay_ref' => $old['pay_ref'], 'due_note' => $old['due_note'], 'due_date' => $old['due_date'], 'issue_status' => $old['issue_status']], $e, (int) $id, $old['kind'], 422); }
        unset($d['kind'], $d['issue_status']);   // jenis hanya berubah lewat "Terbitkan Invoice"
        Db::tx(function () use ($id, $d, $old) {
            Db::update('invoices', (int) $id, $d);
            Audit::log('invoice_update', 'invoice', (string) $id, 'Ubah piutang ' . ($d['invoice_no'] ?? '') . ' ' . $d['customer'], array_intersect_key($old, $d), $d);
            LinkService::syncInvoice((int) $id);
            Version::bump('invoices');
            if (InvoiceIssueService::isSource($old['issue_status'])) {
                InvoiceIssueService::recalc((int) $id);   // nominal awal berubah → sisa & status (belum terbit / selesai) dihitung ulang
                Version::bump('forecast');
            }
        });
        LinkService::autoLink();
        LinkService::ensureForecastForInvoice((int) $id);   // tak berbuat apa-apa bila sudah tertaut / belum memenuhi syarat
        $this->flash('success', 'Perubahan tersimpan.');
        return $this->redirect('/invoices/' . (int) $id);
    }

    public function delete(string $id): Response
    {
        $this->requireAdmin();
        $old = $this->find((int) $id);
        $isSrc = InvoiceIssueService::isSource($old['issue_status']);
        if (Db::val('SELECT id FROM invoice_payments WHERE invoice_id = ? LIMIT 1', [(int) $id])) {
            $this->flash('error', 'Piutang ini punya penerimaan yang tercatat lewat menu Transaksi, sehingga tidak dapat dihapus. Lepaskan tautan penerimaannya di transaksi terkait lebih dulu.');
            return $this->redirect('/invoices/' . (int) $id);
        }
        if ($isSrc && Db::val('SELECT id FROM invoice_issuances WHERE source_id = ? LIMIT 1', [(int) $id])) {
            $this->flash('error', 'Invoice Belum Terbit ini sudah punya riwayat penerbitan sehingga tidak dapat dihapus (histori dipertahankan). Batalkan penerbitannya dengan menghapus invoice hasil terbit bila perlu.');
            return $this->redirect('/invoices/' . (int) $id);
        }
        $restored = [];
        Db::tx(function () use ($id, $old, &$restored) {
            $restored = InvoiceIssueService::cancelForInvoice((int) $id, (int) $this->user()['id']);   // invoice hasil penerbitan: saldo sumber kembali, histori ditandai dibatalkan
            Db::q('DELETE FROM invoices WHERE id = ?', [(int) $id]);
            if ($restored && $old['forecast_item_id'] && !Db::val('SELECT id FROM forecast_realizations WHERE forecast_id = ? LIMIT 1', [(int) $old['forecast_item_id']])) {
                Db::q('DELETE FROM forecast_items WHERE id = ?', [(int) $old['forecast_item_id']]);   // item Proyeksi otomatis dari penerbitan ikut dibatalkan
            }
            Audit::log('invoice_delete', 'invoice', (string) $id, ($restored ? 'Batalkan penerbitan & hapus invoice ' : 'Hapus piutang ') . ($old['invoice_no'] ?? '') . ' ' . $old['customer'], $old, $restored ? ['sumber_kembali' => $restored] : null);
            Version::bump('invoices');
            Version::bump('forecast');
        });
        $this->flash('success', $isSrc ? 'Invoice belum terbit dihapus.' : ($restored ? 'Penerbitan dibatalkan: invoice dihapus dan saldo Invoice Belum Terbit dikembalikan.' : 'Piutang dihapus.'));
        return $this->redirect('/invoices?kind=' . $old['kind'] . ($isSrc ? '&issue=' . InvoiceIssueService::BELUM : ''));
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
