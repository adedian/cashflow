<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Page;
use App\Core\Response;
use App\Models\Lookup;
use App\Services\Audit;
use App\Services\DebtService;
use App\Services\Filters;
use App\Services\LedgerService;
use App\Services\LinkError;
use App\Services\LinkService;
use App\Services\PartyService;
use App\Services\TxLinkService;
use App\Services\Version;
use App\Services\TransactionService;
use RuntimeException;

final class TransactionController extends Controller
{
    private const PER_PAGE = 25;

    private function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin';
    }

    /* ---------------- daftar ---------------- */
    public function index(): Response
    {
        $f = Filters::transactions($this->req);
        $sort = in_array($this->req->str('sort'), ['date_asc', 'date_desc', 'amount_desc', 'amount_asc'], true) ? $this->req->str('sort') : 'date_desc';
        $page = max(1, $this->req->int('page', 1));
        $includeVoid = $this->isAdmin() && $this->req->str('void') === '1';
        $res = LedgerService::listTransactions($f, $page, self::PER_PAGE, $sort, $includeVoid, true, true, true);
        $pages = max(1, (int) ceil($res['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $res = LedgerService::listTransactions($f, $page, self::PER_PAGE, $sort, $includeVoid, true, true, true);
        }
        $base = array_filter($f + ['sort' => $sort === 'date_desc' ? null : $sort, 'void' => $includeVoid ? '1' : null], static fn($v) => $v !== null && $v !== '' && $v !== false);
        if (!empty($f['flagged'])) {
            $base['flagged'] = '1';
        }
        unset($base['tutup']);   // status "Tutup bulan" dibawa cookie, bukan query tautan
        $data = [
            'res' => $res, 'f' => $f, 'sort' => $sort, 'page' => $page, 'pages' => $pages, 'perPage' => self::PER_PAGE,
            'base' => $base, 'isAdmin' => $this->isAdmin(), 'includeVoid' => $includeVoid,
            'showBalance' => true,
            'parties' => \App\Core\Db::all("SELECT DISTINCT counterparty AS name FROM transactions WHERE counterparty IS NOT NULL AND counterparty <> '' ORDER BY counterparty"),
            'accounts' => Lookup::accounts(true), 'categories' => Lookup::categories(true), 'projects' => Lookup::projects(true),
            'canExport' => $this->isAdmin() || setting('viewer_can_export', '1') === '1',
        ];
        if ($this->req->str('partial') === '1') {
            $this->releaseSession();
            return Response::html(\App\Core\View::partial('transactions/_results', $data));
        }
        $actions = $this->isAdmin() ? '<a class="btn btn-orange" href="' . e(url('/transactions/new')) . '"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah transaksi</a>' : '';
        Page::set(['title' => 'Transaksi', 'subtitle' => 'Buku kas semua rekening — cari, filter, dan telusuri', 'actions' => $actions, 'scripts' => ['transactions.js']]);
        return $this->view('transactions/index', $data);
    }

    /* ---------------- detail ---------------- */
    public function show(string $id): Response
    {
        $t = $this->load((int) $id);
        $hist = [];
        if ($this->isAdmin()) {
            $hist = Db::all("SELECT created_at, username, action, summary FROM audit_logs WHERE entity = 'transaction' AND entity_id = ? ORDER BY id DESC LIMIT 20", [(string) $t['id']]);
        }
        $actions = '<a class="btn btn-light" href="' . e(url('/transactions')) . '">Kembali</a>';
        if ($this->isAdmin() && $t['voided_at'] === null) {
            $actions .= '<a class="btn btn-orange" href="' . e(url('/transactions/' . $t['id'] . '/edit')) . '"><svg class="i i-sm"><use href="#i-edit"/></svg>Ubah</a>';
        }
        Page::set(['title' => 'Detail transaksi', 'subtitle' => ($t['voucher_code'] ?: 'Tanpa kode bukti') . ' · ' . fmt_date($t['tx_date']), 'actions' => $actions]);
        // keterhubungan: sumber yang dibayar/diterima/direalisasikan lewat transaksi ini (satu sumber per baris uraian) + rujukan historis
        $related = ['links' => [], 'legacy' => Db::all('SELECT id, kind, customer, invoice_no, paid, pay_ref FROM invoices WHERE pay_tx_id = ? ORDER BY id', [(int) $t['id']])];
        foreach (TxLinkService::linksOf((int) $t['id']) as $link) {
            if (!$link['legacy']) {
                $related['links'][] = (TxLinkService::label($link['type'], $link['id'], (int) $t['id']) ?? []) + ['type' => $link['type'], 'sid' => $link['id'], 'link_amount' => $link['amount']];
            }
        }
        $fcDecision = null;
        if ($this->isAdmin() && $t['voided_at'] === null) {
            foreach ($related['links'] as $rl) {
                if ($rl['type'] === 'forecast' && ($fi = Db::one('SELECT * FROM forecast_items WHERE id = ?', [(int) $rl['sid']]))) {
                    $fcDecision = ['it' => $fi, 'sum' => \App\Services\ForecastService::summary($fi), 'isAdmin' => true];
                    break;
                }
            }
        }
        return $this->view('transactions/show', ['t' => $t, 'lines' => TransactionService::lines($t), 'itemRows' => TransactionService::itemRows((int) $t['id']), 'hist' => $hist, 'isAdmin' => $this->isAdmin(), 'related' => $related, 'fcDecision' => $fcDecision]);
    }

    private function load(int $id): array
    {
        $t = Db::one(
            'SELECT t.*, a.name AS account_name, a.code AS account_code, c.name AS category_name, c.kind AS category_kind, p.name AS project_name,
                    uc.full_name AS created_name, uu.full_name AS updated_name, uv.full_name AS voided_name
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN projects p ON p.id = t.project_id
             LEFT JOIN users uc ON uc.id = t.created_by LEFT JOIN users uu ON uu.id = t.updated_by LEFT JOIN users uv ON uv.id = t.voided_by
             WHERE t.id = ?',
            [$id]
        );
        if (!$t) {
            throw new \App\Core\HttpException('Transaksi tidak ditemukan.', 404);
        }
        if (!$this->isAdmin()) {
            // Viewer tidak boleh melihat nomor rekening penerima, catatan tinjauan, atau jejak impor
            unset($t['counterparty_detail'], $t['flag'], $t['source_sheet'], $t['source_row'], $t['idem_key']);
        }
        return $t;
    }

    /* ---------------- tambah / ubah (Super Admin) ---------------- */
    public function create(): Response
    {
        $t = ['account_id' => $this->req->int('account') ?: '', 'tx_date' => date('Y-m-d'), 'direction' => $this->req->str('dir') === 'in' ? 'in' : 'out'];
        // dari tombol "Bayar Hutang" / "Terima Pembayaran" / "Realisasikan": isi referensi & data yang relevan (tetap dapat diubah)
        if ($ref = TxLinkService::parseRef($this->req->str('ref'))) {
            $s = TxLinkService::source($ref[0], $ref[1]);
            if (!$s || $s['block'] || bccomp($s['remaining'], '0.004', 3) <= 0) {
                $this->flash('warn', $s['block'] ?? ($s ? 'Sumber ini sudah lunas / terealisasi penuh; tidak ada sisa untuk dicatat.' : 'Referensi tidak ditemukan.'));
            } else {
                $t['direction'] = $s['direction'];
                $t['source_ref'] = $ref[0] . ':' . $ref[1];
                $t['counterparty'] = $s['party'] ?? '';
                $t['amount'] = money($s['remaining'], false);
                $t['account_id'] = $s['account_id'] ?? $t['account_id'];
                $t['description'] = match ($ref[0]) {
                    'debt' => 'Pembayaran hutang ' . $s['party'] . ($s['description'] ? ' — ' . $s['description'] : ''),
                    'invoice' => 'Penerimaan piutang ' . $s['party'] . ($s['ref_no'] ? ' — ' . $s['ref_no'] : ''),
                    default => (string) $s['title'],
                };
                $t['description'] = mb_substr((string) $t['description'], 0, 500);
            }
        }
        return $this->form($t, [], null);
    }

    public function store(): Response
    {
        [$d, $err] = TransactionService::validate($this->req->post);
        if ($err) {
            return $this->form($this->req->post, $err, null, 422);
        }
        try {
            $id = TransactionService::create($d, (int) $this->user()['id'], (string) $this->req->str('idem_key'), null, $this->req->bool('confirm_dup'));
        } catch (LinkError $e) {
            return $this->form($this->req->post, ['source_ref' => $e->getMessage()] + ($e->duplicate ? ['_dup' => '1'] : []), null, 422);
        } catch (\Throwable $e) {
            app_log('tx create gagal: ' . $e->getMessage());
            return $this->form($this->req->post, ['_' => 'Transaksi gagal disimpan. Tidak ada data yang berubah. Silakan coba lagi.'], null, 500);
        }
        LinkService::autoLink();
        $this->flash('success', 'Transaksi tersimpan.');
        return $this->redirect('/transactions/' . $id);
    }

    public function edit(string $id): Response
    {
        $t = $this->load((int) $id);
        if ($t['voided_at'] !== null) {
            $this->flash('error', 'Transaksi dibatalkan tidak dapat diubah. Pulihkan terlebih dahulu.');
            return $this->redirect('/transactions/' . (int) $id);
        }
        $both = bccomp((string) $t['debit'], '0', 2) !== 0 && bccomp((string) $t['credit'], '0', 2) !== 0;
        $t['direction'] = bccomp((string) $t['debit'], '0', 2) !== 0 ? 'in' : 'out';
        $t['amount'] = $t['direction'] === 'in' ? $t['debit'] : $t['credit'];
        $t['lines'] = TransactionService::lines($t);
        if (trim((string) ($t['note'] ?? '')) !== '' && $t['lines'] && !array_filter($t['lines'], static fn($l) => ($l['note'] ?? null) !== null)) {
            $t['lines'][0]['note'] = mb_substr(trim((string) $t['note']), 0, 500);   // catatan lama tingkat transaksi → catatan uraian pertama
        }
        $links = TxLinkService::linksOf((int) $id);
        if ($links && $links[0]['legacy']) {
            $t['legacy_link'] = (int) $links[0]['id'];
        } elseif ($links && !array_filter($t['lines'], static fn($l) => ($l['source_ref'] ?? null) !== null)) {
            // transaksi lama (satu sumber untuk seluruh transaksi): setiap barisnya mengikuti sumber itu
            foreach ($t['lines'] as $k => $l) {
                $t['lines'][$k]['source_ref'] = $links[0]['type'] . ':' . $links[0]['id'];
            }
        }
        $warn = $both ? 'Baris ini punya nilai masuk DAN keluar sekaligus (dari Excel). Menyimpan akan memakai jenis dan nominal di bawah saja.' : null;
        return $this->form($t, [], (int) $id, 200, $warn);
    }

    public function update(string $id): Response
    {
        $old = $this->load((int) $id);
        [$d, $err] = TransactionService::validate($this->req->post, $old);
        if ($err) {
            return $this->form($this->req->post, $err, (int) $id, 422);
        }
        try {
            TransactionService::update((int) $id, $d, (int) $this->user()['id'], $this->req->str('row_version') === '' ? null : $this->req->int('row_version'), null, $this->req->bool('confirm_dup'));
        } catch (LinkError $e) {
            return $this->form($this->req->post, ['source_ref' => $e->getMessage()] + ($e->duplicate ? ['_dup' => '1'] : []), (int) $id, 422);
        } catch (RuntimeException $e) {
            return $this->form($this->req->post, ['_' => $e->getMessage()], (int) $id, 409);
        } catch (\Throwable $e) {
            app_log('tx update gagal: ' . $e->getMessage());
            return $this->form($this->req->post, ['_' => 'Perubahan gagal disimpan. Tidak ada data yang berubah.'], (int) $id, 500);
        }
        LinkService::autoLink();
        $this->flash('success', 'Perubahan tersimpan.');
        return $this->redirect('/transactions/' . (int) $id);
    }

    public function void(string $id): Response
    {
        try {
            TransactionService::void((int) $id, (int) $this->user()['id'], $this->req->str('reason'));
            $this->flash('success', 'Transaksi dibatalkan (tidak lagi dihitung dalam saldo). Riwayat tetap tersimpan.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('/transactions/' . (int) $id);
    }

    public function restore(string $id): Response
    {
        try {
            TransactionService::restore((int) $id, (int) $this->user()['id']);
            LinkService::autoLink();
            $this->flash('success', 'Transaksi dipulihkan.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('/transactions/' . (int) $id);
    }

    /** Daftar pilihan No. Invoice/PO menurut jenis transaksi (JSON; hanya Super Admin). */
    public function refs(): Response
    {
        $dir = $this->req->str('dir') === 'in' ? 'in' : 'out';
        $tx = $this->req->int('tx') ?: null;
        $this->releaseSession();
        try {
            return $this->json(['ok' => true, 'dir' => $dir, 'items' => TxLinkService::options($dir, $this->req->str('q'), $tx)]);
        } catch (\Throwable $e) {
            app_log('refs gagal: ' . $e->getMessage());
            return $this->fail('Daftar referensi gagal dimuat.', 500);
        }
    }

    /**
     * Tambah cepat No. Invoice/PO dari sebuah baris uraian (JSON, hanya Super Admin): Mutasi Keluar → Hutang (jenis Usaha),
     * Mutasi Masuk → Piutang Proyek. Isian: dir, ref_no, party (supplier/customer; dibuat di master bila belum ada), amount,
     * due_date (wajib untuk Piutang), description. Dokumen yang sama (nomor + pihak) tidak dibuat ganda — yang sudah ada dikembalikan.
     * Hasil: item pilihan yang sama bentuknya dengan daftar /api/refs, siap dipilih di kotak No. Invoice/PO.
     */
    public function createRef(): Response
    {
        $dir = $this->req->str('dir') === 'in' ? 'in' : 'out';
        $no = mb_substr(trim((string) preg_replace('/\s+/', ' ', $this->req->str('ref_no'))), 0, 80);
        $partyRaw = trim((string) preg_replace('/\s+/', ' ', $this->req->str('party')));
        $party = PartyService::clean($partyRaw);
        $desc = mb_substr(trim($this->req->str('description')), 0, 255);
        $amount = parse_amount($this->req->str('amount'));
        $dueRaw = $this->req->str('due_date');
        $due = $dueRaw === '' ? null : Filters::date($dueRaw);
        $who = $dir === 'out' ? 'Supplier' : 'Customer';
        if ($no === '') {
            return $this->fail('No. invoice / PO wajib diisi.');
        }
        if ($party === '' || mb_strlen($partyRaw) > ($dir === 'out' ? 160 : 255)) {
            return $this->fail($who . ' wajib diisi (pilih dari daftar atau ketik nama baru).');
        }
        if ($amount === null || bccomp($amount, '0', 2) <= 0) {
            return $this->fail('Nominal harus berupa angka lebih dari nol.');
        }
        if ($dueRaw !== '' && $due === null) {
            return $this->fail('Tgl. jatuh tempo tidak valid.');
        }
        if ($dir === 'in' && $due === null) {
            return $this->fail('Tgl. jatuh tempo wajib diisi untuk Piutang (agar masuk Proyeksi).');
        }
        try {
            if ($dir === 'out') {
                $ex = Db::val("SELECT id FROM debts WHERE voided_at IS NULL AND LOWER(TRIM(ref_no)) = LOWER(?) AND LOWER(TRIM(supplier)) = LOWER(?) ORDER BY id LIMIT 1", [$no, $party]);
                if ($ex) {
                    return $this->ok(['created' => false, 'item' => TxLinkService::label('debt', (int) $ex)]);
                }
                $sup = Db::one('SELECT id, name, is_active FROM suppliers WHERE LOWER(name) = LOWER(?)', [$party]);
                if ($sup && !(int) $sup['is_active']) {
                    return $this->fail('Supplier “' . $sup['name'] . '” sudah ada tetapi nonaktif. Aktifkan di Master Data → Supplier.');
                }
                $sid = $sup ? (int) $sup['id'] : 0;
                if ($sid === 0) {
                    $sid = (int) Db::tx(static function () use ($party) {
                        $id = (int) Db::insert('suppliers', ['name' => $party]);
                        Audit::log('supplier_create', 'supplier', (string) $id, "Tambah supplier $party (dari formulir Transaksi)", null, ['name' => $party]);
                        Version::bump('master');
                        return $id;
                    });
                }
                [$d, $e] = DebtService::validate(['category' => 'usaha', 'supplier_id' => $sid, 'ref_no' => $no, 'description' => $desc,
                    'amount' => $amount, 'due_date' => $due ?? '']);
                if ($e) {
                    return $this->fail((string) reset($e));
                }
                $d['paid'] = '0.00';
                $res = DebtService::create($d, (int) $this->user()['id']);
                LinkService::autoLink();
                LinkService::ensureForecastForDebt((int) $res['id']);
                return $this->ok(['created' => true, 'item' => TxLinkService::label('debt', (int) $res['id'])]);
            }
            $ex = Db::val("SELECT id FROM invoices WHERE kind = 'proyek' AND LOWER(TRIM(invoice_no)) = LOWER(?) AND LOWER(TRIM(customer)) = LOWER(?) ORDER BY id LIMIT 1", [$no, $party]);
            if ($ex) {
                return $this->ok(['created' => false, 'item' => TxLinkService::label('invoice', (int) $ex)]);
            }
            $cust = PartyService::canonical($party);
            if ($cust === null) {
                PartyService::ensure($party);   // customer baru masuk master (Master Data → Customer)
                $cust = PartyService::canonical($party) ?? $party;
            }
            $newId = (int) Db::tx(function () use ($cust, $no, $desc, $due, $amount) {
                $d = ['kind' => 'proyek', 'customer' => mb_substr($cust, 0, 160), 'invoice_no' => $no, 'description' => $desc !== '' ? $desc : null,
                    'due_date' => $due, 'amount' => $amount, 'paid' => '0.00', 'paid_known' => 1];
                $id = (int) Db::insert('invoices', $d + ['created_by' => $this->user()['id']]);
                Audit::log('invoice_create', 'invoice', (string) $id, 'Tambah invoice ' . $no . ' ' . $cust . ' (dari formulir Transaksi)', null, $d);
                Version::bump('invoices');
                return $id;
            });
            LinkService::autoLink();
            LinkService::ensureForecastForInvoice($newId);
            return $this->ok(['created' => true, 'item' => TxLinkService::label('invoice', $newId)]);
        } catch (\Throwable $e) {
            app_log('createRef gagal: ' . $e->getMessage());
            return $this->fail('Gagal menambahkan. Tidak ada data yang berubah.', 500);
        }
    }

    private function form(array $t, array $errors, ?int $id, int $status = 200, ?string $warn = null): Response
    {
        Page::set(['title' => $id ? 'Ubah transaksi' : 'Tambah transaksi', 'subtitle' => $id ? 'Perubahan dicatat di audit log' : 'Nominal disimpan sebagai DECIMAL; saldo dihitung otomatis', 'scripts' => ['transactions.js']]);
        // baris uraian: kiriman formulir (saat galat) > rincian tersimpan (ubah) > satu baris dari description + amount
        $rows = [];
        if (is_array($t['line_desc'] ?? null)) {
            $amts = is_array($t['line_amount'] ?? null) ? array_values($t['line_amount']) : [];
            $cats = is_array($t['line_cat'] ?? null) ? array_values($t['line_cat']) : [];
            $projs = is_array($t['line_proj'] ?? null) ? array_values($t['line_proj']) : [];
            $refs = is_array($t['line_ref'] ?? null) ? array_values($t['line_ref']) : null;
            $notes = is_array($t['line_note'] ?? null) ? array_values($t['line_note']) : [];
            foreach (array_values($t['line_desc']) as $i => $ds) {
                $rows[] = ['description' => is_scalar($ds) ? (string) $ds : '', 'amount' => is_scalar($amts[$i] ?? null) ? (string) $amts[$i] : '',
                    'category_id' => is_scalar($cats[$i] ?? null) ? (string) $cats[$i] : '', 'project_id' => is_scalar($projs[$i] ?? null) ? (string) $projs[$i] : '',
                    'source_ref' => $refs !== null ? (is_scalar($refs[$i] ?? null) ? trim((string) $refs[$i]) : '') : trim((string) ($t['source_ref'] ?? '')),
                    'note' => is_scalar($notes[$i] ?? null) ? (string) $notes[$i] : ''];
            }
        } elseif (!empty($t['lines'])) {
            foreach ($t['lines'] as $l) {
                $rows[] = ['description' => $l['description'], 'amount' => money($l['amount'], false), 'category_id' => $l['category_id'] ?? '', 'project_id' => $l['project_id'] ?? '',
                    'source_ref' => (string) ($l['source_ref'] ?? ''), 'note' => (string) ($l['note'] ?? '')];
            }
        } elseif (($t['description'] ?? '') !== '' || ($t['amount'] ?? '') !== '') {
            $rows[] = ['description' => (string) ($t['description'] ?? ''), 'amount' => (string) ($t['amount'] ?? ''),
                'category_id' => $t['category_id'] ?? '', 'project_id' => $t['project_id'] ?? '',
                'source_ref' => trim((string) ($t['source_ref'] ?? '')), 'note' => ''];
        }
        // info tiap referensi terpilih (nilai awal kotak No. Invoice/PO di tiap baris)
        $refInfos = [];
        foreach ($rows as $r) {
            $rf = (string) ($r['source_ref'] ?? '');
            if ($rf !== '' && !isset($refInfos[$rf]) && ($p = TxLinkService::parseRef($rf))) {
                $refInfos[$rf] = TxLinkService::label($p[0], $p[1], $id);
            }
        }
        $resp = $this->view('transactions/form', [
            't' => $t, 'errors' => $errors, 'id' => $id, 'warn' => $warn, 'lineRows' => $rows,
            'accounts' => Lookup::accounts(), 'categories' => Lookup::categories(), 'projects' => Lookup::projects(),
            'counterpartyAccounts' => Lookup::counterpartyAccounts(),
            'parties' => \App\Services\PartyService::options((string) ($t['counterparty'] ?? '')),
            'refInfos' => $refInfos,
            'suppliers' => Db::all('SELECT name FROM suppliers WHERE is_active = 1 ORDER BY name'),
            'idem' => bin2hex(random_bytes(16)),
        ]);
        $resp->status = $status;
        return $resp;
    }
}
