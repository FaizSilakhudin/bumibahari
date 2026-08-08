<?php
require 'config/koneksi.php';

$error = '';
if(isset($_POST['login'])){
    if(!csrf_check($_POST['csrf']?? '')){ 
        $error = "Sesi tidak valid. Silakan refresh halaman.";
    } else {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        // 1. PAKAI PREPARED STATEMENT
        $stmt = $conn->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if($user && password_verify($password, $user['password'])){
            // 2. ANTI SESSION FIXATION
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['id_cabang'] = $user['id_cabang'];
            $_SESSION['nama_pengelola'] = $user['nama_pengelola'];
            
            if($user['role'] == 'pusat'){
                header("Location: admin_pusat/index"); // TANPA .PHP
            } else {
                header("Location: admin_cabang/input_data"); // TANPA .PHP
            }
            exit;
        } else {
            $error = "Username atau password salah!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIMC-WBB</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(52, 58, 64, 0.08);
            transition: transform 0.3s ease;
        }

        .logo-wrapper {
            width: 72px;
            height: 72px;
            background: #f8fafc;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px auto;
            border: 2px solid #e2e8f0;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
            overflow: hidden;
        }

        .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .brand-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.5px;
        }

        .brand-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-group-custom .form-control {
            padding-left: 42px;
            padding-right: 42px; /* BARU: kasih ruang buat icon mata */
            height: 48px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
            color: #334155;
            background-color: #f8fafc;
            transition: all 0.2s ease;
        }

        .input-group-custom .form-control:focus {
            background-color: #ffffff;
            border-color: #343a40;
            box-shadow: 0 0 0 4px rgba(52, 58, 64, 0.1);
        }

        .input-group-custom .input-icon {
            position: absolute;
            left: 15px;
            color: #94a3b8;
            font-size: 1.1rem;
            z-index: 4;
            display: flex;
            align-items: center;
        }

        .input-group-custom .toggle-password {
            position: absolute;
            right: 15px;
            left: auto;
            color: #94a3b8;
            font-size: 1.1rem;
            z-index: 4;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .input-group-custom .toggle-password:hover {
            color: #343a40;
        }

        .btn-login {
            height: 48px;
            background-color: #343a40;
            border-color: #343a40;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.95rem;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .btn-login:hover {
            background-color: #212529;
            border-color: #212529;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(33, 37, 41, 0.15);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert-custom {
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 12px;
            border: none;
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .footer-text {
            font-size: 0.775rem;
            color: #94a3b8;
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="card login-card border-0">
        <div class="card-body p-4 p-sm-5">
            
            <div class="text-center mb-4">
                <div class="logo-wrapper">
                    <img id="logoWWB" src="assets/img/wbb.png" alt="Logo WBB" onerror="this.src='https://placehold.co/100x100?text=WBB'">
                </div>
                <h3 class="brand-title mb-1">WARTEG BUMI BAHARI</h3>
                <p class="brand-subtitle mb-0">Budaya Kuliner Indonesia</p>
            </div>
            
            <?php if($error):?>
                <div class="alert alert-custom d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div><?= h($error)?></div> <!-- XSS -->
                </div>
            <?php endif;?>
            
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf" value="<?=csrf_token()?>"> <!-- CSRF -->
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Masukkan username admin" required autofocus>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control" placeholder="••••" required>
                        <span class="toggle-password" onclick="togglePassword()">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                </div>
                
                <button type="submit" name="login" class="btn btn-login w-100 d-flex align-items-center justify-content-center gap-2">
                    <span>Masuk ke Sistem</span>
                    <i class="bi bi-arrow-right-short fs-5"></i>
                </button>
            </form>
            
        </div>
    </div>
    
    <div class="footer-text">
        &copy; 2026 Warteg Bumi Bahari. All Rights Reserved.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
    const passInput = document.getElementById('password');
    const icon = document.getElementById('toggleIcon');
    
    if (passInput.type === 'password') {
        passInput.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        passInput.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>
</body>
</html>