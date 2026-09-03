<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids = pic_cabang_ids($conn, $id_user);

$id_cabang = (int) ($_GET['id_cabang'] ?? $_POST['id_cabang'] ?? 0);
$tanggal   = $_GET['tanggal'] ?? $_POST['tanggal'] ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d', strtotime('-1 day'));
}

// Wajib: cabang ini benar-benar salah satu yang dipegang PIC ini.
if (!in_array($id_cabang, $cabang_ids, true)) {
    echo "<script>alert('Anda tidak berwenang atas cabang ini.'); window.location='index';</script>";
    exit;
}

// Identitas cabang + pengelola PADA TANGGAL laporan ini (bukan yang aktif
// sekarang) — supaya kalau ada rotasi pengelola, laporan lama tetap
// tercatat atas nama pengelola yang menjabat waktu itu.
$stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang = ?");
$stmt->bind_param("i", $id_cabang);
$stmt->execute();
$data_cabang = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nama_cabang = $data_cabang['nama_cabang'] ?? '-';
$nama_pengelola = pengelola_pada_tanggal($conn, $id_cabang, $tanggal);

// Cabang ini sudah ditandai libur/tutup untuk tanggal ini? Tidak ada laporan
// keuangan yang perlu (atau boleh) diinput.
$stmt = $conn->prepare("SELECT status_laporan FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
$stmt->bind_param("is", $id_cabang, $tanggal);
$stmt->execute();
$status_existing = $stmt->get_result()->fetch_assoc()['status_laporan'] ?? null;
$stmt->close();
$is_libur = ($status_existing === 'libur');

// =====================================================
// PROSES SIMPAN
// =====================================================
if (isset($_POST['simpan'])) {

    if ($is_libur) {
        echo "<script>alert('Cabang ini ditandai Libur/Tutup pada tanggal ini. Tidak bisa input laporan keuangan.'); window.location='index?tanggal=" . $tanggal . "';</script>";
        exit;
    }

    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }

    $ket = $_POST['keterangan'] ?? '';

    $h = hitung_laporan_harian($_POST);
    [
        'tunai' => $tunai, 'qris' => $qris, 'grab_food' => $grab, 'go_food' => $go,
        'pencairan_qris' => $pencairan_qris, 'total_omset' => $total_omset,
        'belanja_pasar' => $pasar, 'belanja_sembako' => $sembako,
        'belanja_beras' => $beras, 'belanja_toko' => $toko, 'total_rutin' => $total_rutin,
        'sewa' => $sewa, 'gaji' => $gaji, 'listrik' => $listrik, 'air' => $air,
        'sampah' => $sampah, 'keamanan' => $keamanan, 'internet' => $internet, 'gas' => $gas,
        'mingguan_karyawan' => $mingguan_karyawan, 'es_batu' => $es_batu, 'bensin' => $bensin, 'lain_lain' => $lain,
        'total_operasional' => $total_op, 'total_pengeluaran' => $total_pengeluaran,
        'sisa_tunai' => $sisa_tunai, 'sisa_qris' => $sisa_qris,
        'net_profit' => $net, 'persentase' => $persen,
    ] = $h;

    $sql = "
        INSERT INTO laporan_cabang (
            id_cabang, nama_pengelola, tanggal,
            tunai, qris, grab_food, go_food, pencairan_qris, total_omset,
            belanja_pasar, belanja_sembako, belanja_beras, belanja_toko, total_rutin,
            sewa, gaji, listrik, air, sampah, keamanan, internet, gas, mingguan_karyawan, es_batu, bensin, lain_lain,
            total_operasional, total_pengeluaran, sisa_tunai, sisa_qris, net_profit, persentase,
            keterangan, id_user_laporan, status_laporan
        ) VALUES (
            ?,?,?, ?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?, 'lengkap'
        )
        ON DUPLICATE KEY UPDATE
            nama_pengelola = VALUES(nama_pengelola),
            tunai = VALUES(tunai), qris = VALUES(qris), grab_food = VALUES(grab_food), go_food = VALUES(go_food),
            pencairan_qris = VALUES(pencairan_qris), total_omset = VALUES(total_omset),
            belanja_pasar = VALUES(belanja_pasar), belanja_sembako = VALUES(belanja_sembako),
            belanja_beras = VALUES(belanja_beras), belanja_toko = VALUES(belanja_toko), total_rutin = VALUES(total_rutin),
            sewa = VALUES(sewa), gaji = VALUES(gaji), listrik = VALUES(listrik), air = VALUES(air),
            sampah = VALUES(sampah), keamanan = VALUES(keamanan), internet = VALUES(internet), gas = VALUES(gas),
            mingguan_karyawan = VALUES(mingguan_karyawan), es_batu = VALUES(es_batu), bensin = VALUES(bensin), lain_lain = VALUES(lain_lain),
            total_operasional = VALUES(total_operasional), total_pengeluaran = VALUES(total_pengeluaran),
            sisa_tunai = VALUES(sisa_tunai), sisa_qris = VALUES(sisa_qris), net_profit = VALUES(net_profit), persentase = VALUES(persentase),
            keterangan = VALUES(keterangan), id_user_laporan = VALUES(id_user_laporan), status_laporan = 'lengkap'
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare gagal: " . $conn->error);
    }
    $stmt->bind_param(
        "iss" . str_repeat("d", 29) . "si",
        $id_cabang, $nama_pengelola, $tanggal,
        $tunai, $qris, $grab, $go, $pencairan_qris, $total_omset,
        $pasar, $sembako, $beras, $toko, $total_rutin,
        $sewa, $gaji, $listrik, $air, $sampah, $keamanan, $internet, $gas, $mingguan_karyawan, $es_batu, $bensin, $lain,
        $total_op, $total_pengeluaran, $sisa_tunai, $sisa_qris, $net, $persen,
        $ket, $id_user
    );

    if ($stmt->execute()) {
        audit($conn, 'laporan_pic_simpan', 'laporan_cabang', $id_cabang . '@' . $tanggal, [
            'id_cabang' => $id_cabang, 'tanggal' => $tanggal, 'total_omset' => $total_omset, 'net_profit' => $net,
        ]);

        kirim_notifikasi($conn, semua_user_pusat($conn), 'laporan_diinput',
            'Laporan Diinput: ' . $nama_cabang,
            'PIC ' . current_username() . ' menginput laporan cabang ' . $nama_cabang . ' tanggal ' . date('d M Y', strtotime($tanggal)) . '.',
            'laporan.php?id_cabang=' . $id_cabang . '&tgl_awal=' . $tanggal . '&tgl_akhir=' . $tanggal
        );

        echo "<script>
                alert('Laporan berhasil disimpan.');
                window.location.replace('index?tanggal=" . $tanggal . "');
              </script>";
        exit;
    } else {
        echo "<script>alert('Gagal simpan: " . addslashes($stmt->error) . "'); history.back();</script>";
        exit;
    }
}

