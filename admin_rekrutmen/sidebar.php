<?php
// PROTEKSI HALAMAN: Harus login dan role rekrutmen
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'rekrutmen') {
    header("Location:../login");
    exit;
}

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
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f6; overflow-x: hidden; }

        .sidebar-container {
            height: 100vh; position: fixed; top: 0; left: 0; z-index: 1040;
            background: linear-gradient(135deg, #0f766e 0%, #042f2e 100%);
            box-shadow: 5px 0 25px rgba(4, 47, 46, 0.15);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; flex-direction: column;
        }

        @media (min-width: 1200px) {
            .sidebar-container { width: 275px; }
            .content { margin-left: 275px; padding: 40px 35px; }
            .navbar-mobile-toggle, .btn-close-sidebar { display: none !important; }
        }
        @media (min-width: 992px) and (max-width: 1199.98px) {
            .sidebar-container { width: 85px; }
            .sidebar-container .sidebar-brand, .sidebar-container .nav-link-custom span { display: none; }
            .sidebar-container .sidebar-header { justify-content: center !important; padding: 25px 0; }
            .sidebar-container .nav-link-custom { justify-content: center; padding: 14px 0; gap: 0; }
            .sidebar-container .nav-link-custom i { font-size: 1.4rem !important; }
            .content { margin-left: 85px; padding: 30px 20px; }
            .navbar-mobile-toggle, .btn-close-sidebar { display: none !important; }
        }
        @media (max-width: 991.98px) {
            .sidebar-container { width: 280px; transform: translateX(-100%); }
            .sidebar-container.show { transform: translateX(0); }
            .content { margin-left: 0; padding: 20px; padding-top: 85px; }
            .navbar-mobile-toggle {
                position: fixed; top: 0; left: 0; right: 0; height: 65px;
                background: linear-gradient(135deg, #0f766e 0%, #042f2e 100%);
                display: flex; align-items: center; padding: 0 20px; z-index: 1030;
                box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            }
        }

        .sidebar-header { padding: 30px 24px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
        .logo-wbb-img {
            height: 45px; width: auto; max-width: 45px; object-fit: contain;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.35)); flex-shrink: 0;
        }
        .sidebar-brand { color: #ffffff; font-weight: 700; font-size: 1.1rem; letter-spacing: 0.3px; line-height: 1.2; margin: 0; }

        .sidebar-menu { padding: 25px 16px; }
        .nav-link-custom {
            display: flex; align-items: center; gap: 14px; padding: 14px 18px;
            color: rgba(153, 246, 228, 0.8); font-weight: 500; font-size: 0.95rem;
            text-decoration: none; border-radius: 12px; transition: all 0.2s ease;
            margin-bottom: 10px; cursor: pointer;
        }
        .nav-link-custom:hover { color: #ffffff; background: rgba(255, 255, 255, 0.06); transform: translateX(4px); }
        @media (min-width: 992px) and (max-width: 1199.98px) { .nav-link-custom:hover { transform: scale(1.08); } }
        .nav-link-custom.active {
            color: #ffffff; background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
            box-shadow: 0 8px 20px rgba(13, 148, 136, 0.35); font-weight: 600;
        }
        .nav-link-logout { color: #ffca2c; border: 1px solid rgba(255, 202, 44, 0.15); background: rgba(255, 202, 44, 0.02); margin-top: 30px; }
        .nav-link-logout:hover { color: #ffffff; background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%); border-color: transparent; box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3); }

        .sidebar-backdrop {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background-color: rgba(0, 0, 0, 0.5); z-index: 1035; display: none; opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-backdrop.show { display: block; opacity: 1; }
    </style>
</head>
<body>

<div class="navbar-mobile-toggle justify-content-between align-items-center d-flex d-lg-none">
    <div class="d-flex align-items-center gap-2">
        <img src="../assets/img/wbb.png?v=2" alt="Logo" class="logo-wbb-img" style="height: 36px; max-width: 36px;" onerror="this.style.display='none'; this.parentNode.insertAdjacentHTML('beforeend', '<i class=\'bi bi-person-lines-fill text-warning fs-6\'></i>')">
        <span class="text-white fw-bold" style="font-size: 0.95rem; letter-spacing: 0.5px;">Warteg Bumi Bahari</span>
    </div>
    <button class="btn btn-warning border-0 p-2" type="button" onclick="toggleSidebar()" style="background: rgba(255,255,255,0.15); border-radius: 10px;">
        <i class="bi bi-list fs-4 text-white"></i>
    </button>
</div>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<div class="sidebar-container" id="sidebarContainer">
    <div class="sidebar-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <img id="logoWWB" src="../assets/img/wbb.png?v=2" alt="Logo WBB" class="logo-wbb-img" onerror="this.style.display='none'; this.parentNode.insertAdjacentHTML('beforeend', '<i class=\'bi bi-person-lines-fill text-dark fs-5\'></i>')">
            <h5 class="sidebar-brand">
                Bumi Bahari
                <span style="color: #5eead4; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; margin-top: 2px; letter-spacing: 1px; display: block;">Admin Rekrutmen</span>
            </h5>
        </div>
        <button type="button" class="btn-close btn-close-white btn-close-sidebar" onclick="toggleSidebar()"></button>
    </div>

    <div class="sidebar-menu flex-grow-1">
        <nav class="nav flex-column">
            <a class="nav-link-custom <?=($current_page=='index')?'active':''?>" href="index">
                <i class="bi bi-person-lines-fill fs-5"></i>
                <span>Data Calon Pengelola</span>
            </a>

            <a class="nav-link-custom nav-link-logout" href="../logout">
                <i class="bi bi-box-arrow-right fs-5"></i>
                <span>Keluar Aplikasi</span>
            </a>
        </nav>
    </div>
</div>

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
        setTimeout(() => { sidebar.classList.add('show'); backdrop.classList.add('show'); }, 10);
    }
}
</script>
