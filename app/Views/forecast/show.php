<?php
/** @var array $it @var ?array $due @var ?string $payer @var bool $isAdmin @var ?array $link @var array $cand @var array $reals @var string $realized @var ?string $blockReal @var array $sum @var array $decisions @var ?string $closer */
$out = $it['direction'] === 'out';
[$y, $mo] = explode('-', $it['period_month']);
$id = (int) $it['id'];
$stTone = ['belum' => 'pill-blue', 'sebagian' => 'pill-yellow', 'terealisasi' => 'pill-green', 'diakhiri' => 'pill-green', 'nonaktif' => 'pill-gray'];
if (!$link && in_array($sum['status'], ['sebagian', 'diakhiri'], true)) {
    $stPill = '<span class="pill ' . $stTone[$sum['status']] . '">' . e($sum['label']) . '</span>';
} elseif ($it['paid_at']) {
    $stPill = '<span class="pill pill-green">' . ($out ? 'Sudah dibayar' : 'Sudah diterima') . '</span>';
} elseif (!$it['is_active']) {
    $stPill = '<span class="pill pill-gray">Nonaktif / dibatalkan</span>';
} elseif ($due) {
    $cls = ['red' => 'pill-red', 'amber' => 'pill-yellow', 'neutral' => 'pill-blue'][$due['tone']];
    $stPill = '<span class="pill ' . $cls . '">' . e($due['label']) . '</span> <span class="muted">' . e($due['rel']) . '</span>';
} else {
    $stPill = '<span class="pill pill-blue">Aktif</span>';
}
?>
<section class="card" style="max-width:820px" id="fcDetail" data-id="<?= $id ?>">
  <div class="card-head">
    <h2><?= e($it['description']) ?></h2>
    <?= $stPill ?>
  </div>
  <dl class="kv">
    <dt>Jenis</dt><dd><?= $out ? 'Pengeluaran' : 'Penerimaan' ?></dd>
    <dt><?= $link ? 'Nominal' : 'Estimasi awal' ?></dt><dd class="num"><b class="<?= $out ? 'neg' : 'pos' ?>"><?= e(money($it['amount'])) ?></b></dd>
    <?php if (!$link): ?>
    <dt>Total <?= $out ? 'dibayar' : 'diterima' ?> (Transaksi)</dt><dd class="num <?= $out ? 'neg' : 'pos' ?>"><?= e(money($sum['realized'])) ?></dd>
    <dt>Sisa estimasi</dt><dd class="num"><b><?= e(money($sum['remaining'])) ?></b><?= $sum['status'] === 'diakhiri' ? ' <span class="muted">(diakhiri — tidak lagi dihitung sebagai estimasi aktif)</span>' : '' ?></dd>
    <dt>Status</dt><dd><span class="pill <?= $stTone[$sum['status']] ?>"><?= e($sum['label']) ?></span></dd>
    <?php if ($sum['closed']): ?>
    <dt>Sisa diakhiri</dt><dd class="num"><b><?= e(money($sum['closed_remaining'] ?? '0')) ?></b><?php if ($sum['closed_remaining'] !== null && bccomp((string) $sum['closed_remaining'], $sum['remaining'], 2) !== 0): ?> <span class="pill pill-yellow" title="Realisasi berubah sejak sisa diakhiri (mis. transaksi dibatalkan/dipulihkan)">sisa kini <?= e(money($sum['remaining'])) ?> — tinjau</span><?php endif; ?></dd>
    <dt>Waktu penutupan</dt><dd><?= e(fmt_date($it['closed_at'], 'd/m/Y H:i')) ?><?= $closer ? ' · oleh ' . e($closer) : '' ?></dd>
    <?php if ($it['close_note']): ?><dt>Catatan penutupan</dt><dd><?= e($it['close_note']) ?></dd><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <dt><?= $out ? 'Jatuh tempo' : 'Perkiraan tanggal' ?></dt><dd><?= $it['expected_date'] ? e(fmt_date($it['expected_date'], 'd/m/Y')) : '<span class="muted">Belum ditentukan</span>' ?></dd>
    <dt>Bulan proyeksi</dt><dd><?= e(month_name((int) $mo) . ' ' . $y) ?></dd>
    <dt>Rekening / sumber</dt><dd><?= e($it['account_hint'] ?: '-') ?></dd>
    <?php if ($it['paid_at']): ?>
    <dt>Dibayar pada</dt><dd><?= e(fmt_date($it['paid_at'], 'd/m/Y H:i')) ?><?= $payer ? ' · oleh ' . e($payer) : '' ?></dd>
    <?php endif; ?>
  </dl>
  <?php if ($out && !$it['expected_date'] && $it['is_active'] && !$it['paid_at']): ?>
    <p class="help mt-2 mb-0">Item ini belum punya tanggal jatuh tempo, jadi tidak muncul di Pengingat Pembayaran. <?= $isAdmin ? 'Isi “Perkiraan tanggal” lewat Ubah.' : '' ?></p>
  <?php endif; ?>
  <p class="help mt-2 mb-0">Item proyeksi adalah rencana/estimasi dan tidak mengubah saldo. Realisasi dicatat <b>satu kali</b> di menu Transaksi lalu dihubungkan ke item ini; status “<?= $out ? 'dibayar' : 'diterima' ?>” hanya berubah setelah transaksinya tersimpan dan realisasinya mencapai nilai rencana.</p>

  <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a class="btn btn-ghost" href="<?= e(url('/forecast')) ?>">Ke Proyeksi</a>
    <?php if ($isAdmin): ?>
      <a class="btn btn-ghost" href="<?= e(url('/forecast/' . $id . '/edit')) ?>"><svg class="i i-sm"><use href="#i-edit"/></svg>Ubah</a>
      <?php if ($link): ?>
        <?php if (in_array($link['status'], ['belum', 'sebagian'], true)): ?>
          <a class="btn" href="<?= e(url('/transactions/new?ref=' . $link['type'] . ':' . (int) $link['id'])) ?>"><svg class="i i-sm"><use href="#i-plus"/></svg><?= $link['type'] === 'debt' ? 'Bayar Hutang' : 'Terima Pembayaran' ?> melalui Transaksi</a>
        <?php endif; ?>
        <span class="help">Status mengikuti <?= $link['type'] === 'debt' ? 'Hutang' : 'Piutang' ?> yang tertaut.</span>
      <?php elseif ($it['is_active'] && !$blockReal && bccomp(bcsub((string) $it['amount'], $realized, 2), '0.004', 3) > 0): ?>
        <a class="btn" href="<?= e(url('/transactions/new?ref=forecast:' . $id)) ?>"><svg class="i i-sm"><use href="#i-plus"/></svg>Realisasikan melalui Transaksi</a>
      <?php elseif ($blockReal && ($it['paid_at'] && !$reals)): ?>
        <span class="help"><?= e($blockReal) ?></span>
      <?php endif; ?>
      <?php if (!$link && $it['paid_at'] && !$reals): ?>
        <form method="post" action="<?= e(url('/forecast/' . $id . '/unpay')) ?>" data-confirm="Batalkan tanda dibayar? Item akan muncul lagi di Pengingat Pembayaran bila masuk jangka pengingat." data-confirm-ok="Ya, batalkan">
          <?= csrf_field() ?><button class="btn btn-ghost" type="submit" data-no-lock>Batalkan tanda dibayar</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php if (!$link): ?>
