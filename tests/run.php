<?php
/**
 * Jalankan SEMUA test suite di folder ini sekaligus:
 *     C:\xampp\php\php.exe tests\run.php
 *
 * Tidak butuh PHPUnit/Composer. Tambah file test baru di sini dengan pola
 * *_test.php dan otomatis ikut dijalankan.
 */

$dir = __DIR__;
$files = glob($dir . '/*_test.php');
sort($files);

if (!$files) {
    echo "Tidak ada file *_test.php ditemukan di tests/.\n";
    exit(1);
}

$gagal_total = 0;
foreach ($files as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    $exit = 0;
    passthru((PHP_BINARY ?: 'php') . ' ' . escapeshellarg($file), $exit);
    if ($exit !== 0) {
        $gagal_total++;
    }
}

echo "\n==============================\n";
if ($gagal_total > 0) {
    echo "$gagal_total dari " . count($files) . " file test GAGAL.\n";
    exit(1);
}
echo "Semua " . count($files) . " file test lolos.\n";
exit(0);
