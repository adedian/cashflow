<?php /** @var array $rows @var array $tot @var string $kind @var bool $isAdmin @var string $today @var int $page @var int $pages @var int $total @var array $links @var string $q @var string $status */
$lampu = $kind === 'lampu';
$pageLink = static fn(int $p) => url('/invoices') . '?' . qs_merge(['kind' => $kind, 'q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : null]);
$stPill = static function (array $r) use ($today): string {
    if ((int) $r['paid_known'] === 0) {
        return '<span class="pill pill-yellow" title="Pembayaran di Excel tidak terbaca (#REF!). Buka detail lalu isi pembayaran yang benar.">Perlu konfirmasi</span>';
    }
    $rem = bcsub((string) $r['amount'], (string) $r['paid'], 2);
    $paid = bccomp((string) $r['paid'], '0', 2) > 0;
    [$cls, $lbl] = bccomp($rem, '0.004', 3) <= 0 ? ['pill-green', 'Lunas'] : ($paid ? ['pill-yellow', 'Sebagian'] : ['pill-red', 'Belum dibayar']);
    $o = '<div class="st-wrap"><span class="pill ' . $cls . '">' . $lbl . '</span>';
    if ($lbl !== 'Lunas' && !empty($r['due_date']) && $r['due_date'] < $today) {
        $o .= '<span class="pill pill-red">Lewat tempo</span>';
    }
    return $o . '</div>';
};
?>
<div class="grid g-4 mb-2">
  <div class="card stat"><div class="k"><?= $lampu ? 'Total piutang' : 'Total tagihan' ?></div><div class="v num"><?= e(money($tot['a'])) ?></div><div class="d"><?= number_format($total, 0, ',', '.') ?> data pada filter ini</div></div>
  <div class="card stat"><div class="k">Sudah dibayar</div><div class="v num pos"><?= e(money($tot['p'])) ?></div></div>
  <div class="card stat"><div class="k">Sisa (pasti belum dibayar)</div><div class="v num neg"><?= e(money($tot['o'])) ?></div><div class="d"><?= (int) $tot['late_n'] ?> lewat jatuh tempo (<?= e(money($tot['late_a'])) ?>)</div></div>
  <?php if ((int) $tot['un'] > 0): ?>
  <div class="card stat"><div class="k">Perlu konfirmasi</div><div class="v num"><?= e(money($tot['ua'])) ?></div><div class="d"><?= (int) $tot['un'] ?> baris: pembayaran tak terbaca di Excel — tidak dihitung lunas maupun belum</div></div>
  <?php endif; ?>
</div>
<div class="card card-flush">
<?php if (!$rows): ?>
  <div class="empty"><svg class="i"><use href="#i-file"/></svg><p>Belum ada data yang cocok.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="tbl stack inv <?= $lampu ? 'inv-lampu' : '' ?>">
      <thead><tr>
        <th>Tanggal</th><th class="c-sj"><?= $lampu ? 'No. surat jalan' : 'No. invoice' ?></th><th><?= $lampu ? 'Customer' : 'Customer / proyek' ?></th>
        <?php if ($lampu): ?><th class="c-items">Uraian barang</th><?php endif; ?>
        <th class="r"><?= $lampu ? 'Total piutang' : 'Tagihan' ?></th>
        <?php if ($lampu): ?><th class="c-due">Jatuh tempo</th><?php endif; ?>
        <th class="r c-paid">Dibayar</th><th class="r">Sisa</th><th>Status</th><th class="c-link">Terhubung</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $known = (int) $r['paid_known'] === 1;
          $rem = bcsub((string) $r['amount'], (string) $r['paid'], 2);
          $href = url('/invoices/' . (int) $r['id']);
          $lk = $r['forecast_item_id'] ? ($links[(int) $r['forecast_item_id']] ?? null) : null;
      ?>
        <tr data-href="<?= e($href) ?>">
          <td data-label="Tanggal" class="nowrap"><?= e(fmt_date($r['invoice_date'])) ?></td>
          <td data-label="<?= $lampu ? 'No. SJ' : 'No. invoice' ?>" class="c-sj"><a href="<?= e($href) ?>" style="color:inherit"><?= e($r['invoice_no'] ?? '') ?: '<span class="muted">-</span>' ?></a></td>
          <td data-label="Customer" class="desc"><b><?= e($r['customer']) ?></b><div class="sub"><?= e($r['project_name'] ?? '') ?><?= $r['description'] ? ' · ' . e($r['description']) : '' ?><?= $r['salesperson'] ? ' · sales ' . e($r['salesperson']) : '' ?><?= $lampu && $r['note'] ? ' · ' . e($r['note']) : '' ?><span class="d-sj"><?= $r['invoice_no'] ? ' · ' . e($r['invoice_no']) : '' ?><?= $lampu && $r['due_date'] ? ' · tempo ' . e(fmt_date($r['due_date'])) : '' ?></span></div></td>
          <?php if ($lampu): $its = \App\Controllers\InvoiceController::itemsOf($r); ?>
          <td data-label="Uraian" class="c-items"><?php if (!$its): ?><span class="muted">-</span><?php else: foreach (array_slice($its, 0, 3) as $it): ?><div class="it"><?= e($it['name']) ?> <span class="muted nowrap">· <?= e(money($it['qty'], false)) ?> × <?= e(money($it['price'], false)) ?></span></div><?php endforeach; ?><?= count($its) > 3 ? '<div class="muted">+' . (count($its) - 3) . ' barang lain</div>' : '' ?><?php endif; ?></td>
          <?php endif; ?>
          <td data-label="<?= $lampu ? 'Total piutang' : 'Tagihan' ?>" class="r num"><?= e(money($r['amount'], false)) ?></td>
          <?php if ($lampu): ?><td data-label="Jatuh tempo" class="nowrap c-due"><?= $r['due_date'] ? e(fmt_date($r['due_date'])) : ($r['due_note'] ? e($r['due_note']) : '<span class="muted">-</span>') ?><?= $r['due_days'] !== null ? '<div class="sub">' . (int) $r['due_days'] . ' hari</div>' : '' ?></td><?php endif; ?>
          <td data-label="Dibayar" class="r num c-paid"><?= $known ? e(money($r['paid'], false)) : '<span class="muted">?</span>' ?><?= $lampu && $r['paid_date'] ? '<div class="sub">' . e(fmt_date($r['paid_date'])) . '</div>' : '' ?></td>
          <td data-label="Sisa" class="r num <?= $known && bccomp($rem, '0.004', 3) > 0 ? 'neg' : '' ?>"><?= $known ? e(money(bccomp($rem, '0', 2) > 0 ? $rem : '0', false)) : '<span class="muted">?</span>' ?></td>
          <td data-label="Status" class="nowrap"><?= $stPill($r) ?></td>
          <td data-label="Terhubung" class="c-link">
            <?php if ($lk): ?><a class="pill pill-blue" href="<?= e(url('/forecast/' . (int) $r['forecast_item_id'])) ?>" title="Item Proyeksi #<?= (int) $r['forecast_item_id'] ?>">Proyeksi</a><?php endif; ?>
            <?php if ($r['pay_tx_id']): ?><a class="pill pill-green" href="<?= e(url('/transactions/' . (int) $r['pay_tx_id'])) ?>" title="Transaksi Mutasi Masuk #<?= (int) $r['pay_tx_id'] ?>"><?= e($r['pay_ref'] ?: 'Transaksi') ?></a>
            <?php elseif ($r['pay_ref']): ?><span class="pill pill-gray" title="Kode bukti di Excel; belum cocok dengan transaksi di sistem"><?= e($r['pay_ref']) ?></span><?php endif; ?>
            <?= bank_pills($proofs[(int) $r['id']] ?? null, $href . ($lampu ? '#invPays' : '#invBank'), 'Mutasi Masuk') ?>
            <?php if (!empty($isAdmin) && $known && bccomp($rem, '0.004', 3) > 0): ?><a class="btn btn-sm no-print" href="<?= e(url('/transactions/new?ref=invoice:' . (int) $r['id'])) ?>" title="Catat penerimaan lewat menu Transaksi (Mutasi Masuk)">Terima</a><?php endif; ?>
          </td>
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
<p class="help">Sisa = tagihan − dibayar (dihitung server). <?php if ($lampu): ?>Data berasal dari sheet “Piutang Lampu”: bila kolom pembayarannya berisi galat <code>#REF!</code> di Excel, baris ditandai <b>Perlu konfirmasi</b> dan tidak dihitung lunas maupun belum dibayar sampai Super Admin mengisinya. Kode bukti (mis. BM 11/02) dicocokkan ke transaksi Mutasi Masuk bila tanggal & nominalnya sama.<?php else: ?>Data awal berasal dari rekap invoice di Excel; kolom “kurang” Excel tidak dipakai karena dapat memuat selisih pembulatan.<?php endif; ?> Klik baris untuk melihat detail dan keterhubungan dengan Proyeksi/Transaksi.</p>
