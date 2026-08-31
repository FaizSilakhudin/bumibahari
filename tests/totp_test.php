<?php
/**
 * Jaring pengaman untuk implementasi TOTP (2FA). Jalankan:
 *     C:\xampp\php\php.exe tests\totp_test.php
 */

require __DIR__ . '/../config/totp.php';

$lolos = 0;
$gagal = 0;

function cek($label, $aktual, $harapan)
{
    global $lolos, $gagal;
    if ($aktual === $harapan) {
        $lolos++;
        echo "  OK   $label\n";
    } else {
        $gagal++;
        echo "  GAGAL $label — dapat: " . var_export($aktual, true) . ", harusnya: " . var_export($harapan, true) . "\n";
    }
}

// RFC 6238 Appendix B — test vector resmi (SHA1, 6 digit = 8 digit mod 10^6).
echo "[RFC 6238 test vector resmi]\n";
$rfc_secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 dari ASCII "12345678901234567890"
cek('T=59  -> 287082', totp_code($rfc_secret, 59), '287082');
cek('T=1111111109 -> 081804', totp_code($rfc_secret, 1111111109), '081804');
cek('T=1234567890 -> 005924', totp_code($rfc_secret, 1234567890), '005924');
echo "\n";

echo "[Generate secret & verify round-trip]\n";
$secret = totp_generate_secret();
cek('Panjang secret = 32 karakter base32', strlen($secret), 32);
$kode_sekarang = totp_code($secret);
cek('Kode barusan lolos verifikasi', totp_verify($secret, $kode_sekarang), true);
cek('Kode salah ditolak', totp_verify($secret, '000000'), false);
cek('Format bukan 6 digit ditolak', totp_verify($secret, 'abcdef'), false);
echo "\n";

echo "[Backup codes]\n";
$bc = totp_generate_backup_codes(8);
cek('Jumlah backup code = 8', count($bc['plain']), 8);
cek('Kode plain cocok dengan hash-nya', password_verify($bc['plain'][0], $bc['hashed'][0]), true);
cek('Kode plain lain TIDAK cocok dengan hash berbeda', password_verify($bc['plain'][1], $bc['hashed'][0]), false);

echo "==============================\n";
echo "Total: $lolos lolos, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
