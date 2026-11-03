<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Page;
use App\Core\Response;
use App\Models\Lookup;
use App\Services\Filters;
use App\Services\LedgerService;
use App\Services\ReportService;

final class ReportController extends Controller
{
    private function params(): array
    {
        return ReportService::params($this->req);
    }

    private function canExport(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin' || setting('viewer_can_export', '1') === '1';
    }

    public function index(): Response
    {
        [$type, $f] = $this->params();
        $report = ReportService::build($type, $f);
        $base = ['from' => $f['from'], 'to' => $f['to'], 'account' => $f['account'], 'period' => $f['period'] ?: null];
        $tabs = [];
        foreach (ReportService::TYPES as $k => $label) {
            $tabs[] = ['href' => url('/reports') . '?' . qs_merge($base, ['type' => $k]), 'label' => $label, 'active' => $k === $type];
        }
        Page::set(['title' => 'Laporan', 'subtitle' => $report['title'], 'tabs' => $tabs, 'scripts' => ['reports.js']]);
        return $this->view('reports/index', [
            'report' => $report, 'type' => $type, 'f' => $f, 'base' => $base, 'canExport' => $this->canExport(),
            'accounts' => Lookup::accounts(true), 'company' => setting('company_name', 'PT Hexa Multi Energi'),
            'printedBy' => $this->user()['full_name'] ?? '',
        ]);
    }

    public function data(): Response
    {
        $this->releaseSession();
        [$type, $f] = $this->params();
        return $this->ok(['html' => \App\Core\View::partial('reports/_table', ['report' => ReportService::build($type, $f)])]);
    }
}
