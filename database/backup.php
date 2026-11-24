<?php
/**
 * database/backup.php — cadangan otomatis (3 hari sekali) + enkripsi AES-256-CBC:
 *   database + folder uploads + regenerasi database/db_bumi_bahari.sql
 *
 * Jalankan dari CLI:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\warteg-bumi-bahari\database\backup.php
 *
 * Hasil di database/backups/:
 *   db_bumi_bahari_YYYYMMDD_HHMMSS.sql.enc   (terenkripsi — RETENSI_DB terbaru)
 *   uploads_YYYYMMDD_HHMMSS.zip.enc          (terenkripsi — RETENSI_UPLOADS terbaru)
 *   database/db_bumi_bahari.sql              (dump resmi — tidak dienkripsi, selalu segar)
 *
 * Enkripsi: AES-256-CBC, kunci 256-bit dari config/backup_key.php (dibuat otomatis sekali).
 * Dekripsi: gunakan database/decrypt_backup.php <file.enc>
 * Tiap langkah independen — kalau salah satu gagal, yang lain tetap dicoba.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script ini hanya boleh dijalankan dari command line.');
}

const RETENSI_DB      = 30; // jumlah cadangan database (.sql.enc) yang dipertahankan
const RETENSI_UPLOADS = 14; // jumlah cadangan uploads (.zip.enc) yang dipertahankan

$root       = dirname(__DIR__);
$backup_dir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';

if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) {
    fwrite(STDERR, "Gagal membuat folder: $backup_dir\n");
    exit(1);
}

// --- Kredensial DB ---
$db = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'db_bumi_bahari'];
$cred_file = $root . '/config/db_credentials.php';
if (is_file($cred_file)) {
    $db = array_merge($db, (array) require $cred_file);
}

// =============================================================================
// ENKRIPSI — kunci AES-256-CBC dari config/backup_key.php
// Jika file belum ada, dibuat otomatis sekali dan ditampilkan di log.
// SIMPAN isi config/backup_key.php di tempat yang aman (di luar mesin ini).
// =============================================================================
$kunci_file = $root . '/config/backup_key.php';

function muat_atau_buat_kunci(string $kunci_file): string
{
    if (is_file($kunci_file)) {
        $hex = trim((string)(require $kunci_file));
        if (strlen($hex) === 64 && ctype_xdigit($hex)) {
            return hex2bin($hex);
        }
        fwrite(STDERR, "PERINGATAN: format kunci di $kunci_file tidak valid. Membuat kunci baru.\n");
    }

    // Buat kunci baru 256-bit (32 byte)
    $bytes = random_bytes(32);
    $hex   = bin2hex($bytes);

    $isi = "<?php\n// Kunci enkripsi backup AES-256-CBC — JANGAN di-commit, SIMPAN di tempat aman!\n// Tanpa kunci ini file .enc TIDAK BISA didekripsi.\nreturn '$hex';\n";
    if (file_put_contents($kunci_file, $isi) === false) {
        fwrite(STDERR, "GAGAL menyimpan kunci ke $kunci_file\n");
        exit(1);
    }

    echo "  [KUNCI BARU] Dibuat: $kunci_file\n";
    echo "  !! PENTING: Salin & simpan kunci ini di luar mesin ini !!\n";
    echo "  Kunci (hex): $hex\n\n";
    return $bytes;
}

$kunci = muat_atau_buat_kunci($kunci_file);

/** Enkripsi $src dengan AES-256-CBC, simpan ke $dest.enc, hapus $src. */
function enkripsi_dan_hapus(string $src, string $kunci): ?string
{
    $data = file_get_contents($src);
    if ($data === false) {
        fwrite(STDERR, "  Gagal membaca $src untuk enkripsi\n");
        return null;
    }

    $iv        = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $kunci, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        fwrite(STDERR, "  Enkripsi gagal: " . openssl_error_string() . "\n");
        return null;
    }

    $dest = $src . '.enc';
    // Format: 16 byte IV + data terenkripsi
    if (file_put_contents($dest, $iv . $encrypted) === false) {
        fwrite(STDERR, "  Gagal menulis $dest\n");
        return null;
    }

    unlink($src); // hapus versi tidak terenkripsi
    return $dest;
}

/** Pangkas berkas cadangan lama, simpan yang terbaru. */
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
$stamp    = date('Ymd_His');
$ada_error = false;

// =============================================================================
// 1. CADANGAN DATABASE → dump SQL → enkripsi → .sql.enc
// =============================================================================
echo "[1/3] Backup database...\n";
if ($mysqldump !== null) {
    $target = $backup_dir . DIRECTORY_SEPARATOR . $db['name'] . "_$stamp.sql";
    if (jalankan_mysqldump($mysqldump, $db, [], $target)) {
        $ukuran_asli = number_format(filesize($target) / 1024, 1);
        $enc = enkripsi_dan_hapus($target, $kunci);
        if ($enc !== null) {
            echo "  OK  " . basename($enc) . "  ($ukuran_asli KB → terenkripsi)\n";
            pangkas_lama($backup_dir . DIRECTORY_SEPARATOR . $db['name'] . '_*.sql.enc', RETENSI_DB);
        } else {
            $ada_error = true;
        }
    } else {
        $ada_error = true;
    }
} else {
    $ada_error = true;
}

// =============================================================================
// 2. CADANGAN uploads/ → zip → enkripsi → .zip.enc
// =============================================================================
echo "[2/3] Backup folder uploads...\n";
$uploads_dir = $root . DIRECTORY_SEPARATOR . 'uploads';
$zip_target  = $backup_dir . DIRECTORY_SEPARATOR . "uploads_$stamp.zip";
if (zip_folder($uploads_dir, $zip_target)) {
    $ukuran_asli = number_format(filesize($zip_target) / 1024 / 1024, 2);
    $enc = enkripsi_dan_hapus($zip_target, $kunci);
    if ($enc !== null) {
        echo "  OK  " . basename($enc) . "  ($ukuran_asli MB → terenkripsi)\n";
        pangkas_lama($backup_dir . DIRECTORY_SEPARATOR . 'uploads_*.zip.enc', RETENSI_UPLOADS);
    } else {
        $ada_error = true;
    }
} else {
    echo "  (tidak ada berkas di uploads/, dilewati)\n";
}

// =============================================================================
// 3. REGENERASI database/db_bumi_bahari.sql — dump skema+data resmi (tidak dienkripsi)
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

echo "\nSelesai" . ($ada_error ? " (ada error — periksa log)" : " tanpa error") . ".\n";
exit($ada_error ? 1 : 0);
