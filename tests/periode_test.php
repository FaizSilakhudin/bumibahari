<?php
/**
 * Jaring pengaman untuk atribusi pengelola/investor historis
 * (config/koneksi.php: pengelola_pada_tanggal(), investor_pada_tanggal()).
 *
 * Ini test integrasi (butuh koneksi database) — beda dari keuangan_test.php
 * dan totp_test.php yang murni logika. Pakai transaksi + ROLLBACK supaya
 * tidak pernah menyisakan data uji di database live.
 *
 * Jalankan: C:\xampp\php\php.exe tests\periode_test.php
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
    // Cabang dummy untuk isolasi total dari data live.
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_ROTASI', '000', 'Default Lama')");
    $id_cabang = $conn->insert_id;

    // -----------------------------------------------------------------
    // Skenario: 2 pengelola berurutan di cabang yang sama.
    //   Pengelola A: 2026-01-01 s/d 2026-06-30
    //   Pengelola B: 2026-07-01 s/d sekarang (tgl_selesai NULL)
    // -----------------------------------------------------------------
    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola A', '2026-01-01', '2026-06-30', 'nonaktif')");
    $stmt->bind_param('i', $id_cabang);
    $stmt->execute();

    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola B', '2026-07-01', NULL, 'aktif')");
    $stmt->bind_param('i', $id_cabang);
    $stmt->execute();

    echo "[pengelola_pada_tanggal — rotasi pengelola]\n";
    cek('Maret 2026 -> Pengelola A (bukan yang sekarang aktif)', pengelola_pada_tanggal($conn, $id_cabang, '2026-03-15'), 'Pengelola A');
    cek('Tepat hari terakhir A (2026-06-30) -> Pengelola A', pengelola_pada_tanggal($conn, $id_cabang, '2026-06-30'), 'Pengelola A');
    cek('Tepat hari pertama B (2026-07-01) -> Pengelola B', pengelola_pada_tanggal($conn, $id_cabang, '2026-07-01'), 'Pengelola B');
    cek('September 2026 -> Pengelola B (yang sekarang aktif)', pengelola_pada_tanggal($conn, $id_cabang, '2026-09-15'), 'Pengelola B');
    cek('Sebelum A pernah ada (2025-01-01) -> fallback ke cabang.nama_pengelola', pengelola_pada_tanggal($conn, $id_cabang, '2025-01-01'), 'Default Lama');
    echo "\n";

    // -----------------------------------------------------------------
    // Skenario: 2 investor berurutan (rolling investasi).
    //   Investor lama: 2026-01-01 s/d 2026-05-31
    //   Investor baru: 2026-06-01 s/d sekarang
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO investor (nama_investor, status) VALUES ('Investor Lama', 'aktif')");
    $id_inv_lama = $conn->insert_id;
    $conn->query("INSERT INTO investor (nama_investor, status) VALUES ('Investor Baru', 'aktif')");
    $id_inv_baru = $conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO cabang_investor (id_cabang, id_investor, tgl_mulai, tgl_selesai) VALUES (?, ?, '2026-01-01', '2026-05-31')");
    $stmt->bind_param('ii', $id_cabang, $id_inv_lama);
    $stmt->execute();

    $stmt = $conn->prepare("INSERT INTO cabang_investor (id_cabang, id_investor, tgl_mulai, tgl_selesai) VALUES (?, ?, '2026-06-01', NULL)");
    $stmt->bind_param('ii', $id_cabang, $id_inv_baru);
    $stmt->execute();

    echo "[investor_pada_tanggal — rolling investasi]\n";
    cek('Februari 2026 -> Investor Lama (bukan yang sekarang aktif)', investor_pada_tanggal($conn, $id_cabang, '2026-02-10'), 'Investor Lama');
    cek('Juli 2026 -> Investor Baru (yang sekarang aktif)', investor_pada_tanggal($conn, $id_cabang, '2026-07-10'), 'Investor Baru');
    cek('Sebelum ada investor sama sekali (2025-01-01) -> "-"', investor_pada_tanggal($conn, $id_cabang, '2025-01-01'), '-');
    echo "\n";
} finally {
    $conn->rollback();
}

// -----------------------------------------------------------------------
// anchor_periode() — anchor untuk RINGKASAN periode (rekap/laporan mingguan)
// harus akhir periode (dibatasi hari ini), BUKAN awal periode. Kalau anchor
// balik ke awal periode, bug lama muncul lagi: investor/pengelola yang baru
// terdaftar di TENGAH periode akan tampak "tidak tersambung" padahal
// tersambung sejak pertengahan periode itu.
// -----------------------------------------------------------------------
echo "[anchor_periode — anchor akhir periode, bukan awal periode]\n";
$hari_ini = date('Y-m-d');
$kemarin  = date('Y-m-d', strtotime('-1 day'));
$besok    = date('Y-m-d', strtotime('+1 day'));
cek('Akhir periode sudah lewat -> pakai akhir periode itu sendiri', anchor_periode($kemarin), $kemarin);
cek('Akhir periode = hari ini -> tetap hari ini', anchor_periode($hari_ini), $hari_ini);
cek('Akhir periode di masa depan (periode belum selesai) -> dibatasi hari ini', anchor_periode($besok), $hari_ini);
echo "\n";

echo "==============================\n";
echo "Total: $lolos lolos, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
