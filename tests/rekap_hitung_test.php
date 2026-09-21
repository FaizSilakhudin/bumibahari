<?php
/**
 * Jaring pengaman untuk config/rekap_hitung.php: hitung_rekap_segmen().
 *
 * Test integrasi (butuh koneksi database), pakai transaksi + ROLLBACK supaya
 * tidak pernah menyisakan data uji di database live.
 *
 * Jalankan: C:\xampp\php\php.exe tests\rekap_hitung_test.php
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

function dekat($label, $aktual, $harapan, $toleransi = 0.01)
{
    global $lolos, $gagal;
    if (abs($aktual - $harapan) < $toleransi) {
        $lolos++;
        echo "  OK   $label\n";
    } else {
        $gagal++;
        echo "  GAGAL $label — dapat: $aktual, harusnya: $harapan\n";
    }
}

$conn->begin_transaction();

try {
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_HITUNG_SEGMEN', '000', 'Default')");
    $id_cabang = $conn->insert_id;

    // 2 laporan lengkap di rentang segmen, 1 di LUAR rentang (harus tidak ikut terhitung)
    $ins = function ($tgl, $omset, $peng, $laba, $persen, $sewa, $gaji) use ($conn, $id_cabang) {
        $st = $conn->prepare("INSERT INTO laporan_cabang (id_cabang, tanggal, total_omset, total_pengeluaran, net_profit, persentase, sewa, gaji, status_laporan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'lengkap')");
        $st->bind_param('isdddddd', $id_cabang, $tgl, $omset, $peng, $laba, $persen, $sewa, $gaji);
        $st->execute();
        $st->close();
    };
    $ins('2026-05-01', 1000000, 400000, 600000, 60.00, 100000, 50000);
    $ins('2026-05-02', 2000000, 800000, 1200000, 60.00, 100000, 50000);
    $ins('2026-05-20', 5000000, 1000000, 4000000, 80.00, 100000, 50000); // di luar rentang segmen (1-15)

    echo "[hitung_rekap_segmen — aggregate hanya rentang segmen, bukan seluruh bulan]\n";
    $hasil = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 1);
    dekat('penjualan = 3.000.000 (2 laporan dlm rentang, bukan 3)', $hasil['penjualan'], 3000000);
    dekat('pengeluaran = 1.200.000', $hasil['pengeluaran'], 1200000);
    dekat('laba_bersih_dasar = 1.800.000', $hasil['laba_bersih_dasar'], 1800000);
    dekat('bo_db sewa = 200.000 (2x100rb)', (float) $hasil['bo_db']['sewa'], 200000);
    echo "\n";

    echo "[hitung_rekap_segmen — revenue sharing: admin fee 3%, split 50/50]\n";
    dekat('share_admin = 54.000 (3% dari 1.800.000)', $hasil['share_admin'], 54000);
    dekat('laba_setelah_admin = 1.746.000 (tanpa klaim bulanan)', $hasil['laba_setelah_admin'], 1746000);
    dekat('share_investor = 873.000 (50%)', $hasil['share_investor'], 873000);
    dekat('share_pengelola = 873.000 (50%)', $hasil['share_pengelola'], 873000);
    echo "\n";

    // ----- Klaim Bulanan dipotong SEBELUM admin fee (urutan baru) -----
    $tahun_kb = 2026;
    $bulan_kb = 5; // akhir segmen (2026-05-15) jatuh di bulan 5
    $stmt = $conn->prepare("INSERT INTO klaim_bulanan (id_cabang, tahun, bulan, urutan_pengelola, urutan, uraian, nominal, sumber_dana) VALUES (?, ?, ?, 1, 0, 'Test klaim warung', 46000, 'warung')");
    $stmt->bind_param('iii', $id_cabang, $tahun_kb, $bulan_kb);
    $stmt->execute();
    $stmt->close();

    echo "[hitung_rekap_segmen — Klaim Bulanan dipotong SEBELUM admin fee 3%]\n";
    $hasil2 = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 1);
    dekat('total_klaim_bulanan = 46.000', $hasil2['total_klaim_bulanan'], 46000);
    cek('jumlah baris klaim = 1', count($hasil2['daftar_klaim_bulanan']), 1);
    dekat('total_klaim_dana_investor = 0 (baris ini "warung", bukan "investor")', $hasil2['total_klaim_dana_investor'], 0);
    dekat('share_admin = 52.620 (3% dari [1.800.000-46.000]=1.754.000, BUKAN dari 1.800.000)', $hasil2['share_admin'], 52620);
    dekat('laba_setelah_admin = 1.701.380 (1.754.000-52.620)', $hasil2['laba_setelah_admin'], 1701380);
    dekat('share_investor = 850.690 (50% dari 1.701.380)', $hasil2['share_investor'], 850690);
    echo "\n";

    // ----- sumber_dana='investor' dihitung terpisah (total_klaim_dana_investor) -----
    $stmt = $conn->prepare("INSERT INTO klaim_bulanan (id_cabang, tahun, bulan, urutan_pengelola, urutan, uraian, nominal, sumber_dana) VALUES (?, ?, ?, 1, 1, 'Test klaim investor', 20000, 'investor')");
    $stmt->bind_param('iii', $id_cabang, $tahun_kb, $bulan_kb);
    $stmt->execute();
    $stmt->close();

    echo "[hitung_rekap_segmen — sumber_dana='investor' dijumlah terpisah]\n";
    $hasil2b = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 1);
    dekat('total_klaim_bulanan = 66.000 (46.000 warung + 20.000 investor)', $hasil2b['total_klaim_bulanan'], 66000);
    dekat('total_klaim_dana_investor = 20.000 (cuma baris "investor")', $hasil2b['total_klaim_dana_investor'], 20000);
    echo "\n";

    // ----- urutan_pengelola berbeda tidak saling bocor -----
    $stmt = $conn->prepare("INSERT INTO klaim_bulanan (id_cabang, tahun, bulan, urutan_pengelola, urutan, uraian, nominal) VALUES (?, ?, ?, 2, 0, 'Klaim segmen 2 saja', 99999)");
    $stmt->bind_param('iii', $id_cabang, $tahun_kb, $bulan_kb);
    $stmt->execute();
    $stmt->close();

    echo "[hitung_rekap_segmen — urutan_pengelola berbeda TIDAK saling bocor]\n";
    $hasil_seg1 = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 1);
    dekat('segmen 1 total_klaim_bulanan tetap 66.000 (bukan +99.999)', $hasil_seg1['total_klaim_bulanan'], 66000);
    $hasil_seg2 = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 2);
    dekat('segmen 2 total_klaim_bulanan = 99.999 (punya sendiri)', $hasil_seg2['total_klaim_bulanan'], 99999);
    echo "\n";

    // ----- Keterangan Beban Operasional per segmen -----
    $stmt = $conn->prepare("INSERT INTO beban_operasional_keterangan (id_cabang, tahun, bulan, urutan_pengelola, uraian_key, keterangan) VALUES (?, ?, ?, 1, 'sewa', 'Catatan sewa segmen 1')");
    $stmt->bind_param('iii', $id_cabang, $tahun_kb, $bulan_kb);
    $stmt->execute();
    $stmt->close();

    echo "[hitung_rekap_segmen — Keterangan Beban Operasional per segmen]\n";
    $hasil_ket = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 1);
    cek('ket_bo sewa terisi utk segmen 1', $hasil_ket['ket_bo']['sewa'] ?? null, 'Catatan sewa segmen 1');
    $hasil_ket2 = hitung_rekap_segmen($conn, $id_cabang, '2026-05-01', '2026-05-15', 2);
    cek('ket_bo sewa KOSONG utk segmen 2 (tidak bocor)', $hasil_ket2['ket_bo']['sewa'] ?? null, null);
    echo "\n";

    // ----- Segmen tanpa data laporan sama sekali -> semua nol, tidak fatal -----
    echo "[hitung_rekap_segmen — segmen tanpa data (rentang kosong)]\n";
    $hasil_kosong = hitung_rekap_segmen($conn, $id_cabang, '2026-06-01', '2026-06-30', 1);
    dekat('penjualan = 0', $hasil_kosong['penjualan'], 0);
    dekat('laba_bersih_dasar = 0', $hasil_kosong['laba_bersih_dasar'], 0);
    cek('nama_pic = "-" (tidak ada laporan)', $hasil_kosong['nama_pic'], '-');
    echo "\n";
} finally {
    $conn->rollback();
}

echo "==============================\n";
echo "Total: $lolos lolos, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
