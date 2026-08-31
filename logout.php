<?php
require 'config/koneksi.php'; // biar session_start nya dari sini

// Catat aktivitas logout sebelum sesi dihapus.
if (!empty($_SESSION['user_id'])) {
    audit($conn, 'logout', 'users', $_SESSION['user_id'], ['username' => $_SESSION['username'] ?? null]);
}

// 1. Kosongkan semua data session
$_SESSION = [];

// 2. Hapus cookie session di browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Hancurkan session
session_destroy();

header("Location: login"); // TANPA.PHP
exit;
?>