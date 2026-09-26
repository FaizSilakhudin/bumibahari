<?php
/**
 * Endpoint AJAX: tombol "Simpan Akumulasi" (Modal Awal) di kartu "3. Matrik
 * Akumulasi", admin_pic/rekapitulasi.php. Sama seperti admin_pusat, tapi
 * discope pic_cabang_ids() — PIC hanya boleh menyimpan untuk cabang yang dia
 * pegang.
 *
 * Response JSON:
 *   sukses : {ok:true, modal_awal:<float>}
 *   gagal  : {ok:false, msg:'...'}
 *
 * Keamanan:
 *   - require_role('pic')
 *   - csrf_check($_POST['csrf'])
 *   - pic_cabang_ids() — cabang harus salah satu yang dipegang PIC ini
 *   - audit()
 */

require '../config/koneksi.php';
require_role('pic');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token tidak valid atau method salah']);
    exit;
}

$id_cabang  = (int) ($_POST['id_cabang'] ?? 0);
$tahun      = (int) ($_POST['tahun'] ?? 0);
$bulan      = (int) ($_POST['bulan'] ?? 0);
$modal_awal = (float) ($_POST['modal_awal'] ?? 0);

if ($id_cabang <= 0 || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12 || $modal_awal < 0) {
    echo json_encode(['ok' => false, 'msg' => 'Parameter tidak valid']);
    exit;
}

$cabang_ids_pic = pic_cabang_ids($conn, current_user_id());
if (!in_array($id_cabang, $cabang_ids_pic, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Cabang ini bukan yang Anda pegang']);
    exit;
}

$uid = current_user_id();
$sql = "INSERT INTO akumulasi_modal_awal (id_cabang, tahun, bulan, modal_awal, updated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE modal_awal = VALUES(modal_awal), updated_by = VALUES(updated_by)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iiidi', $id_cabang, $tahun, $bulan, $modal_awal, $uid);
$stmt->execute();
$stmt->close();

audit($conn, 'akumulasi_modal_awal_simpan', 'akumulasi_modal_awal', $id_cabang, [
    'id_cabang'  => $id_cabang,
    'tahun'      => $tahun,
    'bulan'      => $bulan,
    'modal_awal' => $modal_awal,
]);

echo json_encode(['ok' => true, 'modal_awal' => $modal_awal]);
