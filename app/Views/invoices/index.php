<?php /** @var array $rows @var array $tot @var string $q @var string $status @var string $kind @var bool $isAdmin @var int $page */ ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/invoices')) ?>" id="invFilter">
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div class="field grow"><label for="q">Cari</label><input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= $kind === 'lampu' ? 'Customer, no. surat jalan, kode bukti, info…' : 'Customer, proyek, no. invoice…' ?>" maxlength="100"></div>
    <div class="field"><label for="status">Status</label>
      <select class="input" id="status" name="status">
        <option value="">Semua</option>
        <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Masih ada sisa</option>
        <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Lunas</option>
        <option value="late" <?= $status === 'late' ? 'selected' : '' ?>>Lewat jatuh tempo</option>
        <?php if ($kind === 'lampu'): ?><option value="unknown" <?= $status === 'unknown' ? 'selected' : '' ?>>Perlu konfirmasi</option><?php endif; ?>
      </select></div>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px">
      <button class="btn" type="submit" data-no-lock>Terapkan</button>
      <a class="btn btn-ghost" href="<?= e(url('/invoices?kind=' . $kind)) ?>">Reset</a>
    </div>
  </form>
</section>
<section id="invResults" data-query="<?= e(qs_merge(['kind' => $kind, 'q' => $q, 'status' => $status, 'page' => $page > 1 ? $page : null])) ?>">
  <?= \App\Core\View::partial('invoices/_list', get_defined_vars()) ?>
</section>
