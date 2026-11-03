<?php
/** @var array $t @var array $hist @var bool $isAdmin @var array $related @var ?array $fcDecision */
$inn = bccomp((string) $t['debit'], '0', 2) !== 0;
$amount = $inn ? $t['debit'] : $t['credit'];
$void = $t['voided_at'] !== null;
?>
<?php if (!empty($fcDecision)): ?><?= \App\Core\View::partial('forecast/_decision', $fcDecision) ?><?php endif; ?>
<section class="grid g-main mt-2">
  <div class="card">
    <div class="card-head">
      <div class="cell-flex">
        <span class="tx-ico <?= $inn ? 'in' : 'out' ?>"><svg class="i"><use href="#i-<?= $inn ? 'in' : 'out' ?>"/></svg></span>
        <div>
          <div class="muted" style="font-size:12.5px"><?= $inn ? 'Mutasi Masuk' : 'Mutasi Keluar' ?></div>
          <div class="stat"><div class="v num <?= $inn ? 'pos' : 'neg' ?>"><?= e(money($amount)) ?></div></div>
        </div>
      </div>
      <?php if ($void): ?><span class="pill pill-gray">Dibatalkan</span><?php endif; ?>
    </div>
    <?php if ($void): ?>
      <div class="alert alert-warn mb-2">Dibatalkan <?= e(fmt_date($t['voided_at'], 'd/m/Y H:i')) ?> oleh <?= e($t['voided_name'] ?? '-') ?>. Alasan: <?= e($t['void_reason'] ?? '-') ?>. Transaksi ini tidak dihitung dalam saldo.</div>
    <?php endif; ?>
    <dl class="kv">
      <dt>Tanggal</dt><dd><?= e(fmt_date($t['tx_date'])) ?></dd>
      <dt>Kode bukti</dt><dd><?= e($t['voucher_code'] ?: '-') ?></dd>
      <dt>Rekening</dt><dd><?= e($t['account_name']) ?></dd>
      <dt>Pihak</dt><dd><?= e($t['counterparty'] ?: '-') ?></dd>
      <?php if ($isAdmin): ?><dt>Detail rekening pihak</dt><dd><?= e($t['counterparty_detail'] ?: '-') ?></dd><?php endif; ?>
      <dt>Kategori</dt><dd><?= e($t['category_name'] ?: 'Belum dikategorikan') ?><?php if ($t['category_kind']): ?> <span class="pill pill-blue"><?= e($t['category_kind']) ?></span><?php endif; ?></dd>
      <dt>Proyek</dt><dd><?= e($t['project_name'] ?: '-') ?></dd>
      <dt>Uraian</dt><dd><?= e($t['description'] ?: '-') ?></dd>
      <dt>No. referensi (invoice/PO)</dt><dd><?= e($t['ref_no'] ?: '-') ?></dd>
      <dt>Catatan</dt><dd style="white-space:pre-wrap"><?= e($t['note'] ?: '-') ?></dd>
      <?php if ($isAdmin && !empty($t['flag'])): ?><dt>Perlu ditinjau</dt><dd><span class="pill pill-yellow"><?= e($t['flag']) ?></span></dd><?php endif; ?>
      <?php if ($isAdmin && !empty($t['source_sheet'])): ?><dt>Sumber Excel</dt><dd><?= e($t['source_sheet']) ?> baris <?= (int) $t['source_row'] ?></dd><?php endif; ?>
      <?php if ($related['link'] || $related['legacy']): ?><dt>Terhubung ke</dt><dd>
        <?php if ($rl = $related['link']): $path = ['debt' => '/debts/', 'invoice' => '/invoices/', 'forecast' => '/forecast/'][$rl['type']]; $nm = ['debt' => 'Hutang', 'invoice' => 'Piutang', 'forecast' => 'Proyeksi'][$rl['type']]; ?>
          <div><a class="pill pill-blue" href="<?= e(url($path . (int) $rl['sid'])) ?>"><?= e($rl['tag'] ?? $nm) ?></a> <?= e(trim(($rl['party'] ?? '') . ' · ' . ($rl['title'] ?? ''), ' ·')) ?> — <?= $rl['type'] === 'debt' ? 'pembayaran' : ($rl['type'] === 'invoice' ? 'penerimaan' : 'realisasi') ?> <b><?= e(money($rl['link_amount'])) ?></b><?= $void ? ' <span class="pill pill-gray">tidak dihitung (transaksi dibatalkan)</span>' : '' ?></div>
        <?php endif; ?>
        <?php foreach ($related['legacy'] as $ri): ?><div><a class="pill pill-green" href="<?= e(url('/invoices/' . (int) $ri['id'])) ?>">Piutang <?= $ri['kind'] === 'lampu' ? 'Lampu' : 'Proyek' ?></a> <?= e($ri['customer']) ?><?= $ri['invoice_no'] ? ' · ' . e($ri['invoice_no']) : '' ?> — bukti penerimaan historis (dibayar <?= e(money($ri['paid'])) ?>)</div><?php endforeach; ?>
      </dd><?php endif; ?>
      <dt>Dibuat</dt><dd><?= e(fmt_date($t['created_at'], 'd/m/Y H:i')) ?> <?= $t['created_name'] ? 'oleh ' . e($t['created_name']) : '' ?></dd>
      <dt>Terakhir diubah</dt><dd><?= e(fmt_date($t['updated_at'], 'd/m/Y H:i')) ?> <?= $t['updated_name'] ? 'oleh ' . e($t['updated_name']) : '' ?></dd>
    </dl>

    <?php if ($isAdmin): ?>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if (!$void): ?>
        <form method="post" action="<?= e(url('/transactions/' . (int) $t['id'] . '/void')) ?>" data-confirm="Batalkan transaksi ini? Transaksi tidak lagi dihitung dalam saldo, tetapi riwayatnya tetap tersimpan dan dapat dipulihkan." data-confirm-title="Batalkan transaksi" data-confirm-ok="Ya, batalkan" data-reason="required">
          <?= csrf_field() ?><input type="hidden" name="reason" value="">
          <button class="btn btn-danger" type="submit" data-no-lock><svg class="i i-sm"><use href="#i-trash"/></svg>Batalkan (void)</button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/transactions/' . (int) $t['id'] . '/restore')) ?>" data-confirm="Pulihkan transaksi ini? Nilainya akan dihitung kembali dalam saldo." data-confirm-title="Pulihkan transaksi" data-confirm-ok="Ya, pulihkan">
          <?= csrf_field() ?><button class="btn" type="submit" data-no-lock><svg class="i i-sm"><use href="#i-refresh"/></svg>Pulihkan</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="card">
    <div class="card-head"><h3>Riwayat perubahan</h3></div>
    <?php if (!$hist): ?><p class="muted">Belum ada riwayat tercatat untuk transaksi ini (data hasil impor).</p><?php else: ?>
      <div class="tx-list">
      <?php foreach ($hist as $h): ?>
        <div class="tx-item" style="grid-template-columns:1fr">
          <div><div class="t" style="white-space:normal"><?= e($h['summary']) ?></div><div class="s"><?= e(fmt_date($h['created_at'], 'd/m/Y H:i')) ?> · <?= e($h['username'] ?? '-') ?> · <?= e($h['action']) ?></div></div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
