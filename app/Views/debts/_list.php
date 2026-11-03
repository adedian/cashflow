<?php /** @var array $f @var array $res @var array $base @var array $proofs @var bool $isAdmin @var string $today */
$rows = $res['rows'];
$tot = $res['tot'];
$link = static fn(array $over) => url('/debts') . '?' . qs_merge($base, $over);
$loan = \App\Services\DebtService::isLoan($f['category']);   // Hutang Direksi: Lama pinjaman & Rekening penerima menggantikan Jatuh tempo & Rekening sumber
$accLbl = $loan ? 'Rekening penerima' : 'Rekening sumber';
$stPill = static function (array $r) use ($loan): string {
    $m = ['lunas' => ['pill-green', 'Lunas'], 'sebagian' => ['pill-yellow', 'Dibayar Sebagian'], 'belum' => ['pill-red', 'Belum Dibayar']][$r['status']];
    $o = '<div class="st-wrap"><span class="pill ' . $m[0] . '">' . $m[1] . '</span>';
    if ($loan) {
        // pinjaman direksi tidak memakai jatuh tempo
    } elseif ($r['due'] && $r['due']['status'] === 'late') {
        $o .= '<div class="st-extra"><span class="pill pill-red">Terlambat</span></div>';
    } elseif ($r['due'] && $r['due']['status'] === 'today') {
        $o .= '<div class="st-extra"><span class="pill pill-yellow">Jatuh tempo hari ini</span></div>';
    }
    return $o . '</div>';
};
?>
<div class="grid g-4 mb-2">
  <div class="card stat"><div class="k">Total hutang</div><div class="v num"><?= e(money($tot['a'])) ?></div><div class="d"><?= number_format($res['total'], 0, ',', '.') ?> hutang pada filter ini</div></div>
  <div class="card stat"><div class="k">Sudah dibayar</div><div class="v num pos"><?= e(money($tot['p'])) ?></div></div>
  <div class="card stat"><div class="k">Sisa hutang</div><div class="v num neg"><?= e(money($tot['o'])) ?></div></div>
  <?php if ($loan): ?>
  <div class="card stat"><div class="k">Pinjaman terlama</div><div class="v num"><?= $tot['longest'] !== null ? (int) $tot['longest'] . ' hari' : '-' ?></div><div class="d">pinjaman yang belum lunas, dihitung sampai hari ini</div></div>
  <?php else: ?>
  <div class="card stat"><div class="k">Terlambat</div><div class="v num <?= (int) $tot['late_n'] > 0 ? 'neg' : '' ?>"><?= e(money($tot['late_amt'])) ?></div><div class="d"><?= (int) $tot['late_n'] ?> hutang melewati jatuh tempo</div></div>
  <?php endif; ?>
