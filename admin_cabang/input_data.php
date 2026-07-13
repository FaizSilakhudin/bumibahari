<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_cabang = $_SESSION['id_cabang'];
$nama_pengelola = $_SESSION['nama_pengelola'];
$cabang = mysqli_fetch_assoc(mysqli_query($conn, "SELECT nama_cabang FROM cabang WHERE id_cabang=$id_cabang"))['nama_cabang'];

if(isset($_POST['simpan'])){
    $tgl = $_POST['tanggal'];
    
    // BACKEND CLEANING: Membersihkan format koma/titik ribuan dari input text sebelum masuk ke kalkulasi PHP & Database
    function cleanNumber($val) {
        return (int)preg_replace('/[^0-9]/', '', $val);
    }

    $tunai = cleanNumber($_POST['tunai']); 
    $qris = cleanNumber($_POST['qris']); 
    $grab = cleanNumber($_POST['grab_food']); 
    $go = cleanNumber($_POST['go_food']);
    $total_omset = $tunai + $qris + $grab + $go;
    
    $pasar = cleanNumber($_POST['belanja_pasar']); 
    $sembako = cleanNumber($_POST['belanja_sembako']); 
    $beras = cleanNumber($_POST['belanja_beras']); 
    $toko = cleanNumber($_POST['belanja_toko']);
    $total_rutin = $pasar + $sembako + $beras + $toko;
    
    $sewa = cleanNumber($_POST['sewa']); 
    $gaji = cleanNumber($_POST['gaji']); 
    $listrik = cleanNumber($_POST['listrik']); 
    $air = cleanNumber($_POST['air']); 
    $sampah = cleanNumber($_POST['sampah']); 
    $keamanan = cleanNumber($_POST['keamanan']); 
    $internet = cleanNumber($_POST['internet']); 
    $lain = cleanNumber($_POST['lain_lain']);
    $total_op = $sewa + $gaji + $listrik + $air + $sampah + $keamanan + $internet + $lain;
    
    $total_pengeluaran = $total_rutin + $total_op;
    $sisa = $tunai - $total_pengeluaran;
    $net = $total_omset - $total_pengeluaran;
    $persen = $total_omset > 0 ? ($net / $total_omset) * 100 : 0;
    $ket = mysqli_real_escape_string($conn, $_POST['keterangan']);

    $foto = [];
    for($i=1; $i<=4; $i++){
        if(!empty($_FILES["foto_nota$i"]['name'])){
            $ext = pathinfo($_FILES["foto_nota$i"]['name'], PATHINFO_EXTENSION);
            $nama_file = time()."_$i.".$ext;
            move_uploaded_file($_FILES["foto_nota$i"]['tmp_name'], "../uploads/nota/$nama_file");
            $foto[$i] = $nama_file;
        } else $foto[$i] = '';
    }

    mysqli_query($conn, "INSERT INTO laporan_cabang (id_cabang,nama_pengelola,tanggal,tunai,qris,grab_food,go_food,total_omset,belanja_pasar,belanja_sembako,belanja_beras,belanja_toko,total_rutin,sewa,gaji,listrik,air,sampah,keamanan,internet,lain_lain,total_operasional,total_pengeluaran,sisa_tunai,net_profit,persentase,keterangan,foto_nota1,foto_nota2,foto_nota3,foto_nota4) VALUES ($id_cabang,'$nama_pengelola','$tgl',$tunai,$qris,$grab,$go,$total_omset,$pasar,$sembako,$beras,$toko,$total_rutin,$sewa,$gaji,$listrik,$air,$sampah,$keamanan,$internet,$lain,$total_op,$total_pengeluaran,$sisa,$net,$persen,'$ket','$foto[1]','$foto[2]','$foto[3]','$foto[4]')");
    echo "<script>alert('Data berhasil disimpan!'); window.location='input_data.php';</script>";
}
?>

