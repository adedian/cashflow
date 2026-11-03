<?php /** @var array $f @var array $res @var array $base @var bool $isAdmin @var string $today */
$st = ['' => 'Semua status', 'open' => 'Belum lunas', 'belum' => 'Belum dibayar', 'sebagian' => 'Dibayar sebagian', 'lunas' => 'Lunas', 'terlambat' => 'Terlambat', 'batal' => 'Dibatalkan'];
?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/debts')) ?>" id="debtFilter">
    <input type="hidden" name="category" value="<?= e($f['category']) ?>">
    <div class="field grow"><label for="q">Cari</label><input class="input" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Supplier, keterangan, nomor, rekening sumber…" maxlength="100"></div>
    <div class="field"><label for="status">Status</label>
      <select class="input" id="status" name="status">
        <?php foreach ($st as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <details class="collapse-m" style="flex:1 1 100%" <?= ($f['due_from'] || $f['due_to'] || $f['date_from'] || $f['date_to']) ? 'open' : '' ?>>
      <summary class="btn btn-ghost btn-sm">Filter tanggal</summary>
      <div class="filters" style="margin-top:12px">
        <div class="field"><label for="due_from">Jatuh tempo dari</label><input class="input" type="date" id="due_from" name="due_from" value="<?= e($f['due_from']) ?>"></div>
        <div class="field"><label for="due_to">Jatuh tempo sampai</label><input class="input" type="date" id="due_to" name="due_to" value="<?= e($f['due_to']) ?>"></div>
        <div class="field"><label for="date_from">Tanggal transaksi dari</label><input class="input" type="date" id="date_from" name="date_from" value="<?= e($f['date_from']) ?>"></div>
        <div class="field"><label for="date_to">Tanggal transaksi sampai</label><input class="input" type="date" id="date_to" name="date_to" value="<?= e($f['date_to']) ?>"></div>
      </div>
      <p class="help mb-0">Tanggal transaksi hanya terisi bila Excel/formulir memuatnya; hutang tanpa tanggal itu tidak muncul pada filter tanggal transaksi.</p>
    </details>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px">
      <button class="btn" type="submit" data-no-lock>Terapkan</button>
      <a class="btn btn-ghost" href="<?= e(url('/debts?category=' . $f['category'])) ?>">Reset</a>
    </div>
  </form>
</section>
<section id="debtResults" data-query="<?= e(qs_merge($base, ['page' => $res['page'] > 1 ? $res['page'] : null])) ?>">
  <?= \App\Core\View::partial('debts/_list', get_defined_vars()) ?>
</section>
