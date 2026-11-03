<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Page;
use App\Core\Response;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\BackupService;
use App\Services\ReminderService;
use App\Services\Version;
use RuntimeException;

/** Pengaturan aplikasi & backup (hanya Super Admin). */
final class SettingsController extends Controller
{
    public function index(): Response
    {
        Page::set(['title' => 'Pengaturan & Backup', 'subtitle' => 'Konfigurasi aplikasi dan cadangan database']);
        return $this->view('settings/index', [
            'company' => setting('company_name', 'PT Hexa Multi Energi'),
            'viewerExport' => setting('viewer_can_export', '1') === '1',
            'reminderDays' => ReminderService::days(),
            'backups' => BackupService::all(),
            'sys' => ['php' => PHP_VERSION, 'db' => (string) Db::val('SELECT VERSION()'), 'tz' => date_default_timezone_get(), 'env' => (string) env('APP_ENV', 'production')],
        ]);
    }

    public function save(): Response
    {
        $company = mb_substr(trim($this->req->str('company_name')), 0, 120);
        if ($company === '') {
            $this->flash('error', 'Nama perusahaan wajib diisi.');
            return $this->redirect('/settings');
        }
        $viewerExport = $this->req->bool('viewer_can_export') ? '1' : '0';
        $daysRaw = $this->req->str('reminder_days');
        if (!ctype_digit($daysRaw) || (int) $daysRaw < ReminderService::MIN_DAYS || (int) $daysRaw > ReminderService::MAX_DAYS) {
            $this->flash('error', 'Jangka pengingat harus berupa angka ' . ReminderService::MIN_DAYS . '–' . ReminderService::MAX_DAYS . ' hari.');
            return $this->redirect('/settings');
        }
        $days = (string) (int) $daysRaw;
        Db::tx(function () use ($company, $viewerExport, $days) {
            $old = ['company_name' => setting('company_name'), 'viewer_can_export' => setting('viewer_can_export', '1'), 'reminder_days' => (string) ReminderService::days()];
            Setting::set('company_name', $company);
            Setting::set('viewer_can_export', $viewerExport);
            Setting::set('reminder_days', $days);
            Audit::log('settings_update', 'settings', null, 'Pengaturan aplikasi diubah', $old, ['company_name' => $company, 'viewer_can_export' => $viewerExport, 'reminder_days' => $days]);
            if ($old['reminder_days'] !== $days) {
                Version::bump('forecast');   // jangka pengingat berubah -> kartu pengingat di semua sesi disegarkan
            }
        });
        $this->flash('success', 'Pengaturan tersimpan.');
        return $this->redirect('/settings');
    }

    public function backup(): Response
    {
        try {
            $meta = BackupService::create((string) ($this->user()['username'] ?? ''));
            Audit::log('backup_create', 'backup', $meta['file'], 'Backup dibuat: ' . $meta['file'] . ($meta['verified'] ? ' (terverifikasi)' : ' (GAGAL verifikasi)'), null, ['size' => $meta['size'], 'sha256' => $meta['sha256'], 'problems' => $meta['problems']]);
            $this->flash($meta['verified'] ? 'success' : 'error', $meta['verified'] ? 'Backup dibuat dan terverifikasi: ' . $meta['file'] : 'Backup dibuat tetapi verifikasi GAGAL: ' . implode('; ', $meta['problems']));
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('/settings');
    }

    public function download(string $file): Response
    {
        if (!BackupService::validName($file) || !is_file(BackupService::dir() . '/' . $file)) {
            throw new HttpException('Backup tidak ditemukan.', 404);
        }
        Audit::log('backup_download', 'backup', $file, 'Backup diunduh: ' . $file);
        return Response::download((string) file_get_contents(BackupService::dir() . '/' . $file), $file, 'application/sql');
    }
}
