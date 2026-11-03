<?php /** @var array $accounts */ ?>
<?php if (!$accounts): ?>
  <div class="card"><div class="empty"><svg class="i"><use href="#i-card"/></svg><p>Belum ada rekening.</p></div></div>
<?php endif; ?>
<?php foreach ($accounts as $a):
    $isLoan = $a['balance_mode'] === 'liability';
    $neg = !$isLoan && bccomp($a['balance'], '0', 2) < 0;
    $pct = isset($a['used_pct']) ? max(0, min(100, (float) $a['used_pct'])) : null;
    $cls = $pct !== null ? ($pct >= 90 ? 'danger' : ($pct >= 70 ? 'warn' : '')) : '';
?>
<section class="card">
  <div class="card-head">
    <div>
      <h2><?= e($a['name']) ?> <?php if (!$a['is_active']): ?><span class="pill pill-gray">Nonaktif</span><?php endif; ?></h2>
      <span class="muted" style="font-size:12.5px"><?= $isLoan ? 'Pinjaman (pemakaian = penarikan − pembayaran)' : ($a['kind'] === 'facility' ? 'Rekening fasilitas (saldo negatif = pemakaian limit)' : 'Rekening bank operasional') ?>
        <?php $fullNo = account_number_visible($a); ?>
        <?= $a['bank_name'] ? ' · ' . e($a['bank_name']) : '' ?><?= $fullNo !== null ? ' · ' . e(format_account_no($fullNo)) : ($a['acct_hint'] ? ' · **** ' . e($a['acct_hint']) : '') ?><?= $a['account_holder'] ? ' · a.n. ' . e($a['account_holder']) : '' ?> ·<?= number_format((int) $a['tx_count'], 0, ',', '.') ?> transaksi</span>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/transactions?account=' . (int) $a['id'])) ?>">Lihat transaksi</a>
  </div>
  <div class="grid g-3">
    <div class="stat"><div class="k"><?= $isLoan ? 'Pemakaian' : 'Saldo' ?></div><div class="v num <?= $neg ? 'neg' : '' ?>"><?= e(money($a['balance'])) ?></div><div class="d">Saldo awal <?= e(money($a['opening_balance'])) ?> (<?= e(fmt_date($a['opening_date'])) ?>)</div></div>
    <?php if ($pct !== null): ?>
      <div class="stat"><div class="k">Sisa limit</div><div class="v num"><?= e(money($a['remaining'])) ?></div><div class="d">dari limit <?= e(money($a['credit_limit'])) ?></div></div>
      <div class="limit-item"><div class="top"><b>Terpakai <?= e(money($a['used'])) ?></b><span class="num"><?= e(number_format($pct, 1, ',', '.')) ?>%</span></div>
        <div class="bar <?= $cls ?>" role="progressbar" aria-valuenow="<?= e((string) $pct) ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?= e((string) $pct) ?>%"></i></div></div>
    <?php else: ?>
      <div class="stat"><div class="k">Total mutasi masuk</div><div class="v num pos"><?= e(money($a['sum_debit'])) ?></div></div>
      <div class="stat"><div class="k">Total mutasi keluar</div><div class="v num neg"><?= e(money($a['sum_credit'])) ?></div></div>
    <?php endif; ?>
  </div>
  <?php if ($a['months']): ?>
  <div class="table-wrap mt-2">
    <table class="tbl stack acc-months">
      <thead><tr><th>Bulan</th><th class="r"><?= $isLoan ? 'Pembayaran (debit)' : 'Mutasi Masuk (debit)' ?></th><th class="r"><?= $isLoan ? 'Penarikan (kredit)' : 'Mutasi Keluar (kredit)' ?></th><th class="r"><?= $isLoan ? 'Pemakaian akhir bulan' : 'Saldo akhir bulan' ?></th></tr></thead>
      <tbody>
      <?php foreach ($a['months'] as $m): [$y, $mo] = explode('-', $m['ym']); ?>
        <tr><td data-label="Bulan"><?= e(month_name((int) $mo) . ' ' . $y) ?></td><td data-label="Mutasi Masuk" class="r num"><?= e(money($m['debit'], false)) ?></td><td data-label="Mutasi Keluar" class="r num"><?= e(money($m['credit'], false)) ?></td><td data-label="Saldo" class="r num"><b><?= e(money($m['closing'])) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
