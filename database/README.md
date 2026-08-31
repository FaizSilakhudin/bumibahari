# Database — Warteg Bumi Bahari

Nama database: **`db_bumi_bahari`**

## Struktur

| Tabel | Isi |
|---|---|
| `users` | Akun login (`role`: pusat / pic / cabang / investor) + pelacakan login (`login_attempts`, `lock_until`, `last_login_at`, `last_login_ip`, `password_changed_at`). `id_cabang` dipakai role cabang (1 cabang), `id_investor` dipakai role investor. |
| `cabang` | Master cabang + rekening cabang |
| `pengelola` | Penugasan PIC ke cabang (`id_user` = akun PIC, `id_cabang` = cabang yang dipegang). Satu PIC bisa punya beberapa baris (satu per cabang) — riwayat lewat `tgl_mulai` / `tgl_selesai` / `status`. |
| `investor` | Master investor |
| `cabang_investor` | Relasi cabang ↔ investor beserta periode investasi |
| `laporan_cabang` | Laporan keuangan harian per cabang (unik: `id_cabang` + `tanggal`). `status_laporan` ('menunggu' / 'lengkap') menandai apakah PIC sudah mengisi angka keuangan; `id_user_nota` / `id_user_laporan` mencatat siapa kirim nota vs siapa isi laporan. |
| `audit_log` | Jejak audit seluruh aktivitas penting (login, edit laporan, CRUD user/cabang/pengelola/investor) |
| `laporan_cabang_arsip` | Snapshot lengkap (`data_json`) setiap baris `laporan_cabang` yang dihapus dari `admin_pusat/laporan.php` — dibuat WAJIB sebelum hapus, bisa dipulihkan lewat `admin_pusat/arsip_laporan.php`. Data lama tidak pernah hilang permanen. |

`users` juga punya `totp_secret` / `totp_enabled` / `totp_backup_codes` untuk 2FA (opsional, role `pusat`) — lihat bagian Keamanan di bawah.

## Alur kerja laporan harian (sejak Agustus 2026)

1. **Cabang** login → hanya kirim 4 foto nota (`admin_cabang/input_data.php`). Baris `laporan_cabang` dibuat/di-update dengan `status_laporan='menunggu'`.
2. **PIC** (memegang beberapa cabang lewat tabel `pengelola`) login → lihat antrian di `admin_pic/index.php`, cek foto nota, isi angka omzet/belanja/operasional di `admin_pic/input_laporan.php`. Setelah disimpan, `status_laporan='lengkap'`.
3. **Investor** login → dashboard read-only agregat (`investor/dashboard.php`), hanya cabang yang aktif di `cabang_investor`, hanya menghitung laporan `status_laporan='lengkap'`.
4. **Pusat** memantau semua (`admin_pusat/index.php`, `rekapitulasi.php`, `laporan.php`) — dashboard/rekap hanya menghitung laporan yang sudah `lengkap`; pusat tetap bisa mengoreksi langsung lewat modal "Koreksi Laporan Harian" di `laporan.php` (otomatis menandai `lengkap`).

Migrasi terkait: `database/migrations/2026_08_31_000001_pic_investor_login.sql`.

