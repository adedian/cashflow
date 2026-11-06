<?php
/** @var array $res @var array $f @var array $base @var int $page @var int $pages @var int $perPage @var bool $showBalance @var bool $canExport @var bool $isAdmin @var string $sort */
$rows = $res['rows'];
$link = static fn(array $over) => url('/transactions') . '?' . qs_merge($base, $over);
$sortLink = static function (string $asc, string $desc) use ($sort, $link): string {
    $next = $sort === $desc ? $asc : $desc;
    return $link(['sort' => $next === 'date_desc' ? null : $next, 'page' => null]);
};
$net = bcsub($res['sum_debit'], $res['sum_credit'], 2);
?>
<div class="card-head" style="padding:20px 22px 0">
  <div>
    <h2>Hasil</h2>
    <span class="muted" style="font-size:12.5px"><?= number_format($res['total'], 0, ',', '.') ?> transaksi · debit <b class="pos"><?= e(money($res['sum_debit'])) ?></b> · kredit <b class="neg"><?= e(money($res['sum_credit'])) ?></b> · selisih <b class="num"><?= e(money($net)) ?></b></span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center" class="no-print">
    <?php if (can_export()): ?>
    <span class="tx-selbar">
      <span class="sel-count" id="txSelCount" aria-live="polite"></span>
      <button type="button" class="sel-clear" id="txSelClear" hidden>Hapus pilihan</button>
      <button type="button" class="btn btn-ghost btn-sm" id="txPrintSel" data-export="bukti" data-export-title="Bukti Bank (BK/BM)" data-export-query="" disabled title="Centang transaksi pada tabel, lalu cetak bukti BK/BM-nya"><svg class="i i-sm"><use href="#i-print"/></svg>Cetak Detail</button>
    </span>
    <?php endif; ?>
    <?= export_button('transaksi', $base) ?>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="empty"><svg class="i"><use href="#i-list"/></svg><p>Tidak ada transaksi yang cocok dengan filter.</p></div>
<?php else: ?>
<div class="table-wrap mt-1">
  <table class="tbl stack tx">
    <thead>
      <tr>
        <?php if (can_export()): ?><th class="c-sel no-print"><input type="checkbox" id="txSelAll" aria-label="Pilih semua transaksi di halaman ini"></th><?php endif; ?>
        <th><a href="<?= e($sortLink('date_asc', 'date_desc')) ?>" style="color:inherit">Tgl <?= $sort === 'date_asc' ? '▲' : ($sort === 'date_desc' ? '▼' : '') ?></a></th>
        <th class="c-code">Nomor Bukti</th>
        <th class="c-party">Rekening Lawan</th>
        <th class="desc">Keterangan</th>
        <th class="proj">Project</th>
        <th class="cat">Kategori Biaya</th>
        <th class="c-inv">No. Invoice</th>
        <th class="c-note">Catatan</th>
        <th class="r c-deb"><a href="<?= e($sortLink('amount_asc', 'amount_desc')) ?>" style="color:inherit">Debit</a></th>
        <th class="r c-kre">Kredit</th>
        <?php if ($showBalance): ?><th class="r c-sal">Saldo Akhir</th><?php endif; ?>
        <th class="c-act"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $t):
        $void = $t['voided_at'] !== null;
        $inn = bccomp((string) $t['debit'], '0', 2) !== 0;
        $out = bccomp((string) $t['credit'], '0', 2) !== 0;
    ?>
      <tr class="<?= $void ? 'voided' : '' ?>">
        <?php if (can_export()): ?><td class="c-sel no-print" data-label="Pilih"><input type="checkbox" class="tx-sel" value="<?= (int) $t['id'] ?>"<?= $void ? ' disabled title="Transaksi dibatalkan tidak dicetak"' : '' ?> aria-label="Pilih transaksi <?= e($t['voucher_code'] ?: '#' . (int) $t['id']) ?>"></td><?php endif; ?>
        <td data-label="Tgl" class="nowrap"><?= e(fmt_date($t['tx_date'])) ?><div class="sub" title="<?= e($t['account_name']) ?>"><?= e($t['account_code']) ?></div></td>
        <td data-label="Nomor Bukti" class="nowrap c-code"><?= e($t['voucher_code'] ?? '') ?></td>
        <td data-label="Rekening Lawan" class="c-party">
          <?= e($t['counterparty'] ?? '') ?>
          <?php if ($isAdmin && !empty($t['counterparty_detail'])): ?><div class="sub"><?= e($t['counterparty_detail']) ?></div><?php endif; ?>
        </td>
        <td data-label="Keterangan" class="desc">
          <a href="<?= e(url('/transactions/' . (int) $t['id'])) ?>" style="color:inherit;font-weight:600"><?= e($t['description'] ?: '(tanpa keterangan)') ?></a>
          <?php if ($isAdmin && !empty($t['flag'])): ?><span class="pill pill-yellow" title="<?= e($t['flag']) ?>" style="margin-left:6px">Perlu ditinjau</span><?php endif; ?>
          <?php if ($void): ?><span class="pill pill-gray">Dibatalkan</span><?php endif; ?>
        </td>
        <td data-label="Project" class="proj"><?= e($t['project_name'] ?? '') ?></td>
        <td data-label="Kategori Biaya" class="cat"><?= e($t['category_name'] ?? 'Belum dikategorikan') ?></td>
        <td data-label="No. Invoice" class="c-inv"><?= e($t['ref_no'] ?? '') ?></td>
        <td data-label="Catatan" class="c-note"><?= e($t['note'] ?? '') ?></td>
        <td data-label="Debit" class="r num c-deb <?= $inn ? 'pos' : '' ?>"><?= $inn ? e(money($t['debit'], false)) : '' ?></td>
        <td data-label="Kredit" class="r num c-kre <?= $out ? 'neg' : '' ?>"><?= $out ? e(money($t['credit'], false)) : '' ?></td>
        <?php if ($showBalance): ?><td data-label="Saldo Akhir" class="r num c-sal"><?= $t['running'] !== null ? e(money($t['running'], false)) : '' ?></td><?php endif; ?>
        <td class="nowrap no-print c-act"><a class="btn btn-ghost btn-sm" href="<?= e(url('/transactions/' . (int) $t['id'])) ?>">Detail</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
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
