<?php
/**
 * database/decrypt_backup.php — dekripsi file backup .enc hasil backup.php
 *
 * Penggunaan:
 *   C:\xampp\php\php.exe database\decrypt_backup.php database\backups\db_bumi_bahari_YYYYMMDD_HHMMSS.sql.enc
 *   C:\xampp\php\php.exe database\decrypt_backup.php database\backups\uploads_YYYYMMDD_HHMMSS.zip.enc
 *
 * Hasil: file dekripsi di lokasi yang sama tanpa ekstensi .enc
 *   db_bumi_bahari_YYYYMMDD_HHMMSS.sql.enc  →  db_bumi_bahari_YYYYMMDD_HHMMSS.sql
 *   uploads_YYYYMMDD_HHMMSS.zip.enc         →  uploads_YYYYMMDD_HHMMSS.zip
 *
 * Butuh: config/backup_key.php (kunci enkripsi yang dipakai saat backup dibuat).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script ini hanya boleh dijalankan dari command line.');
}

$root      = dirname(__DIR__);
$kunci_file = $root . '/config/backup_key.php';

if (!is_file($kunci_file)) {
    fwrite(STDERR, "Kunci enkripsi tidak ditemukan: $kunci_file\n");
    fwrite(STDERR, "Pastikan file config/backup_key.php ada dan berisi kunci yang sama dengan saat backup dibuat.\n");
    exit(1);
}

$hex = trim((string)(require $kunci_file));
if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
    fwrite(STDERR, "Format kunci tidak valid di $kunci_file (harus 64 karakter hex).\n");
    exit(1);
}
$kunci = hex2bin($hex);

if ($argc < 2 || empty($argv[1])) {
    echo "Penggunaan: php decrypt_backup.php <file.enc>\n";
    echo "Contoh   : php decrypt_backup.php database\\backups\\db_bumi_bahari_20261124_020000.sql.enc\n";
    exit(1);
}

$enc_file = $argv[1];
if (!is_file($enc_file)) {
    fwrite(STDERR, "File tidak ditemukan: $enc_file\n");
    exit(1);
}

if (!str_ends_with($enc_file, '.enc')) {
    fwrite(STDERR, "File bukan hasil enkripsi backup (ekstensi .enc diharapkan): $enc_file\n");
    exit(1);
}

$output_file = substr($enc_file, 0, -4); // hapus .enc
if (is_file($output_file)) {
    fwrite(STDERR, "File output sudah ada: $output_file\n");
    fwrite(STDERR, "Hapus dulu atau pindahkan sebelum menjalankan dekripsi.\n");
    exit(1);
}

echo "Mendekripsi: " . basename($enc_file) . "\n";

$raw = file_get_contents($enc_file);
if ($raw === false || strlen($raw) <= 16) {
    fwrite(STDERR, "File enkripsi tidak valid atau kosong.\n");
    exit(1);
}

// Format: 16 byte IV + data terenkripsi
$iv        = substr($raw, 0, 16);
$encrypted = substr($raw, 16);

$decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $kunci, OPENSSL_RAW_DATA, $iv);
if ($decrypted === false) {
    fwrite(STDERR, "Dekripsi gagal — kunci mungkin salah atau file rusak.\n");
    fwrite(STDERR, "OpenSSL: " . openssl_error_string() . "\n");
    exit(1);
}

if (file_put_contents($output_file, $decrypted) === false) {
    fwrite(STDERR, "Gagal menyimpan hasil dekripsi ke: $output_file\n");
    exit(1);
}

echo "  OK  → " . basename($output_file) . "  (" . number_format(strlen($decrypted) / 1024, 1) . " KB)\n";
echo "\nUntuk restore database:\n";
echo "  mysql -u root db_bumi_bahari < " . basename($output_file) . "\n";
echo "\nUntuk extract uploads:\n";
echo "  Buka file ZIP dengan Windows Explorer atau WinZip/7-Zip.\n";
