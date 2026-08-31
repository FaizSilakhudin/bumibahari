<?php
/**
 * Template kredensial database.
 *
 * Salin file ini menjadi  config/db_credentials.php  (TIDAK di-commit),
 * lalu isi dengan user least-privilege 'wbb_app' (lihat database/README.md).
 *
 * Bila config/db_credentials.php tidak ada, aplikasi memakai root tanpa
 * password — hanya untuk pengembangan lokal.
 */
return [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'db_bumi_bahari',
];
