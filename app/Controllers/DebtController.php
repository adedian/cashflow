<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Page;
use App\Core\Response;
use App\Services\DebtService;
use App\Services\Filters;
use App\Services\LinkService;
use App\Services\ReminderService;
use App\Services\TxLinkService;

/**
 * Menu Hutang: daftar, detail, riwayat pembayaran (dicatat lewat menu Transaksi). Viewer hanya membaca; semua perubahan hanya Super Admin
 * (rute 'admin' + pemeriksaan ulang di sini). Hutang bukan transaksi kas: tidak memengaruhi saldo/laporan/proyeksi.
 */
final class DebtController extends Controller
{
    private const STATUSES = ['open', 'belum', 'sebagian', 'lunas', 'terlambat', 'batal'];

    private function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin';
    }

    private function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new \App\Core\HttpException('Anda tidak memiliki hak akses untuk tindakan ini.', 403);
        }
    }

    /** Jenis hutang = tab di menu Hutang (Usaha / Direksi / Lain-lain); tidak dikenal / kosong = Hutang Usaha. */
    private function category(): string
    {
        $c = $this->req->str('category');
        return isset(DebtService::CATEGORIES[$c]) ? $c : 'usaha';
    }

    private function filters(): array
    {
        $status = $this->req->str('status');
        return [
            'q' => mb_substr($this->req->str('q'), 0, 100),
            'category' => $this->category(),
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'due_from' => Filters::date($this->req->str('due_from')) ?? '',
            'due_to' => Filters::date($this->req->str('due_to')) ?? '',
            'date_from' => Filters::date($this->req->str('date_from')) ?? '',
            'date_to' => Filters::date($this->req->str('date_to')) ?? '',
        ];
    }

    public function index(): Response
    {
        $f = $this->filters();
        $page = max(1, $this->req->int('page', 1));
        $res = DebtService::search($f, $page);
        $base = $f;
        $data = ['proofs' => \App\Services\TxLinkService::debtProofs($res['rows']), 'f' => $f, 'res' => $res, 'base' => $base, 'isAdmin' => $this->isAdmin(), 'today' => ReminderService::today()];
        if ($this->req->str('partial') === '1') {
            $this->releaseSession();
            return Response::html(\App\Core\View::partial('debts/_list', $data));
        }
        $cat = $f['category'];
        $actions = $this->isAdmin() ? '<a class="btn btn-orange" href="' . e(url('/debts/new?category=' . $cat)) . '"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah hutang</a>' : '';
        $tabs = [];
        foreach (DebtService::CATEGORIES as $k => $label) {
            $tabs[] = ['href' => url('/debts?category=' . $k), 'label' => $label, 'active' => $k === $cat];
        }
        $subtitle = ['usaha' => 'Kewajiban kepada supplier/vendor (tidak memengaruhi saldo kas)', 'direksi' => 'Hutang kepada direksi (tidak memengaruhi saldo kas)', 'lain' => 'Hutang di luar usaha & direksi (tidak memengaruhi saldo kas)'][$cat];
        Page::set(['title' => 'Hutang', 'subtitle' => $subtitle, 'actions' => $actions, 'tabs' => $tabs, 'scripts' => ['debts.js']]);
        return $this->view('debts/index', $data);
    }

    public function show(string $id): Response
    {
        $d = ctype_digit($id) ? DebtService::find((int) $id) : null;
        if (!$d) {
            $this->flash('warn', 'Hutang tersebut tidak ditemukan.');
            return $this->redirect('/debts');
        }
        $fc = $d['forecast_item_id'] ? \App\Core\Db::one('SELECT id, description, expected_date, amount, paid_at, is_active FROM forecast_items WHERE id = ?', [(int) $d['forecast_item_id']]) : null;
        $link = $fc ? (LinkService::info([(int) $fc['id']])[(int) $fc['id']] ?? null) : null;
        $cand = ($this->isAdmin() && !$fc && $d['voided_at'] === null)
            ? \App\Core\Db::all("SELECT f.id, f.description, f.expected_date, f.amount FROM forecast_items f WHERE f.direction = 'out' AND f.is_active = 1 AND f.paid_at IS NULL AND f.id NOT IN (SELECT forecast_item_id FROM debts WHERE forecast_item_id IS NOT NULL) AND f.id NOT IN (SELECT forecast_item_id FROM invoices WHERE forecast_item_id IS NOT NULL) ORDER BY (f.expected_date IS NULL), f.expected_date, f.id LIMIT 200")
            : [];
        Page::set(['title' => 'Detail hutang', 'subtitle' => $d['supplier'], 'tabs' => [['href' => url('/debts?category=' . $d['category']), 'label' => '‹ ' . (DebtService::CATEGORIES[$d['category']] ?? 'Hutang'), 'active' => false]], 'scripts' => ['debts.js']]);
        return $this->view('debts/show', ['d' => $d, 'pays' => DebtService::payments((int) $d['id']), 'fc' => $fc, 'link' => $link, 'cand' => $cand, 'isAdmin' => $this->isAdmin(), 'errors' => [], 'in' => []]);
    }

    private function form(array $t, array $errors, ?int $id, int $status = 200): Response
    {
        Page::set(['title' => $id ? 'Ubah hutang' : 'Tambah hutang', 'subtitle' => 'Sisa hutang dihitung otomatis: nominal − pembayaran']);
        $r = $this->view('debts/form', [
            't' => $t, 'errors' => $errors, 'id' => $id,
            'suppliers' => \App\Core\Db::all('SELECT id, name, is_active FROM suppliers WHERE is_active = 1 OR id = ? ORDER BY name', [(int) ($t['supplier_id'] ?? 0)]),
            'accounts' => \App\Core\Db::all('SELECT id, name, bank_name, account_no, is_active FROM accounts WHERE is_active = 1 OR id = ? ORDER BY sort_order, id', [(int) ($t['source_account_id'] ?? 0)]),
        ]);
        $r->status = $status;
        return $r;
    }

    public function create(): Response
    {
        $this->requireAdmin();
        return $this->form(['category' => $this->category()], [], null);
    }

    public function edit(string $id): Response
    {
        $this->requireAdmin();
        $d = ctype_digit($id) ? DebtService::find((int) $id) : null;
        if (!$d || $d['voided_at'] !== null) {
            $this->flash('warn', 'Hutang tidak ditemukan atau sudah dibatalkan.');
            return $this->redirect('/debts');
        }
        return $this->form($d, [], (int) $id);
    }

    public function store(): Response
    {
        $this->requireAdmin();
        [$d, $e] = DebtService::validate($this->req->post);
        if ($e) {
            return $this->form($this->req->post, $e, null, 422);
        }
        $d['paid'] = '0.00';   // pembayaran hanya lewat menu Transaksi
        $res = DebtService::create($d, (int) $this->user()['id']);
        LinkService::autoLink();
        LinkService::ensureForecastForDebt((int) $res['id']);
        $res['linked'] = (bool) \App\Core\Db::val('SELECT forecast_item_id FROM debts WHERE id = ?', [$res['id']]);
        $this->flash('success', 'Hutang tersimpan.' . ($res['linked'] ? ' Otomatis masuk Proyeksi — nominal & tanggal Proyeksi mengikuti hutang ini.' : ''));
        return $this->redirect('/debts/' . $res['id']);
    }

    public function update(string $id): Response
    {
        $this->requireAdmin();
        $old = ctype_digit($id) ? DebtService::find((int) $id) : null;
        if (!$old || $old['voided_at'] !== null) {
            $this->flash('warn', 'Hutang tidak ditemukan atau sudah dibatalkan.');
            return $this->redirect('/debts');
        }
        $hadDue = $old['due_date'] !== null;
        [$d, $e] = DebtService::validate($this->req->post, $old);
        if ($e) {
            return $this->form($this->req->post, $e, (int) $id, 422);
        }
        try {
            DebtService::update((int) $id, $d, (int) $this->user()['id']);
        } catch (\Throwable $ex) {
            return $this->form($this->req->post, ['amount' => $this->safeMessage($ex)], (int) $id, 422);
        }
        LinkService::autoLink();
        if (!$hadDue) { LinkService::ensureForecastForDebt((int) $id); }   // jatuh tempo baru diisi -> hutang masuk Proyeksi
        $this->flash('success', 'Perubahan tersimpan.');
        return $this->redirect('/debts/' . (int) $id);
    }

    public function void(string $id): Response
    {
        $this->requireAdmin();
        try {
            DebtService::void((int) $id, (int) $this->user()['id'], $this->req->str('reason'));
            $this->flash('success', 'Hutang dibatalkan (riwayat tetap tersimpan).');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/debts/' . (int) $id);
    }

    public function restore(string $id): Response
    {
        $this->requireAdmin();
        try {
            DebtService::restore((int) $id, (int) $this->user()['id']);
            $this->flash('success', 'Hutang dipulihkan.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/debts/' . (int) $id);
    }

    public function linkForecast(string $id): Response
    {
        $this->requireAdmin();
        try {
            LinkService::linkDebt((int) $id, $this->req->int('forecast_id'));
            $this->flash('success', 'Hutang ditautkan ke item Proyeksi.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/debts/' . (int) $id);
    }

    public function unlinkForecast(string $id): Response
    {
        $this->requireAdmin();
        try {
            LinkService::unlinkDebt((int) $id);
            $this->flash('success', 'Tautan ke Proyeksi dilepas.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/debts/' . (int) $id);
    }

    public function deletePayment(string $id, string $pid): Response
    {
        $this->requireAdmin();
        try {
            DebtService::deletePayment((int) $id, (int) $pid);
            $this->flash('success', 'Catatan pembayaran dihapus.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect('/debts/' . (int) $id);
    }
}
