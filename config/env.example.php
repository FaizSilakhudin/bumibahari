<?php
/**
 * Salin file ini menjadi  config/env.php  untuk memaksa mode lingkungan.
 *
 *   'development'  -> error ditampilkan (untuk XAMPP/lokal)
 *   'production'   -> error disembunyikan & dicatat ke log (untuk hosting)
 *
 * Kalau config/env.php TIDAK ada:
 *   - akses dari 127.0.0.1 / ::1  -> otomatis 'development'
 *   - selain itu                  -> otomatis 'production'
 */
return 'production';