</div>
<div class="card card-flush">
<?php if (!$rows): ?>
  <div class="empty"><svg class="i"><use href="#i-file"/></svg><p>Belum ada <?= e(mb_strtolower(\App\Services\DebtService::CATEGORIES[$f['category']] ?? 'hutang')) ?> yang cocok dengan filter.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="tbl stack debts">
      <thead><tr><th><?= $loan ? 'Lama pinjaman' : 'Jatuh tempo' ?></th><th><?= $loan ? 'Direksi' : 'Supplier' ?> / keterangan</th><th class="c-acc"><?= $accLbl ?></th><th class="r">Nominal</th><th class="r c-paid">Dibayar</th><th class="r">Sisa</th><th>Status</th><th class="c-link">Terhubung</th><th class="c-act"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $href = url('/debts/' . (int) $r['id']); ?>
        <tr class="<?= $r['voided_at'] !== null ? 'voided' : '' ?>" data-href="<?= e($href) ?>">
          <?php if ($loan): ?>
          <td data-label="Lama pinjaman" class="nowrap"><?php if ($r['loan_days'] !== null): ?><b><?= (int) $r['loan_days'] ?> hari</b><?php elseif ($r['debt_date']): ?><span class="muted">Tgl. pelunasan tidak diketahui</span><?php else: ?><span class="muted">Tgl. setoran belum diisi</span><?php endif; ?>
            <?php if ($r['debt_date']): ?><div class="sub">sejak <?= e(fmt_date($r['debt_date'])) ?><?= $r['status'] === 'lunas' && $r['last_pay'] ? ' · lunas ' . e(fmt_date($r['last_pay'])) : '' ?></div><?php endif; ?></td>
          <?php else: ?>
          <td data-label="Jatuh tempo" class="nowrap"><?= $r['due_date'] ? e(fmt_date($r['due_date'])) : '<span class="muted">Belum ditentukan</span>' ?><?php if ($r['due'] && $r['due']['status'] !== 'late'): ?><div class="sub"><?= e($r['due']['rel']) ?></div><?php elseif ($r['due']): ?><div class="sub neg"><?= e($r['due']['rel']) ?></div><?php endif; ?></td>
          <?php endif; ?>
          <td data-label="<?= $loan ? 'Direksi' : 'Supplier' ?>" class="desc"><a href="<?= e($href) ?>" style="color:inherit;font-weight:600"><?= e($r['supplier']) ?></a>
            <div class="sub"><?= e($r['description'] ?? '') ?><?= $r['ref_no'] ? ' · ' . e($r['ref_no']) : '' ?><span class="d-acc"><?= $r['source_account'] ? ' · ' . e($r['source_account']) : '' ?></span></div></td>
          <td data-label="<?= $accLbl ?>" class="c-acc"><?= e($r['source_account'] ?? '') ?></td>
          <td data-label="Nominal" class="r num"><?= e(money($r['amount'], false)) ?></td>
          <td data-label="Dibayar" class="r num c-paid"><?= e(money($r['paid'], false)) ?></td>
          <td data-label="Sisa" class="r num <?= bccomp($r['remaining'], '0', 2) > 0 ? 'neg' : '' ?>"><?= e(money($r['remaining'], false)) ?></td>
          <td data-label="Status" class="nowrap"><?= $r['voided_at'] !== null ? '<span class="pill pill-gray">Dibatalkan</span>' : $stPill($r) ?></td>
          <td data-label="Terhubung" class="c-link"><?= bank_pills($proofs[(int) $r['id']] ?? null, $href, 'Mutasi Keluar') ?></td>
          <td class="nowrap no-print c-act"><?php if ($isAdmin && $r['voided_at'] === null && bccomp($r['remaining'], '0', 2) > 0): ?><a class="btn btn-sm" href="<?= e(url('/transactions/new?ref=debt:' . (int) $r['id'])) ?>" title="Catat pembayaran lewat menu Transaksi (Mutasi Keluar)">Bayar</a> <?php endif; ?><a class="btn btn-ghost btn-sm" href="<?= e($href) ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($res['pages'] > 1): $page = $res['page']; $pages = $res['pages']; ?>
  <div class="pager no-print">
    <span class="muted">Halaman <?= (int) $page ?> dari <?= (int) $pages ?></span>
    <div class="pages">
      <?php if ($page > 1): ?><a href="<?= e($link(['page' => $page - 1])) ?>">‹</a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
        <?php if ($p === $page): ?><span class="pg cur"><?= $p ?></span><?php else: ?><a href="<?= e($link(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($link(['page' => $page + 1])) ?>">›</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php if ($loan): ?>
<p class="help">Lama pinjaman = jumlah hari sejak tanggal setoran sampai hari ini (setoran 1 Nov, dibuka 4 Nov = 3 hari) dan bertambah otomatis setiap hari; bila sudah lunas, berhenti di tanggal pelunasan. Rekening penerima = rekening perusahaan yang menerima setoran direksi (hanya informasi). Sisa = nominal − total pembayaran yang dicatat lewat menu Transaksi (Mutasi Keluar). Hutang tidak memengaruhi saldo, laporan, atau proyeksi.</p>
<?php else: ?>
<p class="help">Sisa = nominal − total pembayaran yang dicatat lewat menu Transaksi (Mutasi Keluar; dihitung server). Pembayaran sebagian tidak dianggap lunas. “Terlambat” = masih ada sisa dan lewat tanggal jatuh tempo; hutang tanpa tanggal jatuh tempo tidak pernah ditandai terlambat. Hutang tidak memengaruhi saldo, laporan, atau proyeksi.</p>
<?php endif; ?>
