<?php
/**
 * Endpoint AJAX minimal: tombol "Simpan Revenue Sharing" DAN "Simpan Kasbon"
 * di admin_pic/rekapitulasi.php. PIC TIDAK punya halaman revenue_sharing.php
 * sendiri (itu tetap pusat-only) — handler ini HANYA menerima aksi
 * simpan_dari_rekap / simpan_kasbon, discope pic_cabang_ids().
 *
 * Response JSON:
 *   sukses : {ok:true, ...}
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
if (!in_array($aksi, ['simpan_dari_rekap', 'simpan_kasbon'], true)) {
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

// Sinkronisasi otomatis ke buku "Kasbon Pengelola" (menu admin_pusat) -- HANYA
// kalau sumbernya "Dana Pusat". PENTING: nominal ini BUKAN kasbon baru --
// ini adalah POTONGAN PEMBAYARAN/PENGEMBALIAN dari kasbon yang SUDAH ADA
// (persis seperti efek tombol "Bayar" di menu Kasbon Pengelola), diterapkan
// ke kasbon 'berjalan' pengelola tsb yang paling lama diambil (FIFO). Dana
// Investor/Dana Warung tidak disinkron (bukan transaksi lewat pusat).
//
// Dicari lewat kunci (asal_otomatis=1, id_cabang_periode, tahun_periode,
// bulan_periode) di kasbon_pengelola_riwayat supaya klik "Simpan Kasbon"
// berulang utk cabang+periode yang sama meng-UPDATE baris riwayat yang sama
// (menerapkan ULANG nominal barunya ke kasbon terkait), bukan menambah
// pembayaran baru tiap kali disimpan.
function sinkronkan_kasbon_dana_pusat(mysqli $conn, int $id_cabang, int $tahun, int $bulan, string $kasbon_sumber, float $kasbon_nominal, string $kasbon_keterangan, ?int $uid): void {
    $periode_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));
    $pgl = $conn->prepare("SELECT id FROM pengelola WHERE id_cabang = ? AND tgl_mulai <= ? AND (tgl_selesai IS NULL OR tgl_selesai >= ?) ORDER BY tgl_mulai DESC LIMIT 1");
    $pgl->bind_param('iss', $id_cabang, $periode_akhir, $periode_akhir);
    $pgl->execute();
    $id_pengelola_sync = $pgl->get_result()->fetch_assoc()['id'] ?? null;
    $pgl->close();
    if (!$id_pengelola_sync) {
        return;
    }
    $id_pengelola_sync = (int) $id_pengelola_sync;

    $tgl_bayar = $periode_akhir;
    $nama_periode = nama_bulan_id($bulan) . ' ' . $tahun;
    $ket = ($kasbon_keterangan !== '' ? $kasbon_keterangan . ' — ' : '') . "Potongan kasbon dari Rekapitulasi (Dana Pusat) periode $nama_periode";

    $cek = $conn->prepare("SELECT id, id_kasbon, jumlah_bayar FROM kasbon_pengelola_riwayat
        WHERE asal_otomatis = 1 AND id_cabang_periode = ? AND tahun_periode = ? AND bulan_periode = ?");
    $cek->bind_param('iii', $id_cabang, $tahun, $bulan);
    $cek->execute();
    $existing = $cek->get_result()->fetch_assoc();
    $cek->close();

    if ($existing) {
        $kb = $conn->prepare("SELECT jumlah_kasbon, jumlah_dikembalikan FROM kasbon_pengelola WHERE id = ?");
        $kb->bind_param('i', $existing['id_kasbon']);
        $kb->execute();
        $kasbon_terkait = $kb->get_result()->fetch_assoc();
        $kb->close();
        $dikembalikan_tanpa_lama = $kasbon_terkait ? max(0, (float) $kasbon_terkait['jumlah_dikembalikan'] - (float) $existing['jumlah_bayar']) : null;

        if ($kasbon_sumber !== 'pusat' || $kasbon_nominal <= 0) {
            if ($kasbon_terkait) {
                $status_balik = $dikembalikan_tanpa_lama >= (float) $kasbon_terkait['jumlah_kasbon'] ? 'lunas' : 'berjalan';
                $up = $conn->prepare("UPDATE kasbon_pengelola SET jumlah_dikembalikan = ?, status = ? WHERE id = ?");
                $up->bind_param('dsi', $dikembalikan_tanpa_lama, $status_balik, $existing['id_kasbon']);
                $up->execute();
                $up->close();
            }
            $del = $conn->prepare("DELETE FROM kasbon_pengelola_riwayat WHERE id = ?");
            $del->bind_param('i', $existing['id']);
            $del->execute();
            $del->close();
            return;
        }

        if ($kasbon_terkait) {
            $dikembalikan_baru = min((float) $kasbon_terkait['jumlah_kasbon'], $dikembalikan_tanpa_lama + $kasbon_nominal);
            $status_baru = $dikembalikan_baru >= (float) $kasbon_terkait['jumlah_kasbon'] ? 'lunas' : 'berjalan';
            $up = $conn->prepare("UPDATE kasbon_pengelola SET jumlah_dikembalikan = ?, tanggal_pengembalian = ?, status = ? WHERE id = ?");
            $up->bind_param('dssi', $dikembalikan_baru, $tgl_bayar, $status_baru, $existing['id_kasbon']);
            $up->execute();
            $up->close();

            $upr = $conn->prepare("UPDATE kasbon_pengelola_riwayat SET jumlah_bayar = ?, tanggal_bayar = ?, keterangan = ? WHERE id = ?");
            $upr->bind_param('dssi', $kasbon_nominal, $tgl_bayar, $ket, $existing['id']);
            $upr->execute();
            $upr->close();
        }
        return;
    }

    if ($kasbon_sumber !== 'pusat' || $kasbon_nominal <= 0) {
        return;
    }

    $kb = $conn->prepare("SELECT id, jumlah_kasbon, jumlah_dikembalikan FROM kasbon_pengelola WHERE id_pengelola = ? AND status = 'berjalan' ORDER BY tanggal_kasbon ASC, id ASC LIMIT 1");
    $kb->bind_param('i', $id_pengelola_sync);
    $kb->execute();
    $kasbon_aktif = $kb->get_result()->fetch_assoc();
    $kb->close();
    if (!$kasbon_aktif) {
        return;
    }

    $dikembalikan_baru = min((float) $kasbon_aktif['jumlah_kasbon'], (float) $kasbon_aktif['jumlah_dikembalikan'] + $kasbon_nominal);
    $status_baru = $dikembalikan_baru >= (float) $kasbon_aktif['jumlah_kasbon'] ? 'lunas' : 'berjalan';
    $up = $conn->prepare("UPDATE kasbon_pengelola SET jumlah_dikembalikan = ?, tanggal_pengembalian = ?, status = ? WHERE id = ?");
    $up->bind_param('dssi', $dikembalikan_baru, $tgl_bayar, $status_baru, $kasbon_aktif['id']);
    $up->execute();
    $up->close();

    $ins = $conn->prepare("INSERT INTO kasbon_pengelola_riwayat (id_kasbon, jumlah_bayar, tanggal_bayar, keterangan, asal_otomatis, id_cabang_periode, tahun_periode, bulan_periode, created_by) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)");
    $ins->bind_param('idssiiii', $kasbon_aktif['id'], $kasbon_nominal, $tgl_bayar, $ket, $id_cabang, $tahun, $bulan, $uid);
    $ins->execute();
    $ins->close();
}

// ---- Aksi: simpan Kasbon Pengelola dari Koreksi Dividen: Sisi Investor ----
if ($aksi === 'simpan_kasbon') {
    $urutan_pengelola  = (int) ($_POST['urutan_pengelola'] ?? 1);
    $kasbon_nominal    = (float) ($_POST['kasbon_nominal'] ?? 0);
    $kasbon_sumber     = (string) ($_POST['kasbon_sumber'] ?? 'investor');
    $kasbon_keterangan = trim((string) ($_POST['kasbon_keterangan'] ?? ''));

    if ($urutan_pengelola < 1 || $urutan_pengelola > 9) {
        echo json_encode(['ok' => false, 'msg' => 'Segmen pengelola tidak valid']);
        exit;
    }
    if (!in_array($kasbon_sumber, ['investor', 'pusat', 'warung'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Sumber kasbon tidak valid']);
        exit;
    }
    if ($kasbon_nominal < 0) {
        echo json_encode(['ok' => false, 'msg' => 'Nominal kasbon tidak boleh negatif']);
        exit;
    }
    if ($kasbon_keterangan !== '' && mb_strlen($kasbon_keterangan) > 255) {
        $kasbon_keterangan = mb_substr($kasbon_keterangan, 0, 255);
    }

    $st = $conn->prepare('SELECT persen_service_fee, status_pembayaran FROM revenue_sharing WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ?');
    $st->bind_param('iiii', $id_cabang, $tahun, $bulan, $urutan_pengelola);
    $st->execute();
    $row_sebelum = $st->get_result()->fetch_assoc();
    $st->close();
    $persen_sekarang = $row_sebelum ? (float) $row_sebelum['persen_service_fee'] : 5.00;
    $status_sekarang = $row_sebelum ? (string) $row_sebelum['status_pembayaran'] : 'pending';

    $uid = current_user_id();
    $sql = "INSERT INTO revenue_sharing (id_cabang, tahun, bulan, urutan_pengelola, persen_service_fee, status_pembayaran, kasbon_nominal, kasbon_sumber, kasbon_keterangan, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              kasbon_nominal = VALUES(kasbon_nominal),
              kasbon_sumber = VALUES(kasbon_sumber),
              kasbon_keterangan = VALUES(kasbon_keterangan),
              updated_by = VALUES(updated_by)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiidsdssi', $id_cabang, $tahun, $bulan, $urutan_pengelola, $persen_sekarang, $status_sekarang, $kasbon_nominal, $kasbon_sumber, $kasbon_keterangan, $uid);
    $stmt->execute();
    $stmt->close();

    sinkronkan_kasbon_dana_pusat($conn, $id_cabang, $tahun, $bulan, $kasbon_sumber, $kasbon_nominal, $kasbon_keterangan, $uid);

    audit($conn, 'revenue_sharing_simpan_kasbon', 'revenue_sharing', $id_cabang, [
        'id_cabang'         => $id_cabang,
        'tahun'             => $tahun,
        'bulan'             => $bulan,
        'urutan_pengelola'  => $urutan_pengelola,
        'kasbon_nominal'    => $kasbon_nominal,
        'kasbon_sumber'     => $kasbon_sumber,
        'kasbon_keterangan' => $kasbon_keterangan,
    ]);

    echo json_encode([
        'ok'                => true,
        'kasbon_nominal'    => $kasbon_nominal,
        'kasbon_sumber'     => $kasbon_sumber,
        'kasbon_keterangan' => $kasbon_keterangan,
    ]);
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
