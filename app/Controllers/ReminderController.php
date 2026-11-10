<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Page;
use App\Core\Response;
use App\Services\ReminderService;

/** Pengingat pembayaran (hanya baca; Viewer & Super Admin). Mengubah status dilakukan lewat ForecastController (admin). */
final class ReminderController extends Controller
{
    /** Halaman Pengingat: kartu per kategori; ?cat=<kunci> = detail seluruh tagihan kategori itu (diisi JS dari /api/reminders, realtime). */
    public function index(): Response
    {
        $cat = $this->req->str('cat');
        $keys = array_merge(array_keys(\App\Services\DebtService::CATEGORIES), ['estimasi']);
        Page::set(['title' => 'Pengingat Pembayaran', 'subtitle' => 'Tagihan yang mendekati / melewati jatuh tempo, per kategori', 'scripts' => ['reminders-page.js']]);
        return $this->view('reminders/index', ['cat' => in_array($cat, $keys, true) ? $cat : '', 'days' => ReminderService::days()]);
    }

    public function data(): Response
    {
        $this->releaseSession();
        $r = ReminderService::active();
        $r['can_manage'] = ($this->user()['role'] ?? '') === 'super_admin';
        return $this->ok($r + ['generated_at' => date('c')]);
    }
}
