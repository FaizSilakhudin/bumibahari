<?php
require 'config/koneksi.php';

// Kalau sudah login penuh, tidak perlu di sini.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . role_home($_SESSION['role'] ?? ''));
    exit;
}

$MAKS_PERCOBAAN = 5;
$KADALUARSA_DETIK = 300; // 5 menit

$pending_uid = $_SESSION['pending_2fa_uid'] ?? null;
$pending_at  = $_SESSION['pending_2fa_at'] ?? 0;

if (empty($pending_uid) || (time() - $pending_at) > $KADALUARSA_DETIK) {
    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_tries']);
    header('Location: login');
    exit;
}

if (isset($_GET['batal'])) {
    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_tries']);
    header('Location: login');
    exit;
}

$error = '';

if (isset($_POST['verifikasi'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Sesi tidak valid. Silakan refresh halaman.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $pending_uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $kode = trim($_POST['kode'] ?? '');
        $valid = false;
        $pakai_backup = false;

        if ($user && !empty($user['totp_enabled'])) {
            if (preg_match('/^\d{6}$/', $kode)) {
                $valid = totp_verify($user['totp_secret'], $kode);
            } elseif ($kode !== '') {
                // Coba sebagai kode cadangan (format XXXX-XXXX).
                $backup_codes = json_decode($user['totp_backup_codes'] ?? '[]', true) ?: [];
                foreach ($backup_codes as $i => $hash) {
                    if (password_verify($kode, $hash)) {
                        $valid = true;
                        $pakai_backup = true;
                        unset($backup_codes[$i]); // sekali pakai
                        $upd = $conn->prepare("UPDATE users SET totp_backup_codes = ? WHERE id = ?");
                        $new_json = json_encode(array_values($backup_codes));
                        $upd->bind_param('si', $new_json, $user['id']);
                        $upd->execute();
                        $upd->close();
                        break;
                    }
                }
            }
        }

        if ($valid) {
            unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_tries']);
            finalize_login($conn, $user);
            if ($pakai_backup) {
                audit($conn, 'login_2fa_pakai_backup', 'users', $user['id'], ['username' => $user['username']]);
            }
            header('Location: ' . role_home($user['role']));
            exit;
        }

        $_SESSION['pending_2fa_tries'] = ($_SESSION['pending_2fa_tries'] ?? 0) + 1;
        audit($conn, 'login_2fa_gagal', 'users', $pending_uid, []);

        if ($_SESSION['pending_2fa_tries'] >= $MAKS_PERCOBAAN) {
            unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_tries']);
            header('Location: login?gagal_2fa=1');
            exit;
        }

        $error = 'Kode salah. Coba lagi.';
        usleep(500000);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi 2FA — SIMC-WBB</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card-2fa { width: 100%; max-width: 420px; background: #fff; border-radius: 20px; box-shadow: 0 15px 35px rgba(52,58,64,.08); padding: 40px; }
        .icon-2fa { width: 64px; height: 64px; border-radius: 50%; background: #eef2ff; color: #4318ff; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 16px; }
        .kode-input { text-align: center; font-size: 1.6rem; letter-spacing: .4rem; font-weight: 700; border-radius: 10px; border: 1px solid #cbd5e1; height: 56px; }
        .kode-input:focus { border-color: #4318ff; box-shadow: 0 0 0 4px rgba(67,24,255,.1); }
        .btn-verify { height: 48px; background: #4318ff; border-color: #4318ff; font-weight: 600; border-radius: 10px; }
        .btn-verify:hover { background: #3310cc; border-color: #3310cc; }
    </style>
</head>
<body>
<div class="card-2fa text-center">
    <div class="icon-2fa"><i class="bi bi-shield-lock-fill"></i></div>
    <h4 class="fw-bold mb-1">Verifikasi 2 Langkah</h4>
    <p class="text-muted small mb-4">Masukkan kode 6 digit dari aplikasi authenticator Anda.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger small mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="text" name="kode" class="form-control kode-input mb-3" maxlength="9" inputmode="numeric"
               placeholder="000000" autofocus autocomplete="one-time-code" required>
        <button type="submit" name="verifikasi" class="btn btn-primary btn-verify w-100 mb-3">Verifikasi</button>
    </form>

    <p class="small text-muted mb-1">Kehilangan akses ke aplikasi authenticator? Masukkan salah satu kode cadangan Anda di kolom yang sama.</p>
    <a href="verify_2fa?batal=1" class="small text-decoration-none">&larr; Batal, kembali ke login</a>
</div>
</body>
</html>
