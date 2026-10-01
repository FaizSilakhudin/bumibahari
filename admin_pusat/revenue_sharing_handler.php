<?php
/**
 * Endpoint AJAX untuk REVENUE SHARING (admin_pusat/revenue_sharing.php DAN
 * tombol "Simpan Revenue Sharing" di admin_pusat/rekapitulasi.php).
 *
 * Aksi (field POST 'aksi'):
 *   - simpan_dari_rekap : simpan admin_fee + persen/nominal_service_fee dari
 *                         Rekapitulasi utk (id_cabang, tahun, bulan, urutan_pengelola)
 *   - simpan_kasbon     : simpan kasbon_nominal/kasbon_sumber/kasbon_keterangan
 *                         (tombol "Simpan Kasbon" di Koreksi Dividen: Sisi Investor)
 *   - update_status     : simpan status_pembayaran untuk (id_cabang, tahun, bulan)
 *                         — satu-satunya yang masih editable inline di revenue_sharing.php
 *
 * (update_persen DIHAPUS — presentase tidak lagi bisa diedit langsung di
 * revenue_sharing.php, hanya lewat simpan_dari_rekap.)
 *
 * Response JSON:
 *   sukses  : {ok:true, ...}
 *   gagal   : {ok:false, msg:'...'}
 *
 * Keamanan:
 *   - require_role('pusat')                       : hanya user pusat
 *   - csrf_check($_POST['csrf'])                  : token CSRF (FormData)
 *   - Whitelist nilai enum sebelum bind_param    : status_pembayaran ∈ {pending,belum_lunas,lunas}
 *   - audit()                                     : jejak perubahan
 */

require '../config/koneksi.php';
require_role('pusat');

header('Content-Type: application/json; charset=utf-8');

// Wajib method POST + CSRF valid
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token tidak valid atau method salah']);
    exit;
}

$aksi = $_POST['aksi'] ?? '';
$id_cabang = (int) ($_POST['id_cabang'] ?? 0);
$tahun     = (int) ($_POST['tahun'] ?? 0);
$bulan     = (int) ($_POST['bulan'] ?? 0);

if ($id_cabang <= 0 || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12) {
    echo json_encode(['ok' => false, 'msg' => 'Parameter tidak valid']);
    exit;
}

// Verifikasi cabang masih ada (FK sudah jamin, tapi sanity check cepat)
$cek = $conn->prepare("SELECT id_cabang FROM cabang WHERE id_cabang = ?");
$cek->bind_param('i', $id_cabang);
$cek->execute();
if (!$cek->get_result()->fetch_assoc()) {
    echo json_encode(['ok' => false, 'msg' => 'Cabang tidak ditemukan']);
    exit;
}
$cek->close();

