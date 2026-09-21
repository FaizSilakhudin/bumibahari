<?php
/**
 * Endpoint AJAX: simpan "Keterangan Tambahan" pada tabel Beban Operasional
 * (Rekapitulasi, admin_pusat). 12 field sekaligus dalam 1 request (bukan
 * autosave per-field).
 *
 * Response JSON:
 *   sukses : {ok:true, jumlah_field:<int>}
 *   gagal  : {ok:false, msg:'...'}
 *
 * Keamanan:
 *   - require_role('pusat')
 *   - csrf_check($_POST['csrf'])
 *   - Whitelist uraian_key thd 12 field yang dikenal $uraian_bo (rekapitulasi.php)
 *   - audit()
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
$ket_bo    = $_POST['ket_bo'] ?? [];

if ($id_cabang <= 0 || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12 || !is_array($ket_bo)) {
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

// Whitelist — harus persis 12 field yang dikenal ($uraian_bo di rekapitulasi.php).
$field_sah = ['sewa', 'gaji', 'listrik', 'air', 'sampah', 'keamanan', 'internet', 'gas', 'mingguan_karyawan', 'es_batu', 'bensin', 'lain_lain'];

$uid = current_user_id();
$sql = "INSERT INTO beban_operasional_keterangan (id_cabang, tahun, bulan, urutan_pengelola, uraian_key, keterangan, updated_by)
        VALUES (?, ?, ?, 1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan), updated_by = VALUES(updated_by)";
$stmt = $conn->prepare($sql);

$disimpan = 0;
foreach ($field_sah as $key) {
    $nilai = isset($ket_bo[$key]) ? trim((string) $ket_bo[$key]) : '';
    $nilai = $nilai !== '' ? mb_substr($nilai, 0, 255) : null;
    $stmt->bind_param('iiissi', $id_cabang, $tahun, $bulan, $key, $nilai, $uid);
    $stmt->execute();
    $disimpan++;
}
$stmt->close();

audit($conn, 'rekap_beban_keterangan_simpan', 'beban_operasional_keterangan', $id_cabang, [
    'id_cabang' => $id_cabang, 'tahun' => $tahun, 'bulan' => $bulan, 'jumlah_field' => $disimpan,
]);

echo json_encode(['ok' => true, 'jumlah_field' => $disimpan]);
