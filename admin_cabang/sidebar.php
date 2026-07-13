<?php if($_SESSION['role']!= 'cabang'){ header("Location:../login.php"); exit; }?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Cabang - Warteg Bumi Bahari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6; /* Latar belakang aplikasi lebih bersih & soft */
        }

        /* --- DESKTOP VIEW (Lebih dari 992px) --- */
        @media (min-width: 992px) {
            .sidebar-container {
                width: 275px; /* Disesuaikan agar penempatan teks dan logo seimbang */
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1000;
                /* Gradasi linear gelap emerald eksklusif */
                background: linear-gradient(135deg, #093922 0%, #052415 100%);
                box-shadow: 5px 0 25px rgba(5, 36, 21, 0.15);
            }
            .content {
                margin-left: 275px;
                padding: 40px 35px;
                min-height: 100vh;
            }
            .navbar-mobile-toggle {
                display: none !important; /* Sembunyikan topbar mobile di komputer */
            }
        }

        /* --- MOBILE VIEW (Kurang dari 992px) --- */
        @media (max-width: 991.98px) {
            .sidebar-container {
                position: fixed;
                bottom: 0;
                z-index: 1045;
                display: flex;
                flex-direction: column;
                max-width: 100%;
                visibility: hidden;
                background: linear-gradient(180deg, #093922 0%, #052415 100%);
                background-clip: padding-box;
                outline: 0;
                transition: transform .35s cubic-bezier(0.4, 0, 0.2, 1);
                width: 290px;
                left: 0;
                top: 0;
                transform: translateX(-100%);
                box-shadow: 10px 0 30px rgba(0,0,0,0.3);
            }
            .sidebar-container.show {
                visibility: visible;
                transform: none;
            }
            .content {
                margin-left: 0;
                padding: 20px;
                padding-top: 85px; /* Jarak aman agar konten tidak terpotong Topbar HP */
            }
        }

        /* --- BRANDING LOGO WBB STYLE --- */
        .sidebar-header {
            padding: 30px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        
        /* Bingkai Lingkaran Logo */
        .logo-wbb-container {
            width: 45px;
            height: 45px;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            padding: 2px;
            border: 2px solid rgba(255, 193, 7, 0.4); /* Border aksen emas tipis */
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .logo-wbb-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-brand {
            color: #ffffff;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.3px;
            line-height: 1.2;
            margin: 0;
        }

        /* --- NAVIGATION LINK DESIGN --- */
        .sidebar-menu {
            padding: 25px 16px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: rgba(163, 207, 187, 0.8);
            font-weight: 500;
            font-size: 0.95rem;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            margin-bottom: 10px;
        }

        /* Efek Hover Soft Glassmorphism */
        .nav-link-custom:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.1);
            transform: translateX(6px);
        }

        /* Tampilan Menu Ketika Aktif/Dipilih */
        .nav-link-custom.active {
            color: #ffffff;
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            box-shadow: 0 8px 20px rgba(25, 135, 84, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            font-weight: 600;
        }

        /* Modifikasi Tombol Keluar / Logout */
        .nav-link-logout {
            color: #ffca2c;
            border: 1px solid rgba(255, 202, 44, 0.15);
            background: rgba(255, 202, 44, 0.02);
            margin-top: 30px;
        }
        
        .nav-link-logout:hover {
            color: #ffffff;
            background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%);
            border-color: transparent;
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        /* Topbar Khusus Mobile (Header HP) */
        .navbar-mobile-toggle {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 65px;
            background: linear-gradient(135deg, #093922 0%, #052415 100%);
            display: flex;
            align-items: center;
            padding: 0 20px;
            z-index: 999;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>

<div class="navbar-mobile-toggle justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <div class="logo-wbb-container" style="width: 36px; height: 36px;">
            <img src="../assets/img/wbb.png" alt="Logo" class="logo-wbb-img" onerror="this.style.display='none'; this.parentNode.insertAdjacentHTML('beforeend', '<i class=\'bi bi-shop text-success fs-6\'></i>')">
        </div>
        <span class="text-white fw-bold" style="font-size: 0.95rem; letter-spacing: 0.5px;">Warteg Bumi Bahari</span>
    </div>
    <button class="btn btn-success border-0 p-2" type="button" onclick="toggleSidebar()" style="background: rgba(255,255,255,0.12); border-radius: 10px;">
        <i class="bi bi-list fs-4 text-white"></i>
    </button>
</div>

<div class="sidebar-container" id="sidebarContainer">
    <div class="sidebar-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <div class="logo-wbb-container">
                <img src="../assets/img/wbb.png" alt="Logo WBB" class="logo-wbb-img" onerror="this.style.display='none'; this.parentNode.insertAdjacentHTML('beforeend', '<i class=\'bi bi-shop text-dark fs-5\'></i>')">
            </div>
            <h5 class="sidebar-brand">
                Bumi Bahari
                <span style="color: #ffca2c; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; margin-top: 2px; letter-spacing: 1px; display: block;">Admin Cabang</span>
            </h5>
        </div>
        <button type="button" class="btn-close btn-close-white d-lg-none" onclick="toggleSidebar()"></button>
    </div>
    
    <div class="sidebar-menu flex-grow-1">
        <nav class="nav flex-column">
            <a class="nav-link-custom active" href="input_data.php">
                <i class="bi bi-clipboard-plus fs-5"></i> 
                <span>Input Data Harian</span>
            </a>
            
            <a class="nav-link-custom nav-link-logout" href="../logout.php">
                <i class="bi bi-box-arrow-right fs-5"></i> 
                <span>Keluar Aplikasi</span>
            </a>
        </nav>
    </div>
</div>

<div class="modal-backdrop fade show d-lg-none d-none" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<div class="content">

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebarContainer');
    const backdrop = document.getElementById('sidebarBackdrop');
    
    sidebar.classList.toggle('show');
    backdrop.classList.toggle('d-none');
}
</script>