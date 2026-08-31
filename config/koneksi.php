<?php
/**
 * config/koneksi.php — inti koneksi + keamanan.
 * Di-require oleh SEMUA halaman. Menyediakan:
 *   $conn                       (mysqli)
 *   h(), csrf_token(), csrf_check()
 *   client_ip(), current_user_id(), current_role(), current_username()
 *   require_login(), require_role()
 *   audit()                     (pencatatan audit trail)
 *
 * Tampilan tidak berubah; hanya pengerasan keamanan & util.
 */

// ---------------------------------------------------------------------------
// 0. Buffer output → header()/redirect tetap jalan walau ada output duluan.
// ---------------------------------------------------------------------------
if (ob_get_level() === 0) {
    ob_start();
}

// ---------------------------------------------------------------------------
// 1. Lingkungan (production vs development)
//    - Ada file config/env.php  -> pakai isinya  (return 'production';)
//    - Kalau tidak, dari localhost -> development, selain itu -> production.
// ---------------------------------------------------------------------------
if (!defined('APP_ENV')) {
    $__env = 'production';
    if (is_file(__DIR__ . '/env.php')) {
        $__env = trim((string) (require __DIR__ . '/env.php')) ?: 'production';
    } elseif (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        $__env = 'development';
    }
    define('APP_ENV', $__env);
}

ini_set('log_errors', '1');
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

// ---------------------------------------------------------------------------
// 2. HTTPS + header keamanan dasar (tidak mempengaruhi tampilan)
//    HTTPS hanya dipaksa saat production; localhost/dev tidak terpengaruh.
// ---------------------------------------------------------------------------
$__https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

if (APP_ENV === 'production' && !$__https && PHP_SAPI !== 'cli' && !headers_sent()) {
    $__host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__uri  = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $__host . $__uri, true, 301);
    exit;
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (APP_ENV === 'production' && $__https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header_remove('X-Powered-By');
}

// ---------------------------------------------------------------------------
// 3. Session yang lebih aman  (WAJIB sebelum session_start)
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $__secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $__secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    // Regenerasi id berkala (anti session hijacking), tiap 20 menit.
    if (!empty($_SESSION['user_id'])) {
        if (!isset($_SESSION['_regen']) || (time() - $_SESSION['_regen']) > 1200) {
            session_regenerate_id(true);
            $_SESSION['_regen'] = time();
        }
        // Idle timeout 2 jam.
        if (isset($_SESSION['_last']) && (time() - $_SESSION['_last']) > 7200) {
            $_SESSION = [];
            session_destroy();
            session_start();
        }
        $_SESSION['_last'] = time();
    }
}

date_default_timezone_set('Asia/Jakarta'); // Waktu WIB

// ---------------------------------------------------------------------------
// 4. Koneksi database
// ---------------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$__db = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'db_bumi_bahari'];
if (is_file(__DIR__ . '/db_credentials.php')) {
    $__db = array_merge($__db, (array) (require __DIR__ . '/db_credentials.php'));
}

