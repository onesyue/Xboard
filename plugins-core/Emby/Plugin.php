<?php

namespace Plugin\Emby;

use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\Emby\Services\EmbyService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('order.open.after', [$this, 'onOrderOpened'], 20);
    }

    public function onOrderOpened(mixed $payload): void
    {
        $order = $payload instanceof Order ? $payload : null;
        if (!$order) {
            return;
        }

        $config   = $this->getConfig();
        $enabled  = $this->boolConfig($config['enabled'] ?? true, true);
        $embyHost = $config['emby_host'] ?? '';
        $apiKey   = $config['emby_api_key'] ?? '';

        if (!$enabled || !$embyHost || !$apiKey) {
            return;
        }

        $user = User::find($order->user_id);
        if (!$user) {
            return;
        }

        $maxStreams = max(1, min(10, (int) ($config['max_streams'] ?? 2)));

        try {
            $embyService = new EmbyService($embyHost, $apiKey);
            $embyService->findOrCreateUser($user->email, $user->id, $maxStreams);
            Log::info("[Emby] onOrderOpened: provisioned user {$user->email} (order #{$order->id})");
        } catch (\Exception $e) {
            Log::error("[Emby] onOrderOpened error for {$user->email}: " . $e->getMessage());
        }
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('emby:sync')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    private function boolConfig(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
