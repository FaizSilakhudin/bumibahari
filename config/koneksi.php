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

if (!function_exists('nama_bulan_id')) {
    // Nama bulan Indonesia — TIDAK pakai setlocale() (tidak portable, tergantung
    // locale terpasang di server). $bulan = 1-12.
    function nama_bulan_id(int $bulan): string
    {
        $nama = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        return $nama[$bulan] ?? '-';
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

if (!function_exists('kompres_gambar_upload')) {
    // Kecilkan foto (resize + re-encode JPEG) SETELAH diupload, di tempat (in-place).
    // Hanya untuk upload BARU — tidak pernah dipanggil untuk foto lama yang sudah
    // tersimpan, supaya riwayat foto lama tidak pernah tersentuh/hilang kualitasnya.
    // Aman dipanggil walau GD tidak aktif: kalau gagal di titik manapun, file asli
    // dibiarkan apa adanya (tidak pernah menghapus/merusak upload yang sudah masuk).
    // Ukuran & kualitas SENGAJA dijaga cukup tinggi (2000px/88%) — ini foto nota/struk
    // yang teksnya (nominal rupiah) harus tetap terbaca jelas oleh PIC, bukan foto biasa.
    function kompres_gambar_upload(string $path, int $sisi_maks = 2000, int $kualitas = 88): void
    {
        if (!extension_loaded('gd') || !is_file($path)) {
            return;
        }
        $info = @getimagesize($path);
        if (!$info) {
            return;
        }
        [$lebar, $tinggi] = $info;
        if ($lebar <= 0 || $tinggi <= 0) {
            return;
        }

        $src = null;
        switch ($info['mime'] ?? '') {
            case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
            case 'image/png':  $src = @imagecreatefrompng($path); break;
        }
        if (!$src) {
            return;
        }

        $skala = min(1, $sisi_maks / max($lebar, $tinggi));
        $lebar_baru = max(1, (int) round($lebar * $skala));
        $tinggi_baru = max(1, (int) round($tinggi * $skala));

        $dst = imagecreatetruecolor($lebar_baru, $tinggi_baru);
        $putih = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $putih);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $lebar_baru, $tinggi_baru, $lebar, $tinggi);
        imagedestroy($src);

        $tmp = $path . '.tmp';
        if (imagejpeg($dst, $tmp, $kualitas) && filesize($tmp) > 0 && filesize($tmp) < filesize($path)) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
        imagedestroy($dst);
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

if (!function_exists('anchor_periode')) {
    // Titik tanggal untuk pengelola_pada_tanggal()/investor_pada_tanggal() saat
    // menampilkan RINGKASAN satu periode (minggu/bulan) — SELALU akhir periode
    // (dibatasi maksimal hari ini), BUKAN awal periode.
    //
    // Kenapa akhir, bukan awal: relasi pengelola/investor kadang baru mulai
    // tercatat di TENGAH periode (mis. investor baru terdaftar tanggal 28,
    // padahal laporan bulan itu baru ada dari tanggal 30) — kalau dites di
    // AWAL periode, hasilnya "-" (dianggap belum tersambung) walau sebenarnya
    // sudah tersambung sejak pertengahan periode itu. Anchor di akhir periode
    // menangkap kondisi "siapa yang menjabat paling akhir dalam periode ini",
    // sekaligus tetap tidak bisa "melihat masa depan" karena dibatasi hari ini.
    function anchor_periode(string $tgl_akhir_periode): string
    {
        $hari_ini = date('Y-m-d');
        return $tgl_akhir_periode < $hari_ini ? $tgl_akhir_periode : $hari_ini;
    }
}

if (!function_exists('pengelola_pada_tanggal')) {
    // Nama pengelola yang MENJABAT pada tanggal tertentu — bukan pengelola
    // yang aktif SEKARANG. Wajib dipakai tiap kali menampilkan/menyimpan data
    // untuk tanggal/periode historis, supaya rotasi pengelola tidak menimpa
    // atribusi laporan lama. ("Pengelola aktif sekarang" tetap benar dipakai
    // di konteks identitas sesi login / form input hari ini.) Untuk RINGKASAN
    // periode (minggu/bulan), pakai anchor_periode() supaya anchornya akhir
    // periode, bukan awal periode — lihat komentar anchor_periode().
    function pengelola_pada_tanggal(mysqli $conn, int $id_cabang, string $tanggal): string
    {
        $stmt = $conn->prepare("
            SELECT nama_pengelola FROM pengelola
            WHERE id_cabang = ? AND tgl_mulai <= ? AND (tgl_selesai IS NULL OR tgl_selesai >= ?)
            ORDER BY tgl_mulai DESC LIMIT 1
        ");
        $stmt->bind_param('iss', $id_cabang, $tanggal, $tanggal);
        $stmt->execute();
        $nama = $stmt->get_result()->fetch_assoc()['nama_pengelola'] ?? null;
        $stmt->close();
        if ($nama) {
            return $nama;
        }

        $stmt = $conn->prepare('SELECT nama_pengelola FROM cabang WHERE id_cabang = ?');
        $stmt->bind_param('i', $id_cabang);
        $stmt->execute();
        $fallback = $stmt->get_result()->fetch_assoc()['nama_pengelola'] ?? null;
        $stmt->close();
        return $fallback ?: '-';
    }
}

if (!function_exists('hitung_poin_ranking_cabang')) {
    // Rangking cabang berdasarkan POIN PERFORMA, bukan omzet tertinggi.
    // Poin = %share omzet + %share net profit thd total SELURUH cabang yg
    // masuk filter (omzet:total_omzet x100% + net_profit:total_net_profit x100%).
    // Dengan begini cabang beromzet kecil tapi net profit bagus bisa mengungguli
    // cabang beromzet besar tapi net profit-nya tipis/rugi.
    // $rows: array asosiatif dari hasil query, tiap elemen wajib punya
    // 'total_omset' dan 'total_net_profit'. Kembalian: array yg sama, sudah
    // diurutkan (poin tertinggi dulu) + field tambahan 'pct_omzet',
    // 'pct_net_profit', 'poin', dan 'no'.
    function hitung_poin_ranking_cabang(array $rows): array
    {
        $total_omzet_semua = 0.0;
        $total_laba_semua = 0.0;
        foreach ($rows as $row) {
            $total_omzet_semua += (float) $row['total_omset'];
            $total_laba_semua  += (float) $row['total_net_profit'];
        }

        foreach ($rows as &$row) {
            // Jika total net profit seluruh cabang <=0 (rugi/impas), share net
            // profit tidak dihitung (dianggap 0 utk semua) supaya rangking tidak
            // terbalik-balik akibat pembagian dgn angka negatif.
            $pct_omzet = $total_omzet_semua > 0 ? ((float) $row['total_omset'] / $total_omzet_semua) * 100 : 0.0;
            $pct_laba  = $total_laba_semua > 0 ? ((float) $row['total_net_profit'] / $total_laba_semua) * 100 : 0.0;
            $row['pct_omzet'] = $pct_omzet;
            $row['pct_net_profit'] = $pct_laba;
            $row['poin'] = $pct_omzet + $pct_laba;
        }
        unset($row);

        usort($rows, fn($a, $b) => $b['poin'] <=> $a['poin']);

        $no = 1;
        foreach ($rows as &$row) {
            $row['no'] = $no++;
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('investor_pada_tanggal')) {
    // Nama investor yang berinvestasi PADA tanggal tertentu — bukan investor
    // aktif sekarang. Sama alasannya dengan pengelola_pada_tanggal().
    function investor_pada_tanggal(mysqli $conn, int $id_cabang, string $tanggal): string
    {
        $stmt = $conn->prepare("
            SELECT i.nama_investor FROM cabang_investor ci
            JOIN investor i ON i.id_investor = ci.id_investor
            WHERE ci.id_cabang = ? AND ci.tgl_mulai <= ? AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= ?)
            ORDER BY ci.tgl_mulai DESC LIMIT 1
        ");
        $stmt->bind_param('iss', $id_cabang, $tanggal, $tanggal);
        $stmt->execute();
        $nama = $stmt->get_result()->fetch_assoc()['nama_investor'] ?? null;
        $stmt->close();
        return $nama ?: '-';
    }
}

// ---------------------------------------------------------------------------
// 5a-1b. Resolusi periode "bulanan" untuk Rekapitulasi — closing digabung
//        sekali di awal kalau cabang baru mulai pembukuan >= tanggal 20, dan
//        pemecahan periode per pengelola kalau ada rotasi di tengah periode.
// ---------------------------------------------------------------------------
if (!function_exists('cabang_mulai_pembukuan')) {
    // Tanggal laporan PERTAMA cabang ini (berapapun status_laporan-nya —
    // menunggu/lengkap/libur tetap dihitung "sudah mulai pembukuan"). Dipakai
    // sebagai pengganti kolom created_at yang memang tidak ada di tabel
    // cabang — diturunkan dari data, bukan disimpan terpisah.
    function cabang_mulai_pembukuan(mysqli $conn, int $id_cabang): ?string
    {
        $stmt = $conn->prepare("SELECT MIN(tanggal) AS mulai FROM laporan_cabang WHERE id_cabang = ?");
        $stmt->bind_param('i', $id_cabang);
        $stmt->execute();
        $mulai = $stmt->get_result()->fetch_assoc()['mulai'] ?? null;
        $stmt->close();
        return $mulai;
    }
}

if (!function_exists('resolve_periode_bulanan')) {
    // Hitung rentang tanggal EFEKTIF untuk "bulan $bulan/$tahun" cabang ini,
    // dengan aturan: kalau cabang baru mulai pembukuan pada/setelah tanggal 20,
    // periode parsial pertamanya digabung ke closing bulan berikutnya —
    // SEKALI SAJA di awal, bukan siklus berulang tiap bulan.
    //
    // Balikan:
    //   tgl_mulai, tgl_selesai : rentang efektif untuk query laporan_cabang
    //   digabung               : true kalau tgl_mulai dimundurkan (periode ini
    //                            berisi gabungan sisa bulan sebelumnya)
    //   periode_kosong         : true kalau $tahun-$bulan justru adalah bulan
    //                            mulai cabang itu sendiri (day >= 20) — closing
    //                            untuk bulan ini tidak ada, sudah digabung maju
    //                            ke bulan berikutnya, caller sebaiknya tampilkan
    //                            notice & sembunyikan tabel/export.
    function resolve_periode_bulanan(mysqli $conn, int $id_cabang, int $tahun, int $bulan): array
    {
        $awal_kalender  = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir_kalender = date('Y-m-t', strtotime($awal_kalender));
        $hasil = ['tgl_mulai' => $awal_kalender, 'tgl_selesai' => $akhir_kalender, 'digabung' => false, 'periode_kosong' => false];

        $mulai_pembukuan = cabang_mulai_pembukuan($conn, $id_cabang);
        if ($mulai_pembukuan === null) {
            return $hasil; // belum ada laporan sama sekali — tidak ada yang bisa digeser
        }

        $hari_mulai = (int) date('j', strtotime($mulai_pembukuan));
        if ($hari_mulai < 20) {
            return $hasil; // mulai di awal/pertengahan bulan — tidak perlu digabung
        }

        $bulan_mulai   = date('Y-m', strtotime($mulai_pembukuan));
        $bulan_diminta = sprintf('%04d-%02d', $tahun, $bulan);
        $bulan_sebelum = date('Y-m', strtotime("$awal_kalender -1 month"));

        if ($bulan_mulai === $bulan_diminta) {
            // Periode yang diminta ADALAH bulan mulai cabang ini sendiri —
            // closing untuk bulan ini tidak ada, sudah digabung ke bulan depan.
            $hasil['periode_kosong'] = true;
            return $hasil;
        }

        if ($bulan_mulai === $bulan_sebelum) {
            // Periode yang diminta adalah bulan SETELAH cabang mulai —
            // mundurkan tgl_mulai supaya sisa hari bulan sebelumnya ikut masuk.
            $hasil['tgl_mulai'] = $mulai_pembukuan;
            $hasil['digabung']  = true;
        }

        return $hasil;
    }
}

if (!function_exists('resolve_pengelola_segments')) {
    // Pecah rentang [$tgl_mulai, $tgl_selesai] jadi beberapa segmen kalau ada
    // rotasi pengelola di tengah rentang itu (mis. Pengelola A s/d tgl 15,
    // Pengelola B mulai tgl 16). Kasus normal (1 pengelola sepanjang rentang)
    // tetap balikin array isi 1 elemen, supaya caller selalu bisa "loop N>=1"
    // tanpa percabangan khusus utk kasus normal.
    //
    // Balikan: array berurutan (tgl_mulai ASC) berisi:
    //   ['pengelola' => <baris tabel pengelola, atau null kalau tidak ada data>,
    //    'tgl_mulai' => <dipotong ke dalam rentang>,
    //    'tgl_selesai' => <dipotong ke dalam rentang>,
    //    'urutan' => 1, 2, ...]
    function resolve_pengelola_segments(mysqli $conn, int $id_cabang, string $tgl_mulai, string $tgl_selesai): array
    {
        $stmt = $conn->prepare("
            SELECT * FROM pengelola
            WHERE id_cabang = ? AND tgl_mulai <= ? AND (tgl_selesai IS NULL OR tgl_selesai >= ?)
            ORDER BY tgl_mulai ASC
        ");
        $stmt->bind_param('iss', $id_cabang, $tgl_selesai, $tgl_mulai);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($baris)) {
            // Tidak ada data pengelola sama sekali utk rentang ini — 1 segmen
            // tunggal, caller jatuh ke fallback (mis. cabang.nama_pengelola).
            return [[
                'pengelola'   => null,
                'tgl_mulai'   => $tgl_mulai,
                'tgl_selesai' => $tgl_selesai,
                'urutan'      => 1,
            ]];
        }

        $segmen = [];
        $urutan = 1;
        foreach ($baris as $row) {
            $seg_mulai   = max($row['tgl_mulai'], $tgl_mulai);
            $seg_selesai = $row['tgl_selesai'] !== null ? min($row['tgl_selesai'], $tgl_selesai) : $tgl_selesai;
            $segmen[] = [
                'pengelola'   => $row,
                'tgl_mulai'   => $seg_mulai,
                'tgl_selesai' => $seg_selesai,
                'urutan'      => $urutan++,
            ];
        }
        return $segmen;
    }
}

// ---------------------------------------------------------------------------
// 5a-2. Grafik "Trend Performa" — satu logika dipakai bareng oleh admin_pusat
//       dan investor supaya jendela waktu & pelabelan tiap granularitas SAMA
//       PERSIS di kedua tempat, tidak ada yang beda sendiri.
// ---------------------------------------------------------------------------
if (!function_exists('ambil_tren_performa')) {
    /**
     * @param string $granularitas  'harian' | 'mingguan' | 'bulanan' | 'tahunan'
     * @param string $anchor_tanggal  Tanggal akhir jendela (biasanya akhir periode yang lagi dipilih, dibatasi hari ini).
     * @param string $where_filter  Filter TAMBAHAN yang sudah diawali "AND ..." (mis. scoping cabang/investor pemanggil).
     * @param array  $params  Nilai untuk placeholder di $where_filter (belum termasuk tanggal jendela).
     * @param string $types   Tipe bind_param untuk $params (belum termasuk 2 tanggal jendela).
     */
    function ambil_tren_performa(mysqli $conn, string $granularitas, string $anchor_tanggal, string $where_filter, array $params, string $types): array
    {
        switch ($granularitas) {
            case 'harian':
                $mulai    = date('Y-m-d', strtotime("$anchor_tanggal -29 days")); // 30 hari terakhir
                $group    = 'l.tanggal';
                $label_sql = "DATE_FORMAT(MIN(l.tanggal), '%d %b')";
                break;
            case 'mingguan':
                $mulai    = date('Y-m-d', strtotime("$anchor_tanggal -83 days")); // ~12 minggu terakhir
                $group    = 'YEARWEEK(l.tanggal, 1)';
                $label_sql = "DATE_FORMAT(MIN(l.tanggal), '%d %b')"; // awal minggu
                break;
            case 'tahunan':
                $mulai    = date('Y-01-01', strtotime("$anchor_tanggal -4 years")); // 5 tahun terakhir
                $group    = 'YEAR(l.tanggal)';
                $label_sql = 'YEAR(l.tanggal)';
                break;
            case 'bulanan':
            default:
                $granularitas = 'bulanan';
                $mulai    = date('Y-m-01', strtotime("$anchor_tanggal -5 months")); // 6 bulan terakhir
                $group    = "DATE_FORMAT(l.tanggal, '%Y-%m')";
                $label_sql = "DATE_FORMAT(MIN(l.tanggal), '%b %Y')";
                break;
        }

        $sql = "SELECT $label_sql AS label,
                       COALESCE(SUM(l.total_omset), 0) omzet,
                       COALESCE(SUM(l.net_profit), 0) laba
                FROM laporan_cabang l
                WHERE l.tanggal BETWEEN ? AND ? $where_filter
                GROUP BY $group
                ORDER BY MIN(l.tanggal) ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss' . $types, ...array_merge([$mulai, $anchor_tanggal], $params));
        $stmt->execute();
        $res = $stmt->get_result();

        $label = $omzet = $laba = [];
        while ($row = $res->fetch_assoc()) {
            $label[] = (string) $row['label'];
            $omzet[] = (float) $row['omzet'];
            $laba[]  = (float) $row['laba'];
        }
        $stmt->close();

        return ['granularitas' => $granularitas, 'label' => $label, 'omzet' => $omzet, 'laba' => $laba];
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
// 5c. Notifikasi in-app — bel notifikasi sederhana untuk PIC & pusat.
// ---------------------------------------------------------------------------
if (!function_exists('pic_untuk_cabang')) {
    function pic_untuk_cabang(mysqli $conn, int $id_cabang): array
    {
        $ids = [];
        $st = $conn->prepare("
            SELECT DISTINCT p.id_user
            FROM pengelola p
            JOIN users u ON u.id = p.id_user AND u.role = 'pic' AND u.status = 'aktif'
            WHERE p.id_cabang = ? AND p.status = 'aktif'
        ");
        $st->bind_param('i', $id_cabang);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id_user'];
        }
        $st->close();
        return $ids;
    }
}

if (!function_exists('semua_user_pusat')) {
    function semua_user_pusat(mysqli $conn): array
    {
        $ids = [];
        $res = $conn->query("SELECT id FROM users WHERE role = 'pusat' AND status = 'aktif'");
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }
}

if (!function_exists('kirim_notifikasi')) {
    function kirim_notifikasi(mysqli $conn, array $id_user_list, string $jenis, string $judul, string $pesan, string $link): void
    {
        $id_user_list = array_unique(array_filter($id_user_list));
        if (empty($id_user_list)) {
            return;
        }
        $stmt = $conn->prepare("INSERT INTO notifikasi (id_user, jenis, judul, pesan, link) VALUES (?, ?, ?, ?, ?)");
        foreach ($id_user_list as $id_user) {
            $id_user = (int) $id_user;
            $stmt->bind_param("issss", $id_user, $jenis, $judul, $pesan, $link);
            $stmt->execute();
        }
        $stmt->close();
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

// ---------------------------------------------------------------------------
// 10. Komputasi per-segmen Rekapitulasi (closing digabung, split pengelola) —
//     lihat tests/rekap_hitung_test.php.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/rekap_hitung.php';

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