try {
    $conn = new mysqli($__db['host'], $__db['user'], $__db['pass'], $__db['name']);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+07:00'"); // samakan dengan PHP (WIB)
} catch (Throwable $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    http_response_code(503);
    exit(APP_ENV === 'production' ? 'Layanan sedang tidak tersedia.' : 'Koneksi DB gagal: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 4b. Retensi audit trail — pangkas log > 1 tahun secara oportunistik (~1/500 request).
// ---------------------------------------------------------------------------
try {
    if (random_int(1, 500) === 1) {
        $conn->query("DELETE FROM audit_log WHERE waktu < (NOW() - INTERVAL 365 DAY) LIMIT 5000");
    }
} catch (Throwable $e) {
    error_log('audit prune gagal: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 5. Helper keamanan
// ---------------------------------------------------------------------------
if (!function_exists('h')) {
    function h($string): string
    {
        return htmlspecialchars((string) ($string ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check($token): bool
    {
        return !empty($_SESSION['csrf'])
            && is_string($token) && $token !== ''
            && hash_equals($_SESSION['csrf'], $token);
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}
if (!function_exists('current_role')) {
    function current_role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }
}
if (!function_exists('current_username')) {
    function current_username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }
}

if (!function_exists('require_login')) {
    function require_login(string $redirect = '../login'): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    function require_role(string $role, string $redirect = '../login'): void
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== $role) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if (!function_exists('tahun_data_paling_lama')) {
    // Tahun paling awal yang punya data laporan — dipakai sebagai batas bawah
    // dropdown filter tahun, supaya data lama (mis. 2025 dst) tidak pernah
    // "hilang" dari pilihan filter walau bertahun-tahun kemudian.
    function tahun_data_paling_lama(mysqli $conn): int
    {
        static $tahun = null;
        if ($tahun === null) {
            $res = $conn->query("SELECT MIN(YEAR(tanggal)) AS th FROM laporan_cabang");
            $th = $res ? $res->fetch_assoc()['th'] : null;
            $tahun = $th ? (int) $th : ((int) date('Y') - 3);
        }
        return $tahun;
    }
}

// ---------------------------------------------------------------------------
// 5b. Scoping akses PIC & Investor — dipakai admin_pic/ dan investor/
//     supaya query cabang selalu difilter, tidak pernah mengandalkan input user.
// ---------------------------------------------------------------------------
if (!function_exists('pic_cabang_ids')) {
    function pic_cabang_ids(mysqli $conn, int $id_user): array
    {
        $ids = [];
        $st = $conn->prepare("SELECT DISTINCT id_cabang FROM pengelola WHERE id_user = ? AND status = 'aktif' AND id_cabang IS NOT NULL");
        $st->bind_param('i', $id_user);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id_cabang'];
        }
        $st->close();
        return $ids;
    }
}

if (!function_exists('investor_cabang_ids')) {
    function investor_cabang_ids(mysqli $conn, int $id_investor): array
    {
        $ids = [];
        $st = $conn->prepare("SELECT DISTINCT id_cabang FROM cabang_investor WHERE id_investor = ? AND tgl_selesai IS NULL");
        $st->bind_param('i', $id_investor);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id_cabang'];
        }
        $st->close();
        return $ids;
    }
}

// ---------------------------------------------------------------------------
// 6. Audit trail — catat aksi penting. Tidak pernah menggagalkan operasi asli.
// ---------------------------------------------------------------------------
if (!function_exists('audit')) {
    function audit(
        mysqli $conn,
        string $aksi,
        ?string $tabel = null,
        $record_id = null,
        $detail = null
    ): void {
        try {
            $uid   = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $uname = $_SESSION['username'] ?? null;
            $role  = $_SESSION['role'] ?? null;
            $rid   = $record_id === null ? null : substr((string) $record_id, 0, 60);
            $det   = $detail === null
                ? null
                : (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            if (is_string($det) && strlen($det) > 8000) {
                $det = substr($det, 0, 8000);
            }
            $ip = client_ip();
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $st = $conn->prepare(
                "INSERT INTO audit_log (user_id, username, role, aksi, tabel, record_id, detail, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st->bind_param('issssssss', $uid, $uname, $role, $aksi, $tabel, $rid, $det, $ip, $ua);
            $st->execute();
            $st->close();
        } catch (Throwable $e) {
            error_log('audit() gagal: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// 7. Komponen pagination terpusat (dipakai di semua halaman list).
// ---------------------------------------------------------------------------
require_once __DIR__ . '/pagination.php';

// ---------------------------------------------------------------------------
// 8. Rumus laporan keuangan harian — satu sumber kebenaran, lihat tests/keuangan_test.php.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/keuangan.php';

// ---------------------------------------------------------------------------
// 9. TOTP (2FA) — lihat tests/totp_test.php.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/totp.php';

if (!function_exists('role_home')) {
    // Tujuan halaman awal per role, dipakai login.php & verify_2fa.php.
    function role_home(?string $role): string
    {
        switch ($role) {
            case 'pusat':    return 'admin_pusat/index';
            case 'pic':      return 'admin_pic/index';
            case 'investor': return 'investor/dashboard';
            default:         return 'admin_cabang/input_data';
        }
    }
}

if (!function_exists('finalize_login')) {
    // Menuntaskan sesi login (dipanggil setelah password OK, atau setelah 2FA OK
    // kalau akun itu mewajibkannya). Dipakai login.php & verify_2fa.php.
    function finalize_login(mysqli $conn, array $user): void
    {
        $uid = (int) $user['id'];

        session_regenerate_id(true);
        $_SESSION['user_id']     = $uid;
        $_SESSION['username']    = $user['username'];
        $_SESSION['role']        = $user['role'];
        $_SESSION['id_cabang']   = $user['id_cabang'];
        $_SESSION['id_investor'] = $user['id_investor'] ?? null;
        $_SESSION['_regen']      = time();
        $_SESSION['_last']       = time();

        // Nama pengelola: dari tabel pengelola (aktif), fallback ke cabang.
        $nama_pengelola = '';
        if ($user['role'] == 'cabang' && !empty($user['id_cabang'])) {
            $sp = $conn->prepare("
                SELECT COALESCE(
                    (SELECT p.nama_pengelola FROM pengelola p
                       WHERE p.id_cabang = ? AND p.status = 'aktif'
                       ORDER BY p.tgl_mulai DESC LIMIT 1),
                    (SELECT nama_pengelola FROM cabang WHERE id_cabang = ?)
                ) AS nm
            ");
            $sp->bind_param("ii", $user['id_cabang'], $user['id_cabang']);
            $sp->execute();
            $nama_pengelola = $sp->get_result()->fetch_assoc()['nm'] ?? '';
            $sp->close();
        }
        $_SESSION['nama_pengelola'] = $nama_pengelola;

        audit($conn, 'login', 'users', $uid, ['username' => $user['username'], 'role' => $user['role']]);
    }
}
