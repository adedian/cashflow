<?php /** Tab "Invoice Belum Terbit" (Piutang Proyek yang belum diterbitkan). @var array $rows @var array $tot @var bool $isAdmin @var int $page @var int $pages @var int $total @var string $q @var ?array $ub */
$pageLink = static fn(int $p) => url('/invoices') . '?' . qs_merge(['kind' => 'proyek', 'issue' => 'belum_terbit', 'q' => $q, 'page' => $p > 1 ? $p : null]);
?>
<div class="grid g-4 mb-2">
  <div class="card stat"><div class="k">Invoice Belum Terbit</div><div class="v num"><?= e(money($tot['a'])) ?></div><div class="d"><?= number_format($total, 0, ',', '.') ?> invoice<?= $q !== '' ? ' pada filter ini' : ' belum diterbitkan' ?></div></div>
</div>
<div class="card card-flush">
<?php if (!$rows): ?>
  <div class="empty"><svg class="i"><use href="#i-file"/></svg><p><?= $q !== '' ? 'Belum ada data yang cocok.' : 'Tidak ada invoice yang belum terbit.' ?></p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="tbl stack inv" id="ubTable">
      <thead><tr>
        <th>No. referensi</th><th>Customer / proyek</th><th>Keterangan</th><th>Tanggal</th><th class="r">Nominal</th><th>Status</th><?php if ($isAdmin): ?><th class="no-print"></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $href = url('/invoices/' . (int) $r['id']); ?>
        <tr data-href="<?= e($href) ?>">
          <td data-label="No. referensi" class="c-sj"><a href="<?= e($href) ?>" style="color:inherit"><?= $r['invoice_no'] ? e($r['invoice_no']) : '<span class="muted">#' . (int) $r['id'] . '</span>' ?></a></td>
          <td data-label="Customer" class="desc"><b><?= e($r['customer']) ?></b><?php if ($r['project_name']): ?><div class="sub"><?= e($r['project_name']) ?></div><?php endif; ?></td>
          <td data-label="Keterangan"><?= $r['description'] ? e($r['description']) : '<span class="muted">-</span>' ?><?= $r['note'] ? '<div class="sub">' . e($r['note']) . '</div>' : '' ?></td>
          <td data-label="Tanggal" class="nowrap"><?= $r['invoice_date'] ? e(fmt_date($r['invoice_date'])) : '<span class="muted">-</span>' ?></td>
          <td data-label="Nominal" class="r num"><?= e(money($r['amount'], false)) ?></td>
          <td data-label="Status" class="nowrap"><span class="pill pill-yellow" title="Belum diterbitkan: bukan piutang, tanpa jatuh tempo, tanpa pengingat">Belum Terbit</span></td>
          <?php if ($isAdmin): ?><td class="nowrap no-print"><a class="btn btn-sm" href="<?= e(url('/invoices/' . (int) $r['id'] . '/issue')) ?>">Terbitkan Invoice</a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pager no-print">
    <span class="muted">Halaman <?= (int) $page ?> dari <?= (int) $pages ?></span>
    <div class="pages">
      <?php if ($page > 1): ?><a href="<?= e($pageLink($page - 1)) ?>">‹</a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
        <?php if ($p === $page): ?><span class="pg cur"><?= $p ?></span><?php else: ?><a href="<?= e($pageLink($p)) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($pageLink($page + 1)) ?>">›</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>
<p class="help">Invoice belum terbit <b>bukan piutang</b>: tidak dihitung di Total tagihan, tidak punya jatuh tempo, dan tidak membuat pengingat atau item Proyeksi. Setelah dipilih <b>Terbitkan Invoice</b> (lengkapi No. invoice dan tgl. jatuh tempo), baris yang sama berpindah ke daftar Piutang Proyek.</p>
