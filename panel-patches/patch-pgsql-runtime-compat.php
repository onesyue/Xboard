<?php
// PostgreSQL runtime compatibility patch for files that must be edited inside the XBoard container.
// Idempotent. Run via: docker compose exec -T web php /www/patch-pgsql-runtime-compat.php

function patch_file(string $path, callable $fn): void {
    if (!file_exists($path)) {
        echo basename($path) . " missing, skip\n";
        return;
    }
    $src = file_get_contents($path);
    $out = $fn($src);
    if ($out !== $src) {
        file_put_contents($path, $out);
        echo basename($path) . " patched\n";
    } else {
        echo basename($path) . " already patched\n";
    }
}

patch_file('/www/app/Support/AbstractProtocol.php', function (string $s): string {
    $s = str_replace(
        "\$requiredVersion !== '0.0.0' && version_compare(\$this->clientVersion, \$requiredVersion, '<')",
        "\$requiredVersion !== '0.0.0' && \$this->clientVersion !== null && version_compare(\$this->clientVersion, \$requiredVersion, '<')",
        $s
    );
    $s = str_replace(
        "version_compare(\$this->clientVersion, \$minVersion, '<')",
        "version_compare(\$this->clientVersion ?? '0.0.0', \$minVersion, '<')",
        $s
    );
    return $s;
});

patch_file('/www/app/Console/Commands/BackupDatabase.php', function (string $s): string {
    if (strpos($s, "config('database.default') === 'pgsql'") !== false) {
        return $s;
    }
    $needle = <<<'TXT'
            }elseif(config('database.default') === 'sqlite'){
                $databaseBackupPath = storage_path('backup/' .  now()->format('Y-m-d_H-i-s') . '_sqlite'  . '_database_backup.sql');
                $this->info("1️⃣：开始备份Sqlite");
                \Spatie\DbDumper\Databases\Sqlite::create()
                    ->setDbName(config('database.connections.sqlite.database'))
                    ->dumpToFile($databaseBackupPath);
                $this->info("2️⃣：Sqlite备份完成");
            }else{
                $this->error('备份失败，你的数据库不是sqlite或者mysql');
TXT;
    $replace = <<<'TXT'
            }elseif(config('database.default') === 'sqlite'){
                $databaseBackupPath = storage_path('backup/' .  now()->format('Y-m-d_H-i-s') . '_sqlite'  . '_database_backup.sql');
                $this->info("1️⃣：开始备份Sqlite");
                \Spatie\DbDumper\Databases\Sqlite::create()
                    ->setDbName(config('database.connections.sqlite.database'))
                    ->dumpToFile($databaseBackupPath);
                $this->info("2️⃣：Sqlite备份完成");
            }elseif(config('database.default') === 'pgsql'){
                $dbConfig = config('database.connections.pgsql');
                $databaseBackupPath = storage_path('backup/' . now()->format('Y-m-d_H-i-s') . '_' . $dbConfig['database'] . '_database_backup.sql');
                $this->info("1️⃣：开始备份PostgreSQL");
                $env = ['PGPASSWORD' => $dbConfig['password']];
                $cmd = new Process(['pg_dump', '-h', $dbConfig['host'], '-p', (string) $dbConfig['port'], '-U', $dbConfig['username'], '-Fc', $dbConfig['database'], '-f', $databaseBackupPath], null, $env);
                $cmd->setTimeout(600);
                $cmd->run();
                if (!$cmd->isSuccessful()) { $this->error('PG备份失败: ' . $cmd->getErrorOutput()); return; }
                $this->info("2️⃣：PostgreSQL备份完成");
            }else{
                $this->error('备份失败，你的数据库不是sqlite、pgsql或者mysql');
TXT;
    if (strpos($s, $needle) === false) {
        echo "BackupDatabase.php pgsql anchor missing\n";
        return $s;
    }
    return str_replace($needle, $replace, $s);
});

patch_file('/www/app/Models/Plugin.php', function (string $s): string {
    if (strpos($s, "'config' => 'array'") !== false) {
        return $s;
    }
    return str_replace("'is_enabled' => 'boolean'", "'is_enabled' => 'boolean',\n        'config' => 'array'", $s);
});

patch_file('/www/app/Services/Plugin/PluginManager.php', function (string $s): string {
    $s = str_replace(
        '$values = json_decode($dbPlugin->config, true) ?: [];',
        '$values = is_array($dbPlugin->config) ? $dbPlugin->config : (json_decode($dbPlugin->config, true) ?: []);',
        $s
    );
    return str_replace(
        <<<'TXT'
                'config' => json_encode($defaultValues),
TXT,
        <<<'TXT'
                'config' => $defaultValues,
TXT,
        $s
    );
});

patch_file('/www/app/Services/Plugin/PluginConfigService.php', function (string $s): string {
    $s = str_replace(
        <<<'TXT'
                'config' => json_encode($values),
TXT,
        <<<'TXT'
                'config' => $values,
TXT,
        $s
    );
    return str_replace(
        <<<'TXT'
        return json_decode($plugin->config, true);
TXT,
        <<<'TXT'
        $config = $plugin->config;
        return is_array($config) ? $config : (json_decode($config, true) ?? []);
TXT,
        $s
    );
});

patch_file('/www/app/Traits/HasPluginConfig.php', function (string $s): string {
    return str_replace(
        'return json_decode($plugin->config, true) ?? [];',
        'return is_array($plugin->config) ? $plugin->config : (json_decode($plugin->config, true) ?? []);',
        $s
    );
});
