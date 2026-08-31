<?php
/**
 * Partial: handler POST untuk laporan.php (hapus + update/koreksi laporan).
 * Dipisah dari laporan.php supaya file utamanya tidak kegemukan.
 * Butuh variabel dari scope pemanggil: $conn, $filter, $tgl_awal, $tgl_akhir, $id_cabang, $page,
 * dan fungsi redirectLaporan() yang didefinisikan di laporan.php sebelum require ini.
 */
// PROSES HAPUS LAPORAN
// ==========================================================

if(
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['hapus_laporan'])
){

    // ------------------------------------------
    // CEK CSRF
    // ------------------------------------------

    if(!csrf_check($_POST['csrf'] ?? '')){

        echo "<script>
            alert('Token keamanan tidak valid!');
            history.back();
        </script>";

        exit;
    }


    // ------------------------------------------
    // ID
    // ------------------------------------------

    $id = (int)($_POST['id'] ?? 0);

    if($id <= 0){

        echo "<script>
            alert('ID laporan tidak valid!');
            history.back();
        </script>";

        exit;
    }


    // ------------------------------------------
    // AMBIL SELURUH ISI BARIS (untuk diarsipkan sebelum dihapus)
    // ------------------------------------------

    $q = $conn->prepare("
        SELECT l.*, c.nama_cabang
        FROM laporan_cabang l
        LEFT JOIN cabang c ON c.id_cabang = l.id_cabang
        WHERE l.id = ?
    ");

    if(!$q){
        die("Prepare SELECT laporan gagal: " . h($conn->error));
    }

    $q->bind_param("i", $id);

    if(!$q->execute()){
        die("Execute SELECT laporan gagal: " . h($q->error));
    }

    $row_lama = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row_lama) {
        echo "<script>alert('Data laporan tidak ditemukan.'); history.back();</script>";
        exit;
    }

    // NB: foto nota TIDAK dihapus dari disk — snapshot di bawah menyimpan nama
    // filenya, dan kalau baris ini dipulihkan lagi fotonya harus tetap ada.

    // ------------------------------------------
    // ARSIPKAN SNAPSHOT LENGKAP DULU — hapus baru boleh jalan kalau ini sukses.
    // ------------------------------------------

    $data_json = json_encode($row_lama, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $uid_penghapus = current_user_id();
    $uname_penghapus = current_username();

    $arsip = $conn->prepare("
        INSERT INTO laporan_cabang_arsip
            (id_laporan_asli, id_cabang, nama_cabang, tanggal, data_json, dihapus_oleh_user_id, dihapus_oleh_username)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    if(!$arsip){
        die("<div style='padding:30px;font-family:Arial'><h3>ARSIP GAGAL</h3>Laporan TIDAK dihapus karena snapshot arsip gagal dibuat: " . h($conn->error) . "</div>");
    }

    $arsip->bind_param(
        "iisssis",
        $id, $row_lama['id_cabang'], $row_lama['nama_cabang'], $row_lama['tanggal'],
        $data_json, $uid_penghapus, $uname_penghapus
    );

    if(!$arsip->execute()){
        die("<div style='padding:30px;font-family:Arial'><h3>ARSIP GAGAL</h3>Laporan TIDAK dihapus karena snapshot arsip gagal dibuat: " . h($arsip->error) . "</div>");
    }
    $arsip->close();

    // ------------------------------------------
    // HAPUS DATABASE (baris asli) — snapshot sudah aman di laporan_cabang_arsip
    // ------------------------------------------

    $del = $conn->prepare("
        DELETE FROM laporan_cabang
        WHERE id = ?
    ");

    if(!$del){

        die(
            "Prepare DELETE gagal: " .
            h($conn->error)
        );
    }

    $del->bind_param("i", $id);

    if($del->execute()){

        audit($conn, 'laporan_hapus', 'laporan_cabang', $id, [
            'id_cabang' => $row_lama['id_cabang'], 'tanggal' => $row_lama['tanggal'],
            'diarsipkan' => true,
        ]);

        echo "<script>
            alert('Data laporan dihapus dan sudah diarsipkan (bisa dipulihkan lewat Arsip Laporan Terhapus).');
        </script>";

        redirectLaporan(
            $filter,
            $tgl_awal,
            $tgl_akhir,
            $id_cabang,
            $page
        );

    }else{

        die(
            "DELETE DATABASE GAGAL.<br><br>" .
            "Error: " . h($del->error)
        );
    }

}


// ==========================================================
// PROSES UPDATE LAPORAN
// ==========================================================

if(
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_laporan'])
){

    // ======================================================
    // CEK CSRF
    // ======================================================

    if(!csrf_check($_POST['csrf'] ?? '')){

        echo "<script>
            alert('Token keamanan tidak valid! Silakan refresh halaman.');
            history.back();
        </script>";

        exit;
    }


    // ======================================================
    // ID LAPORAN
    // ======================================================

    $id = (int)($_POST['id'] ?? 0);

    if($id <= 0){

        echo "<script>
            alert('ID laporan tidak valid!');
            history.back();
        </script>";

        exit;
    }


    // ======================================================
    // KETERANGAN
    // ======================================================

    $keterangan = trim($_POST['keterangan'] ?? '');

    // ======================================================
    // RUMUS KEUANGAN — satu sumber kebenaran di config/keuangan.php
    // (dites otomatis via tests/keuangan_test.php). Form koreksi pusat
    // boleh input minus untuk penyesuaian manual, makanya pakai
    // bersihkan_angka_koreksi().
    // ======================================================

    $h = hitung_laporan_harian($_POST, 'bersihkan_angka_koreksi');
    [
        'tunai' => $tunai, 'qris' => $qris, 'grab_food' => $grab_food, 'go_food' => $go_food,
        'pencairan_qris' => $pencairan_qris, 'total_omset' => $total_omset,
        'belanja_pasar' => $belanja_pasar, 'belanja_sembako' => $belanja_sembako,
        'belanja_beras' => $belanja_beras, 'belanja_toko' => $belanja_toko, 'total_rutin' => $total_rutin,
        'sewa' => $sewa, 'gaji' => $gaji, 'listrik' => $listrik, 'air' => $air,
        'sampah' => $sampah, 'keamanan' => $keamanan, 'internet' => $internet, 'gas' => $gas,
        'mingguan_karyawan' => $mingguan_karyawan, 'es_batu' => $es_batu, 'bensin' => $bensin, 'lain_lain' => $lain_lain,
        'total_operasional' => $total_operasional, 'total_pengeluaran' => $total_pengeluaran,
        'sisa_tunai' => $sisa_tunai, 'sisa_qris' => $sisa_qris,
        'net_profit' => $net_profit, 'persentase' => $persentase,
    ] = $h;



    // ======================================================
    // CEK DATA SEBELUM UPDATE
    // ======================================================

    $cek = $conn->prepare("
        SELECT id
        FROM laporan_cabang
        WHERE id = ?
        LIMIT 1
    ");

    if(!$cek){

        die(
            "Prepare cek laporan gagal: " .
            h($conn->error)
        );
    }

    $cek->bind_param("i", $id);

    if(!$cek->execute()){

        die(
            "Execute cek laporan gagal: " .
            h($cek->error)
        );
    }

    $hasilCek = $cek->get_result();

    if($hasilCek->num_rows <= 0){

        echo "<script>
            alert('Data laporan dengan ID $id tidak ditemukan di database!');
            history.back();
        </script>";

        exit;
    }

    $cek->close();

    // Simpan SELURUH nilai lama (bukan cuma ringkasan) sebelum ditimpa — supaya
    // riwayat koreksi tetap lengkap & bisa ditelusuri lewat Log Aktivitas walau
    // baris aslinya sudah berubah. Data lama tidak pernah benar-benar hilang.
    $__old_laporan = $conn->query(
        "SELECT * FROM laporan_cabang WHERE id = " . (int) $id
    )->fetch_assoc() ?: [];



    // ======================================================
    // UPDATE DATABASE
    // ======================================================

    $sql = "
        UPDATE laporan_cabang SET

            tunai = ?,
            qris = ?,
            grab_food = ?,
            go_food = ?,
            pencairan_qris = ?,
            total_omset = ?,

            belanja_pasar = ?,
            belanja_sembako = ?,
            belanja_beras = ?,
            belanja_toko = ?,
            total_rutin = ?,

            sewa = ?,
            gaji = ?,
            listrik = ?,
            air = ?,
            sampah = ?,
            keamanan = ?,
            internet = ?,
            gas = ?,
            mingguan_karyawan = ?,
            es_batu = ?,
            bensin = ?,
            lain_lain = ?,
            total_operasional = ?,

            total_pengeluaran = ?,
            sisa_tunai = ?,
            sisa_qris = ?,
            net_profit = ?,
            persentase = ?,
            keterangan = ?,

            status_laporan = 'lengkap',
            id_user_laporan = ?

        WHERE id = ?
    ";



    // ======================================================
    // PREPARE
    // ======================================================

    $stmt = $conn->prepare($sql);

    if(!$stmt){

        die(
            "<div style='padding:30px;font-family:Arial'>" .
            "<h3>UPDATE DATABASE GAGAL</h3>" .
            "<b>Prepare Error:</b><br>" .
            h($conn->error) .
            "</div>"
        );
    }



    // ======================================================
    // BIND PARAMETER
    // ======================================================

    /*
     * 28 INTEGER
     * 1 DECIMAL (persentase, param ke-29)
     * 1 STRING (keterangan)
     * 1 INTEGER (id_user_laporan, penanda pusat yang finalisasi)
     * 1 INTEGER ID
     *
     * TOTAL = 32 PARAMETER
     */

    $types =
        "iiiiiiiiiiiiiiiiiiiiiiiiiiii" .
        "d" .
        "si" .
        "i";

    $id_user_laporan = current_user_id();


    $bind = $stmt->bind_param(
        $types,

        $tunai,
        $qris,
        $grab_food,
        $go_food,
        $pencairan_qris,
        $total_omset,

        $belanja_pasar,
        $belanja_sembako,
        $belanja_beras,
        $belanja_toko,
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
        $lain_lain,
        $total_operasional,

        $total_pengeluaran,
        $sisa_tunai,
        $sisa_qris,
        $net_profit,
        $persentase,

        $keterangan,

        $id_user_laporan,
        $id
    );


    if(!$bind){

        die(
            "<div style='padding:30px;font-family:Arial'>" .
            "<h3>BIND PARAMETER GAGAL</h3>" .
            h($stmt->error) .
            "</div>"
        );
    }



    // ======================================================
    // EXECUTE UPDATE
    // ======================================================

    if(!$stmt->execute()){

        die(
            "<div style='padding:30px;font-family:Arial'>" .
            "<h3>UPDATE DATABASE GAGAL</h3>" .

            "<p><b>Error MySQL:</b></p>" .

            "<pre style='background:#f5f5f5;padding:15px;border:1px solid #ddd;'>" .
            h($stmt->error) .
            "</pre>" .

            "<p><b>ID laporan:</b> " .
            h($id) .
            "</p>" .

            "</div>"
        );
    }



    // ======================================================
    // CEK HASIL
    // ======================================================

    /*
     * affected_rows:
     *
     * > 0 = data berubah
     * = 0 = data sama dengan sebelumnya
     *
     * Keduanya tetap berarti UPDATE berhasil.
     */

    if(
        $stmt->affected_rows >= 0
    ){

        $stmt->close();

        audit($conn, 'laporan_edit', 'laporan_cabang', $id, [
            'lama' => $__old_laporan,
            'baru' => [
                'total_omset'       => $total_omset,
                'total_pengeluaran' => $total_pengeluaran,
                'sisa_tunai'        => $sisa_tunai,
                'sisa_qris'         => $sisa_qris,
                'net_profit'        => $net_profit,
                'persentase'        => $persentase,
            ],
        ]);

        $param = http_build_query([
            'filter'    => $filter,
            'tgl_awal'  => $tgl_awal,
            'tgl_akhir' => $tgl_akhir,
            'id_cabang' => $id_cabang,
            'page'      => $page
        ]);


        echo "
        <script>
            alert('Data laporan berhasil disimpan ke database.');

            window.location.href =
                'laporan?$param';
        </script>
        ";

        exit;
    }


    // ======================================================
    // FALLBACK
    // ======================================================

    echo "
    <script>
        alert('Data tidak berhasil diperbarui.');
        history.back();
    </script>
    ";

    exit;
}
