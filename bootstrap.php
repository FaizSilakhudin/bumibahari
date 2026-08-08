<?php

require __DIR__ . '/vendor/autoload.php';

use Core\Database;

Database::boot();

echo "Database berhasil terhubung!";