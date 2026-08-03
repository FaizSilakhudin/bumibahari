<?php 
// PROTEKSI HALAMAN: Harus login dan role cabang
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'cabang'){ 
    header("Location:../login"); // TANPA .PHP
    exit; 
}

// Auto deteksi menu aktif berdasarkan nama file
$current_page = basename($_SERVER['PHP_SELF'], ".php"); 
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIMC-WBB</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            overflow-x: hidden;
        }

        /* --- STYLING UTAMA SIDEBAR --- */
        .sidebar-container {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1040;
            background: linear-gradient(135deg, #093922 0%, #052415 100%);
            box-shadow: 5px 0 25px rgba(5, 36, 21, 0.15);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
        }

        /* --- RESPONSIVITAS BREAKPOINTS --- */
        
        /* Desktop Luas (Layar Besar) */
        @media (min-width: 1200px) {
            .sidebar-container { width: 275px; }
            .content { margin-left: 275px; padding: 40px 35px; }
            .navbar-mobile-toggle, .btn-close-sidebar { display: none !important; }
        }

        /* Mode Tablet / Layar Menengah (Icon-only Sidebar otomatis) */
        @media (min-width: 992px) and (max-width: 1199.98px) {
            .sidebar-container { width: 85px; }
            .sidebar-container .sidebar-brand, 
            .sidebar-container .nav-link-custom span { display: none; }
            .sidebar-container .sidebar-header { justify-content: center !important; padding: 25px 0; }
            .sidebar-container .nav-link-custom { justify-content: center; padding: 14px 0; gap: 0; }
            .sidebar-container .nav-link-custom i { font-size: 1.4rem !important; }
            .content { margin-left: 85px; padding: 30px 20px; }
            .navbar-mobile-toggle, .btn-close-sidebar { display: none !important; }
        }

        /* Mode HP / Smartphone */
        @media (max-width: 991.98px) {
            .sidebar-container {
                width: 280px;
                transform: translateX(-100%);
            }
            .sidebar-container.show {
                transform: translateX(0);
            }
            .content {
                margin-left: 0;
                padding: 20px;
                padding-top: 85px; /* Jarak aman di bawah topbar mobile */
            }
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
                z-index: 1030;
                box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            }
        }

        /* --- BRANDING & LOGO --- */
        .sidebar-header {
            padding: 30px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        
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
            border: 2px solid rgba(255, 193, 7, 0.4);
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
            transition: all 0.2s ease;
            margin-bottom: 10px;
            cursor: pointer;
        }

        .nav-link-custom:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(4px);
        }

        /* Tablet hover state override (biar ga geser ke kanan kalau mode icon) */
        @media (min-width: 992px) and (max-width: 1199.98px) {
            .nav-link-custom:hover { transform: scale(1.08); }
        }

        .nav-link-custom.active {
            color: #ffffff;
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            box-shadow: 0 8px 20px rgba(25, 135, 84, 0.35);
            font-weight: 600;
        }

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

        /* Backdrop Gelap saat Sidebar Aktif di HP */
        .sidebar-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1035;
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
        }
    </style>
</head>
<body>

<!-- Topbar Ponsel -->
<div class="navbar-mobile-toggle justify-content-between align-items-center d-flex d-lg-none">
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

<!-- Backdrop Pelindung Konten Mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<!-- Komponen Sidebar Utama -->
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
        <button type="button" class="btn-close btn-close-white btn-close-sidebar" onclick="toggleSidebar()"></button>
    </div>
    
    <div class="sidebar-menu flex-grow-1">
        <nav class="nav flex-column">
            <a class="nav-link-custom <?=($current_page=='input_data')?'active':''?>" href="input_data">
                <i class="bi bi-clipboard-plus fs-5"></i> 
                <span>Input Data Harian</span>
            </a>
            
            <a class="nav-link-custom <?=($current_page=='riwayat')?'active':''?>" href="riwayat">
                <i class="bi bi-clock-history fs-5"></i> 
                <span>Riwayat Data</span>
            </a>

            <a class="nav-link-custom nav-link-logout" href="../logout">
                <i class="bi bi-box-arrow-right fs-5"></i> 
                <span>Keluar Aplikasi</span>
            </a>
        </nav>
    </div>
</div>

<!-- Area Konten Utama -->
<div class="content">

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebarContainer');
    const backdrop = document.getElementById('sidebarBackdrop');
    
    if (sidebar.classList.contains('show')) {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
        setTimeout(() => { backdrop.style.display = 'none'; }, 300);
    } else {
        backdrop.style.display = 'block';
        setTimeout(() => {
            sidebar.classList.add('show');
            backdrop.classList.add('show');
        }, 10);
    }
}
</script>