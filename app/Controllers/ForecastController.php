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
use App\Services\LedgerService;
use App\Services\LinkService;
use App\Services\ForecastService;
use App\Services\ReminderService;
use App\Services\TxLinkService;
use App\Services\Version;
use RuntimeException;

/**
 * Proyeksi / rencana (pengganti blok estimasi di sheet CASH FLOW). BUKAN transaksi aktual:
 * tidak memengaruhi saldo atau laporan; saldo awal proyeksi = saldo rekening bank per transaksi terakhir.
 */
final class ForecastController extends Controller
{
    private function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin';
    }

    public function index(): Response
    {
        $data = $this->build();
        if ($this->req->str('partial') === '1') {
            $this->releaseSession();
            return Response::html(\App\Core\View::partial('forecast/_list', $data));
        }
        $actions = export_button('proyeksi', $this->history() ? ['riwayat' => '1'] : [], 'btn btn-light') . ($this->isAdmin() ? '<a class="btn btn-orange" href="' . e(url('/forecast/new')) . '"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah item</a>' : '');
        Page::set(['title' => 'Proyeksi', 'subtitle' => 'Rencana penerimaan & pengeluaran (estimasi, bukan transaksi aktual)', 'actions' => $actions, 'scripts' => ['forecast.js']]);
        return $this->view('forecast/index', $data);
    }

    /** Tampilkan juga item yang sisanya sudah diakhiri (riwayat)? Bawaan: disembunyikan dari daftar. */
    private function history(): bool
    {
        return $this->req->str('riwayat') === '1';
    }

    private function build(): array
    {
        $history = $this->history();
        $bank = '0.00';
        $asOf = LedgerService::lastTxDate();
        foreach (LedgerService::accounts(null, true) as $a) {
            if ($a['kind'] === 'bank' && $a['balance_mode'] === 'asset') {
                $bank = bcadd($bank, $a['balance'], 2);
            }
        }
        $items = TxLinkService::forecastItems();
        $all = [];
        foreach ($items as $it) {
            $all[$it['period_month']][] = $it;
        }
        ksort($all);
        $chain = [];
        $bal = $bank;
        foreach ($all as $ym => $list) {
            $in = '0.00';
            $out = '0.00';
            foreach ($list as $it) {
                if (!$it['is_active']) {
                    continue;
                }
                if ($it['direction'] === 'in') { $in = bcadd($in, (string) $it['eff'], 2); } else { $out = bcadd($out, (string) $it['eff'], 2); }
            }
            $open = $bal;   // saldo awal bulan = saldo akhir bulan sebelumnya (bulan pertama = saldo bank per transaksi terakhir)
            $bal = bcadd(bcsub($bal, $out, 2), $in, 2);
            $chain[$ym] = ['open' => $open, 'in' => $in, 'out' => $out, 'closing' => $bal];
        }
        // item yang sisanya diakhiri otomatis hilang dari daftar (nilainya sudah 0 di saldo proyeksi); riwayat tetap dapat ditampilkan
        $closedCount = 0;
        $months = [];
        foreach ($items as $it) {
            if (($it['status'] ?? null) === 'diakhiri') {
                $closedCount++;
                if (!$history) {
                    continue;
                }
            }
            $months[$it['period_month']][] = $it;
        }
        ksort($months);
        $links = LinkService::info(array_map(static fn($i) => (int) $i['id'], $items));
        return ['months' => $months, 'chain' => $chain, 'startBalance' => $bank, 'asOf' => $asOf, 'isAdmin' => $this->isAdmin(), 'links' => $links,
            'totalItems' => count($items) - ($history ? 0 : $closedCount), 'closedCount' => $closedCount, 'history' => $history];
    }

    private function find(int $id): array
    {
        $r = Db::one('SELECT * FROM forecast_items WHERE id = ?', [$id]);
        if (!$r) {
            throw new HttpException('Item proyeksi tidak ditemukan.', 404);
        }
        return $r;
    }

    /** Detail satu item (tujuan klik kartu Pengingat Pembayaran). Viewer boleh melihat; ubah/bayar hanya Super Admin. */
    public function show(string $id): Response
    {
        $item = ctype_digit($id) ? Db::one('SELECT * FROM forecast_items WHERE id = ?', [(int) $id]) : null;
        if (!$item) {
            // item sudah dihapus / ID tidak sah: arahkan aman ke dashboard (kartu pengingat ikut menyegarkan diri)
            $this->flash('warn', 'Item tersebut sudah tidak ada atau telah dihapus.');
            return $this->redirect('/');
        }
        $due = null;
        if ($item['direction'] === 'out' && $item['is_active'] && !$item['paid_at'] && $item['expected_date']) {
            $left = ReminderService::daysLeft((string) $item['expected_date'], ReminderService::today());
            $due = ReminderService::classify($left) + ['days_left' => $left];
        }
        $payer = $item['paid_by'] ? Db::val('SELECT full_name FROM users WHERE id = ?', [(int) $item['paid_by']]) : null;
        $link = LinkService::info([(int) $item['id']])[(int) $item['id']] ?? null;
        $cand = [];
        if ($this->isAdmin() && !$link) {
            $cand = $item['direction'] === 'out'
                ? Db::all('SELECT d.id, d.category, d.supplier, d.description, d.due_date, d.amount - COALESCE((SELECT SUM(p.amount) FROM debt_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.debt_id = d.id AND (p.transaction_id IS NULL OR t.voided_at IS NULL)), 0) AS remaining FROM debts d WHERE d.voided_at IS NULL AND d.forecast_item_id IS NULL HAVING remaining > 0.004 ORDER BY (d.due_date IS NULL), d.due_date, d.id LIMIT 100')
                : Db::all('SELECT i.id, i.kind, i.customer, i.invoice_no, i.paid_known, i.amount - i.paid AS remaining FROM invoices i WHERE i.forecast_item_id IS NULL AND (i.paid_known = 0 OR i.amount - i.paid > 0.004) ORDER BY i.customer, i.invoice_date, i.id LIMIT 300');
        }
        $reals = Db::all(
            'SELECT r.*, t.tx_date, t.voucher_code, t.voided_at AS tx_void, a.name AS tx_account, u.full_name AS by_name
             FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = r.created_by
             WHERE r.forecast_id = ? ORDER BY t.tx_date, r.id',
            [(int) $item['id']]
        );
        $realized = '0.00';
        foreach ($reals as $x) {
            if ($x['tx_void'] === null) { $realized = bcadd($realized, (string) $x['amount'], 2); }
        }
        $src = TxLinkService::source('forecast', (int) $item['id']);
        $sum = ForecastService::summary($item, $realized);
        $decisions = Db::all("SELECT a.created_at, a.username, a.action, a.summary, a.after_json FROM audit_logs a WHERE a.entity = 'forecast' AND a.entity_id = ? AND a.action IN ('forecast_close','forecast_keep','forecast_reopen') ORDER BY a.id DESC LIMIT 20", [(string) $item['id']]);
        $closer = $item['closed_by'] ? Db::val('SELECT full_name FROM users WHERE id = ?', [(int) $item['closed_by']]) : null;
        Page::set(['title' => 'Detail item proyeksi', 'subtitle' => $item['direction'] === 'in' ? 'Rencana penerimaan' : 'Rencana pengeluaran / tagihan']);
        return $this->view('forecast/show', ['it' => $item, 'due' => $due, 'payer' => $payer, 'isAdmin' => $this->isAdmin(), 'link' => $link, 'cand' => $cand,
            'reals' => $reals, 'realized' => $realized, 'blockReal' => $src['block'] ?? null, 'sum' => $sum, 'decisions' => $decisions, 'closer' => $closer]);
    }

    /** Akhiri sisa estimasi (tutup sisa; histori & transaksi tidak disentuh). */
    public function close(string $id): Response
    {
        return $this->guarded(function () use ($id) {
            $rem = ForecastService::close((int) $id, $this->req->str('note'), (int) $this->user()['id']);
            return 'Sisa estimasi ' . money($rem) . ' diakhiri (Selesai — Sisa Diakhiri) dan tidak lagi tampil di daftar Proyeksi. Tidak ada transaksi atau saldo yang berubah; riwayat tetap tersimpan (lihat lewat “Tampilkan riwayat”).';
        }, 'Sisa estimasi diakhiri.', '/forecast');
    }

    /** Buka kembali sisa yang diakhiri. */
    public function reopen(string $id): Response
    {
        return $this->guarded(function () use ($id) {
            $rem = ForecastService::reopen((int) $id, (int) $this->user()['id']);
            return 'Sisa estimasi ' . money($rem) . ' dibuka kembali sebagai estimasi aktif.';
        }, 'Sisa dibuka kembali.', '/forecast/' . (int) $id);
    }

    public function unpay(string $id): Response
    {
        $old = $this->find((int) $id);
        if (LinkService::info([(int) $id])) {
            $this->flash('warn', 'Item ini tertaut ke Hutang/Piutang, jadi statusnya mengikuti pembayaran di sana.');
            return $this->redirect('/forecast/' . (int) $id);
        }
        if (!$old['paid_at']) {
            $this->flash('warn', 'Item ini belum ditandai dibayar.');
            return $this->redirect('/forecast/' . (int) $id);
        }
        if (Db::val('SELECT id FROM forecast_realizations WHERE forecast_id = ? LIMIT 1', [(int) $id])) {
            $this->flash('warn', 'Status item ini berasal dari realisasi lewat Transaksi. Ubah atau batalkan (void) transaksinya.');
            return $this->redirect('/forecast/' . (int) $id);
        }
        Db::tx(function () use ($id, $old) {
            Db::update('forecast_items', (int) $id, ['paid_at' => null, 'paid_by' => null]);
            Audit::log('forecast_unpay', 'forecast', (string) $id, 'Batalkan tanda dibayar: ' . mb_substr($old['description'], 0, 120), ['paid_at' => $old['paid_at']], ['paid_at' => null]);
            Version::bump('forecast');
        });
        $this->flash('success', 'Tanda dibayar dibatalkan.');
        return $this->redirect('/forecast/' . (int) $id);
    }

    /* ---------------- keterhubungan dengan Hutang / Piutang (hanya Super Admin) ---------------- */

    private function guarded(callable $fn, string $ok, string $to): Response
    {
        if (!$this->isAdmin()) {
            throw new \App\Core\HttpException('Anda tidak memiliki hak akses untuk tindakan ini.', 403);
        }
        try {
            $msg = $fn();
            $this->flash('success', is_string($msg) && $msg !== '' ? $msg : $ok);
        } catch (\Throwable $e) {
            $this->flash('error', $this->safeMessage($e));
        }
        return $this->redirect($to);
    }

    public function linkDebt(string $id): Response
    {
        return $this->guarded(function () use ($id) { LinkService::linkDebt($this->req->int('debt_id'), (int) $id); }, 'Item Proyeksi ditautkan ke Hutang.', '/forecast/' . (int) $id);
    }

    public function linkInvoice(string $id): Response
    {
        return $this->guarded(function () use ($id) { LinkService::linkInvoice($this->req->int('invoice_id'), (int) $id); }, 'Item Proyeksi ditautkan ke Piutang.', '/forecast/' . (int) $id);
    }

    public function makeDebt(string $id): Response
    {
        $debt = 0;
        $r = $this->guarded(function () use ($id, &$debt) {
            $debt = LinkService::debtFromForecast((int) $id, $this->req->str('supplier'), (int) $this->user()['id'], $this->req->str('category'));
        }, 'Hutang dibuat dari item Proyeksi dan sudah tertaut.', '/forecast/' . (int) $id);
        return $debt > 0 ? $this->redirect('/debts/' . $debt) : $r;
    }

    public function unlink(string $id): Response
    {
        return $this->guarded(function () use ($id) {
            $d = Db::val('SELECT id FROM debts WHERE forecast_item_id = ?', [(int) $id]);
            $i = Db::val('SELECT id FROM invoices WHERE forecast_item_id = ?', [(int) $id]);
            if ($d) { LinkService::unlinkDebt((int) $d); } elseif ($i) { LinkService::unlinkInvoice((int) $i); } else { throw new RuntimeException('Item ini tidak tertaut.'); }
        }, 'Tautan dilepas.', '/forecast/' . (int) $id);
    }

    private function form(array $t, array $errors, ?int $id, int $status = 200): Response
    {
        Page::set(['title' => $id ? 'Ubah item proyeksi' : 'Tambah item proyeksi', 'subtitle' => 'Tidak memengaruhi saldo aktual']);
        $r = $this->view('forecast/form', ['t' => $t, 'errors' => $errors, 'id' => $id, 'locked' => $id ? (bool) LinkService::info([$id]) : false]);
        $r->status = $status;
        return $r;
    }

    public function create(): Response
    {
        return $this->form(['period_month' => date('Y-m'), 'direction' => 'out', 'is_active' => 1], [], null);
    }

    public function edit(string $id): Response
    {
        return $this->form($this->find((int) $id), [], (int) $id);
    }

    private function validate(array $in): array
    {
        $e = [];
        $d = [];
        $pm = trim((string) ($in['period_month'] ?? ''));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $pm)) { $e['period_month'] = 'Bulan tidak valid.'; }
        $d['period_month'] = $pm;
        $raw = trim((string) ($in['expected_date'] ?? ''));
        $d['expected_date'] = $raw === '' ? null : Filters::date($raw);
        if ($raw !== '' && $d['expected_date'] === null) { $e['expected_date'] = 'Tanggal tidak valid.'; }
        $dir = (string) ($in['direction'] ?? '');
        if (!in_array($dir, ['in', 'out'], true)) { $e['direction'] = 'Pilih jenis.'; }
        $d['direction'] = $dir === 'in' ? 'in' : 'out';
        $d['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, 500);
        if ($d['description'] === '') { $e['description'] = 'Uraian wajib diisi.'; }
        $amt = parse_amount($in['amount'] ?? '');
        if ($amt === null) { $e['amount'] = 'Nominal tidak valid.'; }
        $d['amount'] = $amt ?? '0.00';
        $d['account_hint'] = mb_substr(trim((string) ($in['account_hint'] ?? '')), 0, 80) ?: null;
        $d['is_active'] = !empty($in['is_active']) ? 1 : 0;
        return [$d, $e];
    }

    public function store(): Response
    {
        [$d, $e] = $this->validate($this->req->post);
        if ($e) { return $this->form($this->req->post, $e, null, 422); }
        Db::tx(function () use ($d) {
            $id = Db::insert('forecast_items', $d + ['created_by' => $this->user()['id']]);
            Audit::log('forecast_create', 'forecast', (string) $id, 'Tambah proyeksi: ' . mb_substr($d['description'], 0, 120), null, $d);
            Version::bump('forecast');
        });
        LinkService::autoLink();
        $this->flash('success', 'Item proyeksi tersimpan.');
        return $this->redirect('/forecast');
    }

    public function update(string $id): Response
    {
        $old = $this->find((int) $id);
        $post = $this->req->post;
        $locked = (bool) LinkService::info([(int) $id]);
        if ($locked) {
            // item tertaut: nominal, tanggal, bulan, dan jenis diatur otomatis dari Hutang/Piutang — kiriman formulir diabaikan
            $post = array_merge($post, ['amount' => $old['amount'], 'expected_date' => (string) $old['expected_date'], 'period_month' => $old['period_month'], 'direction' => $old['direction']]);
        }
        $realized = (string) Db::val('SELECT COALESCE(SUM(r.amount), 0) FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id WHERE r.forecast_id = ? AND t.voided_at IS NULL', [(int) $id]);
        if (Db::val('SELECT id FROM forecast_realizations WHERE forecast_id = ? LIMIT 1', [(int) $id])) {
            $post['direction'] = $old['direction'];   // sudah ada realisasi: jenis (masuk/keluar) tidak boleh berubah
        }
        [$d, $e] = $this->validate($post);
        if (!$e && bccomp($d['amount'], $realized, 2) < 0) { $e['amount'] = 'Nilai rencana tidak boleh lebih kecil dari realisasi yang sudah tercatat (' . money($realized) . ').'; }
        if ($e) { return $this->form($post, $e, (int) $id, 422); }
        Db::tx(function () use ($id, $d, $old, $locked) {
            Db::update('forecast_items', (int) $id, $d);
            if ($locked) { LinkService::syncForecast((int) $id); } else { LinkService::syncRealization((int) $id); }
            Audit::log('forecast_update', 'forecast', (string) $id, 'Ubah proyeksi: ' . mb_substr($d['description'], 0, 120), array_intersect_key($old, $d), $d);
            Version::bump('forecast');
        });
        LinkService::autoLink();
        $this->flash('success', 'Perubahan tersimpan.');
        return $this->redirect('/forecast');
    }

    public function delete(string $id): Response
    {
        $old = $this->find((int) $id);
        if (Db::val('SELECT id FROM forecast_realizations WHERE forecast_id = ? LIMIT 1', [(int) $id])) {
            $this->flash('error', 'Item ini punya realisasi yang tercatat lewat menu Transaksi, sehingga tidak dapat dihapus. Lepaskan tautan transaksinya lebih dulu (nonaktifkan item bila tidak dipakai lagi).');
            return $this->redirect('/forecast/' . (int) $id);
        }
        Db::tx(function () use ($id, $old) {
            Db::q('DELETE FROM forecast_items WHERE id = ?', [(int) $id]);
            Audit::log('forecast_delete', 'forecast', (string) $id, 'Hapus proyeksi: ' . mb_substr($old['description'], 0, 120), $old, null);
            Version::bump('forecast');
        });
        $this->flash('success', 'Item proyeksi dihapus.');
        return $this->redirect('/forecast');
    }
}
