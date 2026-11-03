<?php
declare(strict_types=1);
/**
 * Ekspor pemetaan kategori Excel -> kategori + proyek ke Markdown (untuk ditinjau/disetujui pengguna).
 *   php database/tools/export-category-map.php > docs/CATEGORY_MAPPING.md
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Db;

$rows = Db::all(
    'SELECT m.raw_text, c.name AS category, c.kind, p.name AS project,
            (SELECT COUNT(*) FROM transactions t WHERE t.category_raw = m.raw_text) AS n
     FROM category_map m LEFT JOIN categories c ON c.id = m.category_id LEFT JOIN projects p ON p.id = m.project_id
     ORDER BY c.kind, c.name, p.name, m.raw_text'
);
$cats = Db::all("SELECT c.name, c.kind, COUNT(t.id) n FROM categories c LEFT JOIN transactions t ON t.category_id = c.id AND t.voided_at IS NULL GROUP BY c.id ORDER BY FIELD(c.kind,'pendapatan','biaya','pajak','lainnya','pembiayaan','transfer'), c.name");

echo "# Pemetaan Kategori Excel → Kategori + Proyek\n\n";
echo "Dihasilkan otomatis dari tabel `category_map` pada " . date('d/m/Y H:i') . ". **Ini hasil aturan deterministik, bukan tebakan**: hanya penyatuan ejaan/huruf besar-kecil dan sinonim yang jelas; teks yang tidak dikenali menjadi “Belum Dikategorikan”.\n";
echo "Super Admin dapat mengubah pemetaan kapan saja di menu **Master Data → Pemetaan Excel** (perubahan langsung memperbarui transaksi terkait dan dicatat di audit log).\n\n";
echo "Jenis kategori menentukan perlakuan laporan: *pendapatan/biaya/pajak/lainnya* = mutasi masuk/keluar **usaha**; *pembiayaan* (pinjaman & hutang bank) dan *transfer* (pindah dana) **dikeluarkan** dari kas usaha agar tidak terhitung ganda.\n\n";
echo "## Ringkasan kategori hasil normalisasi (" . count($cats) . ")\n\n| Kategori | Jenis | Transaksi aktif |\n|---|---|---:|\n";
foreach ($cats as $c) {
    printf("| %s | %s | %d |\n", $c['name'], $c['kind'], $c['n']);
}
echo "\n## Pemetaan teks Excel (" . count($rows) . " varian)\n\n| Teks di Excel | Kategori | Jenis | Proyek | Baris |\n|---|---|---|---|---:|\n";
foreach ($rows as $r) {
    printf("| %s | %s | %s | %s | %d |\n", $r['raw_text'] === '' ? '*(kosong)*' : str_replace('|', '\\|', $r['raw_text']), $r['category'], $r['kind'], $r['project'] ?? '—', $r['n']);
}
