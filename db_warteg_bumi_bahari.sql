-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 02, 2026 at 01:48 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_warteg_bumi_bahari`
--

-- --------------------------------------------------------

--
-- Table structure for table `cabang`
--

CREATE TABLE `cabang` (
  `id_cabang` int(11) NOT NULL,
  `nama_cabang` varchar(100) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `no_telp` varchar(20) NOT NULL,
  `nama_pengelola` varchar(20) NOT NULL,
  `investor` varchar(100) NOT NULL,
  `no_rekening` varchar(50) DEFAULT NULL,
  `nama_bank` varchar(50) DEFAULT NULL,
  `id_investor` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cabang`
--

INSERT INTO `cabang` (`id_cabang`, `nama_cabang`, `alamat`, `no_telp`, `nama_pengelola`, `investor`, `no_rekening`, `nama_bank`, `id_investor`) VALUES
(2, 'WBB Jakarta Pusat', 'Jakarta Pusat', '087882834313', 'Faiz', 'Suharto', '876128681263', 'BRI', 2),
(3, 'WBB Jakarta Utara', 'Jakarta Utara', '087882831122', 'Dendi', 'Jokowi', '763276186238913', 'BCA', 3),
(4, 'WBB Jakarta Selatan', 'Jakarta Selatan', '087882833344', 'Yusuf', 'Jokowi', '923646643498', 'MANDIRI', 2),
(5, 'WBB Jakarta Barat', 'Jakarta Barat', '08788285566', 'Adnan', 'Prabowo', '987394729374931', 'BNI', 4),
(6, 'WBB Jakarta Timur', 'Jakarta Timur', '08788288899', 'Fahril', 'Prabowo', '87398477491739479', 'BRI', 3),
(7, 'WBB Bekasi', 'Bekasi', '0878828766', 'Rizki', '', NULL, NULL, 3),
(8, 'brebes', 'brebes', '34532525', 'Faka', '', NULL, NULL, 2);

-- --------------------------------------------------------

--
-- Table structure for table `investor`
--

