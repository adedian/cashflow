<?php /** @var array $rows @var int $total @var int $page @var int $pages @var array $actions @var array $users @var array $base @var string $q @var string $action @var string $user @var ?string $from @var ?string $to */
$link = static fn(array $o) => url('/audit') . '?' . qs_merge($base, $o);
?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/audit')) ?>">
    <div class="field grow"><label for="q">Cari ringkasan / ID / IP</label><input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="100"></div>
    <div class="field"><label for="action">Aktivitas</label><select class="input" id="action" name="action"><option value="">Semua</option><?php foreach ($actions as $a): ?><option <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="user">Pengguna</label><select class="input" id="user" name="user"><option value="">Semua</option><?php foreach ($users as $u): ?><option <?= $u === $user ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="from">Dari</label><input class="input" type="date" id="from" name="from" value="<?= e($from ?? '') ?>"></div>
    <div class="field"><label for="to">Sampai</label><input class="input" type="date" id="to" name="to" value="<?= e($to ?? '') ?>"></div>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px"><button class="btn" type="submit" data-no-lock>Terapkan</button><a class="btn btn-ghost" href="<?= e(url('/audit')) ?>">Reset</a></div>
  </form>
</section>
<section class="card card-flush">
  <div class="card-head" style="padding:20px 22px 0"><h2>Aktivitas</h2><span class="muted" style="font-size:12.5px"><?= number_format($total, 0, ',', '.') ?> catatan</span></div>
  <?php if (!$rows): ?><div class="empty"><svg class="i"><use href="#i-shield"/></svg><p>Tidak ada catatan.</p></div><?php else: ?>
  <div class="table-wrap mt-1"><table class="tbl stack">
    <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aktivitas</th><th>Ringkasan</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td data-label="Waktu" class="nowrap"><?= e(fmt_date($r['created_at'], 'd/m/Y H:i:s')) ?></td>
        <td data-label="Pengguna"><?= e($r['username'] ?? '-') ?></td>
        <td data-label="Aktivitas"><span class="pill <?= str_contains($r['action'], 'fail') || str_contains($r['action'], 'block') || $r['action'] === 'forbidden' ? 'pill-red' : 'pill-blue' ?>"><?= e($r['action']) ?></span></td>
        <td data-label="Ringkasan" class="desc"><?= e($r['summary'] ?? '') ?>
          <?php if ($r['before_json'] || $r['after_json']): ?>
            <details class="mt-1"><summary class="muted" style="cursor:pointer;font-size:12.5px">Lihat perubahan</summary>
              <?php if ($r['before_json']): ?><div class="help">Sebelum</div><pre style="white-space:pre-wrap;word-break:break-word;margin:2px 0 8px;font-size:12px"><?= e($r['before_json']) ?></pre><?php endif; ?>
              <?php if ($r['after_json']): ?><div class="help">Sesudah</div><pre style="white-space:pre-wrap;word-break:break-word;margin:2px 0;font-size:12px"><?= e($r['after_json']) ?></pre><?php endif; ?>
            </details>
          <?php endif; ?>
          <?php if ($r['entity']): ?><div class="sub"><?= e($r['entity']) ?> #<?= e($r['entity_id'] ?? '') ?></div><?php endif; ?></td>
        <td data-label="IP" class="nowrap"><?= e($r['ip'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="pager">
    <span class="muted">Halaman <?= (int) $page ?> dari <?= (int) $pages ?></span>
    <div class="pages">
      <?php if ($page > 1): ?><a href="<?= e($link(['page' => $page - 1])) ?>">‹</a><?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?><?php if ($i === $page): ?><span class="pg cur"><?= $i ?></span><?php else: ?><a href="<?= e($link(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($link(['page' => $page + 1])) ?>">›</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
