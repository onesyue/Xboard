<?php

namespace Plugin\Emby\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbyService
{
    private string $host;
    private string $apiKey;
    private const HTTP_TIMEOUT = 8;
    private const CONNECT_TIMEOUT = 3;

    public function __construct(string $host, string $apiKey)
    {
        $this->host   = rtrim($host, '/');
        $this->apiKey = $apiKey;
    }

    private function http()
    {
        return Http::timeout(self::HTTP_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->retry(1, 300);
    }

    /**
     * 生成确定性密码（用户 ID + 邮箱 + app key 的 HMAC）
     * 无需存储，随时可重新计算
     */
    private function userPassword(string $email, int $userId): string
    {
        return substr(hash_hmac('sha256', $userId . '|' . $email, config('app.key')), 0, 32);
    }

    /**
     * 将 XBoard 邮箱转为合法的 Emby 用户名（公开方法，供 SyncCommand 使用）
     */
    public function publicToUsername(string $email): string
    {
        return $this->toUsername($email);
    }

    /**
     * 将 XBoard 邮箱转为合法的 Emby 用户名
     */
    private function toUsername(string $email): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $email);
    }

    /**
     * 查找 Emby 用户（按用户名精确匹配）
     */
    public function findUser(string $username): ?array
    {
        try {
            $resp = $this->http()
                ->withHeaders(['X-Emby-Token' => $this->apiKey])
                ->get("{$this->host}/Users", ['searchTerm' => $username]);

            if ($resp->successful()) {
                foreach ($resp->json() as $user) {
                    if (strtolower($user['Name']) === strtolower($username)) {
                        return $user;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('[Emby] findUser error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * 获取所有 Emby 用户，返回以小写用户名为 key 的字典（供批量同步使用）
     */
    public function getAllEmbyUsers(): ?array
    {
        try {
            $resp = $this->http()
                ->withHeaders(['X-Emby-Token' => $this->apiKey])
                ->get("{$this->host}/Users");

            if ($resp->successful()) {
                $result = [];
                foreach ($resp->json() as $user) {
                    $result[strtolower($user['Name'])] = $user;
                }
                return $result;
            }
            Log::error('[Emby] getAllEmbyUsers failed: ' . $resp->body());
        } catch (\Exception $e) {
            Log::error('[Emby] getAllEmbyUsers error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * 设置 Emby 用户密码（POST /Users/New 不接受 Password 字段，需单独调用）
     */
    private function setUserPassword(string $embyUserId, string $password): bool
    {
        try {
            $resp = $this->http()->withHeaders([
                'X-Emby-Token'  => $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post("{$this->host}/Users/{$embyUserId}/Password", [
                'NewPw'           => $password,
                'ResetPassword'   => false,
            ]);
            if (!$resp->successful()) {
                Log::error('[Emby] setUserPassword failed: HTTP ' . $resp->status());
            }
            return $resp->successful();
        } catch (\Exception $e) {
            Log::error('[Emby] setUserPassword error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建 Emby 用户并设置密码和策略
     * 注意：POST /Users/New 忽略 Password 字段，需分两步设置
     */
    public function createUser(string $username, string $email, int $userId, int $maxStreams = 2): ?array
    {
        try {
            // 第一步：创建用户（空密码）
            $resp = $this->http()->withHeaders([
                'X-Emby-Token'  => $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post("{$this->host}/Users/New", [
                'Name' => $username,
            ]);

            if (!$resp->successful()) {
                Log::error('[Emby] createUser failed: ' . $resp->body());
                return null;
            }

            $user = $resp->json();

            // 第二步：设置密码
            $password = $this->userPassword($email, $userId);
            if (!$this->setUserPassword($user['Id'], $password)) {
                return null;
            }

            // 第三步：设置访问策略
            $this->setUserPolicy($user['Id'], false, $maxStreams);

            return $user;
        } catch (\Exception $e) {
            Log::error('[Emby] createUser error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 查找或创建用户（幂等）
     */
    public function findOrCreateUser(string $email, int $userId, int $maxStreams = 2): ?array
    {
        $username = $this->toUsername($email);
        $user     = $this->findUser($username);
        if ($user) {
            // 确保账号密码、并发数和启用状态与当前 XBoard 配置一致。
            $this->setUserPassword($user['Id'], $this->userPassword($email, $userId));
            $this->setUserPolicy($user['Id'], false, $maxStreams);
            $user['Policy']['IsDisabled'] = false;
            return $user;
        }
        return $this->createUser($username, $email, $userId, $maxStreams);
    }

    /**
     * 设置用户策略
     */
    public function setUserPolicy(string $embyUserId, bool $disabled = false, int $maxStreams = 2): bool
    {
        try {
            $resp = $this->http()->withHeaders([
                'X-Emby-Token'  => $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post("{$this->host}/Users/{$embyUserId}/Policy", [
                'IsAdministrator'                   => false,
                'IsHidden'                          => true,
                'IsDisabled'                        => $disabled,
                'EnableAllFolders'                  => true,
                'EnableMediaPlayback'               => true,
                'EnableVideoPlaybackTranscoding'    => true,
                'EnablePlaybackRemuxing'            => true,
                'EnableAudioPlaybackTranscoding'    => true,
                'SimultaneousStreamLimit'           => $maxStreams,
            ]);
            if (!$resp->successful()) {
                Log::error('[Emby] setUserPolicy failed: HTTP ' . $resp->status());
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error('[Emby] setUserPolicy error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 为用户生成一次性 AccessToken（用于自动登录 URL）
     */
    public function createAccessToken(string $email, int $userId): ?string
    {
        $username = $this->toUsername($email);
        $password = $this->userPassword($email, $userId);
        try {
            $resp = $this->http()->withHeaders([
                'X-Emby-Authorization' => sprintf(
                    'MediaBrowser Client="XBoard", Device="XBoard", DeviceId="xboard-%d", Version="4.9.3.0"',
                    $userId
                ),
                'Content-Type' => 'application/json',
            ])->post("{$this->host}/Users/AuthenticateByName", [
                'Username' => $username,
                'Pw'       => $password,
            ]);

            if ($resp->successful()) {
                return $resp->json('AccessToken');
            }
            Log::error('[Emby] createAccessToken failed: ' . $resp->body());
        } catch (\Exception $e) {
            Log::error('[Emby] createAccessToken error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * 禁用用户（订阅过期时调用）
     */
    public function disableUser(string $embyUserId): void
    {
        $this->setUserPolicy($embyUserId, true);
    }

    /**
     * 启用用户
     */
    public function enableUser(string $embyUserId, int $maxStreams = 2): void
    {
        $this->setUserPolicy($embyUserId, false, $maxStreams);
    }
}
