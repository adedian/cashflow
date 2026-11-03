<?php /** @var array $notes @var bool $isAdmin @var array $f */ ?>
<?php if (!$notes): ?>
  <div class="card"><div class="empty"><svg class="i"><use href="#i-note"/></svg><p><?= ($f['q'] ?? '') !== '' || !empty($f['category']) || !empty($f['from']) || !empty($f['to']) || !empty($f['important']) ? 'Tidak ada catatan yang cocok.' : 'Belum ada catatan.' ?></p></div></div>
<?php else: ?>
<div class="grid g-3" id="noteGrid">
  <?php foreach ($notes as $n): ?>
    <article class="note-card <?= $n['is_important'] ? 'imp' : '' ?>" data-note-id="<?= (int) $n['id'] ?>" tabindex="0" role="button" aria-label="Buka catatan <?= e($n['title']) ?>">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
        <h3><?= e($n['title']) ?></h3>
        <?php if ($n['is_important']): ?><span class="pill pill-yellow" title="Penting"><svg class="i i-sm"><use href="#i-star"/></svg>Penting</span><?php endif; ?>
      </div>
      <div class="body"><?= e($n['body']) ?></div>
      <div class="meta">
        <?php if ($n['category_name']): ?><span class="pill pill-blue"><?= e($n['category_name']) ?></span><?php endif; ?>
        <span><?= e($n['author'] ?? '-') ?> · <?= e(fmt_date($n['created_at'], 'd/m/Y H:i')) ?></span>
        <?php if ($n['updated_at'] !== $n['created_at']): ?><span>· diperbarui <?= e(fmt_date($n['updated_at'], 'd/m/Y H:i')) ?></span><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
