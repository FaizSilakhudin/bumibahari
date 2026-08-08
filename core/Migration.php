<?php

namespace Core;

use Illuminate\Database\Capsule\Manager as Capsule;

class Migration
{
    public static function run(): void
    {
        echo "Running migrations...\n";

        $migrationPath = __DIR__ . '/../app/Database/Migrations';

        $files = glob($migrationPath . '/*.php');

        sort($files);

        foreach ($files as $file) {
            echo "Migrating: " . basename($file) . "\n";

            $migration = require $file;

            $migration->up();
        }

        echo "\nMigration completed successfully.\n";
    }
}