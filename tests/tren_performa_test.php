<?php
/**
 * Jaring pengaman untuk ambil_tren_performa() (config/koneksi.php) — grafik
 * "Trend Performa" dipakai bareng oleh admin_pusat dan investor, jadi harus
 * benar untuk keempat granularitas: harian, mingguan, bulanan, tahunan.
 *
 * Test integrasi (butuh koneksi database), isolasi lewat transaksi + ROLLBACK
 * supaya tidak pernah menyisakan data uji di database live.
 *
 * Jalankan: C:\xampp\php\php.exe tests\tren_performa_test.php
 */

chdir(__DIR__);
require __DIR__ . '/../config/koneksi.php';

$lolos = 0;
$gagal = 0;

function cek($label, $aktual, $harapan)
{
    global $lolos, $gagal;
    if ($aktual === $harapan) {
        $lolos++;
        echo "  OK   $label\n";
    } else {
        $gagal++;
        echo "  GAGAL $label — dapat: " . var_export($aktual, true) . ", harusnya: " . var_export($harapan, true) . "\n";
    }
}

$conn->begin_transaction();

try {
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_TREN', '000', 'Test')");
    $id_cabang = $conn->insert_id;

    $isi = function (string $tanggal, int $omzet, int $laba) use ($conn, $id_cabang) {
        $stmt = $conn->prepare("INSERT INTO laporan_cabang (id_cabang, nama_pengelola, tanggal, total_omset, net_profit, status_laporan) VALUES (?, 'Test', ?, ?, ?, 'lengkap')");
        $stmt->bind_param('isii', $id_cabang, $tanggal, $omzet, $laba);
        $stmt->execute();
    };

    $where = "AND l.status_laporan = 'lengkap' AND l.id_cabang = ?";
    $params = [$id_cabang];
    $types = 'i';

    // ---------------------------------------------------------------
    // HARIAN — 2 hari beda dalam jendela 30 hari, harus jadi 2 titik terpisah.
    // ---------------------------------------------------------------
    $isi('2026-08-30', 1000000, 300000);
    $isi('2026-08-31', 2000000, 500000);

    $tren = ambil_tren_performa($conn, 'harian', '2026-08-31', $where, $params, $types);
    echo "[harian]\n";
    cek('granularitas ternormalisasi', $tren['granularitas'], 'harian');
    cek('jumlah titik = 2 hari berbeda', count($tren['label']), 2);
    cek('label hari pertama', $tren['label'][0], '30 Aug');
    cek('omzet hari kedua', $tren['omzet'][1], 2000000.0);
    echo "\n";

    // ---------------------------------------------------------------
    // MINGGUAN — 2 laporan di minggu yang SAMA harus digabung jadi 1 titik.
    // ---------------------------------------------------------------
    $conn->query("DELETE FROM laporan_cabang WHERE id_cabang = $id_cabang");
    $isi('2026-08-24', 500000, 100000);  // Senin
    $isi('2026-08-26', 500000, 100000);  // Rabu, minggu yang sama
    $isi('2026-08-31', 900000, 200000);  // minggu berikutnya

    $tren = ambil_tren_performa($conn, 'mingguan', '2026-08-31', $where, $params, $types);
    echo "[mingguan]\n";
    cek('2 laporan minggu sama digabung -> 2 titik total', count($tren['label']), 2);
    cek('omzet minggu pertama tergabung (500rb+500rb)', $tren['omzet'][0], 1000000.0);
    cek('omzet minggu kedua', $tren['omzet'][1], 900000.0);
    echo "\n";

    // ---------------------------------------------------------------
    // BULANAN — beda bulan, tidak boleh tergabung.
    // ---------------------------------------------------------------
    $conn->query("DELETE FROM laporan_cabang WHERE id_cabang = $id_cabang");
    $isi('2026-07-15', 1000000, 250000);
    $isi('2026-08-15', 1500000, 400000);

    $tren = ambil_tren_performa($conn, 'bulanan', '2026-08-31', $where, $params, $types);
    echo "[bulanan]\n";
    cek('2 bulan berbeda -> 2 titik', count($tren['label']), 2);
    cek('label bulan kedua', $tren['label'][1], 'Aug 2026');
    echo "\n";

    // ---------------------------------------------------------------
    // TAHUNAN — beda tahun, tidak boleh tergabung.
    // ---------------------------------------------------------------
    $conn->query("DELETE FROM laporan_cabang WHERE id_cabang = $id_cabang");
    $isi('2024-03-01', 1000000, 200000);
    $isi('2026-08-31', 3000000, 900000);

    $tren = ambil_tren_performa($conn, 'tahunan', '2026-08-31', $where, $params, $types);
    echo "[tahunan]\n";
    cek('2 tahun berbeda -> 2 titik', count($tren['label']), 2);
    cek('label tahun pertama', $tren['label'][0], '2024');
    cek('omzet tahun kedua', $tren['omzet'][1], 3000000.0);
    echo "\n";

    // ---------------------------------------------------------------
    // Granularitas tidak dikenal -> fallback ke bulanan, bukan error.
    // ---------------------------------------------------------------
    $tren = ambil_tren_performa($conn, 'ngasal', '2026-08-31', $where, $params, $types);
    echo "[fallback]\n";
    cek('granularitas ngasal -> fallback bulanan', $tren['granularitas'], 'bulanan');
    echo "\n";
} finally {
    $conn->rollback();
}

echo "==============================\n";
echo "Total: $lolos lolos, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
