<?php
/**
 * TOTP (RFC 6238) — otentikasi dua faktor tanpa dependensi eksternal.
 * Kompatibel dengan Google Authenticator, Authy, Microsoft Authenticator, dll.
 */

if (!function_exists('totp_generate_secret')) {
    // Secret base32, 20 byte (160-bit) — standar untuk TOTP.
    function totp_generate_secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }
}

if (!function_exists('totp_base32_decode')) {
    function totp_base32_decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                break;
            }
            $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }
}

if (!function_exists('totp_code')) {
    function totp_code(string $secret_base32, ?int $time = null, int $digits = 6, int $period = 30): string
    {
        $time = $time ?? time();
        $key = totp_base32_decode($secret_base32);
        $counter = intdiv($time, $period);
        $bin_counter = pack('N*', 0, $counter); // 8-byte big-endian counter (32-bit PHP-safe: hi word always 0)
        $hash = hash_hmac('sha1', $bin_counter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $code = (string) ($truncated % (10 ** $digits));
        return str_pad($code, $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totp_verify')) {
    // $window = toleransi geser waktu (±1 = terima kode 30 detik sebelum/sesudah).
    function totp_verify(string $secret_base32, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(totp_code($secret_base32, $now + ($i * 30)), $code)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('totp_provisioning_uri')) {
    // URI otpauth:// — dipakai untuk QR code manual atau link "buka di app authenticator".
    function totp_provisioning_uri(string $secret_base32, string $account, string $issuer = 'SIMC-WBB'): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret_base32)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}

if (!function_exists('totp_generate_backup_codes')) {
    /** @return array{plain: string[], hashed: string[]} */
    function totp_generate_backup_codes(int $jumlah = 8): array
    {
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $kode = strtoupper(bin2hex(random_bytes(4))); // 8 karakter hex
            $kode = substr($kode, 0, 4) . '-' . substr($kode, 4, 4);
            $plain[] = $kode;
            $hashed[] = password_hash($kode, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hashed' => $hashed];
    }
}
