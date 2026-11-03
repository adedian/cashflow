<?php /** @var array $d @var array $pays @var ?array $fc @var ?array $link @var array $cand @var bool $isAdmin */
$id = (int) $d['id'];
$void = $d['voided_at'] !== null;
$m = ['lunas' => ['pill-green', 'Lunas'], 'sebagian' => ['pill-yellow', 'Dibayar Sebagian'], 'belum' => ['pill-red', 'Belum Dibayar']][$d['status']];
$pct = bccomp((string) $d['amount'], '0', 2) > 0 ? (int) min(100, floor((float) bcdiv(bcmul($d['paid'], '100', 4), (string) $d['amount'], 2))) : 0;
?>
<section class="card" style="max-width:900px" id="debtDetail" data-id="<?= $id ?>">
  <div class="card-head">
    <h2><?= e($d['supplier']) ?></h2>
    <div>
      <?php if ($void): ?><span class="pill pill-gray">Dibatalkan</span>
      <?php else: ?><span class="pill <?= $m[0] ?>"><?= $m[1] ?></span>
        <?php if ($d['due'] && $d['due']['status'] === 'late'): ?> <span class="pill pill-red">Terlambat</span> <span class="muted"><?= e($d['due']['rel']) ?></span>
        <?php elseif ($d['due'] && $d['due']['status'] === 'today'): ?> <span class="pill pill-yellow">Jatuh tempo hari ini</span>
        <?php elseif ($d['due']): ?> <span class="muted"><?= e($d['due']['rel']) ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($void): ?><div class="alert alert-warn mb-2">Hutang ini dibatalkan<?= $d['void_reason'] ? ': ' . e($d['void_reason']) : '' ?>. Tidak ditampilkan di daftar utama maupun pengingat.</div><?php endif; ?>
  <dl class="kv">
    <dt>Jenis hutang</dt><dd><?= e(\App\Services\DebtService::CATEGORIES[$d['category']] ?? $d['category']) ?></dd>
    <dt>Keterangan</dt><dd><?= $d['description'] ? e($d['description']) : '<span class="muted">-</span>' ?></dd>
    <dt>Nomor transaksi / dokumen</dt><dd><?= $d['ref_no'] ? e($d['ref_no']) : '<span class="muted">Tidak tersedia</span>' ?></dd>
    <dt>No. urut</dt><dd><?= $d['seq_no'] !== null ? (int) $d['seq_no'] : '<span class="muted">-</span>' ?> <span class="muted">(nomor urut tampilan, otomatis)</span></dd>
    <dt>Tanggal transaksi</dt><dd><?= $d['debt_date'] ? e(fmt_date($d['debt_date'], 'd/m/Y')) : '<span class="muted">Tidak tersedia</span>' ?></dd>
    <dt>Jatuh tempo</dt><dd><?= $d['due_date'] ? e(fmt_date($d['due_date'], 'd/m/Y')) : '<span class="muted">Belum ditentukan (tidak muncul di pengingat)</span>' ?></dd>
    <dt>Rekening sumber (rencana)</dt><dd><?= e($d['source_account'] ?: '-') ?></dd>
    <dt>Nominal hutang</dt><dd class="num"><b><?= e(money($d['amount'])) ?></b></dd>
    <dt>Sudah dibayar</dt><dd class="num pos"><?= e(money($d['paid'])) ?></dd>
    <dt>Sisa hutang</dt><dd class="num"><b class="<?= bccomp($d['remaining'], '0', 2) > 0 ? 'neg' : 'pos' ?>"><?= e(money($d['remaining'])) ?></b></dd>
    <?php if ($d['note']): ?><dt>Catatan</dt><dd><?= e($d['note']) ?></dd><?php endif; ?>
    <?php if ($fc): ?><dt>Item Proyeksi terkait</dt><dd><a href="<?= e(url('/forecast/' . (int) $fc['id'])) ?>">Rencana pengeluaran #<?= (int) $fc['id'] ?></a> <span class="muted">— <?= e($fc['description']) ?></span></dd><?php endif; ?>
    <?php if ($d['source_sheet'] || $d['source_row']): ?><dt>Asal data</dt><dd><?= e('Baris ' . (int) $d['source_row'] . ($d['import_batch_id'] ? ' · impor batch #' . (int) $d['import_batch_id'] : '')) ?></dd><?php endif; ?>
  </dl>
  <div class="mt-2" aria-hidden="true" style="height:8px;border-radius:99px;background:#eef0f6;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:var(--green)"></div></div>
  <div class="help mt-1 mb-0"><?= $pct ?>% terbayar</div>
