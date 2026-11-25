<?php /** @var array $rows @var array $tot @var string $q @var string $status @var string $kind @var bool $isAdmin @var int $page @var bool $unissued @var string $issue */ ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/invoices')) ?>" id="invFilter">
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <?php if ($unissued): ?><input type="hidden" name="issue" value="<?= e($issue) ?>"><?php endif; ?>
    <div class="field grow"><label for="q">Cari</label><input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= $kind === 'lampu' ? 'Customer, no. surat jalan, kode bukti, info…' : 'Customer, proyek, no. invoice…' ?>" maxlength="100"></div>
    <?php if (!$unissued): ?>
    <div class="field"><label for="status">Status</label>
      <select class="input" id="status" name="status">
        <option value="">Semua</option>
        <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Masih ada sisa</option>
        <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Lunas</option>
        <option value="late" <?= $status === 'late' ? 'selected' : '' ?>>Lewat jatuh tempo</option>
        <?php if ($kind === 'lampu'): ?><option value="unknown" <?= $status === 'unknown' ? 'selected' : '' ?>>Perlu konfirmasi</option><?php endif; ?>
      </select></div>
    <?php endif; ?>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px">
      <button class="btn" type="submit" data-no-lock>Terapkan</button>
      <a class="btn btn-ghost" href="<?= e(url('/invoices?kind=' . $kind . ($unissued ? '&issue=' . $issue : ''))) ?>">Reset</a>
    </div>
  </form>
</section>
<section id="invResults" data-query="<?= e(qs_merge(['kind' => $kind, 'issue' => $issue !== '' ? $issue : null, 'q' => $q, 'status' => $status, 'page' => $page > 1 ? $page : null])) ?>">
  <?= \App\Core\View::partial($unissued ? 'invoices/_unissued' : 'invoices/_list', get_defined_vars()) ?>
</section>