<?= \App\Core\View::partial('forecast/_decision', ['it' => $it, 'sum' => $sum, 'isAdmin' => $isAdmin]) ?>
<section class="card card-flush mt-2" style="max-width:820px" id="fcReals">
  <div class="card-head"><h2>Riwayat realisasi</h2></div>
  <?php if (!$reals): ?>
    <div class="empty"><svg class="i"><use href="#i-card"/></svg><p>Belum ada realisasi yang dicatat lewat menu Transaksi.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Tanggal</th><th class="r">Nominal</th><th>Transaksi (<?= $out ? 'Mutasi Keluar' : 'Mutasi Masuk' ?>)</th><th>Rekening</th><th>Dicatat oleh</th></tr></thead>
    <tbody>
    <?php foreach ($reals as $x): $pv = $x['tx_void'] !== null; ?>
      <tr<?= $pv ? ' class="voided"' : '' ?>>
        <td data-label="Tanggal" class="nowrap"><?= e(fmt_date($x['tx_date'])) ?></td>
        <td data-label="Nominal" class="r num"><?= e(money($x['amount'], false)) ?></td>
        <td data-label="Transaksi"><a href="<?= e(url('/transactions/' . (int) $x['transaction_id'])) ?>">#<?= (int) $x['transaction_id'] ?><?= $x['voucher_code'] ? ' · ' . e($x['voucher_code']) : '' ?></a><?= $pv ? ' <span class="pill pill-gray">dibatalkan — tidak dihitung</span>' : '' ?></td>
        <td data-label="Rekening"><?= e($x['tx_account']) ?></td>
        <td data-label="Oleh"><?= e($x['by_name'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
<?php if ($decisions): ?>
<section class="card card-flush mt-2" style="max-width:820px" id="fcDecisions">
  <div class="card-head"><h2>Riwayat keputusan sisa</h2></div>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Waktu</th><th>Tindakan</th><th>Oleh</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($decisions as $d): $lbl = ['forecast_close' => 'Akhiri sisa', 'forecast_keep' => 'Biarkan aktif', 'forecast_reopen' => 'Buka kembali'][$d['action']] ?? $d['action']; ?>
      <tr>
        <td data-label="Waktu" class="nowrap"><?= e(fmt_date($d['created_at'], 'd/m/Y H:i')) ?></td>
        <td data-label="Tindakan"><b><?= e($lbl) ?></b></td>
        <td data-label="Oleh"><?= e($d['username'] ?? '-') ?></td>
        <td data-label="Keterangan"><?= e($d['summary'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>
<?php endif; ?>
<?php
$lkLbl = ['lunas' => 'Lunas', 'sebagian' => 'Dibayar sebagian', 'belum' => 'Belum dibayar', 'konfirmasi' => 'Perlu konfirmasi', 'batal' => 'Dibatalkan'];
$lkTone = ['lunas' => 'pill-green', 'sebagian' => 'pill-yellow', 'belum' => 'pill-red', 'konfirmasi' => 'pill-yellow', 'batal' => 'pill-gray'];
?>
<section class="card mt-2" style="max-width:820px" id="fcLink">
  <div class="card-head"><h2>Keterhubungan</h2></div>
  <?php if ($link): ?>
    <dl class="kv">
      <dt><?= $link['type'] === 'debt' ? 'Hutang' : 'Piutang' ?></dt><dd><a href="<?= e(url($link['href'])) ?>"><?= e($link['label']) ?></a> <span class="pill <?= $lkTone[$link['status']] ?? 'pill-gray' ?>"><?= e($lkLbl[$link['status']] ?? '') ?></span></dd>
      <dt>Nominal <?= $link['type'] === 'debt' ? 'hutang' : 'piutang' ?></dt><dd class="num"><?= e(money($link['amount'])) ?></dd>
      <dt>Sisa saat ini</dt><dd class="num"><b><?= e(money($link['remaining'])) ?></b></dd>
      <dt>Jatuh tempo</dt><dd><?= $link['due'] ? e(fmt_date($link['due'], 'd/m/Y')) : '<span class="muted">belum ditentukan</span>' ?></dd>
    </dl>
    <p class="help mt-2 mb-0"><b>Otomatis:</b> nominal item ini selalu = sisa <?= $link['type'] === 'debt' ? 'hutang' : 'piutang' ?>, tanggalnya = jatuh tempo, dan status “<?= $out ? 'dibayar' : 'diterima' ?>” mengikuti pelunasan — tidak perlu disinkronkan manual. Pengingat hanya muncul sekali (dari <?= $link['type'] === 'debt' ? 'Hutang' : 'Piutang' ?>). Tautan tidak mengubah saldo atau transaksi.</p>
    <?php if ($isAdmin): ?>
    <div class="mt-2" style="display:flex;gap:10px;flex-wrap:wrap">
      <form method="post" action="<?= e(url('/forecast/' . $id . '/unlink')) ?>" data-confirm="Lepas tautan? Item ini kembali berdiri sendiri (pengingat & status dikelola di Proyeksi)." data-confirm-ok="Ya, lepas">
        <?= csrf_field() ?><button class="btn btn-ghost" type="submit" data-no-lock>Lepas tautan</button></form>
    </div>
    <?php endif; ?>
  <?php elseif (!$isAdmin): ?>
    <p class="muted mb-0">Item ini belum tertaut ke <?= $out ? 'Hutang' : 'Piutang' ?>.</p>
  <?php elseif ($out): ?>
    <p class="help">Belum tertaut ke Hutang. Penautan otomatis berjalan bila uraian dan tanggal jatuh tempo sama persis dengan sebuah Hutang. Bila ditautkan, nominal, tanggal, dan status item ini akan mengikuti Hutang secara otomatis.</p>
    <form method="post" action="<?= e(url('/forecast/' . $id . '/link-debt')) ?>" class="filters">
      <?= csrf_field() ?>
      <div class="field grow"><label for="debt_id">Tautkan ke Hutang yang ada</label>
        <select class="input" id="debt_id" name="debt_id" required><option value="">— pilih hutang —</option>
          <?php foreach ($cand as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e((\App\Services\DebtService::CATEGORIES[$c['category']] ?? 'Hutang') . ' · ' . $c['supplier'] . ' · sisa ' . money($c['remaining']) . ($c['due_date'] ? ' · tempo ' . fmt_date($c['due_date']) : '') . ($c['description'] ? ' · ' . mb_substr($c['description'], 0, 40) : '')) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit" data-no-lock <?= $cand ? '' : 'disabled' ?>>Tautkan</button></div>
    </form>
    <form method="post" action="<?= e(url('/forecast/' . $id . '/make-debt')) ?>" class="filters mt-2" data-confirm="Buat Hutang baru dari item Proyeksi ini (nominal, uraian, tanggal, rekening sama) dan tautkan?" data-confirm-ok="Ya, buat Hutang">
      <?= csrf_field() ?>
      <div class="field" style="flex:0 0 190px"><label for="mk_category">…atau Hutang baru — jenis</label>
        <select class="input" id="mk_category" name="category">
          <?php foreach (\App\Services\DebtService::CATEGORIES as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field grow"><label for="supplier">Nama supplier / pihak pemberi hutang</label><input class="input" id="supplier" name="supplier" maxlength="160" required placeholder="mis. PT. Contoh Jaya"></div>
      <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit" data-no-lock>Jadikan Hutang</button></div>
    </form>
  <?php else: ?>
    <p class="help">Belum tertaut ke Piutang. Penautan otomatis berjalan bila nomor invoice tertulis di uraian. Bila ditautkan, nominal, tanggal, dan status item ini akan mengikuti Piutang secara otomatis.</p>
    <form method="post" action="<?= e(url('/forecast/' . $id . '/link-invoice')) ?>" class="filters">
      <?= csrf_field() ?>
      <div class="field grow"><label for="invoice_id">Tautkan ke Piutang</label>
        <select class="input" id="invoice_id" name="invoice_id" required><option value="">— pilih piutang —</option>
          <?php foreach ($cand as $c): ?><option value="<?= (int) $c['id'] ?>">[<?= $c['kind'] === 'lampu' ? 'Lampu' : 'Proyek' ?>] <?= e($c['customer'] . ($c['invoice_no'] ? ' · ' . $c['invoice_no'] : '') . ((int) $c['paid_known'] === 1 ? ' · sisa ' . money($c['remaining']) : ' · perlu konfirmasi')) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit" data-no-lock <?= $cand ? '' : 'disabled' ?>>Tautkan</button></div>
    </form>
  <?php endif; ?>
</section>
