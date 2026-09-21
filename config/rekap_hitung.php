<?php
/**
 * Komputasi finansial 1 SEGMEN Rekapitulasi (rentang tanggal + 1 pengelola).
 * Dipakai oleh admin_pusat/rekapitulasi.php dan admin_pic/rekapitulasi.php
 * lewat _rekap_blok_pengelola.php, di-loop 1x per segmen dari
 * resolve_pengelola_segments() (config/koneksi.php) — kasus normal (1
 * pengelola sepanjang periode) tetap 1 segmen, jadi fungsi ini SELALU dipakai,
 * tidak ada percabangan "kalau ada split" vs "kalau tidak".
 *
 * TIDAK menghitung info pengelola itu sendiri (nama/rekening) — itu sudah ada
 * di array segmen dari resolve_pengelola_segments(), dioper balik oleh
 * pemanggil. TIDAK menghitung jumlah hari kerja — itu didapat dari
 * _rekap_tabel_harian.php yang di-include terpisah utk rentang segmen yang
 * sama (section 1), lalu dipakai section 2 (Beban Operasional).
 */

if (!function_exists('hitung_rekap_segmen')) {
    function hitung_rekap_segmen(mysqli $conn, int $id_cabang, string $tgl_mulai, string $tgl_selesai, int $urutan_pengelola): array
    {
        // ----- Aggregate finansial (sama query dgn top-level rekapitulasi.php,
        // di-scope ke rentang segmen) -----
        $stmt = $conn->prepare("
            SELECT
                SUM(l.tunai) as tunai, SUM(l.qris) as qris, SUM(l.pencairan_qris) as pencairan_qris,
                SUM(l.grab_food) as grab_food, SUM(l.go_food) as go_food, SUM(l.total_omset) as penjualan,
                SUM(l.belanja_pasar) as belanja_pasar, SUM(l.belanja_sembako) as belanja_sembako,
                SUM(l.belanja_beras) as belanja_beras, SUM(l.belanja_toko) as belanja_toko,
                SUM(l.total_rutin) as total_rutin, SUM(l.sewa) as sewa, SUM(l.gaji) as gaji,
                SUM(l.listrik) as listrik, SUM(l.air) as air, SUM(l.sampah) as sampah,
                SUM(l.keamanan) as keamanan, SUM(l.internet) as internet, SUM(l.gas) as gas,
                SUM(l.mingguan_karyawan) as mingguan_karyawan, SUM(l.es_batu) as es_batu,
                SUM(l.bensin) as bensin, SUM(l.lain_lain) as lain_lain,
                SUM(l.total_operasional) as total_operasional, SUM(l.total_pengeluaran) as pengeluaran,
                SUM(l.sisa_tunai) as sisa_tunai, SUM(l.sisa_qris) as sisa_qris, SUM(l.net_profit) as laba_bersih
            FROM laporan_cabang l
            WHERE l.id_cabang = ? AND l.tanggal BETWEEN ? AND ? AND l.status_laporan = 'lengkap'
        ");
        $stmt->bind_param('iss', $id_cabang, $tgl_mulai, $tgl_selesai);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $penjualan   = (float) ($row['penjualan'] ?? 0);
        $pengeluaran = (float) ($row['pengeluaran'] ?? 0);
        $laba_bersih_dasar = (float) ($row['laba_bersih'] ?? ($penjualan - $pengeluaran));
        $margin = $penjualan > 0 ? ($laba_bersih_dasar / $penjualan) * 100 : 0;

        $bo_db = [
            'belanja_pasar' => $row['belanja_pasar'] ?? 0,
            'belanja_sembako' => $row['belanja_sembako'] ?? 0,
            'belanja_beras' => $row['belanja_beras'] ?? 0,
            'belanja_toko' => $row['belanja_toko'] ?? 0,
            'sewa' => $row['sewa'] ?? 0,
            'gaji' => $row['gaji'] ?? 0,
            'listrik' => $row['listrik'] ?? 0,
            'internet' => $row['internet'] ?? 0,
            'sampah' => $row['sampah'] ?? 0,
            'keamanan' => $row['keamanan'] ?? 0,
            'air' => $row['air'] ?? 0,
            'gas' => $row['gas'] ?? 0,
            'mingguan_karyawan' => $row['mingguan_karyawan'] ?? 0,
            'es_batu' => $row['es_batu'] ?? 0,
            'bensin' => $row['bensin'] ?? 0,
            'lain_lain' => $row['lain_lain'] ?? 0,
        ];

        // ----- Klaim Bulanan (segmen ini saja) -----
        // Catatan: klaim_bulanan disimpan per (id_cabang, tahun, bulan, urutan_pengelola) — bukan
        // per rentang tanggal segmen — jadi tahun/bulan diambil dari AKHIR rentang segmen (bulan
        // closing periode ini), konsisten dgn bagaimana revenue_sharing juga dikunci per tahun/bulan.
        $tahun_kb = (int) date('Y', strtotime($tgl_selesai));
        $bulan_kb = (int) date('n', strtotime($tgl_selesai));
        $stmt_kb = $conn->prepare("SELECT id, uraian, nominal, keterangan FROM klaim_bulanan WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ? ORDER BY urutan ASC, id ASC");
        $stmt_kb->bind_param('iiii', $id_cabang, $tahun_kb, $bulan_kb, $urutan_pengelola);
        $stmt_kb->execute();
        $daftar_klaim_bulanan = $stmt_kb->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_kb->close();
        $total_klaim_bulanan = 0.0;
        foreach ($daftar_klaim_bulanan as $kb) {
            $total_klaim_bulanan += (float) $kb['nominal'];
        }

        // ----- Keterangan Beban Operasional (segmen ini saja) -----
        $ket_bo = [];
        $stmt_ket = $conn->prepare("SELECT uraian_key, keterangan FROM beban_operasional_keterangan WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ?");
        $stmt_ket->bind_param('iiii', $id_cabang, $tahun_kb, $bulan_kb, $urutan_pengelola);
        $stmt_ket->execute();
        $res_ket = $stmt_ket->get_result();
        while ($rket = $res_ket->fetch_assoc()) {
            $ket_bo[$rket['uraian_key']] = $rket['keterangan'];
        }
        $stmt_ket->close();

        // ----- Revenue sharing: admin fee 3% tetap, dikurangi klaim, split 50/50 -----
        $persen_admin = 3;
        $persen_investor = 50;
        $persen_pengelola = 50;
        $share_admin = $laba_bersih_dasar * $persen_admin / 100;
        $laba_setelah_admin = $laba_bersih_dasar - $share_admin - $total_klaim_bulanan;
        $share_investor = $laba_setelah_admin * $persen_investor / 100;
        $share_pengelola = $laba_setelah_admin * $persen_pengelola / 100;

        // ----- PIC yang menginput/memfinalisasi laporan di rentang segmen ini -----
        $stmt_pic = $conn->prepare("
            SELECT GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS pic
            FROM laporan_cabang lc
            JOIN users u ON u.id = lc.id_user_laporan
            WHERE lc.id_cabang = ? AND lc.tanggal BETWEEN ? AND ?
              AND lc.status_laporan = 'lengkap' AND lc.id_user_laporan IS NOT NULL
        ");
        $stmt_pic->bind_param('iss', $id_cabang, $tgl_mulai, $tgl_selesai);
        $stmt_pic->execute();
        $nama_pic = $stmt_pic->get_result()->fetch_assoc()['pic'] ?? null;
        $stmt_pic->close();
        $nama_pic = $nama_pic ?: '-';

        return [
            'tahun_kb'             => $tahun_kb,
            'bulan_kb'             => $bulan_kb,
            'penjualan'            => $penjualan,
            'pengeluaran'          => $pengeluaran,
            'laba_bersih_dasar'    => $laba_bersih_dasar,
            'margin'               => $margin,
            'bo_db'                => $bo_db,
            'ket_bo'               => $ket_bo,
            'daftar_klaim_bulanan' => $daftar_klaim_bulanan,
            'total_klaim_bulanan'  => $total_klaim_bulanan,
            'persen_admin'         => $persen_admin,
            'persen_investor'      => $persen_investor,
            'persen_pengelola'     => $persen_pengelola,
            'share_admin'          => $share_admin,
            'laba_setelah_admin'   => $laba_setelah_admin,
            'share_investor'       => $share_investor,
            'share_pengelola'      => $share_pengelola,
            'nama_pic'             => $nama_pic,
        ];
    }
}
