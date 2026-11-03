<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Export\ExportBuilder;
use App\Export\HtmlPreview;
use App\Export\PdfWriter;
use App\Export\XlsxWriter;
use App\Services\Audit;

/**
 * Cetak: setiap menu yang bisa dicetak membuka dialog pratinjau (PDF atau Excel) lalu mengunduh berkas yang dipilih.
 * Pratinjau dan unduhan dibuat dari model dokumen yang SAMA, jadi yang terlihat di pratinjau = isi berkas.
 */
final class ExportController extends Controller
{
    private function guard(string $doc): void
    {
        if (!isset(ExportBuilder::DOCS[$doc])) {
            throw new HttpException('Jenis dokumen tidak dikenal.', 404);
        }
        $admin = ($this->user()['role'] ?? '') === 'super_admin';
        if (!$admin && setting('viewer_can_export', '1') !== '1') {
            throw new HttpException('Cetak/unduh dinonaktifkan untuk peran Anda.', 403);
        }
    }

    private function format(): string
    {
        return $this->req->str('format') === 'xlsx' ? 'xlsx' : 'pdf';
    }

    public function preview(string $doc): Response
    {
        $this->guard($doc);
        $this->releaseSession();
        $format = $this->format();
        try {
            $d = ExportBuilder::build($doc, $this->req, $this->user());
            if ($format === 'xlsx') {
                $p = HtmlPreview::render($d);
                return $this->ok(['title' => $d->title, 'html' => $p['html'], 'truncated' => $p['truncated'], 'sheets' => $p['sheets'], 'maxRows' => HtmlPreview::MAX_ROWS]);
            }
            $pdf = PdfWriter::build($d);
        } catch (HttpException $e) {
            if ($format === 'xlsx') {
                return $this->fail($e->getMessage(), $e->status());
            }
            // tampil di dalam iframe pratinjau, jadi harus boleh dibingkai satu asal
            $r = Response::html('<!doctype html><meta charset="utf-8"><body style="font-family:system-ui,sans-serif;padding:24px;color:#8e1f24">' . e($e->getMessage()) . '</body>', $e->status());
            $r->headers['X-Frame-Options'] = 'SAMEORIGIN';
            $r->headers['Content-Security-Policy'] = "frame-ancestors 'self'";
            return $r;
        }
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $d->filename('pdf') . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            // pratinjau ditampilkan di dalam halaman aplikasi sendiri (iframe satu asal)
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    public function download(string $doc): Response
    {
        $this->guard($doc);
        $format = $this->format();
        $d = ExportBuilder::build($doc, $this->req, $this->user());
        $bin = $format === 'xlsx' ? XlsxWriter::build($d) : PdfWriter::build($d);
        Audit::log('export', 'document', $doc, 'Unduh ' . strtoupper($format) . ' ' . $d->title, null, ['query' => array_diff_key($this->req->query, ['format' => 1])]);
        return Response::download(
            $bin,
            $d->filename($format),
            $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'
        );
    }
}
