<?php /** @var array $notes @var bool $isAdmin @var array $f @var array $categories */ ?>
<section class="card">
  <form class="filters" id="noteFilter" method="get" action="<?= e(url('/notes')) ?>">
    <div class="field grow"><label for="q">Cari judul / isi</label><input class="input" type="search" id="q" name="q" value="<?= e($f['q']) ?>" maxlength="100" placeholder="Ketik untuk mencari…"></div>
    <div class="field"><label for="category">Kategori</label>
      <select class="input" id="category" name="category"><option value="">Semua</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $f['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="from">Dari tanggal</label><input class="input" type="date" id="from" name="from" value="<?= e($f['from'] ?? '') ?>"></div>
    <div class="field"><label for="to">Sampai tanggal</label><input class="input" type="date" id="to" name="to" value="<?= e($f['to'] ?? '') ?>"></div>
    <div class="field" style="flex:0 0 auto;justify-content:flex-end"><label class="check"><input type="checkbox" name="important" value="1" <?= $f['important'] ? 'checked' : '' ?>>Hanya penting</label></div>
    <div class="field" style="flex:0 0 auto;flex-direction:row;gap:8px"><button class="btn" type="submit" data-no-lock>Cari</button><a class="btn btn-ghost" href="<?= e(url('/notes')) ?>">Reset</a></div>
  </form>
</section>

<section id="notesList" data-query="<?= e(qs_merge(['q' => $f['q'], 'category' => $f['category'] ?: null, 'from' => $f['from'], 'to' => $f['to'], 'important' => $f['important'] ? '1' : null])) ?>">
  <?= \App\Core\View::partial('notes/_list', ['notes' => $notes, 'isAdmin' => $isAdmin, 'f' => $f]) ?>
</section>

<!-- modal baca -->
<div class="modal-back" id="noteView" aria-hidden="true">
  <div class="modal lg" role="dialog" aria-modal="true" aria-labelledby="nvTitle">
    <div class="modal-head"><h3 id="nvTitle"></h3><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-close-modal aria-label="Tutup"><svg class="i"><use href="#i-x"/></svg></button></div>
    <div class="modal-body">
      <div class="muted" id="nvMeta" style="font-size:12.5px;margin-bottom:12px"></div>
      <div class="note-full" id="nvBody"></div>
    </div>
    <div class="modal-foot">
      <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-danger" id="nvDelete"><svg class="i i-sm"><use href="#i-trash"/></svg>Hapus</button>
        <button type="button" class="btn btn-orange" id="nvEdit"><svg class="i i-sm"><use href="#i-edit"/></svg>Ubah</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" data-close-modal>Tutup</button>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- modal tambah/ubah -->
<div class="modal-back" id="noteForm" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="nfTitle">
    <form id="noteFormEl" novalidate data-ajax>
      <div class="modal-head"><h3 id="nfTitle">Tambah Catatan</h3><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-close-modal aria-label="Tutup"><svg class="i"><use href="#i-x"/></svg></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="">
        <div class="form-grid">
          <div class="field full"><label for="nfTitleIn">Judul catatan <span class="req">*</span></label><input class="input" id="nfTitleIn" name="title" maxlength="200" required><div class="err" data-err="title"></div></div>
          <div class="field full"><label for="nfBody">Isi catatan <span class="req">*</span></label><textarea class="input" id="nfBody" name="body" maxlength="10000" rows="7" required></textarea><div class="err" data-err="body"></div></div>
          <div class="field"><label for="nfCat">Kategori (opsional)</label>
            <select class="input" id="nfCat" name="category_id"><option value="">— tanpa kategori —</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="nfCatNew">atau kategori baru</label><input class="input" id="nfCatNew" name="category_new" maxlength="80" placeholder="mis. Pengingat pajak"></div>
          <div class="field full"><label class="check"><input type="checkbox" name="is_important" value="1" id="nfImp">Tandai penting</label></div>
        </div>
        <div class="alert alert-danger hidden mt-2" id="nfError" role="alert"></div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal>Batal</button><button type="submit" class="btn" id="nfSave" data-no-lock>Simpan</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
