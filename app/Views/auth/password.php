<section class="login-card" style="min-height:0;width:min(520px,100%)">
  <div class="login-right" style="width:100%">
    <h1 style="font-size:28px">Ganti password</h1>
    <p class="sub"><?= !empty($forced) ? 'Password sementara harus diganti sebelum Anda dapat memakai aplikasi.' : 'Perbarui password akun Anda.' ?></p>
    <?php if (!empty($error)): ?><div class="alert alert-danger mb-2" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/password')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="uline"><input type="password" name="current_password" placeholder="Password saat ini" autocomplete="current-password" required aria-label="Password saat ini"><span class="ico"><svg class="i"><use href="#i-lock"/></svg></span></div>
      <div class="uline"><input type="password" name="new_password" placeholder="Password baru (min. 10 karakter, huruf + angka)" autocomplete="new-password" required minlength="10" aria-label="Password baru"><span class="ico"><svg class="i"><use href="#i-lock"/></svg></span></div>
      <div class="uline"><input type="password" name="password_confirm" placeholder="Ulangi password baru" autocomplete="new-password" required aria-label="Ulangi password baru"><span class="ico"><svg class="i"><use href="#i-lock"/></svg></span></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn" type="submit">Simpan password</button>
        <?php if (empty($forced)): ?><a class="btn btn-ghost" href="<?= e(url('/')) ?>">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
</section>
