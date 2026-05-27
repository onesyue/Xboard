<?php

namespace Plugin\Emby\Commands;

use App\Models\User;
use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Plugin\Emby\Services\EmbyService;

class SyncEmbyAccounts extends Command
{
    protected $signature   = 'emby:sync {--dry : Print changes without writing to Emby}';
    protected $description = 'Sync Emby account states with XBoard subscription status';

    public function handle(): int
    {
        /** @var PluginManager $pm */
        $pm      = app(PluginManager::class);
        $plugins = $pm->getEnabledPlugins();

        if (!isset($plugins['emby'])) {
            $this->warn('[emby:sync] Emby plugin not enabled, skipping.');
            return 0;
        }

        $plugin   = $plugins['emby'];
        $config   = $plugin->getConfig();
        $enabled  = $this->boolConfig($config['enabled'] ?? true, true);
        $embyHost = $config['emby_host']    ?? '';
        $apiKey   = $config['emby_api_key'] ?? '';
        $dry       = (bool) $this->option('dry');

        if (!$enabled || !$embyHost || !$apiKey) {
            $this->warn('[emby:sync] Plugin disabled or missing host/key, skipping.');
            return 0;
        }

        $maxStreams  = max(1, min(10, (int) ($config['max_streams'] ?? 2)));
        $requireSubscription = $this->boolConfig($config['require_subscription'] ?? true, true);
        $embyService = new EmbyService($embyHost, $apiKey);

        // Fetch all current Emby users — only sync existing accounts
        $embyUsers = $embyService->getAllEmbyUsers();
        if ($embyUsers === null) {
            $this->error('[emby:sync] Failed to fetch Emby user list.');
            return 1;
        }

        $this->info('[emby:sync] Found ' . count($embyUsers) . ' Emby accounts to check.');

        // Build an in-memory index of XBoard users: sanitized_email → active bool.
        // Loading every user also lets us disable accounts accidentally created while
        // require_subscription was false.
        $now        = time();
        $xbIndex    = [];
        User::query()
            ->select(['id', 'email', 'plan_id', 'expired_at'])
            ->chunk(500, function ($users) use ($embyService, &$xbIndex, $requireSubscription, $now) {
                foreach ($users as $u) {
                    $key          = strtolower($embyService->publicToUsername($u->email));
                    $xbIndex[$key] = !$requireSubscription
                        || ($u->plan_id && (!$u->expired_at || $u->expired_at > $now));
                }
            });

        $this->info('[emby:sync] Loaded ' . count($xbIndex) . ' XBoard subscriber records.');

        $enabled_c  = 0;
        $disabled_c = 0;

        foreach ($embyUsers as $lowerName => $embyUser) {
            // Skip admin / service accounts not managed by XBoard
            if (!isset($xbIndex[$lowerName])) {
                continue;
            }

            $isActive   = (bool) $xbIndex[$lowerName];
            $isDisabled = $embyUser['Policy']['IsDisabled'] ?? false;

            if (!$isActive && !$isDisabled) {
                if (!$dry) {
                    $embyService->disableUser($embyUser['Id']);
                }
                $disabled_c++;
                Log::info("[emby:sync] Disabled {$embyUser['Name']} (inactive subscription)");
            } elseif ($isActive && $isDisabled) {
                if (!$dry) {
                    $embyService->setUserPolicy($embyUser['Id'], false, $maxStreams);
                }
                $enabled_c++;
                Log::info("[emby:sync] Re-enabled {$embyUser['Name']}");
            }
        }

        $msg = "[emby:sync] Done — enabled: {$enabled_c}, disabled: {$disabled_c}, dry=" . ($dry ? 'yes' : 'no');
        $this->info($msg);
        Log::info($msg);
        return 0;
    }

    private function boolConfig(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
