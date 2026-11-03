<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Page;
use App\Core\Response;
use App\Services\Audit;

/** Manajemen pengguna (hanya Super Admin). Tidak ada registrasi publik. Password sementara hanya ditampilkan sekali. */
final class UserController extends Controller
{
    /** Password default = username + "123"; tidak dipaksa diganti (must_change_password = 0). */
    private function tempPassword(string $username): string
    {
        return $username . '123';
    }

    public function index(): Response
    {
        Page::set(['title' => 'Pengguna', 'subtitle' => 'Kelola akun dan hak akses (Viewer / Super Admin)']);
        $cred = $_SESSION['new_credentials'] ?? null;
        unset($_SESSION['new_credentials']);
        $users = Db::all('SELECT id, username, full_name, role, status, must_change_password, last_login_at, created_at FROM users ORDER BY role DESC, username');
        return $this->view('users/index', ['users' => $users, 'cred' => $cred, 'me' => (int) $this->user()['id']]);
    }

    public function create(): Response
    {
        $username = strtolower(trim($this->req->str('username')));
        $name = mb_substr(trim($this->req->str('full_name')), 0, 120);
        $role = $this->req->str('role');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username) || $name === '' || !in_array($role, ['viewer', 'super_admin'], true)) {
            $this->flash('error', 'Username 3–50 karakter (huruf kecil, angka, titik, garis bawah, strip), nama, dan peran wajib valid.');
            return $this->redirect('/users');
        }
        if (Db::val('SELECT id FROM users WHERE username = ?', [$username])) {
            $this->flash('error', 'Username sudah dipakai.');
            return $this->redirect('/users');
        }
        $pw = $this->tempPassword($username);
        Db::tx(function () use ($username, $name, $role, $pw) {
            $id = Db::insert('users', ['username' => $username, 'full_name' => $name, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'must_change_password' => 0]);
            Audit::log('user_create', 'user', (string) $id, "Buat akun $username ($role)", null, ['username' => $username, 'full_name' => $name, 'role' => $role]);
        });
        $_SESSION['new_credentials'] = ['username' => $username, 'password' => $pw];
        return $this->redirect('/users');
    }

    private function target(string $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = ?', [(int) $id]);
        if (!$u) {
            throw new HttpException('Pengguna tidak ditemukan.', 404);
        }
        return $u;
    }

    private function activeAdmins(int $excludeId): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active' AND id <> ?", [$excludeId]);
    }

    public function setRole(string $id): Response
    {
        $u = $this->target($id);
        $role = $this->req->str('role');
        if (!in_array($role, ['viewer', 'super_admin'], true)) {
            $this->flash('error', 'Peran tidak valid.');
            return $this->redirect('/users');
        }
        if ((int) $u['id'] === (int) $this->user()['id']) {
            $this->flash('error', 'Anda tidak dapat mengubah peran akun Anda sendiri.');
            return $this->redirect('/users');
        }
        if ($u['role'] === 'super_admin' && $role !== 'super_admin' && $this->activeAdmins((int) $u['id']) < 1) {
            $this->flash('error', 'Harus tersisa minimal satu Super Admin aktif.');
            return $this->redirect('/users');
        }
        Db::tx(function () use ($u, $role) {
            Db::update('users', (int) $u['id'], ['role' => $role]);
            Audit::log('user_role', 'user', (string) $u['id'], "Ubah peran {$u['username']}: {$u['role']} → $role", ['role' => $u['role']], ['role' => $role]);
        });
        $this->flash('success', 'Peran diperbarui (berlaku pada permintaan berikutnya).');
        return $this->redirect('/users');
    }

    public function setStatus(string $id): Response
    {
        $u = $this->target($id);
        $status = $this->req->str('status') === 'disabled' ? 'disabled' : 'active';
        if ((int) $u['id'] === (int) $this->user()['id']) {
            $this->flash('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
            return $this->redirect('/users');
        }
        if ($status === 'disabled' && $u['role'] === 'super_admin' && $this->activeAdmins((int) $u['id']) < 1) {
            $this->flash('error', 'Harus tersisa minimal satu Super Admin aktif.');
            return $this->redirect('/users');
        }
        Db::tx(function () use ($u, $status) {
            Db::update('users', (int) $u['id'], ['status' => $status]);
            Audit::log('user_status', 'user', (string) $u['id'], "Akun {$u['username']} → $status", ['status' => $u['status']], ['status' => $status]);
        });
        $this->flash('success', $status === 'active' ? 'Akun diaktifkan.' : 'Akun dinonaktifkan; sesi aktifnya otomatis berakhir.');
        return $this->redirect('/users');
    }

    public function resetPassword(string $id): Response
    {
        $u = $this->target($id);
        $pw = $this->tempPassword((string) $u['username']);
        Db::tx(function () use ($u, $pw) {
            Db::update('users', (int) $u['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 0]);
            Db::q('DELETE FROM login_attempts WHERE username = ?', [$u['username']]);
            Audit::log('user_password_reset', 'user', (string) $u['id'], "Atur ulang password {$u['username']}");
        });
        $_SESSION['new_credentials'] = ['username' => $u['username'], 'password' => $pw];
        return $this->redirect('/users');
    }
}
