<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if (Auth::user()) {
            return $this->redirect('/');
        }
        return $this->view('auth/login', ['title' => 'Masuk'], 'layouts/auth');
    }

    public function login(): Response
    {
        $username = $this->req->str('username');
        $password = (string) ($this->req->post['password'] ?? '');
        $result = Auth::attempt($username, $password);

        if ($result === 'ok') {
            $to = $_SESSION['intended'] ?? '/';
            unset($_SESSION['intended']);
            // hanya path internal yang diizinkan (cegah open redirect)
            if (!is_string($to) || $to === '' || $to[0] !== '/' || str_starts_with($to, '//') || $to === '/login') {
                $to = '/';
            }
            return $this->redirect($to);
        }

        $msg = $result === 'locked'
            ? 'Terlalu banyak percobaan masuk. Coba lagi dalam beberapa menit.'
            : 'Username atau password salah.';
        $resp = $this->view('auth/login', ['title' => 'Masuk', 'error' => $msg, 'username' => $username], 'layouts/auth');
        $resp->status = $result === 'locked' ? 429 : 401;
        return $resp;
    }

    public function logout(): Response
    {
        Auth::logout();
        return $this->redirect('/login');
    }
}
