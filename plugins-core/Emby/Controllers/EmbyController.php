<?php

namespace Plugin\Emby\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
use Plugin\Emby\Services\EmbyService;

class EmbyController extends Controller
{
    public function getEmby(Request $request)
    {
        $user = $request->user();

        // 获取插件配置
        $pluginManager = app(PluginManager::class);
        $plugins = $pluginManager->getEnabledPlugins();

        if (!isset($plugins['emby'])) {
            return $this->fail([400202, 'Emby 服务未启用']);
        }

        $plugin = $plugins['emby'];
        $config = $plugin->getConfig();

        if (!$this->boolConfig($config['enabled'] ?? true, true)) {
            return $this->fail([400202, 'Emby 服务未启用']);
        }

        $embyHost = $config['emby_host'] ?? '';
        $apiKey   = $config['emby_api_key'] ?? '';
        $serverId = $config['emby_server_id'] ?? '';
        $maxStreams = max(1, min(10, (int) ($config['max_streams'] ?? 2)));

        if (!$embyHost || !$apiKey) {
            return $this->fail([500100, 'Emby 服务配置不完整']);
        }

        // 验证订阅状态
        if ($this->boolConfig($config['require_subscription'] ?? true, true)) {
            $hasActivePlan = $user->plan_id &&
                (!$user->expired_at || $user->expired_at > time());
            if (!$hasActivePlan) {
                return $this->fail([400202, '需要有效订阅才能访问 Emby']);
            }
        }

        $embyService = new EmbyService($embyHost, $apiKey);

        // 查找或创建 Emby 账号
        $embyUser = $embyService->findOrCreateUser($user->email, $user->id, $maxStreams);
        if (!$embyUser) {
            return $this->fail([500100, '创建 Emby 账号失败，请联系管理员']);
        }

        // 生成自动登录 token
        $accessToken = $embyService->createAccessToken($user->email, $user->id);

        // 构造自动登录 URL
        $autoLoginUrl = $embyHost;
        if ($accessToken && $serverId) {
            $autoLoginUrl = $embyHost . '/web/index.html?' . http_build_query([
                'serverId' => $serverId,
                'userId' => $embyUser['Id'],
                'accessToken' => $accessToken,
            ]);
        }

        return $this->success([
            'emby_url'       => $embyHost,
            'auto_login_url' => $autoLoginUrl,
        ]);
    }

    private function boolConfig(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
