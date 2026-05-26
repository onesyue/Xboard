<?php
// PG ILIKE patch — replaces 'like' / 'LIKE' with 'ilike' in admin search controllers.
// Idempotent: marker comment at file head prevents double-patching.
// Run via: docker compose exec -T web php /www/patch-pgsql-ilike.php

$marker = "// [PG ILIKE patch]";

$ilike_files = [
    "app/Http/Controllers/V2/Admin/UserController.php",
    "app/Http/Controllers/V2/Admin/OrderController.php",
    "app/Http/Controllers/V2/Admin/CouponController.php",
    "app/Http/Controllers/V2/Admin/TicketController.php",
    "app/Http/Controllers/V2/Admin/TrafficResetController.php",
    "app/Http/Controllers/V2/Admin/SystemController.php",
    "app/Http/Controllers/V1/User/KnowledgeController.php",
    "app/Scope/FilterScope.php",
];

foreach ($ilike_files as $path) {
    $full = "/www/" . $path;
    if (!file_exists($full)) {
        echo basename($path) . " (skip — not found)\n";
        continue;
    }
    $f = file_get_contents($full);
    if (strpos($f, $marker) !== false) {
        echo basename($path) . " already patched\n";
        continue;
    }
    $orig = $f;

    // Replace ->where(..., 'like', ...) and ->orWhere(..., 'LIKE', ...)
    // Use a callback to be safe with quoting.
    $f = preg_replace_callback(
        '/(->(?:or)?[Ww]here\([^,]+,\s*)([\'"])(?:like|LIKE)\2(\s*,)/',
        function ($m) {
            return $m[1] . "'ilike'" . $m[3];
        },
        $f
    );

    if ($f !== $orig) {
        $f = preg_replace('/^<\?php/', "<?php\n" . $marker, $f, 1);
        file_put_contents($full, $f);
        echo basename($path) . " ILIKE patched\n";
    } else {
        echo basename($path) . " (no changes — pattern not found)\n";
    }
}

// QueryOperators.php uses 'like' as map value in match expression
$qpath = "/www/app/Traits/QueryOperators.php";
if (file_exists($qpath)) {
    $f = file_get_contents($qpath);
    if (strpos($f, $marker) === false) {
        $orig = $f;
        $f = str_replace("'like' => 'like'", "'like' => 'ilike'", $f);
        $f = str_replace("default => 'like'", "default => 'ilike'", $f);
        if ($f !== $orig) {
            $f = preg_replace('/^<\?php/', "<?php\n" . $marker, $f, 1);
            file_put_contents($qpath, $f);
            echo "QueryOperators.php ILIKE patched\n";
        } else {
            echo "QueryOperators.php (no changes)\n";
        }
    } else {
        echo "QueryOperators.php already patched\n";
    }
}

// OrderController has its own 'like' map block
$ocpath = "/www/app/Http/Controllers/V2/Admin/OrderController.php";
if (file_exists($ocpath)) {
    $f = file_get_contents($ocpath);
    // Already patched (file-level marker)? skip
    $orig = $f;
    $f = str_replace("'like' => 'like'", "'like' => 'ilike'", $f);
    $f = str_replace("default => 'like'", "default => 'ilike'", $f);
    if ($f !== $orig) {
        file_put_contents($ocpath, $f);
        echo "OrderController.php map ILIKE patched\n";
    }
}

echo "\n[pg-ilike] All ILIKE patches applied.\n";
