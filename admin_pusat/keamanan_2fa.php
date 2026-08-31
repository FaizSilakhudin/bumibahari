<?php
require '../config/koneksi.php';
require_role('pusat');

$uid = current_user_id();

// =====================================================================
// HANDLER POST (semua sebelum include sidebar — butuh header redirect)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header('Location: keamanan_2fa');
        exit;
    }

    // --- Mulai setup: buat secret baru, simpan sementara di session ---
    if (isset($_POST['mulai_setup'])) {
        $_SESSION['2fa_setup_secret'] = totp_generate_secret();
        header('Location: keamanan_2fa?setup=1');
        exit;
    }

    // --- Aktifkan: verifikasi kode dari secret sementara, lalu simpan permanen ---
    if (isset($_POST['aktifkan'])) {
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        $kode = trim($_POST['kode'] ?? '');

        if (empty($secret) || !totp_verify($secret, $kode)) {
            $_SESSION['error'] = 'Kode salah. Pastikan waktu di HP Anda akurat, lalu coba lagi.';
            header('Location: keamanan_2fa?setup=1');
            exit;
        }

        $backup = totp_generate_backup_codes(8);
        $backup_json = json_encode($backup['hashed']);

        $stmt = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ? WHERE id = ?");
        $stmt->bind_param('ssi', $secret, $backup_json, $uid);
        $stmt->execute();
        $stmt->close();

        unset($_SESSION['2fa_setup_secret']);
        $_SESSION['2fa_backup_codes_tampil'] = $backup['plain']; // ditampilkan sekali saja
        audit($conn, 'user_2fa_aktifkan', 'users', $uid, ['username' => current_username()]);

        header('Location: keamanan_2fa?aktif=1');
        exit;
    }

    // --- Nonaktifkan: wajib konfirmasi password ---
    if (isset($_POST['nonaktifkan'])) {
        $pw = $_POST['password'] ?? '';
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $hash = $stmt->get_result()->fetch_assoc()['password'] ?? '';
        $stmt->close();

        if (!password_verify($pw, $hash)) {
            $_SESSION['error'] = 'Password salah, 2FA tidak dinonaktifkan.';
            header('Location: keamanan_2fa');
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();

        audit($conn, 'user_2fa_nonaktifkan', 'users', $uid, ['username' => current_username()]);
        $_SESSION['success'] = '2FA berhasil dinonaktifkan.';
        header('Location: keamanan_2fa');
        exit;
    }
}

