<?php
/** @var array $report @var string $type @var array $f @var array $base @var bool $canExport @var array $accounts @var string $company @var string $printedBy */
$accName = '';
foreach ($accounts as $a) {
    if ((int) $a['id'] === (int) ($f['account'] ?? 0)) {
        $accName = $a['name'];
    }
}
?>
<section class="card no-print">
  <form class="filters" id="rptFilter" method="get" action="<?= e(url('/reports')) ?>">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="field"><label for="from">Dari tanggal</label><input class="input" type="date" id="from" name="from" value="<?= e($f['from'] ?? '') ?>"></div>
    <div class="field"><label for="to">Sampai tanggal</label><input class="input" type="date" id="to" name="to" value="<?= e($f['to'] ?? '') ?>"></div>
    <div class="field"><label for="account">Rekening</label>
      <select class="input" id="account" name="account"><option value="">Semua rekening</option>
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) ($f['account'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px">
      <button class="btn" type="submit" data-no-lock>Tampilkan</button>
      <a class="btn btn-ghost" href="<?= e(url('/reports') . '?' . qs_merge(['type' => $type, 'period' => 'all'])) ?>">Semua data</a>
    </div>
  </form>
</section>

<section class="card card-flush" id="rptCard" data-query="<?= e(qs_merge($base, ['type' => $type])) ?>">
  <div class="card-head" style="padding:20px 22px 0">
    <div>
      <div class="muted" style="font-size:12.5px"><?= e($company) ?></div>
      <h2><?= e($report['title']) ?></h2>
      <div class="muted" style="font-size:12.5px">Periode: <?= $f['from'] ? e(fmt_date($f['from'])) . ' – ' . e(fmt_date($f['to'])) : 'seluruh data' ?><?= $accName ? ' · Rekening: ' . e($accName) : '' ?> · Disusun <?= e(date('d/m/Y H:i')) ?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap" class="no-print">
      <?= export_button('laporan', $base + ['type' => $type]) ?>
    </div>
  </div>
  <div id="rptBody" class="mt-1"><?= \App\Core\View::partial('reports/_table', ['report' => $report]) ?></div>
</section>
