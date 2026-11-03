<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Page;
use App\Core\Response;
use App\Models\Setting;
use App\Services\Filters;
use App\Services\LedgerService;
use App\Services\Version;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $f = Filters::dashboard($this->req);
        Page::set([
            'title' => 'Dashboard',
            'subtitle' => 'Ringkasan arus kas PT Hexa Multi Energi',
            'actions' => export_button('dashboard', [], 'btn btn-light', 'Cetak', true),
            'scripts' => ['charts.js', 'dashboard.js'],
        ]);
        return $this->view('dashboard/index', [
            'f' => $f,
            'accounts' => array_map(static fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'mode' => $a['balance_mode']], LedgerService::accounts(null)),
            'range' => ['first' => LedgerService::firstTxDate(), 'last' => LedgerService::lastTxDate()],
        ]);
    }

    /** JSON data dashboard (mengikuti filter aktif). Hanya baca. */
    public function data(): Response
    {
        $this->releaseSession();
        $f = Filters::dashboard($this->req);
        $accounts = LedgerService::accounts($f['to']);
        $accOut = [];
        foreach ($accounts as $a) {
            $accOut[] = [
                'id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'kind' => $a['kind'], 'mode' => $a['balance_mode'],
                'balance' => $a['balance'], 'limit' => $a['limit'] ?? null, 'used' => $a['used'] ?? null, 'remaining' => $a['remaining'] ?? null,
                'used_pct' => $a['used_pct'] ?? null, 'hint' => $a['acct_hint'], 'last_tx' => $a['last_tx'], 'tx_count' => (int) $a['tx_count'],
                // info rekening perusahaan: nomor lengkap hanya bila pengguna ini berhak (Super Admin, atau Viewer bila diizinkan)
                'bank' => $a['bank_name'], 'holder' => $a['account_holder'], 'number' => account_number_visible($a),
                'has_number' => trim((string) ($a['account_no'] ?? '')) !== '', 'show' => (bool) $a['show_on_dashboard'],
            ];
        }
        $recent = LedgerService::listTransactions(['from' => $f['from'], 'to' => $f['to'], 'account' => $f['account']], 1, 8, 'date_desc', false, false);
        $bundle = LedgerService::dashboardBundle($f, 7);
        $rec = [];
        foreach ($recent['rows'] as $t) {
            $rec[] = [
                'id' => (int) $t['id'], 'date' => $t['tx_date'], 'voucher' => $t['voucher_code'], 'account' => $t['account_name'],
                'category' => $t['category_name'], 'kind' => $t['category_kind'], 'description' => $t['description'], 'party' => $t['counterparty'],
                'debit' => $t['debit'], 'credit' => $t['credit'],
            ];
        }
        return $this->ok([
            'filters' => $f,
            'accounts' => $accOut,
            'summary' => $bundle['summary'],
            'monthly' => $bundle['monthly'],
            'breakdown' => $bundle['breakdown'],
            'recent' => $rec,
            'versions' => Version::all(),
            'range' => ['first' => LedgerService::firstTxDate(), 'last' => LedgerService::lastTxDate()],
            'generated_at' => date('c'),
            'can_manage' => is_admin(),
        ]);
    }

    /** Polling realtime: hanya penghitung versi (ringan). Hanya baca; kunci sesi dilepas. */
    public function changes(): Response
    {
        $this->releaseSession();
        return $this->ok(['versions' => Version::all(), 'time' => time()]);
    }
}
