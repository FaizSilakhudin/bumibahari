<?php
/**
 * Endpoint AJAX: simpan baris "10. Klaim Bulanan" (Rekapitulasi, admin_pusat).
 *
 * Strategi paling sederhana utk "baris ditambah/dihapus bebas oleh user":
 * hapus SEMUA baris lama utk (id_cabang,tahun,bulan,urutan_pengelola) lalu
 * insert ulang set yang dikirim, dalam 1 transaksi — tidak perlu tracking ID
 * per baris di client.
 *
 * Response JSON:
 *   sukses : {ok:true, total:<float>, jumlah_baris:<int>}
 *   gagal  : {ok:false, msg:'...'}
 */

require '../config/koneksi.php';
require_role('pusat');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token tidak valid atau method salah']);
    exit;
}

$id_cabang = (int) ($_POST['id_cabang'] ?? 0);
$tahun     = (int) ($_POST['tahun'] ?? 0);
$bulan     = (int) ($_POST['bulan'] ?? 0);
$rows_raw  = json_decode($_POST['rows'] ?? '[]', true);

if ($id_cabang <= 0 || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12 || !is_array($rows_raw)) {
    echo json_encode(['ok' => false, 'msg' => 'Parameter tidak valid']);
    exit;
}

$cek = $conn->prepare('SELECT id_cabang FROM cabang WHERE id_cabang = ?');
$cek->bind_param('i', $id_cabang);
$cek->execute();
if (!$cek->get_result()->fetch_assoc()) {
    echo json_encode(['ok' => false, 'msg' => 'Cabang tidak ditemukan']);
    exit;
}
$cek->close();

$uid = current_user_id();

$conn->begin_transaction();
try {
    $del = $conn->prepare('DELETE FROM klaim_bulanan WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = 1');
    $del->bind_param('iii', $id_cabang, $tahun, $bulan);
    $del->execute();
    $del->close();

    $ins = $conn->prepare('INSERT INTO klaim_bulanan (id_cabang, tahun, bulan, urutan_pengelola, urutan, uraian, nominal, keterangan, created_by) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)');
    $total = 0.0;
    $jumlah_baris = 0;
    foreach ($rows_raw as $r) {
        $uraian = trim((string) ($r['uraian'] ?? ''));
        $nominal = (float) ($r['nominal'] ?? 0);
        $keterangan = trim((string) ($r['keterangan'] ?? ''));
        $urutan = (int) ($r['urutan'] ?? 0);
        if ($uraian === '' && $nominal == 0 && $keterangan === '') {
            continue; // baris kosong, skip
        }
        $uraian = mb_substr($uraian, 0, 255);
        $keterangan = $keterangan !== '' ? mb_substr($keterangan, 0, 255) : null;
        $ins->bind_param('iiiisdsi', $id_cabang, $tahun, $bulan, $urutan, $uraian, $nominal, $keterangan, $uid);
        $ins->execute();
        $total += $nominal;
        $jumlah_baris++;
    }
    $ins->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()]);
    exit;
}

audit($conn, 'rekap_klaim_bulanan_simpan', 'klaim_bulanan', $id_cabang, [
    'id_cabang' => $id_cabang, 'tahun' => $tahun, 'bulan' => $bulan, 'jumlah_baris' => $jumlah_baris, 'total' => $total,
]);

echo json_encode(['ok' => true, 'total' => $total, 'jumlah_baris' => $jumlah_baris]);
