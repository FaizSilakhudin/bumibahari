<?php
/**
 * Jaring pengaman otomatis untuk rumus laporan keuangan harian.
 * Tidak butuh PHPUnit/Composer — cukup PHP CLI biasa.
 *
 * Jalankan sebelum & sesudah mengubah apa pun di config/keuangan.php,
 * admin_pic/input_laporan.php, atau admin_pusat/laporan.php:
 *
 *     C:\xampp\php\php.exe tests\keuangan_test.php
 *
 * Exit code 0 = semua lolos. Exit code 1 = ada rumus yang berubah/rusak.
 */

require __DIR__ . '/../config/keuangan.php';

$lolos = 0;
$gagal = 0;

function assert_sama($label, $aktual, $harapan)
{
    global $lolos, $gagal;
    if ($aktual == $harapan) {
        $lolos++;
        echo "  OK   $label\n";
    } else {
        $gagal++;
        echo "  GAGAL $label — dapat: " . var_export($aktual, true) . ", harusnya: " . var_export($harapan, true) . "\n";
    }
}

function kasus($nama, array $input, array $harapan, ?callable $bersihkan = null)
{
    global $gagal;
    echo "[$nama]\n";
    $hasil = hitung_laporan_harian($input, $bersihkan);
    foreach ($harapan as $field => $nilai) {
        assert_sama($field, $hasil[$field], $nilai);
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// 1. Kasus normal — dipakai juga saat smoke-test manual di server dev
//    (tunai 1.000.000, qris 500.000, grab 100.000, go 50.000, pencairan 200.000,
//     belanja pasar 300.000, sewa 50.000).
// ---------------------------------------------------------------------------
kasus('Kasus normal harian', [
    'tunai' => 1000000, 'qris' => 500000, 'grab_food' => 100000, 'go_food' => 50000,
    'pencairan_qris' => 200000, 'belanja_pasar' => 300000, 'sewa' => 50000,
], [
    'total_omset' => 1450000,
    'total_rutin' => 300000,
    'total_operasional' => 50000,
    'total_pengeluaran' => 350000,
    'sisa_tunai' => 650000,
    'sisa_qris' => 300000,
    'net_profit' => 1100000,
    'persentase' => 75.86,
]);

// ---------------------------------------------------------------------------
// 2. Semua nol — tidak boleh division by zero, persentase harus 0.
// ---------------------------------------------------------------------------
kasus('Semua nol', [], [
    'total_omset' => 0,
    'total_pengeluaran' => 0,
    'sisa_tunai' => 0,
    'net_profit' => 0,
    'persentase' => 0,
]);

// ---------------------------------------------------------------------------
// 3. Pengeluaran lebih besar dari tunai — sisa & net boleh negatif.
// ---------------------------------------------------------------------------
kasus('Rugi (pengeluaran > tunai)', [
    'tunai' => 100000, 'belanja_pasar' => 200000,
], [
    'total_omset' => 100000,
    'total_pengeluaran' => 200000,
    'sisa_tunai' => -100000,
    'net_profit' => -100000,
    'persentase' => -100.0,
]);

// ---------------------------------------------------------------------------
// 4. Input berformat (titik ribuan / string dari form) harus tetap bersih.
// ---------------------------------------------------------------------------
kasus('Input berformat titik ribuan', [
    'tunai' => '1.000.000', 'qris' => '500.000',
], [
    'tunai' => 1000000,
    'qris' => 500000,
    'total_omset' => 1500000,
]);

// ---------------------------------------------------------------------------
// 5. Pencairan QRIS mengurangi omset & sisa QRIS, tapi TIDAK dihitung dua
//    kali (harus persis satu kali di total_omset, satu kali di sisa_qris).
// ---------------------------------------------------------------------------
kasus('Pencairan QRIS tidak dobel hitung', [
    'qris' => 1000000, 'pencairan_qris' => 1000000,
], [
    'total_omset' => 0, // qris 1jt masuk omset lalu dikurangi pencairan 1jt -> net 0
    'sisa_qris' => 0,
]);

// ---------------------------------------------------------------------------
// 6. Form koreksi pusat (laporan.php) pakai bersihkan_angka_koreksi — HARUS
//    tetap menerima minus untuk penyesuaian manual. Ini pernah jadi kapabilitas
//    yang gampang hilang tanpa sengaja saat rumus disatukan — makanya dites.
// ---------------------------------------------------------------------------
kasus('Form koreksi pusat menerima minus', [
    'tunai' => '-50.000', 'belanja_pasar' => '100.000',
], [
    'tunai' => -50000,
    'total_omset' => -50000,
    'total_pengeluaran' => 100000,
    'sisa_tunai' => -150000,
], 'bersihkan_angka_koreksi');

echo "==============================\n";
echo "Total: $lolos lolos, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
