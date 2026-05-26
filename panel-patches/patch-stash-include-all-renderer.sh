#!/usr/bin/env bash
# patch-stash-include-all-renderer.sh
#
# XBoard's Stash renderer was originally built around slash-regex placeholders
# inside `proxy-groups[].proxies`. Stash itself supports the newer
# `include-all: true` + `filter` syntax, but the renderer must preserve those
# groups instead of appending every node or dropping an empty `proxies` list.
#
# Run on the panel host from /home/xboard/yue-to after container upgrades.
set -euo pipefail
cd /home/xboard/yue-to

docker compose exec -T web php <<'PHP'
<?php
$path = '/www/app/Protocols/Stash.php';
$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "FATAL: unable to read {$path}\n");
    exit(1);
}

$changed = false;

$old = <<<'CODE'
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies']))
                $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src))
                        continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter)
                    continue;
            }
            if ($isFilter)
                continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
CODE;

$new = <<<'CODE'
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!isset($config['proxy-groups'][$k]['proxies']) || !is_array($config['proxy-groups'][$k]['proxies']))
                $config['proxy-groups'][$k]['proxies'] = [];
            $usesClientSideFilter = !empty($config['proxy-groups'][$k]['include-all']) || !empty($config['proxy-groups'][$k]['use']);
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src))
                        continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter)
                    continue;
            }
            if ($isFilter)
                continue;
            if ($usesClientSideFilter) {
                if ($config['proxy-groups'][$k]['proxies'] === []) {
                    unset($config['proxy-groups'][$k]['proxies']);
                }
                continue;
            }
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return !empty($group['proxies']) || !empty($group['include-all']) || !empty($group['use']);
        });
CODE;

if (!str_contains($src, '$usesClientSideFilter')) {
    if (!str_contains($src, $old)) {
        fwrite(STDERR, "FATAL: expected Stash renderer block not found; inspect {$path}\n");
        exit(1);
    }
    $src = str_replace($old, $new, $src);
    $changed = true;
}

$backup = '/tmp/Stash.php.bak-include-all-' . date('YmdHis');
$dumpOld = 'Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)';
$dumpNew = 'Yaml::dump($config, 8, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)';
if (str_contains($src, $dumpOld)) {
    $src = str_replace($dumpOld, $dumpNew, $src);
    $changed = true;
}

$quoteOld = <<<'CODE'
        $yaml = Yaml::dump($config, 8, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', admin_setting('app_name', 'XBoard'), $yaml);
CODE;

$quoteNew = <<<'CODE'
        $yaml = Yaml::dump($config, 8, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = preg_replace('/^(\s*-\s)(\+\.[^\'"\r\n]*)$/m', '$1\'$2\'', $yaml);
        $yaml = preg_replace('/^(\s*)(\+\.[^:\'"\r\n]*):/m', '$1\'$2\':', $yaml);
        $yaml = str_replace('$app_name', admin_setting('app_name', 'XBoard'), $yaml);
CODE;

if (!str_contains($src, "preg_replace('/^(\\s*-\\s)(\\+\\.")) {
    if (!str_contains($src, $quoteOld)) {
        fwrite(STDERR, "FATAL: expected Stash YAML dump block not found; inspect {$path}\n");
        exit(1);
    }
    $src = str_replace($quoteOld, $quoteNew, $src);
    $changed = true;
}

if (!$changed) {
    echo "[skip] Stash renderer already supports include-all/filter, block-style dump, and quoted plus-domains\n";
    exit(0);
}

if (!copy($path, $backup)) {
    fwrite(STDERR, "FATAL: unable to write backup {$backup}\n");
    exit(1);
}

if (file_put_contents($path, $src) === false) {
    fwrite(STDERR, "FATAL: unable to write {$path}\n");
    exit(1);
}

echo "[ok] patched {$path}; backup={$backup}\n";
PHP
