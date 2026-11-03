<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Page;
use App\Core\Response;
use App\Services\Filters;

/** Audit log (hanya Super Admin; hanya baca — pengguna DB aplikasi tidak punya UPDATE/DELETE pada tabel ini). */
final class AuditController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): Response
    {
        $q = mb_substr($this->req->str('q'), 0, 100);
        $action = mb_substr($this->req->str('action'), 0, 40);
        $user = mb_substr($this->req->str('user'), 0, 50);
        $from = Filters::date($this->req->str('from'));
        $to = Filters::date($this->req->str('to'));
        $page = max(1, $this->req->int('page', 1));
        $w = [];
        $p = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(summary LIKE ? OR entity_id LIKE ? OR ip LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if ($action !== '') { $w[] = 'action = ?'; $p[] = $action; }
        if ($user !== '') { $w[] = 'username = ?'; $p[] = $user; }
        if ($from) { $w[] = 'created_at >= ?'; $p[] = $from . ' 00:00:00'; }
        if ($to) { $w[] = 'created_at <= ?'; $p[] = $to . ' 23:59:59'; }
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $total = (int) Db::val('SELECT COUNT(*) FROM audit_logs' . $where, $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = Db::all('SELECT * FROM audit_logs' . $where . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p);
        $actions = array_column(Db::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
        $users = array_column(Db::all('SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL ORDER BY username'), 'username');
        Page::set(['title' => 'Audit Log', 'subtitle' => 'Jejak aktivitas penting — tidak dapat diubah atau dihapus dari aplikasi']);
        $base = array_filter(['q' => $q, 'action' => $action, 'user' => $user, 'from' => $from, 'to' => $to], static fn($v) => $v !== null && $v !== '');
        return $this->view('audit/index', compact('rows', 'total', 'page', 'pages', 'actions', 'users', 'base', 'q', 'action', 'user', 'from', 'to'));
    }
}
