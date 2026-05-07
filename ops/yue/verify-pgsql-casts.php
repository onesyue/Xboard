<?php

// Verify that numeric and boolean PostgreSQL columns exposed by Eloquent
// models have explicit casts. This avoids PDO_pgsql string hydration leaking
// into JSON responses and breaking numeric rendering in the admin frontend.

$root = getenv('APP_ROOT') ?: dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$missing = [];

foreach (glob($root . '/app/Models/*.php') as $file) {
    $base = basename($file, '.php');
    $class = "App\\Models\\{$base}";

    if (!class_exists($class)) {
        continue;
    }

    try {
        $model = new $class();
    } catch (Throwable) {
        continue;
    }

    if (!method_exists($model, 'getTable')) {
        continue;
    }

    $table = $model->getTable();
    $columns = Illuminate\Support\Facades\DB::select(
        "select column_name, data_type from information_schema.columns where table_schema = 'public' and table_name = ? order by ordinal_position",
        [$table]
    );

    if (!$columns) {
        continue;
    }

    $casts = $model->getCasts();

    foreach ($columns as $column) {
        $name = $column->column_name;
        $type = strtolower($column->data_type);
        $isNumeric = str_contains($type, 'integer')
            || str_contains($type, 'numeric')
            || str_contains($type, 'double')
            || str_contains($type, 'real')
            || str_contains($type, 'boolean');

        if (!$isNumeric || $name === 'id') {
            continue;
        }

        if (!array_key_exists($name, $casts)) {
            $missing[$base . ' -> ' . $table][] = $name . ':' . $type;
        }
    }
}

if ($missing) {
    echo "[yue] PG numeric cast verification FAILED\n";
    echo json_encode($missing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    exit(1);
}

echo "[yue] PG numeric cast verification OK\n";
