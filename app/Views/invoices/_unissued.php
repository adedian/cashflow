<?php /** Tab "Invoice Belum Terbit" (rencana tagihan per project yang belum diterbitkan, penerbitan bertahap). @var array $rows @var array $tot @var bool $isAdmin @var int $page @var int $pages @var int $total @var string $q @var string $status */
$pageLink = static fn(int $p) => url('/invoices') . '?' . qs_merge(['kind' => 'proyek', 'issue' => 'belum_terbit', 'q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : null]);
?>
<div class="grid g-4 mb-2">
  <div class="card stat"><div class="k">Sisa Invoice Belum Terbit</div><div class="v num"><?= e(money($tot['r'])) ?></div><div class="d"><?= number_format($total, 0, ',', '.') ?> pencatatan<?= $q !== '' || $status !== '' ? ' pada filter ini' : '' ?> · nominal awal <?= e(money($tot['a'])) ?></div></div>
</div>
<div class="card card-flush">
<?php if (!$rows): ?>
  <div class="empty"><svg class="i"><use href="#i-file"/></svg><p><?= $q !== '' || $status !== '' ? 'Belum ada data yang cocok.' : 'Tidak ada invoice yang belum terbit.' ?></p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="tbl stack inv" id="ubTable">
      <thead><tr>
        <th>No. referensi</th><th>Project / customer</th><th>Keterangan</th><th>Estimasi jatuh tempo</th><th class="r">Nominal awal</th><th class="r">Diterbitkan</th><th class="r">Sisa</th><th>Status</th><?php if ($isAdmin): ?><th class="no-print"></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $href = url('/invoices/' . (int) $r['id']); $done = $r['issue_status'] === 'selesai'; $part = !$done && bccomp((string) $r['issued'], '0', 2) > 0; ?>
        <tr data-href="<?= e($href) ?>">
          <td data-label="No. referensi" class="c-sj"><a href="<?= e($href) ?>" style="color:inherit"><?= $r['invoice_no'] ? e($r['invoice_no']) : '<span class="muted">#' . (int) $r['id'] . '</span>' ?></a></td>
          <td data-label="Project" class="desc"><b><?= $r['project'] ? e($r['project']) : '<span class="muted">(tanpa project)</span>' ?></b><div class="sub"><?= e($r['customer']) ?></div></td>
          <td data-label="Keterangan"><?= $r['description'] ? e($r['description']) : '<span class="muted">-</span>' ?><?= $r['note'] ? '<div class="sub">' . e($r['note']) . '</div>' : '' ?></td>
          <td data-label="Estimasi jatuh tempo" class="nowrap"><?= $r['invoice_date'] ? e(fmt_date($r['invoice_date'])) : '<span class="muted">-</span>' ?></td>
          <td data-label="Nominal awal" class="r num"><?= e(money($r['amount'], false)) ?></td>
          <td data-label="Diterbitkan" class="r num"><?= e(money($r['issued'], false)) ?></td>
          <td data-label="Sisa" class="r num <?= $done ? '' : 'neg' ?>"><b><?= e(money($r['remaining'], false)) ?></b></td>
          <td data-label="Status" class="nowrap"><?= $done ? '<span class="pill pill-green" title="Seluruh nominal sudah diterbitkan menjadi invoice">Diterbitkan Penuh</span>' : '<span class="pill pill-yellow" title="Belum diterbitkan: bukan piutang, tanpa jatuh tempo, tanpa pengingat">' . ($part ? 'Terbit Sebagian' : 'Belum Terbit') . '</span>' ?></td>
          <?php if ($isAdmin): ?><td class="nowrap no-print"><?php if (!$done): ?><a class="btn btn-sm" href="<?= e(url('/invoices/' . (int) $r['id'] . '/issue')) ?>">Terbitkan Invoice</a><?php endif; ?></td><?php endif; ?>
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
<p class="help">Invoice belum terbit <b>bukan piutang</b>: tidak dihitung di Total tagihan, tanpa jatuh tempo, tanpa pengingat/item Proyeksi. <b>Terbitkan Invoice</b> bisa bertahap per project: tiap penerbitan membuat invoice baru di daftar Piutang Proyek dan mengurangi sisa di sini (Sisa = nominal awal − sudah diterbitkan). Setelah sisa 0, pencatatan berstatus Diterbitkan Penuh dan tetap tersimpan sebagai histori (filter Status).</p>
