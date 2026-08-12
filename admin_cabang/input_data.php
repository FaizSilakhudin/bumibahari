<?php
require '../config/koneksi.php';
include 'sidebar.php';

// =====================================================
// HELPER BIAR GAK ERROR
// =====================================================
if(!function_exists('h')){
    function h($s){
        return htmlspecialchars($s ?? '', ENT_QUOTES);
    }
}

if(!function_exists('csrf_token')){
    function csrf_token(){
        if(empty($_SESSION['csrf'])){
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }
}

if(!function_exists('csrf_check')){
    function csrf_check($t){
        return hash_equals($_SESSION['csrf'] ?? '', $t);
    }
}

// =====================================================
// 1. PROTEKSI LOGIN + ROLE
// =====================================================
if(
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') != 'cabang'
){
    header("Location:../login");
    exit;
}

$id_cabang = $_SESSION['id_cabang'];
$nama_pengelola = $_SESSION['nama_pengelola'];

// =====================================================
// 2. AMANKAN QUERY SELECT PAKAI PREPARED
// =====================================================
$stmt = $conn->prepare("
    SELECT nama_cabang
    FROM cabang
    WHERE id_cabang=?
");

$stmt->bind_param("i", $id_cabang);
$stmt->execute();

$result = $stmt->get_result();

$cabang = $result->fetch_assoc()['nama_cabang'] ?? '-';

$stmt->close();


// =====================================================
// PROSES SIMPAN
// =====================================================

// FIX: WAJIB ADA { SETELAH IF
if(isset($_POST['simpan'])){

    // =================================================
    // 3. VALIDASI CSRF
    // =================================================
    if(!csrf_check($_POST['csrf'] ?? '')){
        die("
            <script>
                alert('Token tidak valid!');
                history.back();
            </script>
        ");
    }

    $tgl = $_POST['tanggal'] ?? date('Y-m-d');

    $ket = $_POST['keterangan'] ?? '';


    // =================================================
    // CLEAN NUMBER
    // =================================================
    function cleanNumber($val) {

        return (int)preg_replace(
            '/[^0-9]/',
            '',
            $val ?? ''
        );

    }


    // =================================================
    // PENDAPATAN / OMZET
    // =================================================
    $tunai = cleanNumber($_POST['tunai'] ?? 0);
    $qris  = cleanNumber($_POST['qris'] ?? 0);
    $grab  = cleanNumber($_POST['grab_food'] ?? 0);
    $go    = cleanNumber($_POST['go_food'] ?? 0);

    $total_omset =
        $tunai +
        $qris +
        $grab +
        $go;


    // =================================================
    // BELANJA RUTIN
    // =================================================
    $pasar =
        cleanNumber($_POST['belanja_pasar'] ?? 0);

    $sembako =
        cleanNumber($_POST['belanja_sembako'] ?? 0);

    $beras =
        cleanNumber($_POST['belanja_beras'] ?? 0);

    $toko =
        cleanNumber($_POST['belanja_toko'] ?? 0);

    $total_rutin =
        $pasar +
        $sembako +
        $beras +
        $toko;


    // =================================================
    // BEBAN OPERASIONAL
    // =================================================
    $sewa =
        cleanNumber($_POST['sewa'] ?? 0);

    $gaji =
        cleanNumber($_POST['gaji'] ?? 0);

    $listrik =
        cleanNumber($_POST['listrik'] ?? 0);

    $air =
        cleanNumber($_POST['air'] ?? 0);

    $sampah =
        cleanNumber($_POST['sampah'] ?? 0);

    $keamanan =
        cleanNumber($_POST['keamanan'] ?? 0);

    $internet =
        cleanNumber($_POST['internet'] ?? 0);

    $gas =
        cleanNumber($_POST['gas'] ?? 0);

    $mingguan_karyawan =
        cleanNumber($_POST['mingguan_karyawan'] ?? 0);

    $es_batu =
        cleanNumber($_POST['es_batu'] ?? 0);

    $bensin =
        cleanNumber($_POST['bensin'] ?? 0);

    $lain =
        cleanNumber($_POST['lain_lain'] ?? 0);


    $total_op =
        $sewa +
        $gaji +
        $listrik +
        $air +
        $sampah +
        $keamanan +
        $internet +
        $gas +
        $mingguan_karyawan +
        $es_batu +
        $bensin +
        $lain;


    // TOTAL PENGELUARAN
    // =================================================
    $total_pengeluaran =
    $total_rutin +
    $total_op;

    // =================================================
    // LOGIKA TUNAI → QRIS
    // =================================================
    // Pengeluaran terlebih dahulu diambil dari TUNAI.
    // Jika tunai tidak cukup, kekurangannya
    // akan diambil dari QRIS.
    // =================================================

    $sisa_tunai =
    $tunai - $total_pengeluaran;

    // =================================================
    // HITUNG KEKURANGAN UNTUK QRIS
    // =================================================
    // Jika total pengeluaran lebih besar
    // dari uang tunai, maka selisihnya
    // diambil dari QRIS.
    // =================================================

    $kekurangan =
    max(
    0,
    $total_pengeluaran - $tunai
    );

    // =================================================
    // PENCAIRAN QRIS
    // =================================================
    // Pencairan QRIS diinput secara manual.
    // =================================================

    $pencairan_qris =
    cleanNumber(
    $_POST['pencairan_qris'] ?? 0
    );

    // =================================================
    // HITUNG SISA QRIS
    // =================================================
    // QRIS berkurang karena:
    // 1. Kekurangan pembayaran dari Tunai
    // 2. Pencairan QRIS
    // =================================================

    $sisa_qris =
    $qris -
    $kekurangan -
    $pencairan_qris;

    // =================================================
    // TOTAL SISA UANG
    // =================================================

    $sisa =
    $sisa_tunai +
    $sisa_qris;

    // =================================================
    // NET PROFIT BERSIH
    // =================================================
    // Net Profit =
    // + Sisa QRIS
    // + Go Food
    // + Grab Food
    // =================================================

    $net =
    $sisa_qris +
    $go +
    $grab;

    // =================================================
    // PERSENTASE / MARGIN KEUNTUNGAN
    // =================================================
    // Margin = Net Profit : Total Omzet × 100%
    // =================================================

    $persen =
    $total_omset > 0
    ? round(
    ($net / $total_omset) * 100,
    2
    )
    : 0;
    // =================================================
    // 4. UPLOAD AMAN
    // =================================================
    $foto = [
        '',
        '',
        '',
        ''
    ];

    $upload_dir = "../uploads/nota/";


    // Buat folder jika belum ada
    if(!is_dir($upload_dir)){

        mkdir(
            $upload_dir,
            0755,
            true
        );

    }


    for($i = 1; $i <= 4; $i++){

        if(
            isset($_FILES["foto_nota$i"]) &&
            $_FILES["foto_nota$i"]['error'] == 0
        ){

            $tmp_name =
                $_FILES["foto_nota$i"]["tmp_name"];

            $file_name =
                $_FILES["foto_nota$i"]["name"];

            $file_size =
                $_FILES["foto_nota$i"]["size"];


            // =========================================
            // CEK GAMBAR
            // =========================================
            $cek =
                getimagesize($tmp_name);


            $ext =
                strtolower(
                    pathinfo(
                        $file_name,
                        PATHINFO_EXTENSION
                    )
                );


            // =========================================
            // FORMAT YANG DIIZINKAN
            // =========================================
            if(
                $cek !== false &&
                in_array(
                    $ext,
                    [
                        'jpg',
                        'jpeg',
                        'png'
                    ]
                )
            ){

                // =====================================
                // MAKSIMAL 2 MB
                // =====================================
                if($file_size <= 2000000){

                    // =================================
                    // NAMA FILE AMAN
                    // =================================
                    $nama_file =
                        date('Ymd') .
                        "_" .
                        $id_cabang .
                        "_" .
                        uniqid() .
                        "_" .
                        $i .
                        "." .
                        $ext;


                    if(
                        move_uploaded_file(
                            $tmp_name,
                            $upload_dir . $nama_file
                        )
                    ){

                        $foto[$i - 1] =
                            $nama_file;

                    }else{

                        echo "
                            <script>
                                alert(
                                    'Gagal upload foto nota $i'
                                );
                            </script>
                        ";

                    }

                }else{

                    echo "
                        <script>
                            alert(
                                'Foto nota $i terlalu besar. Max 2MB'
                            );
                        </script>
                    ";

                }

            }else{

                echo "
                    <script>
                        alert(
                            'Format foto nota $i salah. Harus JPG/JPEG/PNG'
                        );
                    </script>
                ";

            }

        }

    }


    // =================================================
    // 5. UPSERT
    // INSERT BARU ATAU UPDATE
    // KALAU TANGGAL + CABANG SAMA
    // =================================================

    $sql = "
    INSERT INTO laporan_cabang
    (
        id_cabang,
        nama_pengelola,
        tanggal,

        tunai,
        qris,
        grab_food,
        go_food,
        total_omset,

        belanja_pasar,
        belanja_sembako,
        belanja_beras,
        belanja_toko,
        total_rutin,

        sewa,
        gaji,
        listrik,
        air,
        sampah,
        keamanan,
        internet,
        gas,
        mingguan_karyawan,
        es_batu,
        bensin,
        lain_lain,

        total_operasional,
        total_pengeluaran,
        sisa_tunai,
        pencairan_qris,
        sisa_qris,

        net_profit,
        persentase,

        keterangan,

        foto_nota1,
        foto_nota2,
        foto_nota3,
        foto_nota4
    )

    VALUES
    (
        ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
    )

    ON DUPLICATE KEY UPDATE

        nama_pengelola = VALUES(nama_pengelola),

        tunai = VALUES(tunai),
        qris = VALUES(qris),
        grab_food = VALUES(grab_food),
        go_food = VALUES(go_food),
        total_omset = VALUES(total_omset),

        belanja_pasar = VALUES(belanja_pasar),
        belanja_sembako = VALUES(belanja_sembako),
        belanja_beras = VALUES(belanja_beras),
        belanja_toko = VALUES(belanja_toko),
        total_rutin = VALUES(total_rutin),

        sewa = VALUES(sewa),
        gaji = VALUES(gaji),
        listrik = VALUES(listrik),
        air = VALUES(air),
        sampah = VALUES(sampah),
        keamanan = VALUES(keamanan),
        internet = VALUES(internet),
        gas = VALUES(gas),
        mingguan_karyawan = VALUES(mingguan_karyawan),
        es_batu = VALUES(es_batu),
        bensin = VALUES(bensin),
        lain_lain = VALUES(lain_lain),

        total_operasional = VALUES(total_operasional),
        total_pengeluaran = VALUES(total_pengeluaran),
        sisa_tunai = VALUES(sisa_tunai),
        pencairan_qris = VALUES(pencairan_qris),
        sisa_qris = VALUES(sisa_qris),

        net_profit = VALUES(net_profit),
        persentase = VALUES(persentase),

        keterangan = VALUES(keterangan),

        foto_nota1 = IF(
            VALUES(foto_nota1) = '',
            foto_nota1,
            VALUES(foto_nota1)
        ),

        foto_nota2 = IF(
            VALUES(foto_nota2) = '',
            foto_nota2,
            VALUES(foto_nota2)
        ),

        foto_nota3 = IF(
            VALUES(foto_nota3) = '',
            foto_nota3,
            VALUES(foto_nota3)
        ),

        foto_nota4 = IF(
            VALUES(foto_nota4) = '',
            foto_nota4,
            VALUES(foto_nota4)
        )
    ";


    $stmt =
        $conn->prepare($sql);


    if(!$stmt){

        die(
            "Prepare gagal: " .
            $conn->error
        );

    }


    // =================================================
    // PASTIKAN NILAI TIDAK NULL
    // =================================================
    $pencairan_qris =
        $pencairan_qris ?? 0;

    $sisa_tunai =
        $sisa_tunai ?? 0;

    $sisa_qris =
        $sisa_qris ?? 0;


    // =================================================
    // BIND PARAM
    // =================================================
    $stmt->bind_param(
        "issdddddddddddddddddddddddddddddsssss",

        $id_cabang,
        $nama_pengelola,
        $tgl,

        $tunai,
        $qris,
        $grab,
        $go,
        $total_omset,

        $pasar,
        $sembako,
        $beras,
        $toko,
        $total_rutin,

        $sewa,
        $gaji,
        $listrik,
        $air,
        $sampah,
        $keamanan,
        $internet,
        $gas,
        $mingguan_karyawan,
        $es_batu,
        $bensin,
        $lain,

        $total_op,
        $total_pengeluaran,

        $sisa_tunai,
        $pencairan_qris,
        $sisa_qris,

        $net,
        $persen,

        $ket,

        $foto[0],
        $foto[1],
        $foto[2],
        $foto[3]
    );


    // =================================================
    // EXECUTE
    // =================================================
    if($stmt->execute()){

        echo "
            <script>
                alert('Data berhasil disimpan!');
                window.location.replace('input_data.php');
            </script>
        ";

        exit;

    }else{

        echo "
            <script>
                alert('Gagal simpan: " .
                addslashes($stmt->error) .
                "');
                history.back();
            </script>
        ";

        exit;

    }

} // FIX: PENUTUP IF isset($_POST['simpan'])

?>


<style>

body {
    background-color: #f6f8fa;
}

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

.bg-header-success {
    background: #E6FFFA;
    color: #00875A;
}

.bg-header-warning {
    background: #FEF3C7;
    color: #B45309;
}

.bg-header-info {
    background: #EBF8FF;
    color: #2B6CB0;
}

.bg-header-danger {
    background: #FFF5F5;
    color: #C53030;
}

.bg-header-secondary {
    background: #F7FAFC;
    color: #4A5568;
}


/* Responsive Badge Info */
.badge-info-cabang {
    background: linear-gradient(
        135deg,
        #2D3748 0%,
        #1A202C 100%
    );

    border-radius: 12px;
    padding: 16px 20px;
    color: #ffffff;

    box-shadow:
        0 4px 15px
        rgba(26, 32, 44, 0.1);
}


/* Preview Grid for Form inputs on mobile */
@media (max-width: 576px) {

    .card-custom-header {
        padding: 12px 16px;
    }

    .p-mobile-custom {
        padding: 16px!important;
    }

}

</style>


<div class="container-fluid py-3 px-md-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0 fw-bold text-dark">
            <i class="bi bi-clipboard-plus text-secondary me-2"></i> Input Data Harian
        </h3>
    </div>

    <!-- =================================================
         INFORMASI CABANG
    ================================================== -->
    <div class="badge-info-cabang d-flex align-items-center mb-4">
        <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
            <i class="bi bi-shop fs-4 text-dark"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold"><?= h($cabang) ?></h5>
            <small class="opacity-75">
                Nama Pengelola: <strong><?= h($nama_pengelola) ?></strong>
            </small>
        </div>
    </div>

    <!-- =================================================
         FORM 
    ================================================== -->
    <form method="POST" enctype="multipart/form-data" onsubmit="prepareSubmitForm()">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

        <!-- =================================================
             IDENTITAS
        ================================================== -->
        <div class="card card-custom mb-4">
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-person me-1"></i> Nama Pengelola
                        </label>
                        <input type="text" value="<?= h($nama_pengelola) ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-shop me-1"></i> Nama Cabang
                        </label>
                        <input type="text" value="<?= h($cabang) ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-calendar me-1"></i> Tanggal Input
                        </label>
                        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control form-control-custom" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================
             PENDAPATAN
        ================================================== -->
        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-success">
                <i class="bi bi-cash-stack fs-5"></i> 1. Pendapatan / Omzet Cabang
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php
                    $pendapatan_items = [
                        'tunai' => 'Tunai',
                        'qris' => 'QRIS',
                        'grab_food' => 'Grab Food',
                        'go_food' => 'Go Food'
                    ];
                    foreach ($pendapatan_items as $k => $v):
                    ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= $v ?></label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-custom">Rp</span>
                                <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung mask-money" value="0">
                            </div>
                        </div>
                    <?php endforeach; ?>

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

        <!-- =================================================
             BELANJA RUTIN
        ================================================== -->
        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-warning">
                <i class="bi bi-basket fs-5"></i> 2. Pengeluaran Belanja Rutin
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php
                    $rutin_items = [
                        'belanja_pasar' => 'Pasar',
                        'belanja_sembako' => 'Sembako',
                        'belanja_beras' => 'Beras',
                        'belanja_toko' => 'Toko'
                    ];
                    foreach ($rutin_items as $k => $v):
                    ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= $v ?></label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-custom">Rp</span>
                                <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung_rutin mask-money" value="0">
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

        <!-- =================================================
             OPERASIONAL
        ================================================== -->
        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-info">
                <i class="bi bi-receipt fs-5"></i> 3. Beban Biaya Operasional
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3 mb-3">
                    <?php
                    $operasional_items = [
                        'sewa' => 'Sewa Ruko',
                        'gaji' => 'Gaji Karyawan',
                        'listrik' => 'Listrik',
                        'air' => 'Air PAM',
                        'sampah' => 'Sampah',
                        'keamanan' => 'Keamanan',
                        'internet' => 'Internet',
                        'gas' => 'Gas',
                        'mingguan_karyawan' => 'Mingguan Karyawan',
                        'es_batu' => 'Es Batu - Air Galon',
                        'bensin' => 'Bensin',
                        'lain_lain' => 'Operasional Lain'
                    ];
                    foreach ($operasional_items as $k => $v):
                    ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= $v ?></label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-custom">Rp</span>
                                <input type="text" name="<?= $k ?>" class="form-control form-control-custom hitung_op mask-money" value="0">
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

               <!-- PENCAIRAN QRIS -->

                <div class="row">

                    <div class="col-12 col-md-3">

                        <label class="form-label fw-bold">
                            Pencairan QRIS
                        </label>

                        <div class="input-group">

                            <span class="input-group-text input-group-text-custom bg-warning text-dark border-warning">
                                Rp
                            </span>

                            <input
                                type="text"
                                id="pencairan_qris"
                                class="form-control form-control-custom bg-light fw-bold text-dark"
                                value="0"
                                oninput="formatPencairanQRIS(this); hitung()"
                            >

                        </div>

                    </div>

                </div>
            </div>
        </div>

        <!-- =================================================
             RINGKASAN
        ================================================== -->
        <div class="card card-custom mb-4">
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3 mb-4">
                    <!-- TOTAL PENGELUARAN -->
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Total Pengeluaran</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-dark text-white">Rp</span>
                            <input type="text" id="total_pengeluaran" class="form-control form-control-custom bg-light fw-bold text-dark" readonly>
                        </div>
                    </div>

                    <!-- SISA TUNAI -->
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Sisa Uang Tunai</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-info text-white border-info">Rp</span>
                            <input type="text" id="sisa_tunai" class="form-control form-control-custom bg-light text-primary fw-bold" readonly>
                        </div>
                    </div>

                    <!-- SISA QRIS -->
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Sisa QRIS</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-warning text-dark border-warning">Rp</span>
                            <input type="text" id="sisa_qris" class="form-control form-control-custom bg-light text-warning fw-bold" readonly>
                        </div>
                    </div>

                    <!-- NET PROFIT -->
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Net Profit Bersih</label>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-custom bg-success text-white border-success">Rp</span>
                            <input type="text" id="net_profit" class="form-control form-control-custom bg-light text-success fw-bold fs-6" readonly>
                        </div>
                    </div>

                    <!-- MARGIN -->
                    <div class="col-6 col-md">
                        <label class="form-label fw-bold">Margin Keuntungan</label>
                        <div class="input-group">
                            <input type="text" id="persentase" class="form-control form-control-custom bg-light text-center fw-bold fs-6" readonly>
                            <span class="input-group-text input-group-text-custom">%</span>
                        </div>
                    </div>
                </div>

                <!-- KETERANGAN -->
                <div>
                    <label class="form-label">
                        <i class="bi bi-chat-left-text me-1"></i> Catatan / Keterangan Tambahan
                    </label>
                    <textarea name="keterangan" class="form-control form-control-custom" rows="3" placeholder="Tulis rincian tambahan jika ada pengeluaran tidak terduga..."></textarea>
                </div>
            </div>
        </div>

        <!-- =================================================
             FOTO NOTA
        ================================================== -->
        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-secondary">
                <i class="bi bi-camera fs-5"></i> 5. Lampiran Berkas / Dokumen Nota & Struk
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="col-6 col-md-3">
                            <div class="p-2 border rounded-3 text-center bg-light">
                                <label class="form-label fw-bold d-block mb-2">Foto Nota <?= $i ?></label>
                                <input type="file" name="foto_nota<?= $i ?>" class="form-control form-control-sm" accept="image/*" capture="environment">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="mt-3 text-muted" style="font-size: 0.8rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Syarat dokumen resmi: Format gambar (JPG, PNG) dengan ukuran berkas maksimal 2MB. Ketika ditekan di HP, kamera belakang akan otomatis aktif.
                </div>
            </div>
        </div>

        <!-- =================================================
             TOMBOL SIMPAN
        ================================================== -->
        <div class="mb-5">
            <button type="submit" name="simpan" class="btn btn-success btn-lg w-100 py-3 shadow-sm fw-bold rounded-3">
                <i class="bi bi-send-check-fill me-2"></i> Simpan Laporan & Kirim ke Pusat
            </button>
        </div>
    </form>

</div>


<script>

// =====================================================
// FORMAT RUPIAH
// =====================================================

function formatRupiahMask(angka) {

    if(!angka){
        return '0';
    }

    let cleanNumber =
        angka
            .toString()
            .replace(/[^0-9]/g, '');

    if(cleanNumber === ''){
        return '0';
    }

    return parseInt(
        cleanNumber,
        10
    ).toLocaleString('en-US');
}


// =====================================================
// FORMAT RUPIAH DENGAN MINUS
// =====================================================

function formatRupiahWithMinus(angka) {

    angka = Number(angka) || 0;

    // Jika negatif
    if(angka < 0){

        return '-' +
            Math.abs(angka)
                .toLocaleString('en-US');

    }

    return angka.toLocaleString('en-US');
}


// =====================================================
// AMBIL ANGKA MURNI
// =====================================================

function getPureNumber(selectorOrEl) {

    let el =
        typeof selectorOrEl === 'string'
            ? document.querySelector(selectorOrEl)
            : selectorOrEl;

    if(!el){
        return 0;
    }

    let cleanVal =
        el.value.replace(/,/g, '');

    return parseInt(
        cleanVal,
        10
    ) || 0;
}

function formatPencairanQRIS(input) {

    // Ambil hanya angka
    let angka = input.value.replace(/\D/g, '');

    // Jika kosong
    if (angka === '') {
        input.value = '0';
        return;
    }

    // Hapus angka 0 di depan
    angka = angka.replace(/^0+(?=\d)/, '');

    // Format titik ribuan
    input.value = angka.replace(
        /\B(?=(\d{3})+(?!\d))/g,
        '.'
    );
}

// =====================================================
// FUNGSI UTAMA PERHITUNGAN
// =====================================================

function hitung() {

    // =================================================
    // PENDAPATAN
    // =================================================

    let tunai =
        getPureNumber('[name="tunai"]');

    let qris =
        getPureNumber('[name="qris"]');

    let grab =
        getPureNumber('[name="grab_food"]');

    let go =
        getPureNumber('[name="go_food"]');


    // =================================================
    // TOTAL OMZET
    // =================================================

    let omset =
        tunai +
        qris +
        grab +
        go;


    document.getElementById(
        'total_omset'
    ).value =
        formatRupiahMask(omset);


    // =================================================
    // BELANJA RUTIN
    // =================================================

    let rutin =
        Array
            .from(
                document.querySelectorAll(
                    '.hitung_rutin'
                )
            )
            .reduce(
                (sum, el) =>
                    sum +
                    getPureNumber(el),
                0
            );


    document.getElementById(
        'total_rutin'
    ).value =
        formatRupiahMask(rutin);


    // =================================================
    // OPERASIONAL
    // =================================================

    let op =
        Array
            .from(
                document.querySelectorAll(
                    '.hitung_op'
                )
            )
            .reduce(
                (sum, el) =>
                    sum +
                    getPureNumber(el),
                0
            );


    document.getElementById(
        'total_op'
    ).value =
        formatRupiahMask(op);


    // =================================================
    // TOTAL PENGELUARAN
    // =================================================

    let total_pengeluaran =
        rutin +
        op;


    document.getElementById(
        'total_pengeluaran'
    ).value =
        formatRupiahMask(
            total_pengeluaran
        );


    // =================================================
    // LOGIKA TUNAI → QRIS
    // =================================================

    /*
     * Pengeluaran terlebih dahulu diambil dari TUNAI.
     *
     * Jika Tunai mencukupi:
     *
     * Tunai       = 1.000.000
     * Pengeluaran =   700.000
     *
     * Sisa Tunai  =   300.000
     * Kekurangan  =         0
     *
     *
     * Jika Tunai tidak mencukupi:
     *
     * Tunai       = 1.000.000
     * Pengeluaran = 1.200.000
     *
     * Sisa Tunai  = -200.000
     * Kekurangan  = 200.000
     *
     * Kekurangan tersebut diambil dari QRIS.
     */


    // =================================================
    // SISA TUNAI
    // =================================================

    let sisa_tunai =
        tunai -
        total_pengeluaran;


    // =================================================
    // KEKURANGAN YANG DIAMBIL DARI QRIS
    // =================================================

    let kekurangan =
        Math.max(
            0,
            total_pengeluaran - tunai
        );


    // =================================================
    // PENCAIRAN QRIS
    // =================================================

    /*
     * Nominal diisi MANUAL oleh pengguna.
     *
     * Contoh:
     *
     * QRIS             = 2.000.000
     * Kekurangan Tunai =   200.000
     * Pencairan QRIS   =   500.000
     *
     * Sisa QRIS:
     *
     * 2.000.000
     * - 200.000
     * - 500.000
     * = 1.300.000
     */

    let pencairan_qris = 0;

    const elPencairanQRIS =
        document.getElementById(
            'pencairan_qris'
        );

    if (elPencairanQRIS) {

        pencairan_qris =
            parseFloat(
                String(
                    elPencairanQRIS.value || 0
                )
                .replace(/\./g, '')
                .replace(/[^0-9-]/g, '')
            ) || 0;

    }


    // =================================================
    // SISA QRIS
    // =================================================

    /*
     * QRIS berkurang karena:
     *
     * 1. Kekurangan pembayaran dari Tunai
     * 2. Pencairan QRIS manual
     */

    let sisa_qris =
        qris -
        kekurangan -
        pencairan_qris;


    // =================================================
    // TAMPILKAN SISA TUNAI
    // =================================================

    document.getElementById(
        'sisa_tunai'
    ).value =
        formatRupiahWithMinus(
            sisa_tunai
        );


    // =================================================
    // TAMPILKAN SISA QRIS
    // =================================================

    if (
        document.getElementById(
            'sisa_qris'
        )
    ) {

        document.getElementById(
            'sisa_qris'
        ).value =
            formatRupiahWithMinus(
                sisa_qris
            );

    }


    // =================================================
    // NET PROFIT
    // =================================================

    let net =
        sisa_tunai +
        sisa_qris +
        go +
        grab;
        
    document.getElementById(
        'net_profit'
    ).value =
        formatRupiahMask(
            net
        );


    // =================================================
    // PERSENTASE
    // =================================================

    let persen =
        omset > 0
            ? (
                net /
                omset *
                100
            ).toFixed(2)
            : 0;


    document.getElementById(
        'persentase'
    ).value =
        persen;
}

// =====================================================
// EVENT LISTENER MASKING
// =====================================================

document
    .querySelectorAll('.mask-money')
    .forEach(el => {

        el.addEventListener(
            'input',
            function(){

                let cursorPosition =
                    this.selectionStart;

                let oldLength =
                    this.value.length;


                this.value =
                    formatRupiahMask(
                        this.value
                    );


                let newLength =
                    this.value.length;


                cursorPosition =
                    cursorPosition +
                    (
                        newLength -
                        oldLength
                    );


                this.setSelectionRange(
                    cursorPosition,
                    cursorPosition
                );


                hitung();

            }
        );

    });


// =====================================================
// BERSIHKAN KOMA SEBELUM SUBMIT
// =====================================================

function prepareSubmitForm(){

    document
        .querySelectorAll('.mask-money')
        .forEach(el => {

            el.value =
                el.value.replace(
                    /,/g,
                    ''
                );

        });

}


// =====================================================
// HITUNG SAAT HALAMAN DIMUAT
// =====================================================

window.addEventListener(
    'DOMContentLoaded',
    () => {

        document
            .querySelectorAll('.mask-money')
            .forEach(el => {

                el.value =
                    formatRupiahMask(
                        el.value
                    );

            });


        hitung();

    }
);
</script>