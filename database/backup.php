<?php
/**
 * database/backup.php — cadangan harian otomatis: database + folder uploads
 * (foto nota, surat perjanjian) + regenerasi database/db_bumi_bahari.sql
 * supaya dump skema/data resmi tidak pernah basi.
 *
 * Jalankan dari CLI:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\warteg-bumi-bahari\database\backup.php
 *
 * Hasil:
 *   database/backups/db_bumi_bahari_YYYYMMDD_HHMMSS.sql   (RETENSI_DB terbaru disimpan)
 *   database/backups/uploads_YYYYMMDD_HHMMSS.zip          (RETENSI_UPLOADS terbaru disimpan)
 *   database/db_bumi_bahari.sql                            (selalu ditimpa dengan data terbaru)
 *
 * Tiap langkah independen — kalau salah satu gagal, yang lain tetap dicoba.
 * Untuk penjadwalan lihat database/README.md (Windows Task Scheduler + backup.bat).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script ini hanya boleh dijalankan dari command line.');
}

const RETENSI_DB      = 30; // jumlah cadangan database yang dipertahankan
const RETENSI_UPLOADS = 14; // jumlah cadangan uploads yang dipertahankan (berkas besar)

$root       = dirname(__DIR__);
$backup_dir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';

if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) {
    fwrite(STDERR, "Gagal membuat folder: $backup_dir\n");
    exit(1);
}

// --- Kredensial: pakai config/db_credentials.php bila ada, jika tidak default root ---
$db = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'db_bumi_bahari'];
$cred_file = $root . '/config/db_credentials.php';
if (is_file($cred_file)) {
    $db = array_merge($db, (array) require $cred_file);
}

function cari_mysqldump(): ?string
{
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mariadb-dump.exe',
        '/usr/bin/mysqldump',
        'mysqldump',
    ];
    foreach ($candidates as $c) {
        if ($c === 'mysqldump' || is_file($c)) {
            return $c;
        }
    }
    return null;
}

/** Jalankan mysqldump dengan argumen tambahan, tulis ke $target. Return true bila sukses. */
function jalankan_mysqldump(string $mysqldump, array $db, array $extra_args, string $target): bool
{
    putenv('MYSQL_PWD=' . $db['pass']);

    $args = array_merge([
        $mysqldump,
        '--host=' . $db['host'],
        '--user=' . $db['user'],
        '--single-transaction',
        '--routines',
        '--triggers',
        '--default-character-set=utf8mb4',
    ], $extra_args, [$db['name']]);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $target, 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($args, $descriptors, $pipes);
    if (!is_resource($proc)) {
        putenv('MYSQL_PWD');
        return false;
    }
    fclose($pipes[0]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    putenv('MYSQL_PWD');

    if ($code !== 0 || !is_file($target) || filesize($target) < 100) {
        fwrite(STDERR, "  mysqldump gagal (exit $code): " . trim($err) . "\n");
        if (is_file($target)) {
            unlink($target);
        }
        return false;
    }
    return true;
}

/** Pangkas berkas cadangan lama sesuai pola & retensi, tersimpan yang terbaru. */
function pangkas_lama(string $pattern, int $retensi): void
{
    $files = glob($pattern);
    if (!$files || count($files) <= $retensi) {
        return;
    }
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $retensi) as $old) {
        unlink($old);
        echo '  hapus lama: ' . basename($old) . "\n";
    }
}

/** Zip seluruh isi $src_dir (rekursif) ke $zip_path. Return true bila sukses & tidak kosong. */
function zip_folder(string $src_dir, string $zip_path): bool
{
    if (!is_dir($src_dir)) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $ada_isi = false;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src_dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isDir()) {
            continue;
        }
        $local = substr($file->getPathname(), strlen($src_dir) + 1);
        $zip->addFile($file->getPathname(), str_replace('\\', '/', $local));
        $ada_isi = true;
    }
    $zip->close();

    if (!$ada_isi) {
        @unlink($zip_path);
        return false;
    }
    return true;
}

$mysqldump = cari_mysqldump();
if ($mysqldump === null) {
    fwrite(STDERR, "mysqldump tidak ditemukan. Sesuaikan path di backup.php.\n");
}
$stamp = date('Ymd_His');
$ada_error = false;

// =============================================================================
// 1. CADANGAN DATABASE (timestamped, tersimpan RETENSI_DB terbaru)
// =============================================================================
echo "[1/3] Backup database...\n";
if ($mysqldump !== null) {
    $target = $backup_dir . DIRECTORY_SEPARATOR . $db['name'] . "_$stamp.sql";
    if (jalankan_mysqldump($mysqldump, $db, [], $target)) {
        echo '  OK  ' . basename($target) . '  (' . number_format(filesize($target) / 1024, 1) . " KB)\n";
        pangkas_lama($backup_dir . DIRECTORY_SEPARATOR . $db['name'] . '_*.sql', RETENSI_DB);
    } else {
        $ada_error = true;
    }
} else {
    $ada_error = true;
}

// =============================================================================
// 2. CADANGAN FOLDER uploads/ (foto nota, surat perjanjian) → zip timestamped
// =============================================================================
echo "[2/3] Backup folder uploads...\n";
$uploads_dir = $root . DIRECTORY_SEPARATOR . 'uploads';
$zip_target  = $backup_dir . DIRECTORY_SEPARATOR . "uploads_$stamp.zip";
if (zip_folder($uploads_dir, $zip_target)) {
    echo '  OK  ' . basename($zip_target) . '  (' . number_format(filesize($zip_target) / 1024 / 1024, 2) . " MB)\n";
    pangkas_lama($backup_dir . DIRECTORY_SEPARATOR . 'uploads_*.zip', RETENSI_UPLOADS);
} else {
    echo "  (tidak ada berkas di uploads/, dilewati)\n";
}

// =============================================================================
// 3. REGENERASI database/db_bumi_bahari.sql — dump skema+data resmi selalu segar
// =============================================================================
echo "[3/3] Regenerasi dump skema resmi...\n";
if ($mysqldump !== null) {
    $dump_resmi = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'db_bumi_bahari.sql';
    $tmp_dump   = $dump_resmi . '.tmp';
    if (jalankan_mysqldump($mysqldump, $db, ['--add-drop-table'], $tmp_dump)) {
        rename($tmp_dump, $dump_resmi);
        echo "  OK  database/db_bumi_bahari.sql diperbarui (" . date('Y-m-d H:i:s') . ")\n";
    } else {
        $ada_error = true;
    }
} else {
    $ada_error = true;
}

exit($ada_error ? 1 : 0);
