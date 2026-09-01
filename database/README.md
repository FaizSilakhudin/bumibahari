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

Simpan sebagian berkas cadangan (SQL + zip uploads) di lokasi terpisah (drive lain / cloud) secara berkala — backup lokal saja tidak melindungi dari kerusakan disk/PC. **Status saat ini: backup HANYA lokal** (folder `database/backups/` di server ini) — sinkronisasi ke cloud (OneDrive/Google Drive) belum diaktifkan karena OneDrive di komputer ini belum login/jalan saat pengecekan terakhir (2026-09-01). Kalau mau diaktifkan: login OneDrive (atau pasang Google Drive Desktop), lalu arahkan tujuan backup di `database/backup.php` ke folder sync itu.

## CI — test otomatis di GitHub Actions

`.github/workflows/tests.yml` menjalankan `tests/run.php` otomatis di setiap push/PR (branch apa saja) — servis MySQL sementara di-provision oleh GitHub Actions, skema dimuat dari `database/db_bumi_bahari.sql`. Kalau lupa jalankan test manual sebelum push, CI akan menangkapnya.

## Export CSV/Excel

`admin_pusat/laporan.php` dan `admin_pic/laporan.php` (khusus cabang yang dipegang) punya tombol **Export CSV** yang mengambil data sesuai filter tanggal/cabang yang sedang aktif di halaman, dibuka langsung oleh Excel. `rekapitulasi.php` sengaja tidak ditambah CSV karena sudah ada Export PDF (format matrix per-cabang lebih cocok dicetak/dibagikan daripada tabel datar CSV).

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

## Atribusi historis (rolling pengelola & investor)

Cabang bisa ganti pengelola atau investor kapan saja (tabel `pengelola` dan `cabang_investor` sudah punya `tgl_mulai`/`tgl_selesai` per periode). Supaya laporan/rekap tahun-tahun lalu tetap menampilkan pengelola/investor yang **benar-benar menjabat saat itu** — bukan siapa yang aktif sekarang — semua tampilan histori (laporan harian, laporan mingguan, rekapitulasi, riwayat investasi) WAJIB memanggil:

- `pengelola_pada_tanggal($conn, $id_cabang, $tanggal)`
- `investor_pada_tanggal($conn, $id_cabang, $tanggal)`

(keduanya di `config/koneksi.php`) dengan `$tanggal` = tanggal/periode laporan yang sedang ditampilkan — **bukan** `date('Y-m-d')`. Pengecualian yang SENGAJA tetap memakai "sekarang": identitas sesi login, form input hari berjalan, dan widget "Peringatan Dini" (itu memang menampilkan kontak pengelola SAAT INI untuk ditelepon/dihubungi, bukan riwayat).

**Untuk RINGKASAN satu periode (laporan mingguan, rekapitulasi bulanan, dashboard)** — bukan satu tanggal tunggal — WAJIB pakai `anchor_periode($tgl_akhir_periode)` (juga di `config/koneksi.php`) sebagai `$tanggal`, **bukan** tanggal awal periode. Alasan (bug nyata yang pernah terjadi, diperbaiki 2026-09-01): relasi pengelola/investor baru sering baru tercatat di TENGAH periode (mis. investor terdaftar tanggal 28, padahal laporan bulan itu baru ada dari tanggal 30) — kalau anchornya AWAL periode, hasilnya salah tampil "-" (dianggap tidak tersambung) walau sebenarnya sudah tersambung sejak pertengahan periode itu. `anchor_periode()` memakai akhir periode (dibatasi maksimal hari ini, supaya tidak "melihat masa depan" untuk periode yang masih berjalan).

Diuji otomatis di `tests/periode_test.php` (skenario rotasi pengelola & investor + `anchor_periode()`, isolasi lewat transaksi + rollback — tidak pernah menyentuh data live).

## Kompresi foto nota baru

Sejak 2026-09-01, foto nota yang **baru** diupload (`admin_cabang/input_data.php`) otomatis diperkecil (resize maksimal 1600px sisi terpanjang + re-encode JPEG kualitas 78) lewat `kompres_gambar_upload()` di `config/koneksi.php` — butuh ekstensi PHP **GD** (sudah diaktifkan di `php.ini`). Ini utamanya menghemat pertumbuhan ukuran folder `uploads/` untuk pemakaian jangka panjang (backup zip juga jadi lebih cepat & kecil).

**Foto lama TIDAK PERNAH disentuh** — fungsi ini hanya jalan sekali, tepat setelah file baru selesai diupload. Kalau GD ternyata mati atau proses kompresi gagal di titik manapun, file asli hasil upload dibiarkan apa adanya (tidak pernah dihapus atau dirusak).
