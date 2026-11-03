<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Services\ReminderService;

/** Pengingat pembayaran (hanya baca; Viewer & Super Admin). Mengubah status dilakukan lewat ForecastController (admin). */
final class ReminderController extends Controller
{
    public function data(): Response
    {
        $this->releaseSession();
        $r = ReminderService::active();
        $r['can_manage'] = ($this->user()['role'] ?? '') === 'super_admin';
        return $this->ok($r + ['generated_at' => date('c')]);
    }
}
