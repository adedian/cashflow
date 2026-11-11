<?php
use App\Core\Page;

$isAdmin = ($user['role'] ?? '') === 'super_admin';
$cur = $path ?? '/';
$active = static function (string $prefix) use ($cur): string {
    if ($prefix === '/') {
        return $cur === '/' ? ' active' : '';
    }
    return (strncmp($cur, $prefix, strlen($prefix)) === 0) ? ' active' : '';
};
$nav = [
    ['/', 'Dashboard', 'home'],
    ['/reminders', 'Pengingat', 'bell'],
    ['/transactions', 'Transaksi', 'list'],
    ['/accounts', 'Rekening & Limit', 'card'],
    ['/invoices', 'Piutang', 'file'],
    ['/debts', 'Hutang', 'brief'],
    ['/forecast', 'Proyeksi', 'trend'],
    ['/reports', 'Laporan', 'chart'],
    ['/notes', 'Catatan', 'note'],
];
$adminNav = [
    ['/master', 'Master Data', 'tag'],
    ['/import', 'Impor Excel', 'up'],
    ['/audit', 'Audit Log', 'shield'],
    ['/users', 'Pengguna', 'users'],
    ['/settings', 'Pengaturan & Backup', 'settings'],
];
$icon = static fn(string $n, string $c = 'i') => '<svg class="' . $c . '" aria-hidden="true"><use href="#i-' . $n . '"/></svg>';
$title = (string) Page::get('title');
$initial = mb_strtoupper(mb_substr($user['full_name'] ?? '?', 0, 1));
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="app-base" content="<?= e(base_path()) ?>">
<meta name="user-role" content="<?= e($user['role'] ?? '') ?>">
<meta name="theme-color" content="#0b5cff">
<title><?= e($title !== '' ? $title . ' · ' : '') ?>Cashflow HME</title>
<link rel="icon" type="image/png" href="<?= e(asset('images/logo-hme.png')) ?>">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('images/apple-touch-icon.png')) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Cashflow HME">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?= \App\Core\View::partial('partials/icons') ?>

<aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
  <div class="sb-brand">
    <img src="<?= e(asset('images/logo-hme.png')) ?>" alt="HME">
    <div><b>Cashflow HME</b><small>PT Hexa Multi Energi</small></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-group">Menu</div>
    <?php foreach ($nav as [$href, $label, $ic]): ?>
      <a class="sb-link<?= $active($href) ?>" href="<?= e(url($href)) ?>" title="<?= e($label) ?>"><?= $icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <?php if ($isAdmin): ?>
      <div class="sb-group">Administrasi</div>
      <?php foreach ($adminNav as [$href, $label, $ic]): ?>
        <a class="sb-link<?= $active($href) ?>" href="<?= e(url($href)) ?>" title="<?= e($label) ?>"><?= $icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>
  <div class="sb-foot">
    <div class="sb-user">
      <div class="avatar"><?= e($initial) ?></div>
      <div class="who"><b><?= e($user['full_name'] ?? '') ?></b><small><?= $isAdmin ? 'Super Admin' : 'Viewer' ?></small></div>
    </div>
    <button class="sb-link" id="installBtn" type="button" hidden title="Pasang aplikasi" style="width:100%;border:0;background:transparent;cursor:pointer;font:inherit"><?= $icon('up') ?><span>Pasang aplikasi</span></button>
    <a class="sb-link" href="<?= e(url('/password')) ?>" title="Ganti password"><?= $icon('lock') ?><span>Ganti password</span></a>
    <form method="post" action="<?= e(url('/logout')) ?>">
      <?= csrf_field() ?>
      <button class="sb-link" style="width:100%;border:0;background:transparent;cursor:pointer;font:inherit" type="submit" title="Keluar"><?= $icon('logout') ?><span>Keluar</span></button>
    </form>
  </div>
</aside>
<div class="scrim" id="scrim"></div>

<div class="main">
  <header class="hero">
    <div class="topbar">
      <button class="icon-btn" id="menuBtn" type="button" aria-label="Menu"><?= $icon('menu') ?></button>
      <form class="search" action="<?= e(url('/transactions')) ?>" method="get" role="search">
        <?= $icon('search') ?>
        <input type="search" name="q" placeholder="Cari transaksi (uraian, kode, pihak)…" aria-label="Cari transaksi" maxlength="100">
      </form>
      <div class="top-spacer"></div>
      <span class="live-dot off" id="liveDot" title="Sinkronisasi data otomatis">Realtime</span>
      <div class="notif">
        <button class="icon-btn" id="notifBtn" type="button" aria-label="Pengingat pembayaran" aria-haspopup="true" aria-expanded="false" aria-controls="notifPanel"><?= $icon('bell') ?><span class="notif-badge" id="notifBadge" hidden>0</span></button>
        <div class="notif-panel" id="notifPanel" role="dialog" aria-label="Pengingat pembayaran" hidden>
          <div class="notif-head"><b>Pengingat Pembayaran</b><a href="<?= e(url('/reminders')) ?>">Lihat semua</a></div>
          <div class="notif-body" id="notifBody"><div class="skeleton" style="height:70px"></div></div>
        </div>
      </div>
      <div class="userchip">
        <div class="avatar"><?= e($initial) ?></div>
        <div class="meta"><b><?= e($user['full_name'] ?? '') ?></b><small><?= $isAdmin ? 'Super Admin' : 'Viewer' ?></small></div>
      </div>
    </div>
    <div class="page-head">
      <div>
        <h1><?= e($title) ?></h1>
        <?php if (Page::get('subtitle')): ?><p><?= e(Page::get('subtitle')) ?></p><?php endif; ?>
      </div>
      <?php if (Page::get('actions')): ?><div class="page-actions"><?= Page::get('actions') /* HTML tepercaya dari view */ ?></div><?php endif; ?>
    </div>
    <?php if (Page::get('tabs')): ?>
      <nav class="tabs" aria-label="Sub menu">
        <?php foreach (Page::get('tabs') as $t): ?>
          <a href="<?= e($t['href']) ?>" class="<?= !empty($t['active']) ? 'active' : '' ?>"><?= e($t['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </header>

  <main class="content" id="content">
    <?= $content ?>
  </main>
  <?= \App\Core\View::partial('partials/watermark') ?>
</div>

<nav class="bottomnav" aria-label="Navigasi cepat">
  <a href="<?= e(url('/')) ?>" class="<?= trim($active('/')) ?>"><?= $icon('home') ?>Dashboard</a>
  <a href="<?= e(url('/transactions')) ?>" class="<?= trim($active('/transactions')) ?>"><?= $icon('list') ?>Transaksi</a>
  <a href="<?= e(url('/reports')) ?>" class="<?= trim($active('/reports')) ?>"><?= $icon('chart') ?>Laporan</a>
  <a href="<?= e(url('/notes')) ?>" class="<?= trim($active('/notes')) ?>"><?= $icon('note') ?>Catatan</a>
  <button type="button" id="moreBtn"><?= $icon('menu') ?>Menu</button>
</nav>

<div id="toasts" aria-live="polite"></div>
<script id="flash-data" type="application/json"><?= json_encode($flash ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/pwa.js')) ?>"></script>
<script src="<?= e(asset('js/combo.js')) ?>"></script>
<script src="<?= e(asset('js/export.js')) ?>"></script>
<script src="<?= e(asset('js/realtime.js')) ?>"></script>
<script src="<?= e(asset('js/reminders.js')) ?>"></script>
<?php foreach ((array) Page::get('scripts') as $s): ?>
<script src="<?= e(asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
