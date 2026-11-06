<?php
/** @var array $f @var array $accounts @var array $categories @var array $projects @var array $parties @var array $base @var bool $canExport @var bool $isAdmin @var bool $includeVoid */
$kinds = \App\Models\Lookup::KIND_LABEL;
?>
<section class="card">
  <form class="filters" id="txFilter" method="get" action="<?= e(url('/transactions')) ?>">
    <div class="field grow">
      <label for="q">Cari</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Uraian, kode bukti, pihak, no. referensi…" maxlength="100">
    </div>
    <div class="field">
      <label for="account">Rekening</label>
      <select class="input" id="account" name="account">
        <option value="">Semua</option>
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <details class="collapse-m" style="flex:1 1 100%" <?= ($f['dir'] || $f['party'] || $f['category'] || $f['project'] || $f['from'] || $f['to'] || !empty($f['flagged']) || $includeVoid) ? 'open' : '' ?>>
    <summary class="btn btn-ghost btn-sm">Filter lanjutan</summary>
    <div class="filters" style="margin-top:12px">
    <div class="field">
      <label for="dir">Jenis</label>
      <select class="input" id="dir" name="dir">
        <option value="">Mutasi Masuk & Keluar</option>
        <option value="in" <?= $f['dir'] === 'in' ? 'selected' : '' ?>>Mutasi Masuk</option>
        <option value="out" <?= $f['dir'] === 'out' ? 'selected' : '' ?>>Mutasi Keluar</option>
      </select>
    </div>
    <div class="field">
      <label for="party">Rekening Lawan</label>
      <select class="input" id="party" name="party">
        <option value="">Semua</option>
        <?php foreach ($parties as $pt): ?><option value="<?= e($pt['name']) ?>" <?= $f['party'] === $pt['name'] ? 'selected' : '' ?>><?= e($pt['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="category">Kategori</label>
      <select class="input" id="category" name="category">
        <option value="">Semua</option>
        <?php $cur = null; foreach ($categories as $c): if ($cur !== $c['kind']) { if ($cur !== null) echo '</optgroup>'; $cur = $c['kind']; echo '<optgroup label="' . e($kinds[$c['kind']] ?? $c['kind']) . '">'; } ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $f['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; if ($cur !== null) echo '</optgroup>'; ?>
      </select>
    </div>
    <div class="field">
      <label for="project">Proyek</label>
      <select class="input" id="project" name="project">
        <option value="">Semua</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $f['project'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="from">Dari</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($f['from'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="to">Sampai</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($f['to'] ?? '') ?>">
    </div>
    <?php if ($isAdmin): ?>
    <div class="field" style="flex:0 0 auto;justify-content:flex-end">
      <label class="check"><input type="checkbox" name="flagged" value="1" <?= !empty($f['flagged']) ? 'checked' : '' ?>>Perlu ditinjau</label>
      <label class="check"><input type="checkbox" name="void" value="1" <?= $includeVoid ? 'checked' : '' ?>>Tampilkan yang dibatalkan</label>
    </div>
    <?php endif; ?>
    </div>
    </details>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px">
      <button class="btn" type="submit" data-no-lock>Terapkan</button>
      <a class="btn btn-ghost" href="<?= e(url('/transactions')) ?>">Reset</a>
    </div>
  </form>
</section>

<section class="card card-flush" id="txResults" data-query="<?= e(qs_merge($base, ['page' => $page > 1 ? $page : null])) ?>">
  <?= \App\Core\View::partial('transactions/_results', get_defined_vars()) ?>
</section>
