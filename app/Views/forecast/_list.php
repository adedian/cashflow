<?php /** @var array $months @var array $chain @var string $startBalance @var ?string $asOf @var bool $isAdmin @var array $links @var int $totalItems @var int $closedCount @var bool $history */
$cols = $isAdmin ? 7 : 6;
$stLbl = ['lunas' => 'lunas', 'sebagian' => 'sebagian', 'belum' => 'belum dibayar', 'konfirmasi' => 'perlu konfirmasi', 'batal' => 'dibatalkan'];
$stTone = ['lunas' => 'pill-green', 'sebagian' => 'pill-yellow', 'belum' => 'pill-blue', 'konfirmasi' => 'pill-yellow', 'batal' => 'pill-gray'];
?>
<div class="fc-toolbar">
  <div class="alert alert-info fc-note">
    <p>Item di bawah adalah rencana/estimasi — <b>tidak memengaruhi saldo atau laporan</b>. Item nonaktif tidak dihitung.
    Setiap bulan dimulai dari saldo akhir bulan sebelumnya; bulan pertama dimulai dari saldo awal.</p>
    <p class="mb-0 mt-1"><svg class="i i-sm" aria-hidden="true"><use href="#i-swap"/></svg> <b><?= count($links) ?></b> dari <?= (int) $totalItems ?> item tertaut ke <a href="<?= e(url('/debts')) ?>">Hutang</a> / <a href="<?= e(url('/invoices')) ?>">Piutang</a>. Item tertaut statusnya mengikuti pembayaran di sana. Realisasi dicatat lewat menu <a href="<?= e(url('/transactions')) ?>">Transaksi</a> (tombol Realisasikan); hanya sisa yang belum terealisasi yang masuk hitungan saldo proyeksi.</p>
  </div>
  <aside class="card sa-card" aria-label="Saldo awal proyeksi">
    <div class="sa-k"><svg class="i i-sm" aria-hidden="true"><use href="#i-db"/></svg>Saldo Awal</div>
    <div class="sa-v num<?= bccomp($startBalance, '0', 2) < 0 ? ' neg' : '' ?>"><?= e(money($startBalance)) ?></div>
    <div class="sa-d"><?= $asOf ? 'Saldo rekening bank per ' . e(fmt_date($asOf)) : 'Belum ada transaksi' ?></div>
  </aside>
</div>
<?php if ($closedCount > 0 || $history): ?>
  <div class="fc-history no-print">
    <span class="muted"><?= $history ? 'Menampilkan riwayat: ' . (int) $closedCount . ' item dengan sisa diakhiri ikut tampil (tidak dihitung di saldo proyeksi).' : (int) $closedCount . ' item dengan sisa diakhiri disembunyikan dari daftar (riwayatnya tetap tersimpan).' ?></span>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/forecast') . ($history ? '' : '?riwayat=1')) ?>"><?= $history ? 'Sembunyikan riwayat' : 'Tampilkan riwayat' ?></a>
  </div>
<?php endif; ?>
<?php if (!$months): ?>
  <div class="card"><div class="empty"><svg class="i"><use href="#i-trend"/></svg><p>Belum ada item proyeksi.</p></div></div>
