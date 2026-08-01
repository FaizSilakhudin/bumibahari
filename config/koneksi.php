<?php
session_start(); // 1. WAJIB PALING ATAS

date_default_timezone_set('Asia/Jakarta'); // 2. Waktu WIB

// 3. KONEKSI DB
$conn = mysqli_connect("localhost","root","","db_warteg_bumi_bahari");
if(!$conn){ 
    die("Koneksi gagal: ".mysqli_connect_error()); 
}

// 4. SET CHARSET BIAR GAK ERROR KARAKTER
mysqli_set_charset($conn, "utf8mb4");

// 5. FUNCTION ANTI XSS GLOBAL
function h($string){
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// 6. FUNCTION CSRF TOKEN
function csrf_token(){
    if(empty($_SESSION['csrf'])){
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check($token){
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// 7. MATIKAN ERROR SAAT DI HOSTING. Biar gak bocor info DB
// error_reporting(0); // aktifkan ini kalau udah online
// ini_set('display_errors', 0);
?>