<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;

/** Profil pengguna yang sedang login: ganti password sendiri. */
final class AccountController extends Controller
{
    public function showPassword(): Response
    {
        $u = $this->user();
        return $this->view('auth/password', ['title' => 'Ganti Password', 'forced' => !empty($u['must_change_password'])], 'layouts/auth');
    }

    public function savePassword(): Response
    {
        $u = $this->user();
        $cur = (string) ($this->req->post['current_password'] ?? '');
        $new = (string) ($this->req->post['new_password'] ?? '');
        $conf = (string) ($this->req->post['password_confirm'] ?? '');

        $error = null;
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [(int) $u['id']]);
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            $error = 'Password saat ini salah.';
        } elseif (strlen($new) < 10) {
            $error = 'Password baru minimal 10 karakter.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $error = 'Password baru harus mengandung huruf dan angka.';
        } elseif ($new !== $conf) {
            $error = 'Konfirmasi password tidak sama.';
        } elseif (hash_equals($cur, $new)) {
            $error = 'Password baru harus berbeda dari password saat ini.';
        }
        if ($error !== null) {
            $resp = $this->view('auth/password', ['title' => 'Ganti Password', 'forced' => !empty($u['must_change_password']), 'error' => $error], 'layouts/auth');
            $resp->status = 422;
            return $resp;
        }

        Db::tx(function () use ($u, $new) {
            Db::update('users', (int) $u['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0]);
            Audit::log('password_change', 'user', (string) $u['id'], 'Pengguna mengganti password sendiri');
        });
        Session::regenerate();
        Csrf::reset();
        $this->flash('success', 'Password berhasil diganti.');
        return $this->redirect('/');
    }
}
