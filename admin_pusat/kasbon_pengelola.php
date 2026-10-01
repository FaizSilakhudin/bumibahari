<?php
/**
 * KASBON PENGELOLA — admin_pusat
 *
 * Buku catatan kasbon pengelola yang berdiri sendiri (riwayat per pengelola,
 * lintas periode) -- TIDAK otomatis terhubung ke kolom "Kasbon Pengelola" di
 * Rekapitulasi > Koreksi Dividen: Sisi Investor (itu per-bulan, mempengaruhi
 * payroll bulan tsb). Halaman ini murni pembukuan utang-piutang kasbon.
 *
 * sisa_kasbon = jumlah_kasbon - jumlah_dikembalikan, selalu dihitung saat
 * ditampilkan (tidak disimpan sebagai kolom terpisah).
 */

require '../config/koneksi.php';

// Role check SEBELUM sidebar_pusat.php di-include -- sidebar_pusat.php sendiri
// langsung mencetak HTML begitu di-include, jadi kalau include-nya duluan,
// header("Location: ...") di blok POST di bawah akan gagal ("headers already
// sent"). Pola ini SAMA PERSIS dengan data_pengelola.php.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header("Location: ../login");
    exit;
}

// ----- Tambah Kasbon -----
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id_pengelola   = (int) ($_POST['id_pengelola'] ?? 0);
    $jumlah_kasbon  = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_kasbon'] ?? '0');
    $tanggal_kasbon = $_POST['tanggal_kasbon'] ?? '';
    $keterangan     = trim($_POST['keterangan'] ?? '');

    if ($id_pengelola <= 0 || $jumlah_kasbon <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kasbon)) {
        $_SESSION['error'] = 'Pengelola, jumlah kasbon, dan tanggal kasbon wajib diisi dengan benar!';
        header("Location: kasbon_pengelola");
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO kasbon_pengelola (id_pengelola, jumlah_kasbon, tanggal_kasbon, keterangan, created_by) VALUES (?, ?, ?, ?, ?)");
    $uid = current_user_id();
    $stmt->bind_param('idssi', $id_pengelola, $jumlah_kasbon, $tanggal_kasbon, $keterangan, $uid);
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        $stmt->close();
        audit($conn, 'kasbon_pengelola_tambah', 'kasbon_pengelola', $new_id, ['id_pengelola' => $id_pengelola, 'jumlah_kasbon' => $jumlah_kasbon, 'tanggal_kasbon' => $tanggal_kasbon]);
        $_SESSION['success'] = 'Kasbon baru berhasil dicatat!';
    } else {
        $err = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal mencatat kasbon: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Catat Pengembalian (tambah ke jumlah_dikembalikan) -----
if (isset($_POST['catat_pengembalian'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $jumlah_bayar = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_pengembalian'] ?? '0');
    $tgl_bayar = $_POST['tanggal_pengembalian'] ?? '';

    if ($id <= 0 || $jumlah_bayar <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_bayar)) {
        $_SESSION['error'] = 'Jumlah pengembalian dan tanggal wajib diisi dengan benar!';
        header("Location: kasbon_pengelola");
        exit;
    }

    $cek = $conn->prepare("SELECT jumlah_kasbon, jumlah_dikembalikan FROM kasbon_pengelola WHERE id = ?");
    $cek->bind_param('i', $id);
    $cek->execute();
    $row = $cek->get_result()->fetch_assoc();
    $cek->close();

    if (!$row) {
        $_SESSION['error'] = 'Data kasbon tidak ditemukan!';
        header("Location: kasbon_pengelola");
        exit;
    }

    // Jumlah dikembalikan dibatasi (clamp) maksimal sebesar jumlah_kasbon --
    // tidak mungkin mengembalikan lebih dari yang dipinjam.
    $dikembalikan_baru = min((float) $row['jumlah_kasbon'], (float) $row['jumlah_dikembalikan'] + $jumlah_bayar);
    $status_baru = $dikembalikan_baru >= (float) $row['jumlah_kasbon'] ? 'lunas' : 'berjalan';

    $up = $conn->prepare("UPDATE kasbon_pengelola SET jumlah_dikembalikan = ?, tanggal_pengembalian = ?, status = ? WHERE id = ?");
    $up->bind_param('dssi', $dikembalikan_baru, $tgl_bayar, $status_baru, $id);
    if ($up->execute()) {
        $up->close();

        // Catat ke riwayat juga -- dipakai modal "Detail" untuk menampilkan
        // histori setiap pembayaran, bukan cuma total akumulasinya.
        $uid = current_user_id();
        $riw = $conn->prepare("INSERT INTO kasbon_pengelola_riwayat (id_kasbon, jumlah_bayar, tanggal_bayar, keterangan, created_by) VALUES (?, ?, ?, 'Pembayaran', ?)");
        $riw->bind_param('idsi', $id, $jumlah_bayar, $tgl_bayar, $uid);
        $riw->execute();
        $riw->close();

        audit($conn, 'kasbon_pengelola_pengembalian', 'kasbon_pengelola', $id, ['jumlah_dibayar' => $jumlah_bayar, 'jumlah_dikembalikan_baru' => $dikembalikan_baru, 'status' => $status_baru]);
        $_SESSION['success'] = $status_baru === 'lunas' ? 'Pengembalian dicatat — kasbon LUNAS!' : 'Pengembalian berhasil dicatat!';
    } else {
        $err = $up->error;
        $up->close();
        $_SESSION['error'] = 'Gagal mencatat pengembalian: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Edit (koreksi manual, termasuk jumlah_dikembalikan kalau perlu) -----
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id                  = (int) ($_POST['id'] ?? 0);
    $id_pengelola        = (int) ($_POST['id_pengelola'] ?? 0);
    $jumlah_kasbon       = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_kasbon'] ?? '0');
    $tanggal_kasbon      = $_POST['tanggal_kasbon'] ?? '';
    $jumlah_dikembalikan = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_dikembalikan'] ?? '0');
    $tanggal_pengembalian = !empty($_POST['tanggal_pengembalian']) ? $_POST['tanggal_pengembalian'] : null;
    $keterangan          = trim($_POST['keterangan'] ?? '');

    if ($id <= 0 || $id_pengelola <= 0 || $jumlah_kasbon <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kasbon)) {
        $_SESSION['error'] = 'Pengelola, jumlah kasbon, dan tanggal kasbon wajib diisi dengan benar!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $jumlah_dikembalikan = min($jumlah_dikembalikan, $jumlah_kasbon);
    $status = $jumlah_dikembalikan >= $jumlah_kasbon ? 'lunas' : 'berjalan';

    // Ambil nilai lama dulu -- kalau jumlah_dikembalikan dinaikkan lewat Edit
    // (bukan lewat tombol Bayar), selisihnya tetap dicatat ke riwayat supaya
    // modal "Detail" tetap lengkap & totalnya bisa ditelusuri.
    $lama = $conn->prepare("SELECT jumlah_dikembalikan FROM kasbon_pengelola WHERE id = ?");
    $lama->bind_param('i', $id);
    $lama->execute();
    $dikembalikan_lama = (float) ($lama->get_result()->fetch_assoc()['jumlah_dikembalikan'] ?? 0);
    $lama->close();

    $stmt = $conn->prepare("UPDATE kasbon_pengelola SET id_pengelola=?, jumlah_kasbon=?, tanggal_kasbon=?, jumlah_dikembalikan=?, tanggal_pengembalian=?, keterangan=?, status=? WHERE id=?");
    $stmt->bind_param('idsdsssi', $id_pengelola, $jumlah_kasbon, $tanggal_kasbon, $jumlah_dikembalikan, $tanggal_pengembalian, $keterangan, $status, $id);
    if ($stmt->execute()) {
        $stmt->close();

        $selisih = $jumlah_dikembalikan - $dikembalikan_lama;
        if ($selisih > 0) {
            $uid = current_user_id();
            $tgl_riwayat = $tanggal_pengembalian ?: date('Y-m-d');
            $riw = $conn->prepare("INSERT INTO kasbon_pengelola_riwayat (id_kasbon, jumlah_bayar, tanggal_bayar, keterangan, created_by) VALUES (?, ?, ?, 'Koreksi manual (Edit)', ?)");
            $riw->bind_param('idsi', $id, $selisih, $tgl_riwayat, $uid);
            $riw->execute();
            $riw->close();
        }

        audit($conn, 'kasbon_pengelola_edit', 'kasbon_pengelola', $id, ['id_pengelola' => $id_pengelola, 'jumlah_kasbon' => $jumlah_kasbon, 'jumlah_dikembalikan' => $jumlah_dikembalikan, 'status' => $status]);
        $_SESSION['success'] = 'Data kasbon berhasil diperbarui!';
    } else {
        $err = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal memperbarui data: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Hapus -----
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $del = $conn->prepare("DELETE FROM kasbon_pengelola WHERE id=?");
    $del->bind_param('i', $id);
    if ($del->execute()) {
        $del->close();
        audit($conn, 'kasbon_pengelola_hapus', 'kasbon_pengelola', $id);
        $_SESSION['success'] = 'Catatan kasbon berhasil dihapus!';
    } else {
        $err = $del->error;
        $del->close();
        $_SESSION['error'] = 'Gagal menghapus data: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Filter & Search -----
$search     = trim($_GET['search'] ?? '');
$sel_status = $_GET['status'] ?? '';
if (!in_array($sel_status, ['berjalan', 'lunas'], true)) $sel_status = '';

// Filter bulan/tahun -- berdasarkan tanggal_kasbon. "" = semua bulan/tahun
// (bukan default ke bulan berjalan, supaya histori lama tetap kelihatan
// begitu halaman pertama kali dibuka tanpa filter apa pun).
$sel_bulan = (int) ($_GET['bulan'] ?? 0);
if ($sel_bulan < 1 || $sel_bulan > 12) $sel_bulan = 0;
$sel_tahun = (int) ($_GET['tahun'] ?? 0);
if ($sel_tahun < 2000 || $sel_tahun > ((int) date('Y') + 1)) $sel_tahun = 0;

$where = "WHERE 1=1";
$params = [];
$types = '';
if ($search !== '') {
    $where .= " AND (p.nama_pengelola LIKE ? OR c.nama_cabang LIKE ? OR k.keterangan LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
    $types .= 'sss';
}
if ($sel_status !== '') {
    $where .= " AND k.status = ?";
    $params[] = $sel_status;
    $types .= 's';
}
if ($sel_bulan > 0) {
    $where .= " AND MONTH(k.tanggal_kasbon) = ?";
    $params[] = $sel_bulan;
    $types .= 'i';
}
if ($sel_tahun > 0) {
    $where .= " AND YEAR(k.tanggal_kasbon) = ?";
    $params[] = $sel_tahun;
    $types .= 'i';
}

// ----- Statistik ringkas (global, tidak terpengaruh filter/paginasi) -----
$stat = $conn->query("SELECT
        COUNT(*) AS jml_aktif,
        COALESCE(SUM(jumlah_kasbon - jumlah_dikembalikan), 0) AS sisa_berjalan
    FROM kasbon_pengelola WHERE status = 'berjalan'")->fetch_assoc();
$jml_kasbon_aktif  = (int) $stat['jml_aktif'];
$total_sisa_berjalan = (float) $stat['sisa_berjalan'];
$total_dikembalikan_all = (float) ($conn->query("SELECT COALESCE(SUM(jumlah_dikembalikan), 0) AS t FROM kasbon_pengelola")->fetch_assoc()['t'] ?? 0);

// ----- Paginasi & Query utama -----
$limit  = 20;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) AS total FROM kasbon_pengelola k
    JOIN pengelola p ON p.id = k.id_pengelola
    LEFT JOIN cabang c ON c.id_cabang = p.id_cabang $where";
$stc = $conn->prepare($sql_count);
if ($types !== '') $stc->bind_param($types, ...$params);
$stc->execute();
$total_data = (int) ($stc->get_result()->fetch_assoc()['total'] ?? 0);
$stc->close();
$total_pages = max(1, (int) ceil($total_data / $limit));

$sql = "SELECT k.*, p.nama_pengelola, p.id_cabang, c.nama_cabang
    FROM kasbon_pengelola k
    JOIN pengelola p ON p.id = k.id_pengelola
    LEFT JOIN cabang c ON c.id_cabang = p.id_cabang
    $where
    ORDER BY k.tanggal_kasbon DESC, k.id DESC
    LIMIT ? OFFSET ?";
$st = $conn->prepare($sql);
$bind_types = $types . 'ii';
$bind_params = array_merge($params, [$limit, $offset]);
$st->bind_param($bind_types, ...$bind_params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ----- Master data pengelola aktif (untuk dropdown Tambah Kasbon) -----
$pengelola_array = $conn->query("SELECT p.id, p.nama_pengelola, c.nama_cabang
    FROM pengelola p LEFT JOIN cabang c ON c.id_cabang = p.id_cabang
    WHERE p.status = 'aktif' ORDER BY p.nama_pengelola ASC")->fetch_all(MYSQLI_ASSOC);

// ----- Riwayat pengembalian untuk baris-baris di halaman ini (modal Detail) -----
$riwayat_per_kasbon = [];
$ids_halaman_ini = array_column($rows, 'id');
if ($ids_halaman_ini) {
    $placeholder = implode(',', array_fill(0, count($ids_halaman_ini), '?'));
    $rw = $conn->prepare("SELECT r.*, u.username AS nama_pencatat FROM kasbon_pengelola_riwayat r
        LEFT JOIN users u ON u.id = r.created_by
        WHERE r.id_kasbon IN ($placeholder) ORDER BY r.tanggal_bayar ASC, r.id ASC");
    $rw->bind_param(str_repeat('i', count($ids_halaman_ini)), ...$ids_halaman_ini);
    $rw->execute();
    $hasil_riwayat = $rw->get_result()->fetch_all(MYSQLI_ASSOC);
    $rw->close();
    foreach ($hasil_riwayat as $r) {
        $riwayat_per_kasbon[(int) $r['id_kasbon']][] = $r;
    }
}

$no = $offset + 1;

include 'sidebar_pusat.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559; }
    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 24px; }
    .title-mark { width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }
    .btn-premium { background-color: #4318ff !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium:hover { background-color: #3310cc !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15); }
    .btn-premium-outline { background-color: #ffffff !important; color: #4318ff !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium-outline:hover { background-color: #e0e7ff !important; }
    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #4318ff !important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important; }

    .btn-action-detail { background-color: #e0e7ff; color: #4338ca; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-detail:hover { background-color: #c7d2fe; }
    .btn-action-pay { background-color: #dcfce7; color: #15803d; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-pay:hover { background-color: #bbf7d0; }
    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-edit:hover { background-color: #ffe8a1; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-delete:hover { background-color: #fbd5d5; }

    /* ===== Hero header (konsisten dengan Revenue Sharing/Rangking Cabang) ===== */
    .kb-hero {
        background: linear-gradient(135deg, #312e81 0%, #4318ff 55%, #7c3aed 100%);
        color: #fff; border-radius: 20px; padding: 26px 28px;
        box-shadow: 0 16px 36px -12px rgba(67, 24, 255, .45);
        position: relative; overflow: hidden;
    }
    .kb-hero::before {
        content: ""; position: absolute; top: -50px; right: -50px;
        width: 200px; height: 200px; border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.16) 0%, transparent 70%);
    }
    .kb-hero .eyebrow { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(255,255,255,.7); font-weight: 700; }
    .kb-hero .title { font-size: 24px; font-weight: 800; letter-spacing: -.5px; margin-top: 4px; }
    .kb-hero .desc { font-size: 12.5px; color: rgba(255,255,255,.78); font-weight: 500; margin-top: 6px; max-width: 560px; }
    .kb-filter-select {
        border-radius: 10px; border: 1px solid rgba(255,255,255,.25);
        padding: 8px 14px; font-size: 13.5px; font-weight: 600; color: #fff;
        background-color: rgba(255,255,255,.12); backdrop-filter: blur(4px);
    }
    .kb-filter-select option { color: #1b2559; background: #fff; }
    .kb-filter-select:focus { border-color: rgba(255,255,255,.6); box-shadow: 0 0 0 3px rgba(255,255,255,.15); outline: none; }

    .stat-card { border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #e0e7ff; height: 100%; position: relative; overflow: hidden; transition: transform .2s ease, box-shadow .2s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -10px rgba(67,24,255,.15); }
    .stat-card .icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; flex-shrink: 0; }
    .stat-card.tone-aktif .icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .stat-card.tone-sisa .icon { background: linear-gradient(135deg, #ef4444, #b91c1c); }
    .stat-card.tone-kembali .icon { background: linear-gradient(135deg, #16a34a, #15803d); }
    .stat-card .value { font-size: 1.5rem; }

    /* ===== Badge sumber & progress bar sisa kasbon ===== */
    .kb-badge-auto { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; border-radius: 20px; padding: 2px 8px; margin-top: 3px; }
    .kb-progress { height: 6px; border-radius: 10px; background: #f1f5f9; overflow: hidden; margin-top: 6px; }
    .kb-progress-bar { height: 100%; border-radius: 10px; background: linear-gradient(90deg, #4318ff, #7c3aed); transition: width .3s ease; }
    .kb-progress-lg { height: 9px; margin-top: 0; }

    /* ===== Modal Detail &amp; Riwayat Pengembalian ===== */
    .kb-detail-modal { border-radius: 18px; overflow: hidden; border: none; }
    .kb-detail-head { background: linear-gradient(135deg, #312e81 0%, #4318ff 60%, #7c3aed 100%); color: #fff; border-bottom: none; padding: 20px 24px; }
    .kb-detail-head .modal-title { color: #fff; }
    .kb-detail-head .text-muted { color: rgba(255,255,255,.75) !important; }
    .kb-detail-head .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    .kb-detail-avatar { width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px; flex-shrink: 0; }

    .kb-summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
    @media (min-width: 576px) { .kb-summary-grid { grid-template-columns: repeat(4, 1fr); } }
    .kb-summary-item { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid #eef2f9; border-radius: 12px; padding: 10px 12px; }
    .kb-summary-icon { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: #fff; flex-shrink: 0; }
    .kb-summary-icon.tone-date { background: linear-gradient(135deg, #64748b, #475569); }
    .kb-summary-icon.tone-total { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
    .kb-summary-icon.tone-kembali { background: linear-gradient(135deg, #16a34a, #15803d); }
    .kb-summary-icon.tone-sisa { background: linear-gradient(135deg, #ef4444, #b91c1c); }
    .kb-summary-label { font-size: 10.5px; color: #8f9bba; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
    .kb-summary-value { font-size: 14px; font-weight: 700; color: #1b2559; }

    .kb-note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 14px; font-size: 13px; color: #1b2559; }

    .kb-riwayat-table thead th { font-size: 10.5px; border-bottom: 2px solid #eef2f9; padding-bottom: 8px; }
    .kb-riwayat-table tbody td { font-size: 13px; padding: 10px 8px; border-bottom: 1px solid #f4f7fe; }
    .kb-riwayat-table tbody tr:last-child td { border-bottom: none; }
    .kb-riwayat-table tbody tr.kb-row-auto { background: #faf9ff; }

    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 16px 12px; border-top: none !important; }
    .table-saas tbody td { padding: 16px 12px; border-bottom: 1px solid #f4f7fe !important; color: #1b2559; font-size: 14px; vertical-align: middle; }
    .table-saas tbody tr:hover { background-color: rgba(244, 247, 254, 0.5); }

    @media (max-width: 767.98px) {
        .table-saas thead { display: none; }
        .table-saas, .table-saas tbody, .table-saas tr, .table-saas td { display: block; width: 100%; }
        .table-saas tr { margin-bottom: 16px; background: #ffffff; border: 1px solid #e0e7ff !important; border-radius: 16px; padding: 12px 16px; box-shadow: 0px 4px 12px rgba(0,0,0,0.02); }
        .table-saas tr:hover { background-color: #ffffff; }
        .table-saas td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0 !important; border-bottom: 1px dashed #eef2f9 !important; text-align: right; }
        .table-saas td:last-child { border-bottom: none !important; }
        .table-saas td::before { content: attr(data-label); font-weight: 700; font-size: 12px; color: #8f9bba; text-transform: uppercase; text-align: left; padding-right: 15px; }
        .table-saas td[data-label="Aksi"] { justify-content: flex-end; margin-top: 8px; flex-wrap: wrap; gap: 6px; }
        /* Sel Sisa Kasbon punya 2 anak (nominal + progress bar) -- defaultnya
           flex-row bikin progress bar-nya kejepit jadi garis tipis di
           samping nominal. Ditumpuk vertikal khusus di sini saja. */
        .table-saas td[data-label="Sisa Kasbon"] { flex-direction: column; align-items: flex-end; gap: 4px; }
        .table-saas td[data-label="Sisa Kasbon"]::before { align-self: flex-start; }
        .table-saas td[data-label="Sisa Kasbon"] .kb-progress { width: 100%; }
        .action-container { flex-direction: column; align-items: stretch !important; }
        .search-form { width: 100% !important; flex-direction: column; }
        .search-input-group { width: 100% !important; }
    }
</style>

<div class="container-fluid py-4">
    <div class="kb-hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3" style="position: relative; z-index: 1;">
            <div>
                <div class="eyebrow">Laporan &bull; Kasbon Pengelola</div>
                <div class="title"><i class="bi bi-cash-coin me-1"></i> Catatan Kasbon Pengelola</div>
                <div class="desc">
                    <i class="bi bi-info-circle me-1"></i>
                    Pinjaman, pengembalian, dan sisa saldo kasbon per pengelola. Kasbon ber-sumber <strong>Dana Pusat</strong> dari Rekapitulasi otomatis muncul &amp; menyesuaikan di sini.
                </div>
            </div>
            <form method="GET" class="d-flex flex-wrap gap-2" style="min-width: 260px;">
                <input type="hidden" name="search" value="<?= h($search) ?>">
                <input type="hidden" name="status" value="<?= h($sel_status) ?>">
                <select name="bulan" class="form-select kb-filter-select" style="flex:1; min-width: 130px;" onchange="this.form.submit()">
                    <option value="">Semua Bulan</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $sel_bulan === $m ? 'selected' : '' ?>><?= nama_bulan_id($m) ?></option>
                    <?php endfor; ?>
                </select>
                <select name="tahun" class="form-select kb-filter-select" style="flex:1; min-width: 100px;" onchange="this.form.submit()">
                    <option value="">Semua Tahun</option>
                    <?php for ($y = (int) date('Y') + 1; $y >= tahun_data_paling_lama($conn); $y--): ?>
                    <option value="<?= $y ?>" <?= $sel_tahun === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card tone-aktif d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="text-muted small">Kasbon Masih Berjalan</div>
                    <div class="fw-bold value"><?= number_format($jml_kasbon_aktif) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card tone-sisa d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="text-muted small">Total Sisa Kasbon Berjalan</div>
                    <div class="fw-bold value">Rp <?= number_format($total_sisa_berjalan, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card tone-kembali d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="text-muted small">Total Sudah Dikembalikan (semua waktu)</div>
                    <div class="fw-bold value">Rp <?= number_format($total_dikembalikan_all, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center action-container gap-3 mb-4">
        <div class="w-100 w-md-auto">
            <button type="button" class="btn btn-premium d-flex align-items-center gap-2 w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle-fill"></i> Catat Kasbon Baru
            </button>
        </div>

        <form method="GET" class="d-flex gap-2 search-form flex-wrap">
            <input type="hidden" name="bulan" value="<?= $sel_bulan ?: '' ?>">
            <input type="hidden" name="tahun" value="<?= $sel_tahun ?: '' ?>">
            <select name="status" class="form-select form-select-premium" style="min-width: 150px;" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="berjalan" <?= $sel_status === 'berjalan' ? 'selected' : '' ?>>Berjalan</option>
                <option value="lunas" <?= $sel_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
            </select>
            <div class="search-input-group flex-grow-1">
                <input type="text" name="search" class="form-control form-control-premium w-100" placeholder="Cari nama pengelola, cabang, keterangan..." value="<?= h($search) ?>">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2 justify-content-center flex-grow-1">
                    <i class="bi bi-search"></i> Cari
                </button>
                <?php if ($search !== '' || $sel_status !== '' || $sel_bulan || $sel_tahun): ?>
                    <a href="kasbon_pengelola" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center" title="Reset Semua Filter">
                        <i class="bi bi-arrow-clockwise"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card saas-card p-0 p-md-3 border-0 bg-transparent bg-md-white shadow-none shadow-md">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">No</th>
                        <th width="18%">Pengelola</th>
                        <th width="10%">Tanggal Kasbon</th>
                        <th width="12%" class="text-end">Jumlah Kasbon</th>
                        <th width="12%" class="text-end">Total Dikembalikan</th>
                        <th width="12%" class="text-end">Sisa Kasbon</th>
                        <th width="10%">Tgl Pengembalian</th>
                        <th width="12%">Keterangan</th>
                        <th width="7%" class="text-center">Status</th>
                        <th width="13%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-5 fw-semibold bg-white rounded-4">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada catatan kasbon
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row):
                        $sisa = max(0, (float) $row['jumlah_kasbon'] - (float) $row['jumlah_dikembalikan']);
                        $pct_kembali = ((float) $row['jumlah_kasbon'] > 0) ? min(100, round((float) $row['jumlah_dikembalikan'] / (float) $row['jumlah_kasbon'] * 100)) : 0;
                    ?>
                        <tr>
                            <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                            <td data-label="Pengelola">
                                <span class="fw-bold d-block" style="color: #1b2559;"><?= h($row['nama_pengelola']) ?></span>
                                <small class="text-primary fw-semibold"><?= h($row['nama_cabang'] ?? 'Tanpa Cabang') ?></small>
                                <?php
                                $ada_potongan_otomatis = false;
                                foreach ($riwayat_per_kasbon[(int) $row['id']] ?? [] as $r_cek) {
                                    if (!empty($r_cek['asal_otomatis'])) { $ada_potongan_otomatis = true; break; }
                                }
                                ?>
                                <?php if ($ada_potongan_otomatis): ?>
                                    <div><span class="kb-badge-auto" title="Pernah menerima potongan pembayaran otomatis dari Rekapitulasi &mdash; lihat Detail"><i class="bi bi-arrow-repeat"></i> Ada potongan dari Rekapitulasi</span></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Tanggal Kasbon"><?= date('d M Y', strtotime($row['tanggal_kasbon'])) ?></td>
                            <td data-label="Jumlah Kasbon" class="text-end fw-semibold">Rp <?= number_format($row['jumlah_kasbon'], 0, ',', '.') ?></td>
                            <td data-label="Total Dikembalikan" class="text-end text-success fw-semibold">Rp <?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?></td>
                            <td data-label="Sisa Kasbon" class="text-end">
                                <div class="fw-bold <?= $sisa > 0 ? 'text-danger' : 'text-muted' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></div>
                                <div class="kb-progress" title="<?= $pct_kembali ?>% sudah dikembalikan"><div class="kb-progress-bar" style="width: <?= $pct_kembali ?>%;"></div></div>
                            </td>
                            <td data-label="Tgl Pengembalian"><?= !empty($row['tanggal_pengembalian']) ? date('d M Y', strtotime($row['tanggal_pengembalian'])) : '-' ?></td>
                            <td data-label="Keterangan"><small><?= $row['keterangan'] !== null && $row['keterangan'] !== '' ? h($row['keterangan']) : '<span class="text-muted fst-italic">-</span>' ?></small></td>
                            <td data-label="Status" class="text-center">
                                <?php if ($row['status'] === 'lunas'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Lunas</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Berjalan</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="text-center">
                                <div class="d-inline-flex gap-2 justify-content-end justify-content-md-center flex-wrap">
                                    <button type="button" class="btn btn-action-detail" data-bs-toggle="modal" data-bs-target="#modalDetail<?= $row['id'] ?>" title="Lihat Riwayat Pengembalian">
                                        <i class="bi bi-clock-history me-1"></i> Detail
                                    </button>
                                    <?php if ($row['status'] !== 'lunas'): ?>
                                    <button type="button" class="btn btn-action-pay" data-bs-toggle="modal" data-bs-target="#modalBayar<?= $row['id'] ?>" title="Catat Pengembalian">
                                        <i class="bi bi-cash-coin me-1"></i> Bayar
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id'] ?>" title="Edit">
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </button>
                                    <form method="POST" class="d-inline" id="form-delete-<?= $row['id'] ?>">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="hapus" value="1">
                                        <button type="button" class="btn btn-action-delete" title="Hapus" onclick="confirmDelete(<?= $row['id'] ?>, <?= json_encode($row['nama_pengelola']) ?>)">
                                            <i class="bi bi-trash-fill me-1"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $total_data ? $offset + 1 : 0, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'catatan kasbon']); ?>
    </div>
</div>

<!-- MODAL TAMBAH KASBON -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Catat Kasbon Baru</h5>
                        <small class="text-muted">Input pinjaman kasbon untuk seorang pengelola</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Pengelola</label>
                            <select name="id_pengelola" class="form-select-premium w-100" required>
                                <option value="">Pilih Pengelola</option>
                                <?php foreach ($pengelola_array as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= h($p['nama_pengelola']) ?> &mdash; <?= h($p['nama_cabang'] ?? 'Tanpa Cabang') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Kasbon</label>
                            <input type="date" name="tanggal_kasbon" value="<?= date('Y-m-d') ?>" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Jumlah Kasbon</label>
                            <input type="text" inputmode="numeric" name="jumlah_kasbon" class="form-control form-control-premium mask-ribuan-titik-kasbon" placeholder="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Keterangan <span class="fw-normal">(opsional)</span></label>
                            <input type="text" name="keterangan" class="form-control form-control-premium" placeholder="Contoh: kasbon servis motor, keperluan keluarga, dll." maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-premium">Simpan Kasbon</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETAIL / BAYAR / EDIT per baris -->
<?php foreach ($rows as $row):
    $sisa = max(0, (float) $row['jumlah_kasbon'] - (float) $row['jumlah_dikembalikan']);
    $riwayat_baris = $riwayat_per_kasbon[(int) $row['id']] ?? [];
?>
<div class="modal fade" id="modalDetail<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content kb-detail-modal">
            <div class="modal-header kb-detail-head">
                <div class="d-flex align-items-center gap-3">
                    <div class="kb-detail-avatar"><?= h(mb_strtoupper(mb_substr($row['nama_pengelola'], 0, 1))) ?></div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0"><?= h($row['nama_pengelola']) ?></h5>
                        <small class="text-muted"><i class="bi bi-shop me-1"></i><?= h($row['nama_cabang'] ?? 'Tanpa Cabang') ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php
                $pct_modal = ((float) $row['jumlah_kasbon'] > 0) ? min(100, round((float) $row['jumlah_dikembalikan'] / (float) $row['jumlah_kasbon'] * 100)) : 0;
                ?>
                <div class="kb-summary-grid mb-3">
                    <div class="kb-summary-item">
                        <div class="kb-summary-icon tone-date"><i class="bi bi-calendar-event"></i></div>
                        <div>
                            <div class="kb-summary-label">Tanggal Kasbon</div>
                            <div class="kb-summary-value"><?= date('d M Y', strtotime($row['tanggal_kasbon'])) ?></div>
                        </div>
                    </div>
                    <div class="kb-summary-item">
                        <div class="kb-summary-icon tone-total"><i class="bi bi-cash-stack"></i></div>
                        <div>
                            <div class="kb-summary-label">Jumlah Kasbon</div>
                            <div class="kb-summary-value">Rp <?= number_format($row['jumlah_kasbon'], 0, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="kb-summary-item">
                        <div class="kb-summary-icon tone-kembali"><i class="bi bi-check2-circle"></i></div>
                        <div>
                            <div class="kb-summary-label">Sudah Dikembalikan</div>
                            <div class="kb-summary-value text-success">Rp <?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="kb-summary-item">
                        <div class="kb-summary-icon tone-sisa"><i class="bi bi-wallet2"></i></div>
                        <div>
                            <div class="kb-summary-label">Sisa Kasbon</div>
                            <div class="kb-summary-value <?= $sisa > 0 ? 'text-danger' : 'text-muted' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 11.5px;">
                    <span class="text-muted fw-semibold">Progres Pengembalian</span>
                    <span class="fw-bold" style="color: #4318ff;"><?= $pct_modal ?>%</span>
                </div>
                <div class="kb-progress kb-progress-lg mb-3"><div class="kb-progress-bar" style="width: <?= $pct_modal ?>%;"></div></div>

                <?php if (!empty($row['keterangan'])): ?>
                <div class="kb-note mb-3">
                    <i class="bi bi-sticky text-muted me-1"></i>
                    <span class="text-muted small">Keterangan:</span> <?= h($row['keterangan']) ?>
                </div>
                <?php endif; ?>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="fw-bold" style="color: #1b2559;"><i class="bi bi-clock-history me-1"></i> Riwayat Pengembalian</div>
                    <?php if (!empty($riwayat_baris)): ?>
                        <span class="badge rounded-pill bg-light text-muted border"><?= count($riwayat_baris) ?> transaksi</span>
                    <?php endif; ?>
                </div>
                <?php if (empty($riwayat_baris)): ?>
                    <div class="text-center text-muted py-4 bg-light rounded-3">
                        <i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i> Belum ada pengembalian yang tercatat
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle kb-riwayat-table mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Tanggal</th>
                                    <th class="text-end">Jumlah Bayar</th>
                                    <th class="text-end">Sisa Setelahnya</th>
                                    <th>Keterangan</th>
                                    <th>Dicatat Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sisa_berjalan = (float) $row['jumlah_kasbon'];
                                foreach ($riwayat_baris as $r):
                                    $sisa_berjalan = max(0, $sisa_berjalan - (float) $r['jumlah_bayar']);
                                ?>
                                <tr class="<?= !empty($r['asal_otomatis']) ? 'kb-row-auto' : '' ?>">
                                    <td><?= date('d M Y', strtotime($r['tanggal_bayar'])) ?></td>
                                    <td class="text-end fw-semibold text-success">+Rp <?= number_format($r['jumlah_bayar'], 0, ',', '.') ?></td>
                                    <td class="text-end text-muted">Rp <?= number_format($sisa_berjalan, 0, ',', '.') ?></td>
                                    <td>
                                        <?php if (!empty($r['asal_otomatis'])): ?>
                                            <span class="kb-badge-auto" title="<?= h($r['keterangan'] ?? '') ?>"><i class="bi bi-arrow-repeat"></i> Rekapitulasi &mdash; <?= h(nama_bulan_id((int) $r['bulan_periode'])) ?> <?= (int) $r['tahun_periode'] ?></span>
                                        <?php else: ?>
                                            <small><?= h($r['keterangan'] ?? '-') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= h($r['nama_pencatat'] ?? '-') ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="modalBayar<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Catat Pengembalian</h5>
                        <small class="text-muted"><?= h($row['nama_pengelola']) ?> &mdash; sisa kasbon saat ini: <strong>Rp <?= number_format($sisa, 0, ',', '.') ?></strong></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Jumlah Pengembalian</label>
                            <input type="text" inputmode="numeric" name="jumlah_pengembalian" class="form-control form-control-premium mask-ribuan-titik-kasbon" placeholder="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Pengembalian</label>
                            <input type="date" name="tanggal_pengembalian" value="<?= date('Y-m-d') ?>" class="form-control form-control-premium" required>
                        </div>
                    </div>
                    <div class="form-text mt-2">Jumlah ini akan <strong>ditambahkan</strong> ke total yang sudah dikembalikan sebelumnya (Rp <?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?>). Kalau totalnya sudah mencapai jumlah kasbon, status otomatis berubah jadi Lunas.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="catat_pengembalian" class="btn btn-premium">Simpan Pengembalian</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT -->
<div class="modal fade" id="modalEdit<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Edit Catatan Kasbon</h5>
                        <small class="text-muted">Koreksi data kasbon &mdash; termasuk total dikembalikan kalau perlu diperbaiki</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Pengelola</label>
                            <select name="id_pengelola" class="form-select-premium w-100" required>
                                <?php foreach ($pengelola_array as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $row['id_pengelola'] ? 'selected' : '' ?>><?= h($p['nama_pengelola']) ?> &mdash; <?= h($p['nama_cabang'] ?? 'Tanpa Cabang') ?></option>
                                <?php endforeach; ?>
                                <?php if (!in_array($row['id_pengelola'], array_column($pengelola_array, 'id'))): ?>
                                    <option value="<?= $row['id_pengelola'] ?>" selected><?= h($row['nama_pengelola']) ?> (nonaktif)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Kasbon</label>
                            <input type="date" name="tanggal_kasbon" value="<?= h($row['tanggal_kasbon']) ?>" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Jumlah Kasbon</label>
                            <input type="text" inputmode="numeric" name="jumlah_kasbon" value="<?= number_format($row['jumlah_kasbon'], 0, ',', '.') ?>" class="form-control form-control-premium mask-ribuan-titik-kasbon" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Total Sudah Dikembalikan</label>
                            <input type="text" inputmode="numeric" name="jumlah_dikembalikan" value="<?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?>" class="form-control form-control-premium mask-ribuan-titik-kasbon">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Pengembalian Terakhir</label>
                            <input type="date" name="tanggal_pengembalian" value="<?= h($row['tanggal_pengembalian'] ?? '') ?>" class="form-control form-control-premium">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Keterangan</label>
                            <input type="text" name="keterangan" value="<?= h($row['keterangan'] ?? '') ?>" class="form-control form-control-premium" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit" class="btn btn-premium">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Pemisah ribuan "." saat mengetik nominal (gaya sama dengan Rekapitulasi).
    function formatRibuanTitikKasbon(str) {
        if (str === '' || str === null || str === undefined) return '';
        const bersih = str.toString().replace(/[^0-9]/g, '');
        if (bersih === '') return '';
        return parseInt(bersih, 10).toLocaleString('id-ID');
    }
    document.querySelectorAll('.mask-ribuan-titik-kasbon').forEach(function (el) {
        el.addEventListener('input', function () {
            let cursorPosition = this.selectionStart;
            let oldLength = this.value.length;
            this.value = formatRibuanTitikKasbon(this.value);
            let newLength = this.value.length;
            cursorPosition += (newLength - oldLength);
            this.setSelectionRange(cursorPosition, cursorPosition);
        });
    });
    // Form di-submit sebagai teks berpemisah titik -- server membersihkannya
    // sendiri (str_replace '.'/',' sebelum cast ke float), jadi tidak perlu
    // dibersihkan lagi di sisi klien sebelum submit.

    function confirmDelete(id, nama) {
        Swal.fire({
            title: 'Hapus Catatan Kasbon?',
            text: "Apakah Anda yakin ingin menghapus catatan kasbon " + nama + "? Data yang dihapus tidak dapat dikembalikan.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            customClass: { popup: 'rounded-4' }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('form-delete-' + id).submit();
            }
        });
    }

    <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: <?= json_encode($_SESSION['success']) ?>,
            timer: 2500,
            showConfirmButton: false,
            customClass: { popup: 'rounded-4' }
        });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Gagal!',
            text: <?= json_encode($_SESSION['error']) ?>,
            customClass: { popup: 'rounded-4' }
        });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
</script>

<?php include '../config/notifikasi_bell.php'; ?>