## Restore / setup awal

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS db_bumi_bahari CHARACTER SET utf8mb4"
mysql -u root db_bumi_bahari < database/db_bumi_bahari.sql
```

`database/db_bumi_bahari.sql` adalah **dump resmi** (struktur + data). Regenerasi setelah perubahan skema:

```bash
mysqldump -u root --databases db_bumi_bahari --add-drop-table --single-transaction --routines --default-character-set=utf8mb4 > database/db_bumi_bahari.sql
```

## Kredensial aplikasi (least privilege)

Aplikasi **tidak** memakai `root`. Buat user khusus sekali saja:

```sql
CREATE USER IF NOT EXISTS 'wbb_app'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON db_bumi_bahari.* TO 'wbb_app'@'localhost';
FLUSH PRIVILEGES;
```

Lalu salin `config/db_credentials.example.php` → `config/db_credentials.php` (tidak di-commit) dan isi passwordnya. Tanpa file itu, aplikasi jatuh ke `root` tanpa password (hanya cocok untuk dev).

## Cadangan otomatis

`database/backup.php` melakukan 3 hal setiap dijalankan (folder `database/backups/` di-gitignore):

1. Dump database → `database/backups/db_bumi_bahari_YYYYMMDD_HHMMSS.sql` (30 berkas terbaru disimpan).
2. Zip folder `uploads/` (foto nota, surat perjanjian) → `database/backups/uploads_YYYYMMDD_HHMMSS.zip` (14 berkas terbaru disimpan — lebih sedikit karena ukurannya besar).
3. **Regenerasi `database/db_bumi_bahari.sql`** (dump resmi struktur+data terbaru) — jadi berkas ini tidak akan pernah basi, selalu mencerminkan isi database terkini.

```bash
C:\xampp\php\php.exe C:\xampp\htdocs\warteg-bumi-bahari\database\backup.php
```

**Sudah terjadwal** lewat Windows Task Scheduler — task **`SIMC-WBB Backup Harian`**, jalan setiap hari jam **02:00** (memanggil `backup.bat`, log ke `database/backups/_backup.log`). Task ini terdaftar untuk user Windows yang aktif saat ini (`Run only when user is logged on`) — kalau PC ini akan sering ditinggal logout/restart tanpa auto-login, ubah task itu di Task Scheduler GUI ke *Run whether user is logged on or not* (klik kanan task → Properties → General; akan diminta password Windows sekali).

Simpan sebagian berkas cadangan (SQL + zip uploads) di lokasi terpisah (drive lain / cloud) secara berkala — backup lokal saja tidak melindungi dari kerusakan disk/PC.

## Migrasi

Perubahan skema baru ditaruh di `database/migrations/` dengan penamaan `YYYY_MM_DD_NNNNNN_nama.sql`, aman diulang (`IF NOT EXISTS`). Jalankan:

```bash
mysql -u root db_bumi_bahari < database/migrations/NAMA_FILE.sql
```

Sudah diterapkan:
- `2026_08_28_000001_security_and_audit.sql` — tabel `audit_log`, kolom login-tracking di `users`, index tambahan.
- `2026_08_31_000001_pic_investor_login.sql` — role `pic`/`investor`, `users.id_investor`, `laporan_cabang.status_laporan`/`id_user_nota`/`id_user_laporan`/`keterangan_nota`.
- `2026_09_01_000001_arsip_laporan_terhapus.sql` — tabel `laporan_cabang_arsip` (snapshot sebelum hapus).
- `2026_09_01_000002_2fa_pusat.sql` — kolom `totp_secret`/`totp_enabled`/`totp_backup_codes` di `users`.

## Jaring pengaman otomatis (tests)

`tests/` berisi test suite murni PHP (tanpa Composer/PHPUnit) untuk bagian paling berisiko kalau diam-diam berubah: rumus keuangan (`config/keuangan.php`) dan algoritma 2FA (`config/totp.php`). **Jalankan sebelum & sesudah mengubah salah satu file itu, atau `admin_pic/input_laporan.php` / `admin_pusat/laporan.php`:**

```bash
C:\xampp\php\php.exe tests\run.php
```

Rumus keuangan sekarang HANYA ada di satu tempat (`config/keuangan.php`, fungsi `hitung_laporan_harian()`) — dipakai baik oleh form PIC maupun modal koreksi pusat, jadi tidak mungkin dua tempat itu diam-diam punya rumus berbeda.

## Keamanan

- **2FA (TOTP)** opsional untuk akun `pusat` — aktifkan sendiri lewat `admin_pusat/keamanan_2fa.php` (kompatibel Google Authenticator/Authy/dll, plus 8 kode cadangan sekali pakai). Login akun pusat yang sudah aktifkan 2FA akan diarahkan ke `verify_2fa.php` setelah password benar.
- **Arsip sebelum hapus** — laporan yang dihapus di `admin_pusat/laporan.php` otomatis disnapshot ke `laporan_cabang_arsip` dulu; kalau snapshot gagal dibuat, penghapusan **dibatalkan** (tidak pernah hapus tanpa cadangan). Pulihkan kapan saja lewat `admin_pusat/arsip_laporan.php`.
- **Riwayat koreksi lengkap** — tiap kali pusat mengoreksi laporan lewat modal "Koreksi Laporan Harian", nilai lama (seluruh kolom, bukan cuma ringkasan) disimpan ke `audit_log` sebelum ditimpa.
- Dropdown filter tahun (dashboard pusat & investor) otomatis menyesuaikan ke tahun data paling lama di `laporan_cabang` (`tahun_data_paling_lama()` di `config/koneksi.php`) — data lama tidak pernah "hilang" dari pilihan filter walau bertahun-tahun kemudian.