// Helper kecil — hitung ulang share_pengelola & service fee setelah update.
// Rumus persis sama dengan admin_pusat/rekapitulasi.php:183-190:
//   laba_setelah_admin = net_profit - (net_profit * 3 / 100)
//   share_pengelola    = laba_setelah_admin * 50 / 100
//   service_fee        = share_pengelola * persen_service_fee / 100
function hitung_service_fee(mysqli $conn, int $id_cabang, int $tahun, int $bulan, float $persen_service_fee): array {
    $st = $conn->prepare("SELECT COALESCE(SUM(net_profit), 0) AS tot
        FROM laporan_cabang
        WHERE id_cabang = ? AND status_laporan = 'lengkap'
          AND YEAR(tanggal) = ? AND MONTH(tanggal) = ?");
    $st->bind_param('iii', $id_cabang, $tahun, $bulan);
    $st->execute();
    $net_profit = (float) $st->get_result()->fetch_assoc()['tot'];
    $st->close();

    $laba_setelah_admin = $net_profit - ($net_profit * 3 / 100);
    $share_pengelola    = $laba_setelah_admin * 50 / 100;
    $service_fee        = $share_pengelola * $persen_service_fee / 100;
    return ['net_profit' => $net_profit, 'service_fee' => $service_fee];
}

// Helper — ambil nilai saat ini sebelum diupdate, untuk audit log "sebelum".
function ambil_nilai_sebelum(mysqli $conn, int $id_cabang, int $tahun, int $bulan, int $urutan_pengelola = 1): array {
    $st = $conn->prepare("SELECT persen_service_fee, status_pembayaran
        FROM revenue_sharing WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ?");
    $st->bind_param('iiii', $id_cabang, $tahun, $bulan, $urutan_pengelola);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return [
        'persen_service_fee' => $r ? (float) $r['persen_service_fee'] : 5.00,
        'status_pembayaran'  => $r ? (string) $r['status_pembayaran'] : 'pending',
    ];
}

// Sinkronisasi otomatis ke buku "Kasbon Pengelola" (menu admin_pusat) -- HANYA
// kalau sumbernya "Dana Pusat" (itu benar-benar piutang pusat ke pengelola;
// Dana Investor/Dana Warung tidak disinkron, bukan piutang pusat). Dicari
// lewat kunci (asal_otomatis=1, id_cabang_periode, tahun_periode, bulan_periode)
// supaya klik "Simpan Kasbon" berulang kali untuk cabang+periode yang sama
// meng-UPDATE baris yang sama, bukan bikin duplikat. jumlah_dikembalikan
// SENGAJA tidak disentuh di sini -- itu murni milik menu Kasbon Pengelola
// (dicatat lewat tombol "Bayar" di sana), supaya riwayat pembayaran yang
// sudah ada tidak pernah hilang hanya karena nominal kasbon di Rekapitulasi
// diedit lagi.
function sinkronkan_kasbon_dana_pusat(mysqli $conn, int $id_cabang, int $tahun, int $bulan, string $kasbon_sumber, float $kasbon_nominal, string $kasbon_keterangan, ?int $uid): void {
    $periode_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));
    $pgl = $conn->prepare("SELECT id FROM pengelola WHERE id_cabang = ? AND tgl_mulai <= ? AND (tgl_selesai IS NULL OR tgl_selesai >= ?) ORDER BY tgl_mulai DESC LIMIT 1");
    $pgl->bind_param('iss', $id_cabang, $periode_akhir, $periode_akhir);
    $pgl->execute();
    $id_pengelola_sync = $pgl->get_result()->fetch_assoc()['id'] ?? null;
    $pgl->close();
    if (!$id_pengelola_sync) {
        return; // Tidak ada pengelola aktif pada periode ini -- tidak bisa disinkron.
    }
    $id_pengelola_sync = (int) $id_pengelola_sync;

    $cek = $conn->prepare("SELECT id, jumlah_dikembalikan FROM kasbon_pengelola WHERE asal_otomatis = 1 AND id_cabang_periode = ? AND tahun_periode = ? AND bulan_periode = ?");
    $cek->bind_param('iii', $id_cabang, $tahun, $bulan);
    $cek->execute();
    $existing = $cek->get_result()->fetch_assoc();
    $cek->close();

    $tgl_kasbon = "$tahun-" . str_pad((string) $bulan, 2, '0', STR_PAD_LEFT) . "-01";
    $ket = $kasbon_keterangan !== '' ? $kasbon_keterangan : 'Kasbon dari Rekapitulasi (Dana Pusat)';

    if ($kasbon_sumber === 'pusat' && $kasbon_nominal > 0) {
        if ($existing) {
            $dikembalikan_lama = (float) $existing['jumlah_dikembalikan'];
            $status_baru = $dikembalikan_lama >= $kasbon_nominal ? 'lunas' : 'berjalan';
            $up = $conn->prepare("UPDATE kasbon_pengelola SET id_pengelola = ?, jumlah_kasbon = ?, tanggal_kasbon = ?, keterangan = ?, status = ? WHERE id = ?");
            $up->bind_param('idsssi', $id_pengelola_sync, $kasbon_nominal, $tgl_kasbon, $ket, $status_baru, $existing['id']);
            $up->execute();
            $up->close();
        } else {
            $ins = $conn->prepare("INSERT INTO kasbon_pengelola (id_pengelola, jumlah_kasbon, tanggal_kasbon, keterangan, status, asal_otomatis, id_cabang_periode, tahun_periode, bulan_periode, created_by) VALUES (?, ?, ?, ?, 'berjalan', 1, ?, ?, ?, ?)");
            $ins->bind_param('idssiiii', $id_pengelola_sync, $kasbon_nominal, $tgl_kasbon, $ket, $id_cabang, $tahun, $bulan, $uid);
            $ins->execute();
            $ins->close();
        }
        return;
    }

    // Sumbernya bukan (lagi) "Dana Pusat", atau nominalnya 0 -- bersihkan
    // sinkronisasi lama. Kalau belum pernah ada pembayaran tercatat, baris
    // auto-sync-nya langsung dihapus. Kalau sudah ada pembayaran, JANGAN
    // dihapus (riwayat bayar penting) -- cukup kunci jumlah_kasbon-nya ke
    // jumlah yang sudah dikembalikan supaya otomatis berstatus Lunas & tidak
    // terus-terusan dianggap "masih berjalan" untuk kasbon yang sudah tidak
    // relevan lagi dari sisi Rekapitulasi.
    if ($existing) {
        if ((float) $existing['jumlah_dikembalikan'] <= 0) {
            $del = $conn->prepare("DELETE FROM kasbon_pengelola WHERE id = ?");
            $del->bind_param('i', $existing['id']);
            $del->execute();
            $del->close();
        } else {
            $up = $conn->prepare("UPDATE kasbon_pengelola SET jumlah_kasbon = jumlah_dikembalikan, status = 'lunas' WHERE id = ?");
            $up->bind_param('i', $existing['id']);
            $up->execute();
            $up->close();
        }
    }
}