$stmt = $conn->prepare("SELECT username, totp_enabled FROM users WHERE id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

$mode_setup = isset($_GET['setup']) && empty($me['totp_enabled']);
$baru_aktif = isset($_GET['aktif']) && !empty($_SESSION['2fa_backup_codes_tampil']);
$setup_secret = $_SESSION['2fa_setup_secret'] ?? '';
$setup_uri = $setup_secret ? totp_provisioning_uri($setup_secret, $me['username']) : '';

include 'sidebar_pusat.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559; }
    .saas-card { background: #fff; border: none !important; border-radius: 20px !important; box-shadow: 0 18px 40px rgba(112,144,176,.06) !important; padding: 28px; max-width: 640px; }
    .title-mark { width: 12px; height: 12px; background: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }
    .btn-premium { background: #4318ff !important; color: #fff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .secret-box { font-family: ui-monospace, monospace; font-size: 1.1rem; letter-spacing: .15rem; background: #f8f9fc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 14px; text-align: center; user-select: all; }
    .backup-code { font-family: ui-monospace, monospace; font-size: .95rem; background: #f8f9fc; border-radius: 8px; padding: 8px 12px; text-align: center; }
    .kode-input { text-align: center; font-size: 1.4rem; letter-spacing: .3rem; font-weight: 700; border-radius: 10px; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center mb-1">
        <span class="title-mark"></span>
        <h3 class="fw-bold mb-0">Keamanan Akun — Verifikasi 2 Langkah</h3>
    </div>
    <span class="text-muted small ms-4 d-block mb-4">Lapis keamanan tambahan untuk akun <?= h($me['username']) ?> selain password.</span>

    <?php if ($baru_aktif): ?>
        <div class="card saas-card mb-4">
            <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><b>2FA berhasil diaktifkan!</b></div>
            <p class="fw-semibold mb-2">Simpan 8 kode cadangan ini di tempat aman. Setiap kode cuma bisa dipakai <b>satu kali</b> kalau HP Anda hilang/rusak dan tidak bisa akses aplikasi authenticator.</p>
            <div class="row g-2 mb-3">
                <?php foreach ($_SESSION['2fa_backup_codes_tampil'] as $c): ?>
                    <div class="col-6"><div class="backup-code"><?= h($c) ?></div></div>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i> Kode ini hanya ditampilkan sekali. Screenshot atau catat sekarang.</div>
        </div>
        <?php unset($_SESSION['2fa_backup_codes_tampil']); ?>

    <?php elseif ($mode_setup): ?>
        <div class="card saas-card mb-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-qr-code me-2"></i>Langkah 1 — Tambahkan ke Aplikasi Authenticator</h5>
            <p class="text-muted small">Buka Google Authenticator / Authy / Microsoft Authenticator → tambah akun baru → pilih "Masukkan kunci setup secara manual" → isi seperti berikut:</p>
            <p class="small fw-semibold mb-1">Kunci Rahasia:</p>
            <div class="secret-box mb-3"><?= h(chunk_split($setup_secret, 4, ' ')) ?></div>
            <p class="small text-muted">Nama akun: <b>SIMC-WBB:<?= h($me['username']) ?></b> &middot; Jenis: Time-based (TOTP)</p>

            <hr class="my-3">
            <h5 class="fw-bold mb-3"><i class="bi bi-key-fill me-2"></i>Langkah 2 — Masukkan Kode dari Aplikasi</h5>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="text" name="kode" class="form-control kode-input mb-3" maxlength="6" inputmode="numeric" placeholder="000000" required autofocus>
                <div class="d-flex gap-2">
                    <button type="submit" name="aktifkan" class="btn btn-premium flex-fill">Aktifkan 2FA</button>
                    <a href="keamanan_2fa" class="btn btn-light border">Batal</a>
                </div>
            </form>
        </div>

    <?php elseif (!empty($me['totp_enabled'])): ?>
        <div class="card saas-card mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill fs-6"><i class="bi bi-shield-check me-1"></i> 2FA Aktif</span>
            </div>
            <p class="text-muted">Akun ini dilindungi kode 6 digit setiap login, selain password.</p>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalNonaktif">
                <i class="bi bi-shield-x me-1"></i> Nonaktifkan 2FA
            </button>
        </div>

    <?php else: ?>
        <div class="card saas-card mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="badge bg-warning-subtle text-warning px-3 py-2 rounded-pill fs-6"><i class="bi bi-shield-exclamation me-1"></i> 2FA Belum Aktif</span>
            </div>
            <p class="text-muted">Aktifkan supaya akun pusat ini butuh kode dari HP Anda, bukan cuma password, setiap login.</p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button type="submit" name="mulai_setup" class="btn btn-premium"><i class="bi bi-shield-plus me-1"></i> Aktifkan 2FA</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL NONAKTIFKAN -->
<div class="modal fade" id="modalNonaktif" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <div class="modal-header"><h5 class="modal-title fw-bold">Nonaktifkan 2FA</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted small">Masukkan password Anda untuk konfirmasi.</p>
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="nonaktifkan" class="btn btn-danger">Nonaktifkan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if (isset($_SESSION['error'])): ?>
Swal.fire({ icon: 'error', title: 'Gagal', text: <?= json_encode($_SESSION['error']) ?> });
<?php unset($_SESSION['error']); endif; ?>
<?php if (isset($_SESSION['success'])): ?>
Swal.fire({ icon: 'success', title: 'Berhasil', text: <?= json_encode($_SESSION['success']) ?>, timer: 2500, showConfirmButton: false });
<?php unset($_SESSION['success']); endif; ?>
</script>