<?php endif; ?>
<?php foreach ($months as $ym => $list): [$y, $mo] = explode('-', $ym); $c = $chain[$ym]; ?>
<section class="card card-flush fc-month">
  <div class="card-head">
    <h2><?= e(month_name((int) $mo) . ' ' . $y) ?></h2>
    <span class="muted fc-open">Saldo awal bulan <b class="num"><?= e(money($c['open'])) ?></b></span>
  </div>
  <div class="table-wrap">
    <table class="tbl stack fc">
      <thead><tr><th class="c-date">Tanggal</th><th>Uraian</th><th class="c-acc">Rekening</th><th class="r c-amt">Masuk</th><th class="r c-amt">Keluar</th><th class="c-st">Status</th><?php if ($isAdmin): ?><th class="c-act"><span class="sr-only">Aksi</span></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($list as $it): $in = $it['direction'] === 'in'; ?>
        <tr class="<?= ($it['is_active'] && $it['status'] !== 'diakhiri') ? '' : 'voided' ?>">
          <td data-label="Tanggal" class="nowrap c-date"><?= $it['expected_date'] ? e(fmt_date($it['expected_date'])) : '<span class="muted">-</span>' ?></td>
          <td data-label="Uraian" class="desc"><a class="fc-link" href="<?= e(url('/forecast/' . (int) $it['id'])) ?>"><?= e($it['description']) ?></a>
            <?php if ($lk = ($links[(int) $it['id']] ?? null)): ?><div class="sub"><a class="pill <?= $stTone[$lk['status']] ?? 'pill-blue' ?>" href="<?= e(url($lk['href'])) ?>" title="<?= e($lk['label']) ?>"><?= $lk['type'] === 'debt' ? 'Hutang' : 'Piutang' ?> · <?= e($stLbl[$lk['status']] ?? '') ?></a><?php if ($lk['differs']): ?> <span class="pill pill-yellow" title="Sisa saat ini <?= e(money($lk['remaining'])) ?> berbeda dari nominal/tanggal Proyeksi">nominal/tanggal beda</span><?php endif; ?></div><?php endif; ?>
            <?php if (bccomp($it['realized'], '0', 2) > 0 || $it['status'] === 'diakhiri'): $tt = ['sebagian' => 'pill-yellow', 'diakhiri' => 'pill-green', 'terealisasi' => 'pill-green'][$it['status']] ?? 'pill-blue'; ?>
              <div class="sub">Estimasi <?= e(money($it['amount'])) ?> · <?= $in ? 'diterima' : 'dibayar' ?> <?= e(money($it['realized'])) ?> · sisa <?= e(money($it['left'])) ?><?= $it['status'] === 'diakhiri' ? ' (diakhiri ' . e(fmt_date($it['closed_at'])) . ')' : '' ?></div>
              <?php if (in_array($it['status'], ['sebagian', 'diakhiri'], true)): ?><div class="sub"><a class="pill <?= $tt ?>" href="<?= e(url('/forecast/' . (int) $it['id'])) ?>#fcDecision"><?= e(\App\Services\ForecastService::STATUS_LABEL[$it['status']]) ?></a></div><?php endif; ?>
            <?php endif; ?></td>
          <td data-label="Rekening" class="c-acc"><?= e($it['account_hint'] ?? '') ?></td>
          <td data-label="Masuk" class="r num pos c-amt"><?= $in ? e(money($it['eff'], false)) : '' ?></td>
          <td data-label="Keluar" class="r num neg c-amt"><?= !$in ? e(money($it['eff'], false)) : '' ?></td>
          <td data-label="Status" class="c-st"><?php
            if ($it['status'] === 'diakhiri') { echo '<span class="pill pill-green">Selesai — Sisa Diakhiri</span>'; }
            elseif ($it['status'] === 'sebagian') { echo '<span class="pill pill-yellow">Realisasi Sebagian</span>'; }
            elseif ($it['paid_at']) { echo '<span class="pill pill-green">' . ($in ? 'Diterima' : 'Dibayar') . '</span>'; }
            elseif (!$it['is_active']) { echo '<span class="pill pill-gray">Nonaktif</span>'; }
            else { echo '<span class="muted">Aktif</span>'; }
          ?></td>
          <?php if ($isAdmin): ?>
          <td class="nowrap c-act">
            <?php if ($it['is_active'] && !$lk && !$it['paid_at'] && $it['status'] !== 'diakhiri' && bccomp($it['eff'], '0.004', 3) > 0): ?><a class="btn btn-sm" href="<?= e(url('/transactions/new?ref=forecast:' . (int) $it['id'])) ?>" title="Realisasikan melalui menu Transaksi">Realisasikan</a><?php endif; ?>
            <a class="btn btn-ghost btn-sm btn-icon" href="<?= e(url('/forecast/' . (int) $it['id'] . '/edit')) ?>" aria-label="Ubah"><svg class="i i-sm"><use href="#i-edit"/></svg></a>
            <form method="post" action="<?= e(url('/forecast/' . (int) $it['id'] . '/delete')) ?>" style="display:inline" data-confirm="Hapus item proyeksi ini?">
              <?= csrf_field() ?><button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Hapus" data-no-lock><svg class="i i-sm"><use href="#i-trash"/></svg></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="fc-sum">
        <tr class="s-in"><th scope="row" colspan="3">Total Saldo Masuk</th><td class="r num pos c-amt"><?= e(money($c['in'], false)) ?></td><td class="blank"></td><td class="blank" colspan="<?= $cols - 5 ?>"></td></tr>
        <tr class="s-out"><th scope="row" colspan="3">Total Saldo Keluar</th><td class="blank"></td><td class="r num neg c-amt"><?= e(money($c['out'], false)) ?></td><td class="blank" colspan="<?= $cols - 5 ?>"></td></tr>
        <tr class="s-end"><th scope="row" colspan="3">Saldo proyeksi akhir <?= e(month_name((int) $mo)) ?></th><td class="r num c-amt<?= bccomp($c['closing'], '0', 2) < 0 ? ' neg' : '' ?>" colspan="2"><?= e(money($c['closing'])) ?></td><td class="blank" colspan="<?= $cols - 5 ?>"></td></tr>
      </tfoot>
    </table>
  </div>
</section>
<?php endforeach; ?>