// ---- Aksi: simpan admin_fee + persen/nominal_service_fee dari Rekapitulasi ----
if ($aksi === 'simpan_dari_rekap') {
    $urutan_pengelola   = (int) ($_POST['urutan_pengelola'] ?? 1);
    $admin_fee          = (float) ($_POST['admin_fee'] ?? 0);
    $persen_mentah      = (float) ($_POST['persen_service_fee'] ?? 0);
    $nominal_service_fee = (float) ($_POST['nominal_service_fee'] ?? 0);

    if ($urutan_pengelola < 1 || $urutan_pengelola > 9) {
        echo json_encode(['ok' => false, 'msg' => 'Segmen pengelola tidak valid']);
        exit;
    }
    if (!in_array($persen_mentah, [3.0, 5.0, 7.5], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Presentase tidak valid (3/5/7,5 saja)']);
        exit;
    }

    $sebelum = ambil_nilai_sebelum($conn, $id_cabang, $tahun, $bulan, $urutan_pengelola);
    $uid = current_user_id();

    // INSERT … ON DUPLICATE KEY UPDATE pada unique key (id_cabang,tahun,bulan,
    // urutan_pengelola) — simpan admin_fee + persen + nominal_service_fee
    // sekaligus (nilai sudah dihitung KLIEN, termasuk Modal Awal/Talangan/
    // Klaim Bulanan — dipercaya apa adanya, tidak di-re-derive server-side).
    // Status TIDAK disentuh di sini.
    $sql = "INSERT INTO revenue_sharing (id_cabang, tahun, bulan, urutan_pengelola, admin_fee, persen_service_fee, nominal_service_fee, status_pembayaran, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              admin_fee = VALUES(admin_fee),
              persen_service_fee = VALUES(persen_service_fee),
              nominal_service_fee = VALUES(nominal_service_fee),
              updated_by = VALUES(updated_by)";
    $st = $conn->prepare($sql);
    $st->bind_param('iiiidddsi', $id_cabang, $tahun, $bulan, $urutan_pengelola, $admin_fee, $persen_mentah, $nominal_service_fee, $sebelum['status_pembayaran'], $uid);
    $st->execute();
    $st->close();

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
    exit;
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

    $sebelum = ambil_nilai_sebelum($conn, $id_cabang, $tahun, $bulan, $urutan_pengelola);
    $uid = current_user_id();

    // INSERT … ON DUPLICATE KEY UPDATE pada baris (id_cabang,tahun,bulan,
    // urutan_pengelola) yang sama dengan admin_fee/service_fee -- hanya
    // kolom kasbon_* yang disentuh di sini, service fee tidak diubah.
    $sql = "INSERT INTO revenue_sharing (id_cabang, tahun, bulan, urutan_pengelola, persen_service_fee, status_pembayaran, kasbon_nominal, kasbon_sumber, kasbon_keterangan, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              kasbon_nominal = VALUES(kasbon_nominal),
              kasbon_sumber = VALUES(kasbon_sumber),
              kasbon_keterangan = VALUES(kasbon_keterangan),
              updated_by = VALUES(updated_by)";
    $st = $conn->prepare($sql);
    $st->bind_param('iiiidsdssi', $id_cabang, $tahun, $bulan, $urutan_pengelola, $sebelum['persen_service_fee'], $sebelum['status_pembayaran'], $kasbon_nominal, $kasbon_sumber, $kasbon_keterangan, $uid);
    $st->execute();
    $st->close();

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

// ---- Aksi: ubah status pembayaran ----
if ($aksi === 'update_status') {
    $status_baru = (string) ($_POST['status_pembayaran'] ?? '');
    if (!in_array($status_baru, ['pending', 'belum_lunas', 'lunas'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Status tidak valid']);
        exit;
    }

    $sebelum = ambil_nilai_sebelum($conn, $id_cabang, $tahun, $bulan);
    $uid = current_user_id();

    // Sama seperti update_persen — INSERT-ON-DUPLICATE-KEY, tapi yang diubah status.
    // Pakai persen_service_fee dari "sebelum" supaya kalau baris baru, default 5%.
    $sql = "INSERT INTO revenue_sharing (id_cabang, tahun, bulan, persen_service_fee, status_pembayaran, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              status_pembayaran = VALUES(status_pembayaran),
              updated_by = VALUES(updated_by)";
    $st = $conn->prepare($sql);
    $st->bind_param('iiidsi', $id_cabang, $tahun, $bulan, $sebelum['persen_service_fee'], $status_baru, $uid);
    $st->execute();
    $st->close();

    // Hitung ulang nominal dengan persen TIDAK BERUBAH (hanya status yang diedit)
    $recalc = hitung_service_fee($conn, $id_cabang, $tahun, $bulan, $sebelum['persen_service_fee']);

    audit($conn, 'revenue_sharing_update_status', 'revenue_sharing', $id_cabang, [
        'id_cabang' => $id_cabang,
        'tahun'     => $tahun,
        'bulan'     => $bulan,
        'sebelum'   => $sebelum['status_pembayaran'],
        'sesudah'   => $status_baru,
    ]);

    echo json_encode([
        'ok'                 => true,
        'persen_service_fee' => $sebelum['persen_service_fee'],
        'status_pembayaran'  => $status_baru,
        'nominal_service_fee'=> round($recalc['service_fee'], 2),
    ]);
    exit;
}

// Aksi tidak dikenal
echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal']);
exit;