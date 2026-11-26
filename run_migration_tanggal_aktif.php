<?php
// Script migrasi satu kali — jalankan via browser, lalu HAPUS file ini.
// Akses: https://domain-anda/database/run_migration_tanggal_aktif.php?token=wbb2026migrate

if (($_GET['token'] ?? '') !== 'wbb2026migrate') {
    http_response_code(403);
    exit('Akses ditolak.');
}

require __DIR__ . '/../config/koneksi.php';

$steps = [];
$ok    = true;

// Langkah 1: tambah kolom tanggal_aktif
$cek = $conn->query("SHOW COLUMNS FROM `cabang` LIKE 'tanggal_aktif'");
if ($cek && $cek->num_rows > 0) {
    $steps[] = ['skip', 'Kolom tanggal_aktif sudah ada — dilewati.'];
} else {
    $r = $conn->query("ALTER TABLE `cabang` ADD COLUMN `tanggal_aktif` DATE DEFAULT NULL AFTER `tipe_outlet`");
    if ($r) {
        $steps[] = ['ok', 'ALTER TABLE cabang ADD COLUMN tanggal_aktif DATE — berhasil.'];
    } else {
        $steps[] = ['err', 'ALTER TABLE gagal: ' . $conn->error];
        $ok = false;
    }
}

// Langkah 2: set tanggal_aktif untuk Ciledug
if ($ok || true) {
    $cek2 = $conn->query("SELECT id_cabang, nama_cabang FROM `cabang` WHERE nama_cabang LIKE '%CILEDUG%'");
    if ($cek2 && $cek2->num_rows > 0) {
        $rows = [];
        while ($r = $cek2->fetch_assoc()) $rows[] = $r;
        $r2 = $conn->query("UPDATE `cabang` SET `tanggal_aktif` = '2026-11-25' WHERE nama_cabang LIKE '%CILEDUG%' AND (tanggal_aktif IS NULL OR tanggal_aktif != '2026-11-25')");
        if ($r2) {
            foreach ($rows as $row) {
                $steps[] = ['ok', "SET tanggal_aktif = 2026-11-25 untuk #{$row['id_cabang']} {$row['nama_cabang']} — {$conn->affected_rows} baris diupdate."];
            }
        } else {
            $steps[] = ['err', 'UPDATE Ciledug gagal: ' . $conn->error];
        }
    } else {
        $steps[] = ['warn', 'Cabang CILEDUG tidak ditemukan di database.'];
    }
}

// Tampilkan hasil
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Migration: tanggal_aktif</title>
<style>body{font-family:monospace;padding:24px;background:#0f1f16;color:#e2f3e9;}
h2{color:#2f9e5c;}
.ok{color:#4ade80;}.err{color:#f87171;}.warn{color:#fbbf24;}.skip{color:#94a3b8;}
pre{background:#152a1e;padding:14px;border-radius:8px;border-left:4px solid #2f9e5c;}</style>
</head><body>
<h2>Migration: cabang.tanggal_aktif</h2>
<pre><?php
foreach ($steps as [$type, $msg]) {
    $icon = $type === 'ok' ? '✓' : ($type === 'err' ? '✗' : ($type === 'warn' ? '⚠' : '—'));
    echo "<span class=\"$type\">$icon $msg</span>\n";
}
?></pre>
<p style="color:#94a3b8;font-size:.85rem;">
  <?= $ok ? '✓ Selesai. <strong style="color:#f87171;">Hapus file ini sekarang!</strong>' : '✗ Ada error — periksa log.' ?>
</p>
<p style="color:#64748b;font-size:.8rem;">Hapus: <code>database/run_migration_tanggal_aktif.php</code></p>
</body></html>
