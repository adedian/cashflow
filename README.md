# Cashflow HME — PT Hexa Multi Energi

Aplikasi internal pencatatan & pemantauan arus kas: PHP 8 native (tanpa framework backend), MariaDB/MySQL (PDO), HTML/CSS/JS sendiri (tanpa pustaka luar). Berjalan di XAMPP (Windows) dan siap dipindah ke VPS/hosting. **Versi PHP:** ≥ 8.0; XAMPP lokal kini memakai **PHP 8.3.36** (kompatibilitas diperiksa 13 Nov 2026).

- **Peran:** *Super Admin* (kelola data & pengguna) dan *Viewer* (hanya baca). Tidak ada registrasi publik.
- **Impor:** workbook Excel cash flow (pemetaan sheet di `config/import_profile.php`, contoh di `config/import_profile.example.php`) dan Impor Excel berpemetaan untuk transaksi/hutang.
- **Realtime:** polling AJAX berversi (bukan WebSocket) — perubahan satu pengguna muncul di sesi pengguna lain tanpa refresh.

> Repositori ini hanya berisi kode. Data usaha (backup database, profil impor nyata, dokumen analisis, dan skrip uji) tidak disertakan.

## Mulai cepat (XAMPP)

1. Nyalakan **Apache** dan **MySQL** di XAMPP Control Panel.
2. Buat berkas `.env` di akar proyek (jangan di-commit) dengan isi seperti di bawah, sesuaikan koneksi database; impor `database/schema.sql` (lalu `database/migrations/*.sql` berurutan).

   ```ini
   APP_ENV=production            # production | test
   APP_DEBUG=0                   # 1 hanya untuk pengembangan lokal (menampilkan pesan galat)
   APP_BASE=/cashflow            # subfolder URL; kosongkan bila aplikasi di akar domain
   APP_URL=http://localhost/cashflow
   APP_TIMEZONE=Asia/Jakarta

   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=cashflow_hme
   DB_USER=cashflow_hme_app      # pengguna DB khusus aplikasi (hak minimum; lihat database/tools/grants.sql)
   DB_PASS=isi_password_kuat

   MYSQLDUMP_PATH=C:/xampp/mysql/bin/mysqldump.exe
   MYSQL_PATH=C:/xampp/mysql/bin/mysql.exe
   SESSION_IDLE_MINUTES=120
   ```
3. Buat akun Super Admin: `php database/tools/create-user.php --username=... --name="..." --role=super_admin --out=storage/kredensial.txt`, lalu buka <http://localhost/cashflow/>. Segera ganti password setelah login.

## Struktur

```
app/        Core (router, DB, sesi, CSRF, auth), Controllers, Models, Services (logika bisnis), Views
config/     routes.php — satu-satunya daftar rute + hak akses (deny by default); import_profile.example.php
database/   schema.sql, migrations/, tools/ (CLI: buat akun, impor, backup, restore, ekspor pemetaan)
public/     index.php (front controller), assets/ (css, js, images)
storage/    logs, uploads, backups, cache — nonpublik (Require all denied)
```

## Perintah CLI

```bash
php database/tools/create-user.php --username=nama --name="Nama" --role=viewer|super_admin --out=storage/kredensial.txt
php database/tools/create-user.php --username=nama --reset --out=storage/kredensial.txt     # atur ulang password
php database/tools/import-excel.php "berkas.xlsx"                       # pratinjau/validasi saja
php database/tools/import-excel.php "berkas.xlsx" --commit --system     # simpan (dicatat sebagai proses sistem)
php database/tools/backup.php                                           # backup + verifikasi
php database/tools/restore.php --file=storage/backups/backup-....sql --db=cashflow_hme_restore --confirm --drop
php database/tools/export-category-map.php > CATEGORY_MAPPING.md
```

## PWA (aplikasi yang dapat dipasang)

- `GET /manifest.webmanifest`, `GET /sw.js`, `GET /offline` dilayani `PwaController` (rute publik; `start_url`/`scope` mengikuti `APP_BASE`). Sumber service worker: `app/Views/pwa/sw.js`.
- Service worker hanya men-cache aset statis (`/assets/*`) dan halaman offline. Halaman & `/api/*` selalu dari jaringan — data keuangan tidak disimpan di perangkat. Saat offline, navigasi menampilkan halaman "Anda sedang offline".
- Cache otomatis berganti saat aset berubah (versi dari `filemtime`). Ikon: `public/assets/images/icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png`.
- Pemasangan memerlukan HTTPS (kecuali `localhost`).
