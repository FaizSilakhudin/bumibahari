<?php 
// PROTEKSI HALAMAN: Harus login dan role pusat
if(!isset($_SESSION['role']) || $_SESSION['role'] != 'pusat'){ 
    header("Location: ../login"); // TANPA .PHP
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
        :root{
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(59, 130, 246, 0.15);
            --sidebar-active: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
            --text-muted: #94a3b8;
            --content-bg: #f8fafc;
        }
        
        *{font-family: 'Inter', sans-serif;}
        
        body{background: var(--content-bg);}
        
        .sidebar{
            width: 260px; 
            height: 100vh; 
            position: fixed; 
            left: 0; 
            top: 0;
            background: var(--sidebar-bg);
            border-right: 1px solid rgba(255,255,255,0.05);
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-brand{
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            margin-bottom: 16px;
        }
        
        .sidebar-brand h5{
            font-weight: 700;
            font-size: 18px;
            letter-spacing: -0.5px;
        }
        
        .sidebar-brand small{
            color: var(--text-muted);
            font-size: 12px;
        }
        
        .nav-link{
            color: var(--text-muted);
            padding: 12px 16px;
            margin: 4px 12px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
        }
        
        .nav-link i{
            font-size: 18px;
            width: 24px;
        }
        
        .nav-link:hover{
            color: #fff;
            background: var(--sidebar-hover);
            transform: translateX(4px);
        }
        
        .nav-link.active{
            color: #fff;
            background: var(--sidebar-active);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .nav-section{
            padding: 20px 24px 8px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
        }
        
        .content{
            margin-left: 260px; 
            padding: 32px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        .logout-link{
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 16px;
        }
        
        .logout-link .nav-link:hover{
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px){
            .sidebar{
                transform: translateX(-100%);
            }
            .sidebar.show{
                transform: translateX(0);
            }
            .content{
                margin-left: 0;
                padding: 20px 16px;
            }
            .btn-toggle-sidebar{
                position: fixed;
                top: 16px;
                left: 16px;
                z-index: 1001;
            }
        }
        
        /* Scrollbar cantik */
        .sidebar::-webkit-scrollbar{width: 6px;}
        .sidebar::-webkit-scrollbar-track{background: transparent;}
        .sidebar::-webkit-scrollbar-thumb{background: rgba(255,255,255,0.1); border-radius: 3px;}
        .sidebar::-webkit-scrollbar-thumb:hover{background: rgba(255,255,255,0.2);}
    </style>
</head>
<body>

<button class="btn btn-primary d-md-none btn-toggle-sidebar" onclick="document.querySelector('.sidebar').classList.toggle('show')">
    <i class="bi bi-list"></i>
</button>

<div class="sidebar d-flex flex-column">
    <div class="sidebar-brand text-center">
        <img id="logoWWB" src="../assets/img/wbb.png" alt="Logo WBB" class="img-fluid mb-2" style="max-height: 65px; object-fit: contain;">
        <h5 class="text-white mb-1">Warteg Bumi Bahari</h5>
        <small class="d-block">Sistem Informasi Manajemen Cabang</small>
    </div>
    
    <div class="nav-section">Menu Utama</div>
    <ul class="nav flex-column flex-grow-1">
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='index')?'active':''?>" href="index"> <!-- 1. TANPA .PHP -->
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='data_user')?'active':''?>" href="data_user"> <!-- 2. TANPA .PHP -->
                <i class="bi bi-buildings-fill"></i> Data User
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='data_cabang')?'active':''?>" href="data_cabang"> <!-- 2. TANPA .PHP -->
                <i class="bi bi-buildings-fill"></i> Data Cabang
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='data_pengelola')?'active':''?>" href="data_pengelola"> <!-- 3. TANPA .PHP -->
                <i class="bi bi-people-fill"></i> Data Pengelola
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='data_investor')?'active':''?>" href="data_investor"> <!-- 4. TANPA .PHP -->
                <i class="bi bi-person-badge-fill"></i> Data Investor
            </a>
        </li>
        <div class="nav-section">Laporan</div>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='laporan')?'active':''?>" href="laporan">
                <i class="bi bi-file-earmark-text-fill"></i> Laporan Harian
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?=($current_page=='laporan_mingguan')?'active':''?>" href="laporan_mingguan">
                <i class="bi bi-calendar-week-fill"></i> Laporan Mingguan
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?=($current_page=='rekapitulasi')?'active':''?>" href="rekapitulasi">
                <i class="bi bi-graph-up-arrow"></i> Rekapitulasi
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?=($current_page=='backup_laporan')?'active':''?>" href="backup_laporan">
                <i class="bi bi-life-preserver"></i> Backup Laporan
            </a>
        </li>

        <div class="nav-section">Sistem</div>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='simulasi')?'active':''?>" href="simulasi">
                <i class="bi bi-calculator-fill"></i> Simulasi Pendapatan
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='audit_log')?'active':''?>" href="audit_log">
                <i class="bi bi-shield-lock-fill"></i> Log Aktivitas
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='arsip_laporan')?'active':''?>" href="arsip_laporan">
                <i class="bi bi-archive-fill"></i> Arsip Laporan Terhapus
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?=($current_page=='keamanan_2fa')?'active':''?>" href="keamanan_2fa">
                <i class="bi bi-shield-lock-fill"></i> Keamanan (2FA)
            </a>
        </li>
    </ul>
    
    <div class="logout-link">
        <a class="nav-link text-danger" href="../logout"> <!-- 7. TANPA .PHP -->
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</div>

<?php include '../config/notifikasi_bell.php'; ?>

<div class="content">