<?php
// Patch v2: 重置订阅后立即断连 — 贯穿 Observer → Job → Service 传递 uuidChanged 标记
// 幂等：检测 forceDisconnectUser 是否已存在

// ========== 1. Patch UserObserver — 检测 uuid dirty 并传给 Job ==========
$file = 'app/Observers/UserObserver.php';
$f = file_get_contents($file);

if (strpos($f, 'uuidChanged') !== false) {
    echo "UserObserver already patched\n";
} else {
    $old = "NodeUserSyncJob::dispatch(\$user->id, 'updated', \$oldGroupId);";
    $new = "\$uuidChanged = \$user->isDirty('uuid') || \$user->wasChanged('uuid');\n      NodeUserSyncJob::dispatch(\$user->id, 'updated', \$oldGroupId, \$uuidChanged);";
    
    if (strpos($f, $old) === false) {
        echo "ERROR: UserObserver target not found\n";
        exit(1);
    }
    $f = str_replace($old, $new, $f);
    file_put_contents($file, $f);
    echo "UserObserver patched\n";
}

// ========== 2. Patch NodeUserSyncJob — 接收 uuidChanged 并传递 ==========
$file = 'app/Jobs/NodeUserSyncJob.php';
$f = file_get_contents($file);

if (strpos($f, 'uuidChanged') !== false) {
    echo "NodeUserSyncJob already patched\n";
} else {
    // Add uuidChanged parameter to constructor
    $old = <<<'OLD'
    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly ?int $oldGroupId = null
    ) {
OLD;
    $new = <<<'NEW'
    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly ?int $oldGroupId = null,
        private readonly bool $uuidChanged = false
    ) {
NEW;
    if (strpos($f, $old) === false) {
        echo "ERROR: NodeUserSyncJob constructor not found\n";
        exit(1);
    }
    $f = str_replace($old, $new, $f);

    // Pass uuidChanged to notifyUserChanged
    $old2 = 'NodeSyncService::notifyUserChanged($user);';
    $new2 = 'NodeSyncService::notifyUserChanged($user, $this->uuidChanged);';
    if (strpos($f, $old2) === false) {
        echo "ERROR: NodeUserSyncJob notifyUserChanged call not found\n";
        exit(1);
    }
    $f = str_replace($old2, $new2, $f);
    file_put_contents($file, $f);
    echo "NodeUserSyncJob patched\n";
}

// ========== 3. Patch NodeSyncService — 接收 uuidChanged, 先 remove 再 add ==========
$file = 'app/Services/NodeSyncService.php';
$f = file_get_contents($file);

if (strpos($f, 'forceDisconnectUser') !== false) {
    // 已有 v1 patch，需要替换整个 notifyUserChanged 方法签名
    // 用更精确的替换
    $old = 'public static function notifyUserChanged(User $user): void';
    $new = 'public static function notifyUserChanged(User $user, bool $uuidChanged = false): void';
    if (strpos($f, $old) !== false) {
        $f = str_replace($old, $new, $f);
    }
    
    // 替换 isDirty/wasChanged 条件为 $uuidChanged 参数
    $old = "\$user->isDirty('uuid') || \$user->wasChanged('uuid')";
    $new = '$uuidChanged';
    $f = str_replace($old, $new, $f);
    
    file_put_contents($file, $f);
    echo "NodeSyncService updated (v1→v2)\n";
} else {
    // 从零 patch
    $old = 'public static function notifyUserChanged(User $user): void';
    $new = 'public static function notifyUserChanged(User $user, bool $uuidChanged = false): void';
    $f = str_replace($old, $new, $f);

    $old2 = <<<'OLD'
            if ($user->isAvailable()) {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [
                        [
                            'id' => $user->id,
                            'uuid' => $user->uuid,
                            'speed_limit' => $user->speed_limit,
                            'device_limit' => $user->device_limit,
                        ]
                    ],
                ]);
OLD;
    $new2 = <<<'NEW'
            if ($user->isAvailable()) {
                // UUID 变更（重置订阅）→ 先 remove 踢掉旧连接，再 add 新 UUID
                if ($uuidChanged) {
                    self::forceDisconnectUser($user->id, $server->id);
                }
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [
                        [
                            'id' => $user->id,
                            'uuid' => $user->uuid,
                            'speed_limit' => $user->speed_limit,
                            'device_limit' => $user->device_limit,
                        ]
                    ],
                ]);
NEW;
    if (strpos($f, $old2) === false) {
        echo "ERROR: NodeSyncService target block not found\n";
        exit(1);
    }
    $f = str_replace($old2, $new2, $f);

    // Add forceDisconnectUser method
    $method = <<<'METHOD'

    /**
     * Force disconnect a user from a specific node
     * Sends sync.user.delta remove to kick existing connections,
     * and clears Redis device state.
     */
    public static function forceDisconnectUser(int $userId, int $nodeId): void
    {
        self::push($nodeId, 'sync.user.delta', [
            'action' => 'remove',
            'users' => [['id' => $userId]],
        ]);

        try {
            $deviceService = app(\App\Services\DeviceStateService::class);
            $deviceService->removeNodeDevices($nodeId, $userId);
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Failed to clear device state: {$e->getMessage()}", [
                'user_id' => $userId,
                'node_id' => $nodeId,
            ]);
        }
    }
METHOD;
    $lastBrace = strrpos($f, '}');
    $f = substr($f, 0, $lastBrace) . $method . "\n" . substr($f, $lastBrace);
    file_put_contents($file, $f);
    echo "NodeSyncService patched (fresh)\n";
}

echo "=== All 3 files patched ===\n";
