<?php

namespace Core;

use Illuminate\Database\Capsule\Manager as Capsule;

class Database
{
    public static function boot(): void
    {
        $config = require __DIR__ . '/../config/database.php';

        $capsule = new Capsule();

        $capsule->addConnection($config);

        $capsule->setAsGlobal();

        $capsule->bootEloquent();
    }
}