<?php
/**
 * Jaring pengaman untuk resolusi periode Rekapitulasi
 * (config/koneksi.php: cabang_mulai_pembukuan(), resolve_periode_bulanan(),
 * resolve_pengelola_segments()).
 *
 * Test integrasi (butuh koneksi database), pakai transaksi + ROLLBACK supaya
 * tidak pernah menyisakan data uji di database live.
 *
 * Jalankan: C:\xampp\php\php.exe tests\rekap_periode_test.php
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
    // -----------------------------------------------------------------
    // resolve_periode_bulanan() — cabang mulai NORMAL (awal bulan, hari < 20)
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_PERIODE_NORMAL', '000', 'X')");
    $id_normal = $conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO laporan_cabang (id_cabang, tanggal, status_laporan) VALUES (?, '2026-09-05', 'lengkap')");
    $stmt->bind_param('i', $id_normal);
    $stmt->execute();

    echo "[resolve_periode_bulanan — mulai tanggal 5 (hari < 20, tidak digabung)]\n";
    $r = resolve_periode_bulanan($conn, $id_normal, 2026, 9);
    cek('tgl_mulai tetap awal bulan kalender', $r['tgl_mulai'], '2026-09-01');
    cek('tgl_selesai akhir bulan kalender', $r['tgl_selesai'], '2026-09-30');
    cek('digabung = false', $r['digabung'], false);
    cek('periode_kosong = false', $r['periode_kosong'], false);
    echo "\n";

    // -----------------------------------------------------------------
    // resolve_periode_bulanan() — cabang mulai tanggal 19 (masih < 20)
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_PERIODE_H19', '000', 'X')");
    $id_h19 = $conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO laporan_cabang (id_cabang, tanggal, status_laporan) VALUES (?, '2026-09-19', 'lengkap')");
    $stmt->bind_param('i', $id_h19);
    $stmt->execute();

    echo "[resolve_periode_bulanan — mulai tanggal 19 (batas bawah, tidak digabung)]\n";
    $r = resolve_periode_bulanan($conn, $id_h19, 2026, 9);
    cek('bulan mulai (Sept) tidak dianggap kosong', $r['periode_kosong'], false);
    cek('tidak digabung', $r['digabung'], false);
    echo "\n";

    // -----------------------------------------------------------------
    // resolve_periode_bulanan() — cabang mulai tanggal 20 (batas geser)
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_PERIODE_H20', '000', 'X')");
    $id_h20 = $conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO laporan_cabang (id_cabang, tanggal, status_laporan) VALUES (?, '2026-09-20', 'lengkap')");
    $stmt->bind_param('i', $id_h20);
    $stmt->execute();

    echo "[resolve_periode_bulanan — mulai tanggal 20 (digeser ke bulan depan)]\n";
    $r = resolve_periode_bulanan($conn, $id_h20, 2026, 9);
    cek('bulan mulai (Sept) sendiri -> periode_kosong = true', $r['periode_kosong'], true);
    cek('bulan mulai -> digabung tetap false (bukan periode yg digabung, tapi yg kosong)', $r['digabung'], false);

    $r2 = resolve_periode_bulanan($conn, $id_h20, 2026, 10);
    cek('bulan berikutnya (Okt) -> tgl_mulai mundur ke 2026-09-20', $r2['tgl_mulai'], '2026-09-20');
    cek('bulan berikutnya -> tgl_selesai tetap akhir Okt', $r2['tgl_selesai'], '2026-10-31');
    cek('bulan berikutnya -> digabung = true', $r2['digabung'], true);
    cek('bulan berikutnya -> periode_kosong = false', $r2['periode_kosong'], false);

    $r3 = resolve_periode_bulanan($conn, $id_h20, 2026, 11);
    cek('2 bulan setelah mulai (Nov) -> kembali normal, tidak digabung lagi (sekali saja)', $r3['digabung'], false);
    cek('Nov -> tgl_mulai kalender biasa', $r3['tgl_mulai'], '2026-11-01');
    echo "\n";

    // -----------------------------------------------------------------
    // resolve_periode_bulanan() — cabang mulai tanggal 31 (akhir bulan)
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_PERIODE_H31', '000', 'X')");
    $id_h31 = $conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO laporan_cabang (id_cabang, tanggal, status_laporan) VALUES (?, '2026-01-31', 'lengkap')");
    $stmt->bind_param('i', $id_h31);
    $stmt->execute();

    echo "[resolve_periode_bulanan — mulai tanggal 31 Januari]\n";
    $rJan = resolve_periode_bulanan($conn, $id_h31, 2026, 1);
    cek('bulan mulai (Jan) -> periode_kosong = true', $rJan['periode_kosong'], true);
    $rFeb = resolve_periode_bulanan($conn, $id_h31, 2026, 2);
    cek('bulan berikutnya (Feb) -> tgl_mulai mundur ke 2026-01-31', $rFeb['tgl_mulai'], '2026-01-31');
    cek('Feb -> digabung = true', $rFeb['digabung'], true);
    echo "\n";

    // -----------------------------------------------------------------
    // cabang_mulai_pembukuan() — cabang belum pernah lapor sama sekali
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_PERIODE_KOSONG', '000', 'X')");
    $id_kosong = $conn->insert_id;
    echo "[cabang_mulai_pembukuan / resolve_periode_bulanan — belum ada laporan]\n";
    cek('cabang_mulai_pembukuan -> null', cabang_mulai_pembukuan($conn, $id_kosong), null);
    $rKosong = resolve_periode_bulanan($conn, $id_kosong, 2026, 9);
    cek('tanpa data -> tidak ada yang digeser (kalender biasa)', $rKosong['tgl_mulai'], '2026-09-01');
    cek('tanpa data -> periode_kosong = false', $rKosong['periode_kosong'], false);
    echo "\n";

    // -----------------------------------------------------------------
    // resolve_pengelola_segments() — rotasi 2 pengelola di tengah rentang
    // -----------------------------------------------------------------
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_SEGMEN_ROTASI', '000', 'Default')");
    $id_rotasi = $conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola A', '2026-01-01', '2026-01-15', 'nonaktif')");
    $stmt->bind_param('i', $id_rotasi);
    $stmt->execute();
    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola B', '2026-01-16', NULL, 'aktif')");
    $stmt->bind_param('i', $id_rotasi);
    $stmt->execute();

    echo "[resolve_pengelola_segments — rotasi di tengah bulan Januari]\n";
    $segs = resolve_pengelola_segments($conn, $id_rotasi, '2026-01-01', '2026-01-31');
    cek('jumlah segmen = 2', count($segs), 2);
    cek('segmen 1 = Pengelola A', $segs[0]['pengelola']['nama_pengelola'] ?? null, 'Pengelola A');
    cek('segmen 1 tgl_mulai dipotong ke 2026-01-01', $segs[0]['tgl_mulai'], '2026-01-01');
    cek('segmen 1 tgl_selesai dipotong ke 2026-01-15', $segs[0]['tgl_selesai'], '2026-01-15');
    cek('segmen 2 = Pengelola B', $segs[1]['pengelola']['nama_pengelola'] ?? null, 'Pengelola B');
    cek('segmen 2 tgl_mulai dipotong ke 2026-01-16', $segs[1]['tgl_mulai'], '2026-01-16');
    cek('segmen 2 tgl_selesai dipotong ke akhir rentang (2026-01-31, bukan NULL)', $segs[1]['tgl_selesai'], '2026-01-31');
    cek('urutan segmen 1', $segs[0]['urutan'], 1);
    cek('urutan segmen 2', $segs[1]['urutan'], 2);
    echo "\n";

    echo "[resolve_pengelola_segments — kasus normal, 1 pengelola sepanjang rentang]\n";
    $segsNormal = resolve_pengelola_segments($conn, $id_rotasi, '2026-02-01', '2026-02-28');
    cek('tetap 1 segmen (bukan percabangan khusus)', count($segsNormal), 1);
    cek('segmen tunggal = Pengelola B (yang aktif di Feb)', $segsNormal[0]['pengelola']['nama_pengelola'] ?? null, 'Pengelola B');
    echo "\n";

    echo "[resolve_pengelola_segments — rotasi 3 pengelola]\n";
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_SEGMEN_TRIPEL', '000', 'Default')");
    $id_tripel = $conn->insert_id;
    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola X', '2026-03-01', '2026-03-10', 'nonaktif')");
    $stmt->bind_param('i', $id_tripel); $stmt->execute();
    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola Y', '2026-03-11', '2026-03-20', 'nonaktif')");
    $stmt->bind_param('i', $id_tripel); $stmt->execute();
    $stmt = $conn->prepare("INSERT INTO pengelola (id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status) VALUES (?, 'Pengelola Z', '2026-03-21', NULL, 'aktif')");
    $stmt->bind_param('i', $id_tripel); $stmt->execute();

    $segsTripel = resolve_pengelola_segments($conn, $id_tripel, '2026-03-01', '2026-03-31');
    cek('jumlah segmen = 3', count($segsTripel), 3);
    cek('segmen 3 = Pengelola Z', $segsTripel[2]['pengelola']['nama_pengelola'] ?? null, 'Pengelola Z');
    echo "\n";

    echo "[resolve_pengelola_segments — tanpa data pengelola sama sekali]\n";
    $conn->query("INSERT INTO cabang (nama_cabang, no_telp, nama_pengelola) VALUES ('TEST_SEGMEN_KOSONG', '000', 'Fallback Nama')");
    $id_seg_kosong = $conn->insert_id;
    $segsKosong = resolve_pengelola_segments($conn, $id_seg_kosong, '2026-04-01', '2026-04-30');
    cek('tetap 1 segmen fallback', count($segsKosong), 1);
    cek('pengelola null (caller jatuh ke fallback)', $segsKosong[0]['pengelola'], null);
    cek('rentang fallback = seluruh rentang diminta', $segsKosong[0]['tgl_mulai'] . '..' . $segsKosong[0]['tgl_selesai'], '2026-04-01..2026-04-30');
    echo "\n";
} finally {
    $conn->rollback();
}

echo "==============================\n";
echo "Total: $lolos lolos, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
