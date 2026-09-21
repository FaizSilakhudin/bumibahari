<?php
/**
 * Endpoint AJAX minimal: tombol "Simpan Revenue Sharing" di admin_pic/rekapitulasi.php.
 * PIC TIDAK punya halaman revenue_sharing.php sendiri (itu tetap pusat-only) —
 * handler ini HANYA menerima aksi simpan_dari_rekap, discope pic_cabang_ids().
 *
 * Response JSON:
 *   sukses : {ok:true, admin_fee:<float>, persen_service_fee:<float>, nominal_service_fee:<float>}
 *   gagal  : {ok:false, msg:'...'}
 */

require '../config/koneksi.php';
require_role('pic');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token tidak valid atau method salah']);
    exit;
}

$aksi = $_POST['aksi'] ?? '';
if ($aksi !== 'simpan_dari_rekap') {
    echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal']);
    exit;
}

$id_cabang = (int) ($_POST['id_cabang'] ?? 0);
$tahun     = (int) ($_POST['tahun'] ?? 0);
$bulan     = (int) ($_POST['bulan'] ?? 0);

if ($id_cabang <= 0 || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12) {
    echo json_encode(['ok' => false, 'msg' => 'Parameter tidak valid']);
    exit;
}

$cabang_ids_pic = pic_cabang_ids($conn, current_user_id());
if (!in_array($id_cabang, $cabang_ids_pic, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Cabang ini bukan yang Anda pegang']);
    exit;
}

$urutan_pengelola    = (int) ($_POST['urutan_pengelola'] ?? 1);
$admin_fee           = (float) ($_POST['admin_fee'] ?? 0);
$persen_mentah       = (float) ($_POST['persen_service_fee'] ?? 0);
$nominal_service_fee = (float) ($_POST['nominal_service_fee'] ?? 0);

if ($urutan_pengelola < 1 || $urutan_pengelola > 9) {
    echo json_encode(['ok' => false, 'msg' => 'Segmen pengelola tidak valid']);
    exit;
}
if (!in_array($persen_mentah, [3.0, 5.0, 7.5], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Presentase tidak valid (3/5/7,5 saja)']);
    exit;
}

// Pertahankan status_pembayaran yang sudah ada (kalau baris baru, default 'pending') —
// PIC tidak berwenang mengubah status pembayaran, hanya nominal dari Rekapitulasi.
$st = $conn->prepare('SELECT status_pembayaran FROM revenue_sharing WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ?');
$st->bind_param('iiii', $id_cabang, $tahun, $bulan, $urutan_pengelola);
$st->execute();
$status_sekarang = $st->get_result()->fetch_assoc()['status_pembayaran'] ?? 'pending';
$st->close();

$uid = current_user_id();
$sql = "INSERT INTO revenue_sharing (id_cabang, tahun, bulan, urutan_pengelola, admin_fee, persen_service_fee, nominal_service_fee, status_pembayaran, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          admin_fee = VALUES(admin_fee),
          persen_service_fee = VALUES(persen_service_fee),
          nominal_service_fee = VALUES(nominal_service_fee),
          updated_by = VALUES(updated_by)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iiiidddsi', $id_cabang, $tahun, $bulan, $urutan_pengelola, $admin_fee, $persen_mentah, $nominal_service_fee, $status_sekarang, $uid);
$stmt->execute();
$stmt->close();

audit($conn, 'revenue_sharing_simpan_dari_rekap', 'revenue_sharing', $id_cabang, [
    'id_cabang'           => $id_cabang,
    'tahun'               => $tahun,
    'bulan'               => $bulan,
    'urutan_pengelola'    => $urutan_pengelola,
    'admin_fee'           => $admin_fee,
    'persen_service_fee'  => $persen_mentah,
    'nominal_service_fee' => $nominal_service_fee,
]);

echo json_encode([
    'ok'                  => true,
    'admin_fee'           => $admin_fee,
    'persen_service_fee'  => $persen_mentah,
    'nominal_service_fee' => $nominal_service_fee,
]);