CREATE TABLE `investor` (
  `id_investor` int(11) NOT NULL,
  `nama_investor` varchar(100) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `no_rekening` varchar(30) DEFAULT NULL,
  `nama_bank` varchar(50) DEFAULT NULL,
  `surat_perjanjian` varchar(255) DEFAULT NULL,
  `status` enum('aktif','nonaktif') DEFAULT 'aktif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `investor`
--

INSERT INTO `investor` (`id_investor`, `nama_investor`, `no_hp`, `email`, `alamat`, `no_rekening`, `nama_bank`, `surat_perjanjian`, `status`) VALUES
(2, 'Jokowi', '9837497434', NULL, 'Slawi, Tegal', '9873373974', 'BNI', 'SP_6a6e226949871.pdf', 'aktif'),
(3, 'Gibran', '9873847334', NULL, 'Solo, Pria solooo', '92739473', 'MANDIRI', 'SP_1781793966.jpeg', 'aktif'),
(4, 'Prabowo', '9878797', NULL, 'brebes', '325425', 'BNI', 'SP_6a6e23dcd898b.pdf', 'aktif'),
(5, 'Bahlil', '76868585', NULL, 'tegal', '76217691', 'BRI', 'SP_6a6e3015f0169.pdf', 'aktif');

-- --------------------------------------------------------

--
-- Table structure for table `laporan_cabang`
--

CREATE TABLE `laporan_cabang` (
  `id` int(11) NOT NULL,
  `id_cabang` int(11) DEFAULT NULL,
  `nama_pengelola` varchar(100) DEFAULT NULL,
  `tanggal` date DEFAULT curdate(),
  `tunai` int(11) DEFAULT 0,
  `qris` int(11) DEFAULT 0,
  `grab_food` int(11) DEFAULT 0,
  `go_food` int(11) DEFAULT 0,
  `total_omset` int(11) DEFAULT 0,
  `belanja_pasar` int(11) DEFAULT 0,
  `belanja_sembako` int(11) DEFAULT 0,
  `belanja_beras` int(11) DEFAULT 0,
  `belanja_toko` int(11) DEFAULT 0,
  `total_rutin` int(11) DEFAULT 0,
  `sewa` int(11) DEFAULT 0,
  `gaji` int(11) DEFAULT 0,
  `listrik` int(11) DEFAULT 0,
  `air` int(11) DEFAULT 0,
  `sampah` int(11) DEFAULT 0,
  `keamanan` int(11) DEFAULT 0,
  `internet` int(11) DEFAULT 0,
  `lain_lain` int(11) DEFAULT 0,
  `total_operasional` int(11) DEFAULT 0,
  `total_pengeluaran` int(11) DEFAULT 0,
  `sisa_tunai` int(11) DEFAULT 0,
  `net_profit` int(11) DEFAULT 0,
  `persentase` decimal(5,2) DEFAULT 0.00,
  `keterangan` text DEFAULT NULL,
  `foto_nota1` varchar(255) DEFAULT NULL,
  `foto_nota2` varchar(255) DEFAULT NULL,
  `foto_nota3` varchar(255) DEFAULT NULL,
  `foto_nota4` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `laporan_cabang`
--

INSERT INTO `laporan_cabang` (`id`, `id_cabang`, `nama_pengelola`, `tanggal`, `tunai`, `qris`, `grab_food`, `go_food`, `total_omset`, `belanja_pasar`, `belanja_sembako`, `belanja_beras`, `belanja_toko`, `total_rutin`, `sewa`, `gaji`, `listrik`, `air`, `sampah`, `keamanan`, `internet`, `lain_lain`, `total_operasional`, `total_pengeluaran`, `sisa_tunai`, `net_profit`, `persentase`, `keterangan`, `foto_nota1`, `foto_nota2`, `foto_nota3`, `foto_nota4`, `created_at`) VALUES
(1, 5, 'Adnan Nur Fadhilah', '2026-06-16', 10000000, 5000000, 0, 0, 15000000, 500000, 500000, 300000, 200000, 1500000, 450000, 350000, 150000, 50000, 30000, 120000, 150000, 250000, 1550000, 3050000, 6950000, 11950000, 79.67, 'gas, minyak, bumbu', '1781622368_1.jpeg', '1781622368_2.jpeg', '1781622368_3.jpeg', '1781622368_4.jpeg', '2026-06-16 15:06:08'),
(2, 2, 'Faiz Silakhudin', '2026-06-16', 17500000, 300000, 0, 0, 17800000, 2500000, 450000, 650000, 530000, 4130000, 250000, 400000, 50000, 30000, 50000, 100000, 150000, 430000, 1460000, 5590000, 11910000, 12210000, 68.60, 'sabun, gas, galon, plastik, bensin', '1781622625_1.png', '1781622625_2.jpeg', '1781622625_3.jpeg', '1781622625_4.jpg', '2026-06-16 15:10:25'),
(3, 2, 'Faiz Silakhudin', '2026-06-20', 23000000, 500000, 350000, 750000, 24600000, 3500000, 700000, 650000, 450000, 5300000, 200000, 450000, 100000, 50000, 30000, 100000, 150000, 750000, 1830000, 7130000, 15870000, 17470000, 71.02, 'gas, galon, bensin', '1781969951_1.jpeg', '1781969951_2.jpeg', '', '', '2026-06-20 15:39:11'),
(4, 2, 'Faiz Silakhudin', '2026-06-21', 25000000, 550000, 0, 0, 25550000, 1540000, 350000, 450000, 600000, 2940000, 400000, 400000, 150000, 50000, 30000, 100000, 150000, 350000, 1630000, 4570000, 20430000, 20980000, 82.11, 'gas, galon, bensin', '1782039247_1.jpeg', '', '1782039247_3.jpeg', '', '2026-06-21 10:54:07'),
(5, 2, 'Faiz Silakhudin', '2026-06-29', 10000000, 500000, 0, 0, 10500000, 1000000, 500000, 400000, 250000, 2150000, 100000, 300000, 50000, 30000, 30000, 50000, 150000, 450000, 1160000, 3310000, 6690000, 7190000, 68.48, 'gas, bensin, kertas nasi', '1782735054_1.jpeg', '1782735054_2.png', '', '', '2026-06-29 12:10:54'),
(6, 2, 'Faiz Silakhudin', '2026-06-30', 15000000, 750000, 0, 0, 15750000, 2500000, 450000, 300000, 350000, 3600000, 200000, 250000, 50000, 20000, 30000, 100000, 100000, 250000, 1000000, 4600000, 10400000, 11150000, 70.79, '', '', '', '', '', '2026-06-30 16:47:27'),
(7, 2, 'Faiz Silakhudin', '2026-07-14', 7000000, 850000, 0, 0, 7850000, 250000, 150000, 400000, 100000, 900000, 200000, 250000, 30000, 30000, 10000, 50000, 50000, 150000, 770000, 1670000, 5330000, 6180000, 78.73, 'gas, air, bensin', '1784032977_1.jpeg', '1784032977_2.png', '', '', '2026-07-14 12:42:57'),
(8, 2, 'Faiz Silakhudin', '2026-07-15', 17000000, 0, 0, 0, 17000000, 500000, 0, 0, 0, 500000, 557754, 0, 0, 0, 0, 0, 0, 0, 557754, 1057754, 15942246, 15942246, 93.78, 'gas', '6a5670cec5408_1.png', '', '', '', '2026-07-14 17:24:30'),
(9, 5, 'Adnan Nur Fadhilah', '2026-07-15', 15000000, 0, 0, 0, 15000000, 500000, 0, 0, 0, 500000, 557754, 0, 0, 0, 0, 0, 0, 0, 557754, 1057754, 13942246, 13942246, 92.95, 'gas', '6a567154d7698_1.png', '', '', '', '2026-07-14 17:26:44'),
(17, 5, 'Adnan Nur Fadhilah', '2026-08-01', 10000000, 0, 0, 0, 10000000, 299999, 0, 0, 0, 299999, 288888, 0, 0, 0, 0, 0, 0, 0, 288888, 588887, 9411113, 9411113, 94.11, 'gas', '6a6e20c17c121_1.jpg', '', '', '', '2026-08-01 16:15:16'),
(22, 8, 'Faka', '2026-08-02', 5000000, 0, 0, 0, 5000000, 600000, 0, 0, 0, 600000, 600000, 0, 0, 0, 0, 0, 0, 0, 600000, 1200000, 3800000, 3800000, 76.00, 'gas', '6a6e3120cac6b_1.jpg', '', '', '', '2026-08-01 17:47:12');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `nama_pengelola` varchar(100) DEFAULT NULL,
  `no_rekening` varchar(50) DEFAULT NULL,
  `nama_bank` varchar(50) DEFAULT NULL,
  `role` enum('pusat','cabang') DEFAULT 'cabang',
  `id_cabang` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `login_attempts`, `lock_until`, `nama_pengelola`, `no_rekening`, `nama_bank`, `role`, `id_cabang`) VALUES
(2, 'adminpusat', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 0, '2026-07-14 23:05:00', 'Admin Pusat', NULL, NULL, 'pusat', NULL),
(3, 'faizsilakhudin', '$2y$10$BCsBhhukb5vmkSxHrgFwI.L4T88nssxfD9afjm10rqefW/SSWZriy', 0, NULL, 'Faiz Silakhudin', '45447477', 'BRI', 'cabang', 2),
(4, 'fahrilabadi', '$2y$10$UhssZsGlt8mBgzjPDIJLf.uODfwIoLJK2NHkyG1TCeA2XGoHfJNq.', 0, NULL, 'Fahril Abadi', '65577887', 'BNI', 'cabang', 6),
(6, 'adnannur', '$2y$10$AertwKeKZFm4PnaQylYoS.dMYFLIhcx2L77qFpnp2ob73k4jX3LMm', 0, NULL, 'Adnan Nur Fadhilah', '76567474', 'MANDIRI', 'cabang', 5),
(8, 'yusuf', '$2y$10$kGTruM/vR4DrymbkyuYmF.i9lcmv2F36CYkh7rYC2oH99G5Yzc5IO', 0, NULL, 'Yusuf', '873646374', 'BRI', 'cabang', 4),
(9, 'faka', '$2y$10$mMMfZ7wqssuf06S3x5LF7uMxlezV4rMU7yGr0OG2h1toP0KwbUGtq', 0, NULL, 'Faka', '6557597', 'BNI', 'cabang', 8),
(10, 'dendi', '$2y$10$Rzz3CeXjCuypXJb/mlVFsONMGUkQoO3q7rfCTbet6eVshkDB8ONR.', 0, NULL, 'Dendi', '234311', 'BNI', 'cabang', 3);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cabang`
--
ALTER TABLE `cabang`
  ADD PRIMARY KEY (`id_cabang`),
  ADD KEY `id_investor` (`id_investor`);

--
-- Indexes for table `investor`
--
ALTER TABLE `investor`
  ADD PRIMARY KEY (`id_investor`);

--
-- Indexes for table `laporan_cabang`
--
ALTER TABLE `laporan_cabang`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_cabang_tanggal` (`id_cabang`,`tanggal`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `id_cabang` (`id_cabang`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cabang`
--
ALTER TABLE `cabang`
  MODIFY `id_cabang` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `investor`
--
ALTER TABLE `investor`
  MODIFY `id_investor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `laporan_cabang`
--
ALTER TABLE `laporan_cabang`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cabang`
--
ALTER TABLE `cabang`
  ADD CONSTRAINT `cabang_ibfk_1` FOREIGN KEY (`id_investor`) REFERENCES `investor` (`id_investor`) ON DELETE SET NULL;

--
-- Constraints for table `laporan_cabang`
--
ALTER TABLE `laporan_cabang`
  ADD CONSTRAINT `laporan_cabang_ibfk_1` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
