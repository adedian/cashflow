<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Page;
use App\Core\Response;
use App\Services\LedgerService;

/** Rekening & limit fasilitas (hanya baca). Pengelolaan akun ada di Master Data (Super Admin). */
final class AccountsController extends Controller
{
    public function index(): Response
    {
        Page::set(['title' => 'Rekening & Limit', 'subtitle' => 'Saldo, pemakaian fasilitas, dan saldo akhir tiap bulan', 'actions' => export_button('rekening', [], 'btn btn-light'), 'scripts' => []]);
        $accounts = LedgerService::accounts(null, false);
        foreach ($accounts as &$a) {
            $a['months'] = array_reverse(LedgerService::monthEndBalances((int) $a['id']));
        }
        return $this->view('accounts/index', ['accounts' => $accounts]);
    }
}
