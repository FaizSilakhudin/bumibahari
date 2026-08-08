<?php

namespace Core;

class Generator
{
    /**
     * Membuat migration
     */
    public static function migration(string $name): void
    {
        $directory = __DIR__ . '/../app/Database/Migrations';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $timestamp = date('YmdHis');

        $filename = $timestamp . '_' . $name . '.php';

        $path = $directory . '/' . $filename;

        $content = <<<PHP
<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return new class
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};

PHP;

        file_put_contents($path, $content);

        echo "Migration created successfully:\n";
        echo "  {$path}\n";
    }


    /**
     * Membuat model
     */
    public static function model(string $name): void
    {
        $directory = __DIR__ . '/../app/Models';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory . '/' . $name . '.php';

        if (file_exists($path)) {
            echo "Model already exists: {$name}\n";
            return;
        }

        $tableName = strtolower($name) . 's';

        $content = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$name} extends Model
{
    protected \$table = '{$tableName}';

    protected \$fillable = [];
}

PHP;

        file_put_contents($path, $content);

        echo "Model created successfully:\n";
        echo "  {$path}\n";
    }

    public static function modelWithMigration(string $name): void
    {
        self::model($name);

        $tableName = strtolower($name) . 's';

        self::migration("create_{$tableName}_table");
    }
}
