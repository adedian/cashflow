<?php /** @var array $users @var ?array $cred @var int $me */ ?>
<?php if ($cred): ?>
<section class="alert alert-ok" role="alert">
  <b>Password sementara untuk <?= e($cred['username']) ?>:</b>
  <code style="font-size:16px;margin-left:8px;user-select:all"><?= e($cred['password']) ?></code>
  <div class="help mt-1">Catat dan sampaikan secara aman sekarang — password ini <b>tidak ditampilkan lagi</b>. Pengguna dapat menggantinya kapan saja lewat menu Ganti password.</div>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>Tambah pengguna</h2></div>
  <form method="post" action="<?= e(url('/users')) ?>" class="filters">
    <?= csrf_field() ?>
    <div class="field"><label for="un">Username</label><input class="input" id="un" name="username" maxlength="50" required autocomplete="off" placeholder="huruf kecil, angka, . _ -"></div>
    <div class="field grow"><label for="fn">Nama lengkap</label><input class="input" id="fn" name="full_name" maxlength="120" required></div>
    <div class="field"><label for="rl">Peran</label><select class="input" id="rl" name="role"><option value="viewer">Viewer (hanya lihat)</option><option value="super_admin">Super Admin</option></select></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit">Buat akun</button></div>
  </form>
</section>

<section class="card card-flush">
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Login terakhir</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = (int) $u['id'] === $me; ?>
      <tr>
        <td data-label="Pengguna"><b><?= e($u['full_name']) ?></b><div class="sub"><?= e($u['username']) ?><?= $self ? ' · (Anda)' : '' ?><?= $u['must_change_password'] ? ' · wajib ganti password' : '' ?></div></td>
        <td data-label="Peran"><span class="pill <?= $u['role'] === 'super_admin' ? 'pill-yellow' : 'pill-blue' ?>"><?= $u['role'] === 'super_admin' ? 'Super Admin' : 'Viewer' ?></span></td>
        <td data-label="Status"><span class="pill <?= $u['status'] === 'active' ? 'pill-green' : 'pill-gray' ?>"><?= $u['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td>
        <td data-label="Login terakhir" class="nowrap"><?= $u['last_login_at'] ? e(fmt_date($u['last_login_at'], 'd/m/Y H:i')) : 'belum pernah' ?></td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
            <?php if (!$self): ?>
            <form method="post" action="<?= e(url('/users/' . (int) $u['id'] . '/role')) ?>" data-confirm="Ubah peran <?= e($u['username']) ?> menjadi <?= $u['role'] === 'super_admin' ? 'Viewer' : 'Super Admin' ?>?" data-confirm-ok="Ya, ubah">
              <?= csrf_field() ?><input type="hidden" name="role" value="<?= $u['role'] === 'super_admin' ? 'viewer' : 'super_admin' ?>"><button class="btn btn-ghost btn-sm" type="submit" data-no-lock>Jadikan <?= $u['role'] === 'super_admin' ? 'Viewer' : 'Super Admin' ?></button></form>
            <form method="post" action="<?= e(url('/users/' . (int) $u['id'] . '/status')) ?>" data-confirm="<?= $u['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?> akun <?= e($u['username']) ?>?" data-confirm-ok="Ya">
              <?= csrf_field() ?><input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'disabled' : 'active' ?>"><button class="btn btn-ghost btn-sm" type="submit" data-no-lock><?= $u['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/users/' . (int) $u['id'] . '/reset')) ?>" data-confirm="Atur ulang password <?= e($u['username']) ?>? Password sementara baru akan dibuat." data-confirm-ok="Ya, atur ulang">
              <?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" data-no-lock>Atur ulang password</button></form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
