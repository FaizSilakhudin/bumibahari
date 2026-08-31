<?php
/**
 * Rumus perhitungan laporan keuangan harian cabang — SATU-SATUNYA sumber
 * kebenaran untuk rumus ini. Sebelumnya rumus ini disalin manual di
 * admin_pic/input_laporan.php dan admin_pusat/laporan.php (modal koreksi),
 * sehingga risiko dua rumus diam-diam berbeda setelah diedit terpisah.
 *
 * JANGAN ubah rumus di sini tanpa menjalankan tests/keuangan_test.php —
 * itu jaring pengaman supaya perubahan tidak diam-diam merusak angka
 * yang sudah dipakai production.
 */

if (!function_exists('bersihkan_angka')) {
    // Dipakai cabang/PIC: hanya digit, tidak menerima minus (nilai dari uang fisik/QRIS selalu >= 0).
    function bersihkan_angka($val): int
    {
        return (int) preg_replace('/[^0-9]/', '', (string) ($val ?? ''));
    }
}

if (!function_exists('bersihkan_angka_koreksi')) {
    // Dipakai pusat di form "Koreksi Laporan Harian": boleh minus untuk penyesuaian manual,
    // dan titik/koma pemisah ribuan dibuang lebih dulu (nilai form sudah terformat mask-money).
    function bersihkan_angka_koreksi($val): int
    {
        $nilai = str_replace(['.', ','], '', (string) ($val ?? ''));
        $nilai = preg_replace('/[^0-9\-]/', '', $nilai);
        return (int) $nilai;
    }
}

if (!function_exists('hitung_laporan_harian')) {
    /**
     * @param array $in Nilai mentah (dari $_POST atau sumber lain), field per field:
     *   tunai, qris, grab_food, go_food, pencairan_qris,
     *   belanja_pasar, belanja_sembako, belanja_beras, belanja_toko,
     *   sewa, gaji, listrik, air, sampah, keamanan, internet, gas,
     *   mingguan_karyawan, es_batu, bensin, lain_lain
     * @param callable $bersihkan Fungsi pembersih satu nilai mentah -> int.
     *   Default bersihkan_angka() (tanpa minus). Pusat pakai bersihkan_angka_koreksi().
     * @return array Nilai bersih (int) + hasil hitung (int/float), field per field:
     *   tunai, qris, grab_food, go_food, pencairan_qris, total_omset,
     *   belanja_pasar, belanja_sembako, belanja_beras, belanja_toko, total_rutin,
     *   sewa, gaji, listrik, air, sampah, keamanan, internet, gas,
     *   mingguan_karyawan, es_batu, bensin, lain_lain, total_operasional,
     *   total_pengeluaran, sisa_tunai, sisa_qris, net_profit, persentase
     */
    function hitung_laporan_harian(array $in, ?callable $bersihkan = null): array
    {
        $bersihkan = $bersihkan ?? 'bersihkan_angka';

        $tunai          = $bersihkan($in['tunai'] ?? 0);
        $qris           = $bersihkan($in['qris'] ?? 0);
        $grab           = $bersihkan($in['grab_food'] ?? 0);
        $go             = $bersihkan($in['go_food'] ?? 0);
        $pencairan_qris = $bersihkan($in['pencairan_qris'] ?? 0);

        $total_omset = $tunai + $qris + $grab + $go - $pencairan_qris;

        $pasar   = $bersihkan($in['belanja_pasar'] ?? 0);
        $sembako = $bersihkan($in['belanja_sembako'] ?? 0);
        $beras   = $bersihkan($in['belanja_beras'] ?? 0);
        $toko    = $bersihkan($in['belanja_toko'] ?? 0);
        $total_rutin = $pasar + $sembako + $beras + $toko;

        $sewa              = $bersihkan($in['sewa'] ?? 0);
        $gaji              = $bersihkan($in['gaji'] ?? 0);
        $listrik           = $bersihkan($in['listrik'] ?? 0);
        $air               = $bersihkan($in['air'] ?? 0);
        $sampah            = $bersihkan($in['sampah'] ?? 0);
        $keamanan          = $bersihkan($in['keamanan'] ?? 0);
        $internet          = $bersihkan($in['internet'] ?? 0);
        $gas               = $bersihkan($in['gas'] ?? 0);
        $mingguan_karyawan = $bersihkan($in['mingguan_karyawan'] ?? 0);
        $es_batu           = $bersihkan($in['es_batu'] ?? 0);
        $bensin            = $bersihkan($in['bensin'] ?? 0);
        $lain              = $bersihkan($in['lain_lain'] ?? 0);
        $total_op = $sewa + $gaji + $listrik + $air + $sampah + $keamanan + $internet
            + $gas + $mingguan_karyawan + $es_batu + $bensin + $lain;

        $total_pengeluaran = $total_rutin + $total_op;
        $sisa_tunai = $tunai - $total_pengeluaran;
        $sisa_qris  = $qris - $pencairan_qris;
        $net = $sisa_qris + $sisa_tunai + $go + $grab;
        $persen = $total_omset > 0 ? round(($net / $total_omset) * 100, 2) : 0;

        return [
            'tunai' => $tunai, 'qris' => $qris, 'grab_food' => $grab, 'go_food' => $go,
            'pencairan_qris' => $pencairan_qris, 'total_omset' => $total_omset,

            'belanja_pasar' => $pasar, 'belanja_sembako' => $sembako,
            'belanja_beras' => $beras, 'belanja_toko' => $toko, 'total_rutin' => $total_rutin,

            'sewa' => $sewa, 'gaji' => $gaji, 'listrik' => $listrik, 'air' => $air,
            'sampah' => $sampah, 'keamanan' => $keamanan, 'internet' => $internet, 'gas' => $gas,
            'mingguan_karyawan' => $mingguan_karyawan, 'es_batu' => $es_batu,
            'bensin' => $bensin, 'lain_lain' => $lain, 'total_operasional' => $total_op,

            'total_pengeluaran' => $total_pengeluaran,
            'sisa_tunai' => $sisa_tunai,
            'sisa_qris' => $sisa_qris,
            'net_profit' => $net,
            'persentase' => $persen,
        ];
    }
}
