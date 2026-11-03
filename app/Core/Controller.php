<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    public function __construct(protected Request $req)
    {
    }

    protected function user(): array
    {
        return Auth::user() ?? [];
    }

    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        $data += [
            'user' => Auth::user(),
            'flash' => Session::pullFlash(),
            'path' => $this->req->path,
        ];
        return Response::html(View::render($template, $data, $layout));
    }

    protected function json($data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function ok(array $data = []): Response
    {
        return Response::json(['ok' => true] + $data);
    }

    protected function fail(string $message, int $status = 422, array $extra = []): Response
    {
        return Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /**
     * Pesan yang aman ditampilkan ke pengguna: hanya pesan domain (RuntimeException buatan aplikasi).
     * Kesalahan database (PDOException juga turunan RuntimeException) tidak boleh membocorkan SQL/skema; dicatat di log.
     */
    protected function safeMessage(\Throwable $e, string $generic = 'Terjadi kesalahan pada server. Tidak ada data yang diubah.'): string
    {
        if ($e instanceof \RuntimeException && !($e instanceof \PDOException)) {
            return $e->getMessage();
        }
        app_log(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $generic;
    }

    /** Untuk endpoint baca/polling: lepas kunci sesi agar permintaan paralel tidak saling menunggu. */
    protected function releaseSession(): void
    {
        Session::release();
    }
}