<style>
    body { background-color: #f6f8fa; }
    .card-custom {
        background: #ffffff;
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        margin-bottom: 24px;
        overflow: hidden;
    }
    .card-custom-header {
        padding: 16px 24px;
        font-weight: 600;
        font-size: 1.05rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-label {
        font-weight: 500;
        font-size: 0.85rem;
        color: #4A5568;
        margin-bottom: 6px;
    }
    .input-group-text-custom {
        background-color: #EDF2F7;
        border-color: #E2E8F0;
        color: #4A5568;
        font-weight: 600;
        font-size: 0.9rem;
    }
    .form-control-custom {
        border-color: #E2E8F0;
        font-size: 0.95rem;
        padding: 10px 14px;
        border-radius: 8px;
    }
    .form-control-custom:focus {
        border-color: #4A5568;
        box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.1);
    }
    .bg-header-success { background: #E6FFFA; color: #00875A; }
    .bg-header-warning { background: #FEF3C7; color: #B45309; }
    .bg-header-info { background: #EBF8FF; color: #2B6CB0; }
    .bg-header-danger { background: #FFF5F5; color: #C53030; }
    .bg-header-secondary { background: #F7FAFC; color: #4A5568; }
    
    /* Responsive Badge Info */
    .badge-info-cabang {
        background: linear-gradient(135deg, #2D3748 0%, #1A202C 100%);
        border-radius: 12px;
        padding: 16px 20px;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(26, 32, 44, 0.1);
    }
    
    /* Preview Grid for Form inputs on mobile */
    @media (max-width: 576px) {
        .card-custom-header { padding: 12px 16px; }
        .p-mobile-custom { padding: 16px !important; }
    }
</style>

<div class="container-fluid py-3 px-md-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0 fw-bold text-dark"><i class="bi bi-clipboard-plus text-secondary me-2"></i>Input Data Harian</h3>
    </div>

    <div class="badge-info-cabang d-flex align-items-center mb-4">
        <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px;">
            <i class="bi bi-shop fs-4 text-dark"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold"><?= $cabang ?></h5>
            <small class="opacity-75">Nama Pengelola: <strong><?= $nama_pengelola ?></strong></small>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" onsubmit="prepareSubmitForm()">

        <div class="card card-custom">
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-person me-1"></i> Nama Pengelola</label>
                        <input type="text" value="<?= $nama_pengelola ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-shop me-1"></i> Nama Cabang</label>
                        <input type="text" value="<?= $cabang ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-calendar me-1"></i> Tanggal Input</label>
                        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control form-control-custom" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-custom-header bg-header-success">
                <i class="bi bi-cash-stack fs-5"></i> 1. Pendapatan / Omzet Cabang
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php foreach(['tunai' => 'Tunai', 'qris' => 'QRIS', 'grab_food' => 'Grab Food', 'go_food' => 'Go Food'] as $k => $v): ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= $v ?></label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom">Rp</span>
                            <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung mask-money" value="0">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-10 col-md-3">
                        <label class="form-label fw-bold text-success">Total Pendapatan/Omzet</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-success text-white border-success">Rp</span>
                            <input type="text" id="total_omset" class="form-control form-control-custom bg-light fw-bold text-success border-success" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-custom-header bg-header-warning">
                <i class="bi bi-basket fs-5"></i> 2. Pengeluaran Belanja Rutin
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php foreach(['belanja_pasar' => 'Pasar', 'belanja_sembako' => 'Sembako', 'belanja_beras' => 'Beras', 'belanja_toko' => 'Toko'] as $k => $v): ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= $v ?></label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom">Rp</span>
                            <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung_rutin mask-money" value="0">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-10 col-md-3">
                        <label class="form-label fw-bold text-warning">Total Belanja Rutin</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-warning text-dark border-warning">Rp</span>
                            <input type="text" id="total_rutin" class="form-control form-control-custom bg-light fw-bold text-warning border-warning" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-custom-header bg-header-info">
                <i class="bi bi-receipt fs-5"></i> 3. Beban Biaya Operasional
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php foreach(['sewa' => 'Sewa', 'gaji' => 'Gaji', 'listrik' => 'Listrik', 'air' => 'Air PAM', 'sampah' => 'Sampah', 'keamanan' => 'Keamanan', 'internet' => 'Internet', 'lain_lain' => 'Lain-lain'] as $k => $v): ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= $v ?></label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom">Rp</span>
                            <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung_op mask-money" value="0">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-10 col-md-3">
                        <label class="form-label fw-bold text-primary">Total Operasional</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-primary text-white border-primary">Rp</span>
                            <input type="text" id="total_op" class="form-control form-control-custom bg-light fw-bold text-primary border-primary" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-custom-header bg-header-danger">
                <i class="bi bi-calculator fs-5"></i> 4. Ringkasan & Rekapitulasi Kalkulasi
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-bold">Total Pengeluaran</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-dark text-white">Rp</span>
                            <input type="text" id="total_pengeluaran" class="form-control form-control-custom bg-light fw-bold text-dark" readonly>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-bold">Sisa Uang Tunai</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-info text-white border-info">Rp</span>
                            <input type="text" id="sisa_tunai" class="form-control form-control-custom bg-light text-primary fw-bold" readonly>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-bold">Net Profit Bersih</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-success text-white border-success">Rp</span>
                            <input type="text" id="net_profit" class="form-control form-control-custom bg-light text-success fw-bold fs-6" readonly>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-bold">Margin Keuntungan</label>
                        <div class="input-group">
                            <input type="text" id="persentase" class="form-control form-control-custom bg-light text-center fw-bold fs-6" readonly>
                            <span class="input-group-text input-group-text-custom">%</span>
                        </div>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label"><i class="bi bi-chat-left-text me-1"></i> Catatan / Keterangan Tambahan</label>
                    <textarea name="keterangan" class="form-control form-control-custom" rows="3" placeholder="Tulis rincian tambahan jika ada pengeluaran tidak terduga..."></textarea>
                </div>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-custom-header bg-header-secondary">
                <i class="bi bi-camera fs-5"></i> 5. Lampiran Berkas / Dokumen Nota & Struk
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php for($i=1;$i<=4;$i++):?>
                    <div class="col-6 col-md-3">
                        <div class="p-2 border rounded-3 text-center bg-light">
                            <label class="form-label fw-bold d-block mb-2">Foto Nota <?= $i ?></label>
                            <input type="file" name="foto_nota<?= $i ?>" class="form-control form-control-sm" accept="image/*" capture="environment">
                        </div>
                    </div>
                    <?php endfor;?>
                </div>
                <div class="mt-3 text-muted" style="font-size: 0.8rem;">
                    <i class="bi bi-info-circle me-1"></i> Syarat dokumen resmi: Format gambar (JPG, PNG) dengan ukuran berkas maksimal 2MB. Ketika ditekan di HP, kamera belakang akan otomatis aktif.
                </div>
            </div>
        </div>

        <div class="mb-5">
            <button type="submit" name="simpan" class="btn btn-success btn-lg w-100 py-3 shadow-sm fw-bold rounded-3">
                <i class="bi bi-send-check-fill me-2"></i>Simpan Laporan & Kirim ke Pusat
            </button>
        </div>
    </form>
</div>

<script>
// Fungsi memformat string mentah menjadi ribuan tanpa batas digit (Mendukung ratusan, ribuan, jutaan, dst)
function formatRupiahMask(angka) {
    if (!angka) return '0';
    // Hanya ambil karakter angka murni
    let cleanNumber = angka.toString().replace(/[^0-9]/g, '');
    if (cleanNumber === '') return '0';
    
    // Mengubah string angka menjadi format pemisah koma internasional untuk ribuan secara dinamis
    return parseInt(cleanNumber, 10).toLocaleString('en-US');
}

// Fungsi pembantu mengambil nilai murni angka dari input string berformat koma
function getPureNumber(selectorOrEl) {
    let el = typeof selectorOrEl === 'string' ? document.querySelector(selectorOrEl) : selectorOrEl;
    if (!el) return 0;
    let cleanVal = el.value.replace(/,/g, '');
    return parseInt(cleanVal, 10) || 0;
}

// Fungsi Utama Perhitungan/Kalkulasi Laporan
function hitung(){
    let tunai = getPureNumber('[name=tunai]');
    let qris  = getPureNumber('[name=qris]');
    let grab  = getPureNumber('[name=grab_food]');
    let go    = getPureNumber('[name=go_food]');
    
    let omset = tunai + qris + grab + go;
    document.getElementById('total_omset').value = formatRupiahMask(omset);

    let rutin = Array.from(document.querySelectorAll('.hitung_rutin')).reduce((sum, el) => sum + getPureNumber(el), 0);
    document.getElementById('total_rutin').value = formatRupiahMask(rutin);

    let op = Array.from(document.querySelectorAll('.hitung_op')).reduce((sum, el) => sum + getPureNumber(el), 0);
    document.getElementById('total_op').value = formatRupiahMask(op);

    let total_pengeluaran = rutin + op;
    document.getElementById('total_pengeluaran').value = formatRupiahMask(total_pengeluaran);

    let sisa = tunai - total_pengeluaran;
    document.getElementById('sisa_tunai').value = formatRupiahMask(sisa);

    let net = omset - total_pengeluaran;
    document.getElementById('net_profit').value = formatRupiahMask(net);

    let persen = omset > 0 ? (net / omset * 100).toFixed(2) : 0;
    document.getElementById('persentase').value = persen;
}

// Pasang Event Listener Masking otomatis saat user mengetikkan nominal angka
document.querySelectorAll('.mask-money').forEach(el => {
    el.addEventListener('input', function(e) {
        // Simpan posisi kursor saat mengetik agar tidak melompat ke belakang
        let cursorPosition = this.selectionStart;
        let oldLength = this.value.length;
        
        this.value = formatRupiahMask(this.value);
        
        let newLength = this.value.length;
        cursorPosition = cursorPosition + (newLength - oldLength);
        this.setSelectionRange(cursorPosition, cursorPosition);
        
        // Panggil fungsi hitung otomatis
        hitung();
    });
});

// Fungsi pembersih koma tepat sebelum data disubmit/dikirimkan ke PHP
function prepareSubmitForm() {
    document.querySelectorAll('.mask-money').forEach(el => {
        el.value = el.value.replace(/,/g, '');
    });
}

// Jalankan perhitungan awal saat halaman pertama kali dimuat
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mask-money').forEach(el => {
        el.value = formatRupiahMask(el.value);
    });
    hitung();
});
</script>