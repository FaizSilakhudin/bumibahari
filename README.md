# SIMC-WBB — Sistem Informasi Manajemen Cabang Warteg Bumi Bahari

Sistem manajemen operasional & keuangan harian untuk jaringan warteg Bumi Bahari (100+ cabang). PHP prosedural murni + mysqli (tanpa framework, tanpa Composer) di atas MySQL/MariaDB.

## Peran (role) & alur kerja

| Role | Akses |
|---|---|
| **pusat** | Akses penuh — dashboard, laporan, rekapitulasi, kelola cabang/pengelola/investor/user, koreksi laporan, arsip, audit log, 2FA |
| **pic** | Memegang beberapa cabang (lewat tabel `pengelola`) — review foto nota, isi laporan keuangan harian, punya dashboard/laporan/rekapitulasi sendiri (scoped hanya ke cabang yang dipegang) |
| **cabang** | Hanya kirim foto nota harian (tidak input angka keuangan) |
| **investor** | Login read-only, lihat ringkasan cabang yang diinvestasikan |

**Alur laporan harian**: Cabang kirim foto nota (`status_laporan='menunggu'`) → PIC review nota & isi angka omzet/belanja/operasional (`status_laporan='lengkap'`) → Pusat pantau semua & bisa koreksi langsung.

## Struktur kode

- `admin_pusat/`, `admin_pic/`, `admin_cabang/`, `investor/` — satu folder per role, masing-masing dengan `sidebar.php` sendiri
- `config/koneksi.php` — inti: koneksi DB, helper keamanan (`h()`, `csrf_token()`/`csrf_check()`, `require_role()`), helper atribusi historis (`pengelola_pada_tanggal()`, `investor_pada_tanggal()`, `anchor_periode()`)
- `config/keuangan.php` — satu-satunya tempat rumus laporan harian (`hitung_laporan_harian()`), dipakai form PIC maupun modal koreksi pusat
- `config/totp.php` — 2FA (TOTP) untuk akun pusat, zero-dependency
- `config/pagination.php` — komponen pagination bersama
- `database/` — migrations, schema dump (`db_bumi_bahari.sql`), backup otomatis (`backup.php`), dokumentasi lengkap di `database/README.md`
- `tests/` — test suite murni PHP (`tests/run.php` menjalankan semua `*_test.php`), tanpa PHPUnit

## Keamanan (ringkas — detail di `database/README.md`)

- Kredensial DB & mode lingkungan lewat `config/db_credentials.php`/`config/env.php` (gitignored, tidak pernah di-commit)
- CSRF token + prepared statements di semua form/query
- 2FA (TOTP) opsional untuk akun pusat
- Arsip wajib sebelum hard-delete laporan (`laporan_cabang_arsip`) — data lama tidak pernah hilang permanen
- Audit trail (`audit_log`) untuk aksi penting
- Foto nota baru otomatis dikompresi saat upload; foto lama tidak pernah disentuh
- Atribusi historis (pengelola/investor per periode) supaya laporan lama tetap benar walau sudah ada rotasi

## Status deployment saat ini (per 2026-09-01)

- **Production**: `wartegbumibahari.co.id` (aaPanel, nginx, PHP 8.1, MariaDB), sudah di-deploy sampai commit terbaru dari `main`.
- **Database production**: `db_bumi_bahari` (user MySQL least-privilege dengan nama sama). Skema lengkap (8 tabel: `cabang`, `cabang_investor`, `investor`, `laporan_cabang`, `users`, `pengelola`, `audit_log`, `laporan_cabang_arsip`).
- **Database lokal**: disinkronkan penuh dari production (`database/backups/LOCAL_before_sync_from_prod_*.sql` adalah cadangan isi lokal sebelum sinkronisasi ini, kalau perlu dibandingkan).
- CI (`.github/workflows/tests.yml`) menjalankan `tests/run.php` otomatis di tiap push/PR.
- Backup database + `uploads/` terjadwal otomatis harian (Windows Task Scheduler di mesin dev; lihat `database/README.md` untuk status backup production).

## Menjalankan lokal

Butuh XAMPP (Apache + PHP 8.1+ + MySQL/MariaDB, ekstensi `mysqli`, `gd`, `zip`).

```bash
# Setup awal
mysql -u root -e "CREATE DATABASE IF NOT EXISTS db_bumi_bahari CHARACTER SET utf8mb4"
mysql -u root db_bumi_bahari < database/db_bumi_bahari.sql
cp config/db_credentials.example.php config/db_credentials.php   # sesuaikan isinya

# Test
C:\xampp\php\php.exe tests\run.php
```

Detail lengkap (migrasi, backup, kredensial, keamanan) ada di [`database/README.md`](database/README.md).