</section>

<section class="card card-flush mt-2" style="max-width:900px">
  <div class="card-head"><h2>Riwayat pembayaran</h2><?php if ($isAdmin && !$void && bccomp($d['remaining'], '0', 2) > 0): ?><a class="btn btn-sm" href="<?= e(url('/transactions/new?ref=debt:' . $id)) ?>"><svg class="i i-sm"><use href="#i-plus"/></svg>Bayar Hutang melalui Transaksi</a><?php endif; ?></div>
  <?php if (!$pays): ?>
    <div class="empty"><svg class="i"><use href="#i-card"/></svg><p>Belum ada pembayaran tercatat.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Tanggal</th><th class="r">Nominal</th><th>Transaksi (Mutasi Keluar)</th><th>Rekening</th><th>Catatan</th><th>Dicatat oleh</th><?php if ($isAdmin && !$void): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($pays as $p): $pv = $p['tx_void'] !== null; ?>
      <tr<?= $pv ? ' class="voided"' : '' ?>>
        <td data-label="Tanggal" class="nowrap"><?= $p['pay_date'] ? e(fmt_date($p['pay_date'])) : '<span class="muted">Tidak diketahui</span>' ?></td>
        <td data-label="Nominal" class="r num"><?= e(money($p['amount'], false)) ?></td>
        <td data-label="Transaksi"><?= $p['transaction_id'] ? '<a href="' . e(url('/transactions/' . (int) $p['transaction_id'])) . '">#' . (int) $p['transaction_id'] . ($p['tx_voucher'] ? ' · ' . e($p['tx_voucher']) : '') . '</a>' . ($pv ? ' <span class="pill pill-gray">dibatalkan — tidak dihitung</span>' : '') : '<span class="muted">Data lama (Excel), tanpa transaksi</span>' ?></td>
        <td data-label="Rekening"><?= e($p['tx_account'] ?? '') ?></td>
        <td data-label="Catatan"><?= e($p['note'] ?? '') ?></td>
        <td data-label="Oleh"><?= e($p['by_name'] ?? '-') ?></td>
        <?php if ($isAdmin && !$void): ?><td class="nowrap">
          <?php if (!$p['transaction_id']): ?>
          <form method="post" action="<?= e(url('/debts/' . $id . '/payments/' . (int) $p['id'] . '/delete')) ?>" style="display:inline" data-confirm="Hapus catatan pembayaran lama <?= e(money($p['amount'])) ?>? Sisa hutang akan bertambah kembali. Tindakan tercatat di audit log.">
            <?= csrf_field() ?><button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Hapus pembayaran" data-no-lock><svg class="i i-sm"><use href="#i-trash"/></svg></button>
          </form>
          <?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <p class="help" style="padding:10px 16px 14px;margin:0">Pembayaran dicatat <b>satu kali</b> di menu Transaksi sebagai Mutasi Keluar lalu dihubungkan ke hutang ini. Untuk mengubah atau membatalkan pembayaran, ubah / batalkan (void) transaksinya.</p>
</section>

