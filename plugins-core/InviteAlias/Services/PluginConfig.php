<?php

namespace Plugin\InviteAlias\Services;

use App\Services\Plugin\PluginManager;

/**
 * 统一从 PluginManager 读 invite_alias 插件 config（跟 CommissionTier 同款做法）
 *
 * 不能用 admin_setting() —— 那个读的是 v2_settings（admin global），不是 v2_plugins.config
 */
class PluginConfig
{
    private const CODE = 'invite_alias';

    public static function all(): array
    {
        $pm = app(PluginManager::class);
        $plugin = $pm->getEnabledPlugins()[self::CODE] ?? null;
        if (!$plugin) return [];
        return (array) $plugin->getConfig();
    }

    public static function get(string $key, $default = null)
    {
        $cfg = self::all();
        $val = $cfg[$key] ?? $default;
        return $val === '' || $val === null ? $default : $val;
    }
}
