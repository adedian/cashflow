<?php
/** @var array $report */
$cols = $report['columns'];
?>
<?php if (!$report['rows']): ?>
  <div class="empty"><svg class="i"><use href="#i-chart"/></svg><p>Tidak ada data pada periode ini.</p></div>
<?php else: ?>
<div class="table-wrap">
  <table class="tbl">
    <thead><tr><?php foreach ($cols as $k => [$label, $kind]): ?><th class="<?= in_array($kind, ['money', 'int'], true) ? 'r' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($report['rows'] as $row): ?>
      <tr>
        <?php foreach ($cols as $k => [$label, $kind]): $v = $row[$k] ?? null; ?>
          <?php if ($kind === 'money'): ?>
            <td class="r num <?= ($v !== null && $v !== '' && bccomp((string) $v, '0', 2) < 0) ? 'neg' : '' ?>"><?= $v === null || $v === '' ? '' : e(money($v, false)) ?></td>
          <?php elseif ($kind === 'int'): ?>
            <td class="r num"><?= $v === null ? '' : e(number_format((int) $v, 0, ',', '.')) ?></td>
          <?php elseif ($kind === 'date'): ?>
            <td class="nowrap"><?= e(fmt_date((string) $v)) ?></td>
          <?php else: ?>
            <td><?= e($v) ?></td>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if (!empty($report['totals'])): ?>
    <tfoot><tr>
      <?php $first = true; foreach ($cols as $k => [$label, $kind]): ?>
        <td class="<?= in_array($kind, ['money', 'int'], true) ? 'r num' : '' ?>"><?php
            if ($first) { echo 'TOTAL'; $first = false; }
            elseif (isset($report['totals'][$k])) { echo $kind === 'int' ? e(number_format((int) $report['totals'][$k], 0, ',', '.')) : e(money($report['totals'][$k], false)); }
        ?></td>
      <?php endforeach; ?>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</div>
<?php endif; ?>
<?php if (!empty($report['note'])): ?><p class="help" style="padding:12px 22px 4px;margin:0"><?= e($report['note']) ?></p><?php endif; ?>