<section class="card mt-2" style="max-width:900px" id="debtLink">
  <div class="card-head"><h2>Keterhubungan dengan Proyeksi</h2><?php if ($link): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/forecast/' . (int) $fc['id'])) ?>">Buka item Proyeksi</a><?php endif; ?></div>
  <?php if ($link): ?>
    <p class="mb-0">Tertaut ke item Proyeksi <b>#<?= (int) $fc['id'] ?></b> (<?= e($fc['description']) ?>). Proyeksi saat ini mencatat <b><?= e(money($fc['amount'])) ?></b><?= $fc['expected_date'] ? ' per ' . e(fmt_date($fc['expected_date'], 'd/m/Y')) : '' ?> —
      status: <?= $fc['paid_at'] ? '<span class="pill pill-green">sudah dibayar (otomatis dari pelunasan hutang)</span>' : '<span class="pill pill-blue">belum dibayar</span>' ?>.</p>
    <p class="help mt-2 mb-0"><b>Otomatis:</b> setiap pembayaran, perubahan nominal/jatuh tempo, pembatalan, atau penghapusan pembayaran di sini langsung diikuti item Proyeksi (nominal = sisa hutang, tanggal = jatuh tempo, status dibayar) — tanpa sinkron manual. Pengingat hanya muncul sekali. Saldo tidak berubah.</p>
    <?php if ($isAdmin): ?>
    <form method="post" action="<?= e(url('/debts/' . $id . '/unlink')) ?>" class="mt-2" data-confirm="Lepas tautan ke item Proyeksi? Item Proyeksi kembali berdiri sendiri dan akan mengingatkan sendiri." data-confirm-ok="Ya, lepas">
      <?= csrf_field() ?><button class="btn btn-ghost" type="submit" data-no-lock>Lepas tautan</button></form>
    <?php endif; ?>
  <?php elseif ($isAdmin && !$void): ?>
    <p class="help">Belum tertaut. Penautan otomatis berjalan bila uraian dan jatuh tempo sama persis dengan item Proyeksi; atau tautkan manual di sini — setelah itu Proyeksi mengikuti hutang ini secara otomatis.</p>
    <form method="post" action="<?= e(url('/debts/' . $id . '/link')) ?>" class="filters">
      <?= csrf_field() ?>
      <div class="field grow"><label for="forecast_id">Item Proyeksi</label>
        <select class="input" id="forecast_id" name="forecast_id" required><option value="">— pilih item Proyeksi —</option>
          <?php foreach ($cand as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e(($c['expected_date'] ? fmt_date($c['expected_date']) : 'tanpa tanggal') . ' · ' . money($c['amount']) . ' · ' . mb_substr($c['description'], 0, 70)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit" data-no-lock <?= $cand ? '' : 'disabled' ?>>Tautkan</button></div>
    </form>
  <?php else: ?>
    <p class="muted mb-0">Belum tertaut ke item Proyeksi.</p>
  <?php endif; ?>
</section>
<div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;max-width:900px">
  <a class="btn btn-ghost" href="<?= e(url('/debts')) ?>">Ke daftar Hutang</a>
  <?php if ($isAdmin && !$void): ?>
    <a class="btn btn-ghost" href="<?= e(url('/debts/' . $id . '/edit')) ?>"><svg class="i i-sm"><use href="#i-edit"/></svg>Ubah</a>
    <form method="post" action="<?= e(url('/debts/' . $id . '/void')) ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap" data-confirm="Batalkan hutang ini? Hutang tidak lagi tampil di daftar dan pengingat. Riwayat tetap tersimpan." data-confirm-ok="Ya, batalkan">
      <?= csrf_field() ?><input class="input" name="reason" placeholder="Alasan pembatalan (wajib)" maxlength="255" required style="max-width:280px">
      <button class="btn btn-ghost" type="submit" data-no-lock>Batalkan hutang</button>
    </form>
  <?php elseif ($isAdmin && $void): ?>
    <form method="post" action="<?= e(url('/debts/' . $id . '/restore')) ?>"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Pulihkan</button></form>
  <?php endif; ?>
</div>
