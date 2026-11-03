<section class="login-card" style="min-height:0;width:min(560px,100%)">
  <div class="login-right" style="text-align:center;align-items:center">
    <h1 style="font-size:54px"><?= e($status) ?></h1>
    <h2 class="mb-1"><?= e($title) ?></h2>
    <p class="sub"><?= e($message) ?></p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center">
      <?php if (!empty($user)): ?>
        <a class="btn" href="<?= e(url('/')) ?>">Ke Dashboard</a>
      <?php else: ?>
        <a class="btn" href="<?= e(url('/login')) ?>">Ke halaman masuk</a>
      <?php endif; ?>
    </div>
  </div>
</section>
