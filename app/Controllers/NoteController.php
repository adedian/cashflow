<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Page;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Filters;
use App\Services\Version;

/** Modul Catatan: terpisah dari transaksi; tidak pernah memengaruhi saldo/laporan. */
final class NoteController extends Controller
{
    private function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin';
    }

    private function listData(): array
    {
        $q = mb_substr($this->req->str('q'), 0, 100);
        $cat = $this->req->int('category');
        $from = Filters::date($this->req->str('from'));
        $to = Filters::date($this->req->str('to'));
        $imp = $this->req->str('important') === '1';
        $w = [];
        $p = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(n.title LIKE ? OR n.body LIKE ?)';
            array_push($p, $like, $like);
        }
        if ($cat > 0) { $w[] = 'n.category_id = ?'; $p[] = $cat; }
        if ($from) { $w[] = 'DATE(n.created_at) >= ?'; $p[] = $from; }
        if ($to) { $w[] = 'DATE(n.created_at) <= ?'; $p[] = $to; }
        if ($imp) { $w[] = 'n.is_important = 1'; }
        $notes = Db::all(
            'SELECT n.id, n.title, n.body, n.is_important, n.created_at, n.updated_at, c.name AS category_name, u.full_name AS author
             FROM notes n LEFT JOIN note_categories c ON c.id = n.category_id LEFT JOIN users u ON u.id = n.created_by' .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY n.is_important DESC, n.created_at DESC, n.id DESC LIMIT 200',
            $p
        );
        return ['notes' => $notes, 'isAdmin' => $this->isAdmin(), 'f' => ['q' => $q, 'category' => $cat, 'from' => $from, 'to' => $to, 'important' => $imp]];
    }

    public function index(): Response
    {
        $data = $this->listData();
        if ($this->req->str('partial') === '1') {
            $this->releaseSession();
            return Response::html(\App\Core\View::partial('notes/_list', $data));
        }
        $data['categories'] = Db::all('SELECT id, name FROM note_categories ORDER BY name');
        $actions = $this->isAdmin() ? '<button type="button" class="btn btn-orange" id="addNoteBtn"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah Catatan</button>' : '';
        Page::set(['title' => 'Catatan', 'subtitle' => 'Informasi internal & pengingat (tidak memengaruhi saldo)', 'actions' => $actions, 'scripts' => ['notes.js']]);
        return $this->view('notes/index', $data);
    }

    /** Detail catatan (JSON) untuk modal baca. */
    public function show(string $id): Response
    {
        $this->releaseSession();
        $n = Db::one(
            'SELECT n.id, n.title, n.body, n.is_important, n.category_id, n.created_at, n.updated_at, c.name AS category_name, u.full_name AS author
             FROM notes n LEFT JOIN note_categories c ON c.id = n.category_id LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?',
            [(int) $id]
        );
        if (!$n) {
            return $this->fail('Catatan tidak ditemukan (mungkin sudah dihapus).', 404);
        }
        return $this->ok(['note' => $n]);
    }

    /** @return array{0:array,1:array} */
    private function validate(): array
    {
        $title = mb_substr(trim($this->req->str('title')), 0, 200);
        $body = trim((string) $this->req->input('body', ''));
        $errors = [];
        if ($title === '') { $errors['title'] = 'Judul catatan wajib diisi.'; }
        if ($body === '') { $errors['body'] = 'Isi catatan wajib diisi.'; }
        if (mb_strlen($body) > 10000) { $errors['body'] = 'Isi catatan maksimal 10.000 karakter.'; }
        $catId = $this->req->int('category_id');
        $newCat = mb_substr(trim($this->req->str('category_new')), 0, 80);
        if ($catId > 0 && !Db::one('SELECT id FROM note_categories WHERE id = ?', [$catId])) {
            $errors['category_id'] = 'Kategori tidak valid.';
        }
        return [['title' => $title, 'body' => $body, 'category_id' => $catId > 0 ? $catId : null, 'category_new' => $newCat, 'is_important' => $this->req->bool('is_important') ? 1 : 0], $errors];
    }

    private function resolveCategory(array $d): ?int
    {
        if ($d['category_new'] !== '') {
            $row = Db::one('SELECT id FROM note_categories WHERE name = ?', [$d['category_new']]);
            return $row ? (int) $row['id'] : Db::insert('note_categories', ['name' => $d['category_new']]);
        }
        return $d['category_id'];
    }

    public function store(): Response
    {
        [$d, $err] = $this->validate();
        if ($err) {
            return $this->fail('Periksa isian catatan.', 422, ['fields' => $err]);
        }
        $id = Db::tx(function () use ($d) {
            $cat = $this->resolveCategory($d);
            $id = Db::insert('notes', ['title' => $d['title'], 'body' => $d['body'], 'category_id' => $cat, 'is_important' => $d['is_important'], 'created_by' => $this->user()['id']]);
            Audit::log('note_create', 'note', (string) $id, 'Tambah catatan: ' . mb_substr($d['title'], 0, 120), null, ['title' => $d['title'], 'category_id' => $cat, 'is_important' => $d['is_important']]);
            Version::bump('notes');
            return $id;
        });
        return $this->ok(['id' => (int) $id]);
    }

    public function update(string $id): Response
    {
        [$d, $err] = $this->validate();
        if ($err) {
            return $this->fail('Periksa isian catatan.', 422, ['fields' => $err]);
        }
        $old = Db::one('SELECT * FROM notes WHERE id = ?', [(int) $id]);
        if (!$old) {
            return $this->fail('Catatan tidak ditemukan (mungkin sudah dihapus).', 404);
        }
        Db::tx(function () use ($id, $d, $old) {
            $cat = $this->resolveCategory($d);
            Db::update('notes', (int) $id, ['title' => $d['title'], 'body' => $d['body'], 'category_id' => $cat, 'is_important' => $d['is_important']]);
            Audit::log('note_update', 'note', (string) $id, 'Ubah catatan: ' . mb_substr($d['title'], 0, 120), ['title' => $old['title'], 'body' => $old['body'], 'category_id' => $old['category_id'], 'is_important' => $old['is_important']], ['title' => $d['title'], 'body' => $d['body'], 'category_id' => $cat, 'is_important' => $d['is_important']]);
            Version::bump('notes');
        });
        return $this->ok(['id' => (int) $id]);
    }

    public function delete(string $id): Response
    {
        $old = Db::one('SELECT * FROM notes WHERE id = ?', [(int) $id]);
        if (!$old) {
            return $this->fail('Catatan tidak ditemukan (mungkin sudah dihapus).', 404);
        }
        Db::tx(function () use ($id, $old) {
            Db::q('DELETE FROM notes WHERE id = ?', [(int) $id]);
            Audit::log('note_delete', 'note', (string) $id, 'Hapus catatan: ' . mb_substr($old['title'], 0, 120), ['title' => $old['title'], 'body' => $old['body'], 'category_id' => $old['category_id'], 'is_important' => $old['is_important']], null);
            Version::bump('notes');
        });
        return $this->ok();
    }
}