// Data existing untuk prefill (kalau sudah pernah diisi) + referensi foto nota.
$stmt = $conn->prepare("SELECT * FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
$stmt->bind_param("is", $id_cabang, $tanggal);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// Kolom kosong (belum pernah diisi) -> field kosong, BUKAN "0" — supaya PIC
// tidak salah ketik jadi kelebihan nol (mis. ngetik di belakang "0" yang sudah
// ada). Baris yang sudah pernah disimpan tetap tampil apa adanya, termasuk
// kalau memang benar 0.
function val($row, $key) {
    return isset($row[$key]) ? (int) $row[$key] : '';
}
?>

<style>
body { background-color: #f6f8fa; }
.card-custom { background: #ffffff; border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); margin-bottom: 24px; overflow: hidden; }
.card-custom-header { padding: 16px 24px; font-weight: 600; font-size: 1.05rem; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px; }
.form-label { font-weight: 500; font-size: 0.72rem; color: #4A5568; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.input-group-text-custom { background-color: #EDF2F7; border-color: #E2E8F0; color: #4A5568; font-weight: 600; font-size: 0.75rem; padding: 6px 8px; }
.form-control-custom { border-color: #E2E8F0; font-size: 0.8rem; padding: 6px 8px; border-radius: 6px; }
.form-control-custom:focus { border-color: #4A5568; box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.1); }
input.form-control-custom { text-align: right; }
.bg-header-success { background: #E6FFFA; color: #00875A; }
.bg-header-warning { background: #FEF3C7; color: #B45309; }
.bg-header-info { background: #EBF8FF; color: #2B6CB0; }
.bg-header-secondary { background: #F7FAFC; color: #4A5568; }
.badge-info-cabang { background: linear-gradient(135deg, #1e3a5f 0%, #12233a 100%); border-radius: 12px; padding: 16px 20px; color: #ffffff; box-shadow: 0 4px 15px rgba(18, 35, 58, 0.1); }
.nota-scroll-area { max-height: calc(100vh - 220px); min-height: 300px; overflow-y: auto; padding-right: 4px; }
.nota-thumb-wrap { position: relative; }
.nota-thumb { width: 100%; height: auto; max-height: 65vh; object-fit: contain; background: #f1f5f9; border-radius: 10px; border: 1px solid #E2E8F0; cursor: zoom-in; transition: box-shadow .15s ease; display: block; }
.nota-thumb:hover { box-shadow: 0 6px 18px rgba(15,23,42,.12); }
.nota-dl-btn { position: absolute; bottom: 6px; right: 6px; width: 30px; height: 30px; border-radius: 50%; background: rgba(15,23,42,.65); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .9rem; text-decoration: none; backdrop-filter: blur(2px); transition: background .15s ease, transform .15s ease; }
.nota-dl-btn:hover { background: #1e3a5f; color: #fff; transform: scale(1.08); }
#picZoomOverlay { position: fixed; inset: 0; z-index: 3000; background: rgba(2,6,23,.93); display: none; align-items: center; justify-content: center; padding: 16px; cursor: zoom-out; }
#picZoomOverlay.show { display: flex; }
#picZoomOverlay img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; }
#picZoomOverlay .pic-zoom-x { position: absolute; top: 12px; right: 18px; color: #fff; font-size: 2.2rem; line-height: 1; background: none; border: 0; cursor: pointer; }
#picZoomOverlay .pic-zoom-dl { position: absolute; top: 16px; right: 70px; color: #fff; font-size: 1.4rem; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); border-radius: 10px; padding: 8px 16px; text-decoration: none; display: flex; align-items: center; gap: 8px; font-size: .95rem; font-weight: 600; cursor: pointer; }
#picZoomOverlay .pic-zoom-dl:hover { background: rgba(255,255,255,.22); color: #fff; }
@media (min-width: 992px) { .pic-nota-sticky { position: sticky; top: 20px; } }
@media (max-width: 576px) { .card-custom-header { padding: 12px 16px; } .p-mobile-custom { padding: 16px!important; } }
</style>

<div class="container-fluid py-3 px-md-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0 fw-bold text-dark"><i class="bi bi-clipboard-plus text-primary me-2"></i> Input Laporan Harian</h3>
        <a href="index?tanggal=<?= h($tanggal) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <div class="badge-info-cabang d-flex align-items-center mb-4">
        <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
            <i class="bi bi-shop fs-4 text-dark"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold"><?= h($nama_cabang) ?></h5>
            <small class="opacity-75">Pengelola: <strong><?= h($nama_pengelola) ?></strong> &middot; Tanggal: <strong><?= date('d M Y', strtotime($tanggal)) ?></strong></small>
        </div>
    </div>

    <?php if ($is_libur): ?>
    <div class="alert alert-secondary d-flex align-items-center rounded-4 py-4">
        <i class="bi bi-moon-stars-fill fs-2 me-3"></i>
        <div>
            <strong>Cabang ini ditandai Libur / Tutup pada tanggal <?= date('d M Y', strtotime($tanggal)) ?>.</strong><br>
            Tidak ada laporan keuangan yang perlu diinput untuk hari ini.
        </div>
    </div>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="pic-nota-sticky">
                <?php if (empty($row) || (!$row['foto_nota1'] && !$row['foto_nota2'] && !$row['foto_nota3'] && !$row['foto_nota4'])): ?>
                    <div class="alert alert-warning rounded-4 mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i> Cabang belum mengirim foto nota untuk tanggal ini. Anda tetap bisa mengisi laporan bila datanya sudah didapat secara manual.</div>
                <?php else: ?>
                    <div class="card card-custom mb-4">
                        <div class="card-custom-header bg-header-secondary"><i class="bi bi-camera fs-5"></i> Foto Nota dari Cabang <span class="ms-2 small fw-normal text-muted">ketuk untuk perbesar penuh layar</span></div>
                        <div class="card-body p-4 p-mobile-custom">
                            <div class="nota-scroll-area">
                                <div class="row g-3">
                                    <?php for ($i = 1; $i <= 4; $i++): if (!empty($row["foto_nota$i"])): ?>
                                        <div class="col-12">
                                            <div class="nota-thumb-wrap">
                                                <img src="../uploads/nota/<?= h($row["foto_nota$i"]) ?>" class="nota-thumb" alt="Nota <?= $i ?>"
                                                     onclick="picZoom('../uploads/nota/<?= h(rawurlencode($row["foto_nota$i"])) ?>')">
                                                <a href="../uploads/nota/<?= h($row["foto_nota$i"]) ?>" download class="nota-dl-btn" title="Unduh Nota <?= $i ?>" onclick="event.stopPropagation()">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; endfor; ?>
                                </div>
                                <?php if (!empty($row['keterangan_nota'])): ?>
                                    <div class="mt-3 small text-muted"><i class="bi bi-chat-left-text me-1"></i> Catatan cabang: <?= h($row['keterangan_nota']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
    <form method="POST" onsubmit="prepareSubmitForm()">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id_cabang" value="<?= (int) $id_cabang ?>">
        <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">

        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-success"><i class="bi bi-cash-stack fs-5"></i> 1. Pendapatan / Omzet Cabang</div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-2">
                    <?php
                    $pendapatan_items = ['tunai' => 'Tunai', 'qris' => 'QRIS', 'grab_food' => 'Grab Food', 'go_food' => 'Go Food'];
                    foreach ($pendapatan_items as $k => $v):
                    ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= $v ?></label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-custom">Rp</span>
                                <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung mask-money" value="<?= val($row, $k) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="col-6 col-md-3">
                        <label class="form-label fw-bold">Pencairan QRIS</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-warning text-dark border-warning">Rp</span>
                            <input type="text" id="pencairan_qris" name="pencairan_qris" class="form-control form-control-custom hitung mask-money" value="<?= val($row, 'pencairan_qris') ?>">
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label fw-bold text-success">Total Pendapatan/Omzet</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-success text-white border-success">Rp</span>
                            <input type="text" id="total_omset" class="form-control form-control-custom bg-light fw-bold text-success border-success" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-warning"><i class="bi bi-basket fs-5"></i> 2. Pengeluaran Belanja Rutin</div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-2">
                    <?php
                    $rutin_items = ['belanja_pasar' => 'Pasar', 'belanja_sembako' => 'Sembako', 'belanja_beras' => 'Beras', 'belanja_toko' => 'Toko'];
                    foreach ($rutin_items as $k => $v):
                    ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= $v ?></label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-custom">Rp</span>
                                <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung_rutin mask-money" value="<?= val($row, $k) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-bold text-warning">Total Belanja Rutin</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-warning text-dark border-warning">Rp</span>
                            <input type="text" id="total_rutin" class="form-control form-control-custom bg-light fw-bold text-warning border-warning" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-info"><i class="bi bi-receipt fs-5"></i> 3. Beban Biaya Operasional</div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-2">
                    <?php
                    $operasional_items = [
                        'sewa' => 'Sewa Ruko', 'gaji' => 'Gaji Karyawan', 'listrik' => 'Listrik', 'air' => 'Air PAM',
                        'sampah' => 'Sampah', 'keamanan' => 'Keamanan', 'internet' => 'Internet', 'gas' => 'Gas',
                        'mingguan_karyawan' => 'Mingguan Karyawan', 'es_batu' => 'Es Batu - Air Galon', 'bensin' => 'Bensin', 'lain_lain' => 'Lain - Lain',
                    ];
                    foreach ($operasional_items as $k => $v):
                    ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= $v ?></label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-custom">Rp</span>
                                <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung_op mask-money" value="<?= val($row, $k) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-bold text-primary">Total Operasional</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-primary text-white border-primary">Rp</span>
                            <input type="text" id="total_op" class="form-control form-control-custom bg-light fw-bold text-primary border-primary" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom mb-4">
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-2 mb-4">
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Total Pengeluaran</label>
                        <div class="input-group"><span class="input-group-text input-group-text-custom bg-dark text-white">Rp</span>
                            <input type="text" id="total_pengeluaran" class="form-control form-control-custom bg-light fw-bold text-dark" readonly></div>
                    </div>
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Sisa Uang Tunai</label>
                        <div class="input-group"><span class="input-group-text input-group-text-custom bg-info text-white border-info">Rp</span>
                            <input type="text" id="sisa_tunai" class="form-control form-control-custom bg-light text-primary fw-bold" readonly></div>
                    </div>
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Sisa QRIS</label>
                        <div class="input-group"><span class="input-group-text input-group-text-custom bg-warning text-dark border-warning">Rp</span>
                            <input type="text" id="sisa_qris" class="form-control form-control-custom bg-light text-warning fw-bold" readonly></div>
                    </div>
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Net Profit Bersih</label>
                        <div class="input-group"><span class="input-group-text input-group-text-custom bg-success text-white border-success">Rp</span>
                            <input type="text" id="net_profit" class="form-control form-control-custom bg-light text-success fw-bold fs-6" readonly></div>
                    </div>
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Margin Keuntungan</label>
                        <div class="input-group"><input type="text" id="persentase" class="form-control form-control-custom bg-light text-center fw-bold fs-6" readonly>
                            <span class="input-group-text input-group-text-custom">%</span></div>
                    </div>
                </div>

                <div>
                    <label class="form-label"><i class="bi bi-chat-left-text me-1"></i> Catatan / Keterangan Tambahan</label>
                    <textarea name="keterangan" class="form-control form-control-custom" rows="3" placeholder="Tulis rincian tambahan jika ada pengeluaran tidak terduga..."><?= h($row['keterangan'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <button type="submit" name="simpan" class="btn btn-primary btn-lg w-100 py-3 shadow-sm fw-bold rounded-3">
                <i class="bi bi-send-check-fill me-2"></i> Simpan Laporan
            </button>
        </div>
    </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function formatRupiahMask(angka) {
    // String kosong (field belum diisi) -> tetap kosong, jangan dipaksa jadi "0".
    // Angka 0 asli (mis. total hasil hitung yang memang nol) tetap tampil "0".
    if (angka === '' || angka === null || angka === undefined) return '';
    let cleanNumber = angka.toString().replace(/[^0-9]/g, '');
    if (cleanNumber === '') return '';
    return parseInt(cleanNumber, 10).toLocaleString('en-US');
}
function formatRupiahWithMinus(angka) {
    angka = Number(angka) || 0;
    if (angka < 0) return '-' + Math.abs(angka).toLocaleString('en-US');
    return angka.toLocaleString('en-US');
}
function getPureNumber(selectorOrEl) {
    let el = typeof selectorOrEl === 'string' ? document.querySelector(selectorOrEl) : selectorOrEl;
    if (!el) return 0;
    let cleanVal = el.value.replace(/,/g, '');
    return parseInt(cleanVal, 10) || 0;
}

function hitung() {
    let tunai = getPureNumber('[name="tunai"]');
    let qris = getPureNumber('[name="qris"]');
    let grab = getPureNumber('[name="grab_food"]');
    let go = getPureNumber('[name="go_food"]');
    let pencairan_qris = getPureNumber('#pencairan_qris');

    let omset = (tunai + qris + grab + go) - pencairan_qris;
    document.getElementById('total_omset').value = formatRupiahWithMinus(omset);

    let rutin = Array.from(document.querySelectorAll('.hitung_rutin')).reduce((sum, el) => sum + getPureNumber(el), 0);
    document.getElementById('total_rutin').value = formatRupiahMask(rutin);

    let op = Array.from(document.querySelectorAll('.hitung_op')).reduce((sum, el) => sum + getPureNumber(el), 0);
    document.getElementById('total_op').value = formatRupiahMask(op);

    let total_pengeluaran = rutin + op;
    document.getElementById('total_pengeluaran').value = formatRupiahMask(total_pengeluaran);

    let sisa_tunai = tunai - total_pengeluaran;
    document.getElementById('sisa_tunai').value = formatRupiahWithMinus(sisa_tunai);

    let sisa_qris = qris - pencairan_qris;
    document.getElementById('sisa_qris').value = formatRupiahWithMinus(sisa_qris);

    let net = sisa_tunai + sisa_qris + go + grab;
    document.getElementById('net_profit').value = formatRupiahWithMinus(net);

    let persen = omset > 0 ? ((net / omset) * 100).toFixed(2) : 0;
    document.getElementById('persentase').value = persen;
}

document.querySelectorAll('.mask-money').forEach(el => {
    el.addEventListener('input', function () {
        let cursorPosition = this.selectionStart;
        let oldLength = this.value.length;
        this.value = formatRupiahMask(this.value);
        let newLength = this.value.length;
        cursorPosition += (newLength - oldLength);
        this.setSelectionRange(cursorPosition, cursorPosition);
        hitung();
    });
});

function prepareSubmitForm() {
    document.querySelectorAll('.mask-money').forEach(el => { el.value = el.value.replace(/,/g, ''); });
}

window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mask-money').forEach(el => { el.value = formatRupiahMask(el.value); });
    hitung();
});

function picZoom(src) {
    document.getElementById('picZoomImg').src = src;
    document.getElementById('picZoomDownload').href = src;
    document.getElementById('picZoomOverlay').classList.add('show');
}
function picZoomClose() {
    document.getElementById('picZoomOverlay').classList.remove('show');
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') picZoomClose(); });
</script>

<div id="picZoomOverlay" onclick="picZoomClose()">
    <a id="picZoomDownload" href="" download class="pic-zoom-dl" title="Unduh foto ini" onclick="event.stopPropagation()">
        <i class="bi bi-download"></i> Unduh
    </a>
    <button type="button" class="pic-zoom-x" onclick="picZoomClose()">&times;</button>
    <img id="picZoomImg" src="" alt="Nota">
</div>
