<?php

namespace Plugin\InviteAlias\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\InviteAlias\Services\PluginConfig;
use Plugin\InviteAlias\Services\AdminNotifier;

/**
 * 全自助审核 —— 六层防线
 *
 *  L1 格式校验   纯本地正则           ~1ms
 *  L2 黑名单     1172 词 + 19 regex   ~1ms
 *  L3 同形检测   Damerau-Levenshtein  ~5ms
 *  L4 AI 审核   Claude Haiku 4.5     ~800ms-1.2s
 *  L5 行为风控  IP/账号年龄/邀请数   ~10ms
 *  L6 唯一性    并发抢注事务         ~50ms（在 Controller 里走 SELECT FOR UPDATE）
 *
 * 任一层失败立即返回，不进入下一层。返回值统一：
 *  ['ok' => true]
 *  ['ok' => false, 'layer' => 'L2', 'code' => 'reserved', 'reason' => '...', 'public_msg' => '...']
 *
 * public_msg 是给用户看的中文提示；reason 仅写日志，不暴露规则细节防绕过。
 */
class AliasValidator
{
    /** L1: 长度上下限的 fallback */
    const MIN_LENGTH = 3;
    const MAX_LENGTH = 20;

    /** L3: 与保留词的 Damerau-Levenshtein 阈值 */
    const HOMOGLYPH_DISTANCE = 2;

    /** L4: AI 审核超时 */
    const AI_TIMEOUT_SECONDS = 8;

    /**
     * 主入口
     *
     * @param string $alias        用户输入（已 trim/lower）
     * @param int    $userId       v2_user.id
     * @param int    $aliasType    1=invite_code 2=isolated 3=brand
     * @param string $registerIp   v4/v6 字符串
     * @param bool   $includeBehavior  L5 行为风控. 2026-05-26: precheck 传 false 仅查名字
     *                                可用性, 不在"检查"阶段就拦"异常注册行为"; redeemPending 真扣分阶段
     *                                必传 true. 防 NAT IP 误伤 + 改善 UX (用户先看名字 OK 再决定兑换).
     * @return array
     */
    public function validate(string $alias, int $userId, int $aliasType, string $registerIp, bool $includeBehavior = true): array
    {
        $alias = strtolower(trim($alias));

        // L1 格式
        $r = $this->checkFormat($alias);
        if (!$r['ok']) return $r;

        // L2 黑名单
        $r = $this->checkReserved($alias);
        if (!$r['ok']) return $r;

        // L3 同形 / 高相似度
        $r = $this->checkHomoglyph($alias);
        if (!$r['ok']) return $r;

        // L4 AI（仅 1888/8888 档）
        if ($aliasType >= 2 && $this->config('ai_review_enabled', true)) {
            $r = $this->checkAi($alias, $aliasType);
            if (!$r['ok']) return $r;
        }

        // L5 行为风控 — 仅在真实兑换 (redeemPending) 时跑, precheck 跳过让用户先看名字可用性
        if ($includeBehavior) {
            $r = $this->checkBehavior($userId, $aliasType, $registerIp);
            if (!$r['ok']) return $r;
        }

        return ['ok' => true];
    }

    /* ─────── L1: 格式 ─────── */

    private function checkFormat(string $alias): array
    {
        $minLen = (int) $this->config('alias_min_length', self::MIN_LENGTH);
        $maxLen = (int) $this->config('alias_max_length', self::MAX_LENGTH);

        if (strlen($alias) < $minLen || strlen($alias) > $maxLen) {
            return self::fail('L1', 'length',
                "len {$minLen}-{$maxLen}",
                "长度需在 {$minLen}-{$maxLen} 个字符之间");
        }

        // ^[a-z0-9][a-z0-9-]*[a-z0-9]$  且首尾不能是 - 不能连续 --
        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $alias)) {
            return self::fail('L1', 'charset',
                'invalid charset',
                '只允许小写英文字母、数字、连字符 (-)，且首尾不能是连字符');
        }

        if (str_contains($alias, '--')) {
            return self::fail('L1', 'double_hyphen',
                'punycode-like',
                '不允许出现连续两个连字符');
        }

        if (preg_match('/^[0-9]+$/', $alias)) {
            return self::fail('L1', 'all_digits',
                'pure numeric',
                '不允许全部是数字（容易与 IP 混淆）');
        }

        return ['ok' => true];
    }

    /* ─────── L2: 黑名单 ─────── */

    private function checkReserved(string $alias): array
    {
        $list = $this->loadReservedList();

        if (isset($list['names_set'][$alias])) {
            $cat = $list['names_set'][$alias];
            return self::fail('L2', 'reserved',
                "matched in '{$cat}'",
                '该名称在保留词清单中，请换一个');
        }

        foreach ($list['patterns'] as $pat) {
            if (@preg_match('/^' . $pat . '$/', $alias)) {
                return self::fail('L2', 'reserved_pattern',
                    "matched pattern '{$pat}'",
                    '该名称结构属于系统保留模式（如 mail1/db2 等）');
            }
        }

        return ['ok' => true];
    }

    /* ─────── L3: 同形 / 编辑距离 ─────── */

    private function checkHomoglyph(string $alias): array
    {
        $list = $this->loadReservedList();

        // 仅对"高价值"保留词做距离检测（防止把所有 1172 词都跑一遍 O(N) 太慢）
        // high_value = 自家品牌 + 知名商标 + 监管机关
        $highValue = $list['high_value'] ?? [];
        if (empty($highValue)) return ['ok' => true];

        // 数字字母同形归一化（防 admln→admin / g00gle→google）
        $normalized = strtr($alias, [
            '0' => 'o', '1' => 'l', '5' => 's', '8' => 'b'
        ]);

        foreach ($highValue as $word => $cat) {
            // 仅对长度差 ≤ 阈值的做 DL 距离（剪枝）
            if (abs(strlen($word) - strlen($alias)) > self::HOMOGLYPH_DISTANCE) {
                continue;
            }

            $d1 = levenshtein($alias, $word);
            $d2 = levenshtein($normalized, $word);
            $d  = min($d1, $d2);

            // 完全相等已经被 L2 拦了，这里只看 1-2 距离
            if ($d > 0 && $d <= self::HOMOGLYPH_DISTANCE) {
                return self::fail('L3', 'homoglyph',
                    "near '{$word}' (cat={$cat}, d={$d})",
                    '该名称与保留词过于相似，请换一个差别更大的');
            }
        }

        return ['ok' => true];
    }

    /* ─────── L4: AI 审核 ─────── */

    private function checkAi(string $alias, int $aliasType): array
    {
        $apiKey = $this->config('ai_review_api_key', '');
        if (!$apiKey) {
            // 配置缺失：fail-open（让流量通过），告警运维
            Log::warning('[InviteAlias] L4 ai_review_api_key not configured, fail-open');
            return ['ok' => true];
        }

        $endpoint = $this->config('ai_review_endpoint', 'https://openrouter.ai/api/v1/chat/completions');
        $model    = $this->config('ai_review_model', 'anthropic/claude-haiku-4.5');

        // 主域档 prompt 更严格
        $strictness = $aliasType === 3 ? 'STRICT (主域 yue.to 子域)' : 'NORMAL (隔离域 i.yue.to 子域)';

        $prompt = <<<EOT
你是子域名注册风控审核员。判断以下 alias 是否存在风险：

alias = "{$alias}"
审核档位 = {$strictness}

风险类别：
- phishing   冒用银行/政府/支付/官方品牌
- profanity  中英脏话或敏感政治词
- brand      知名公司商标（即使本地黑名单未覆盖）
- misleading 诱导词、欺诈词、虚假承诺
- harmful    赌博/毒品/灰产/暴力等

主域档位下，brand/misleading 也按 high 处理；隔离域可以稍宽松。

仅返回严格 JSON：
{"risk":"high|medium|low","categories":["..."],"reason":"50字内解释"}
EOT;

        try {
            $resp = Http::timeout(self::AI_TIMEOUT_SECONDS)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => 'https://yue.to',
                    'X-Title'       => 'YueLink InviteAlias',
                ])
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => '你是严格的内容审核员，只返回 JSON 不返回其他。'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens'  => 200,
                    'temperature' => 0.1,
                    'reasoning' => ['exclude' => true],
                ]);

            if (!$resp->ok()) {
                Log::warning('[InviteAlias] L4 AI HTTP failure', ['status' => $resp->status(), 'body' => $resp->body()]);
                return ['ok' => true];  // fail-open
            }

            $data = $resp->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
            $content = trim($content);

            // 提取 JSON 块（容忍模型偶尔加 ```json 包裹）
            if (preg_match('/\{[^{}]*"risk"[^{}]*\}/s', $content, $m)) {
                $content = $m[0];
            }

            $verdict = json_decode($content, true);
            if (!is_array($verdict) || !isset($verdict['risk'])) {
                Log::warning('[InviteAlias] L4 AI bad response', ['content' => $content]);
                return ['ok' => true];  // fail-open
            }

            $risk = strtolower($verdict['risk']);

            if ($risk === 'high' || ($aliasType === 3 && $risk === 'medium')) {
                AdminNotifier::send('AI L4 拦截', [
                    'alias' => $alias,
                    'tier'  => $aliasType,
                    'risk'  => $risk,
                    'cats'  => $verdict['categories'] ?? [],
                    'reason' => $verdict['reason'] ?? '',
                ], "ai_blocked:{$alias}");

                return self::fail('L4', 'ai_blocked',
                    "ai_verdict={$risk} cats=" . json_encode($verdict['categories'] ?? []),
                    '该名称经 AI 审核存在风险，建议加上你的昵称、品牌前缀等差异化信息');
            }

            return ['ok' => true];

        } catch (\Throwable $e) {
            Log::warning('[InviteAlias] L4 AI exception', ['err' => $e->getMessage()]);
            return ['ok' => true];  // fail-open，不阻塞用户
        }
    }

    /* ─────── L5: 行为风控 ─────── */

    private function checkBehavior(int $userId, int $aliasType, string $registerIp): array
    {
        // 2026-05-26: 默认值放宽
        //   user_cooldown_days 7 → 3 (两次兑换间隔不需要这么久, 正常用户可能想拿多个档)
        //   ip_register_max_per_window 2 → 10 (NAT 出口 IP 共享场景, 公司/家庭多人同 IP 不应互相限制)
        //   ip_register_window_days 7 → 3 (窗口缩短, 减少历史误伤)

        // 用户冷却（同一用户两次兑换间隔）
        $cooldownDays = (int) $this->config('user_cooldown_days', 3);
        $cutoffSec = time() - $cooldownDays * 86400;

        $recentByUser = DB::table('v2_invite_alias')
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1, 2])
            ->where('created_at', '>=', $cutoffSec)
            ->count();

        if ($recentByUser > 0) {
            return self::fail('L5', 'user_cooldown',
                "uid={$userId} cooldown {$cooldownDays}d",
                "两次兑换间隔需至少 {$cooldownDays} 天");
        }

        // VIP 例外: 已邀请 ≥20 人或账号 ≥180d 跳过 IP 节流 (高信用用户不应被 NAT 误伤)
        $vipExemptInvites = (int) $this->config('ip_throttle_exempt_invites', 20);
        $vipExemptAgeDays = (int) $this->config('ip_throttle_exempt_age_days', 180);
        $bypass = false;
        if ($vipExemptInvites > 0) {
            $inviteCount = DB::table('v2_user')->where('invite_user_id', $userId)->count();
            if ($inviteCount >= $vipExemptInvites) {
                $bypass = true;
            }
        }
        if (!$bypass && $vipExemptAgeDays > 0) {
            $createdAt = DB::table('v2_user')->where('id', $userId)->value('created_at');
            if ($createdAt && (time() - $createdAt) >= $vipExemptAgeDays * 86400) {
                $bypass = true;
            }
        }

        // 同 IP 窗口
        if (!$bypass) {
            $windowDays = (int) $this->config('ip_register_window_days', 3);
            $maxPerWindow = (int) $this->config('ip_register_max_per_window', 10);
            $cutoffSec2 = time() - $windowDays * 86400;

            $sameIpCount = DB::table('v2_invite_alias')
                ->where('register_ip', $registerIp)
                ->whereIn('status', [0, 1, 2])
                ->where('created_at', '>=', $cutoffSec2)
                ->count();

            if ($sameIpCount >= $maxPerWindow) {
                return self::fail('L5', 'ip_throttle',
                    "ip={$registerIp} {$sameIpCount}/{$maxPerWindow} in {$windowDays}d",
                    "同 IP 近 {$windowDays} 天已有 {$sameIpCount} 个别名注册，超过上限 {$maxPerWindow}。如多人共享网络，请稍后再试或在不同网络下重试");
            }
        }

        // 邀请人数门槛（仅 1888/8888 档）
        if ($aliasType >= 2) {
            $minInvite = $aliasType === 3
                ? (int) $this->config('min_invite_count_brand', 10)
                : (int) $this->config('min_invite_count_isolated', 3);

            if ($minInvite > 0) {
                $inviteCount = DB::table('v2_user')
                    ->where('invite_user_id', $userId)
                    ->count();

                if ($inviteCount < $minInvite) {
                    return self::fail('L5', 'invite_threshold',
                        "uid={$userId} invited {$inviteCount} < {$minInvite}",
                        "需累计成功邀请 ≥ {$minInvite} 人，当前 {$inviteCount} 人");
                }
            }
        }

        // 主域档：账号年龄 ≥ N 天
        if ($aliasType === 3) {
            $minAgeDays = (int) $this->config('min_account_age_days_brand', 30);
            if ($minAgeDays > 0) {
                $createdAt = DB::table('v2_user')->where('id', $userId)->value('created_at');
                if ($createdAt && (time() - $createdAt) < $minAgeDays * 86400) {
                    return self::fail('L5', 'account_age',
                        "uid={$userId} age <{$minAgeDays}d",
                        "主域档要求账号创建满 {$minAgeDays} 天");
                }
            }
        }

        return ['ok' => true];
    }

    /* ─────── 数据加载 / helpers ─────── */

    /**
     * 加载并缓存保留名清单
     * 5 分钟 Redis 缓存；变更需 Cache::forget('invite_alias.reserved')
     */
    private function loadReservedList(): array
    {
        return Cache::remember('invite_alias.reserved', 300, function () {
            $path = __DIR__ . '/reserved-names.json';
            if (!is_readable($path)) {
                Log::error('[InviteAlias] reserved-names.json missing');
                return ['names_set' => [], 'patterns' => [], 'high_value' => []];
            }

            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data)) {
                return ['names_set' => [], 'patterns' => [], 'high_value' => []];
            }

            // 重组为 O(1) lookup map
            $namesSet = [];
            $highValueCats = ['yue_brand', 'brand', 'authority', 'sensitive', 'crypto_phishing'];
            $highValue = [];

            // 由于 names 已排序、不带分类，需要重新映射
            // 改进：在 PHP 侧用 categories 数据重建
            // 当前文件 names 是扁平 list，我们重新按 jedireza-only 推断 vs 自定义
            foreach ($data['names'] ?? [] as $name) {
                $namesSet[$name] = 'reserved';
            }

            // high-value 从硬编码 list 取（与 reserved-names.json 同步维护）
            foreach (self::HIGH_VALUE_REFERENCE as $word => $cat) {
                if (in_array($cat, $highValueCats, true)) {
                    $highValue[$word] = $cat;
                }
            }

            return [
                'names_set'  => $namesSet,
                'patterns'   => $data['patterns'] ?? [],
                'high_value' => $highValue,
            ];
        });
    }

    /**
     * 高价值保留词参考（L3 同形检测专用）
     * 完整 1172 词清单走 names_set；这里只列易被同形攻击的核心
     */
    const HIGH_VALUE_REFERENCE = [
        // yue brand
        'yue' => 'yue_brand', 'yueto' => 'yue_brand', 'yuelink' => 'yue_brand',
        'yuevideo' => 'yue_brand', 'yuebao' => 'yue_brand',
        // 巨头商标
        'apple' => 'brand', 'google' => 'brand', 'microsoft' => 'brand',
        'amazon' => 'brand', 'meta' => 'brand', 'facebook' => 'brand',
        'twitter' => 'brand', 'youtube' => 'brand', 'tiktok' => 'brand',
        'telegram' => 'brand', 'wechat' => 'brand', 'weixin' => 'brand',
        'baidu' => 'brand', 'alipay' => 'brand', 'tencent' => 'brand',
        // 支付
        'paypal' => 'brand', 'stripe' => 'brand', 'visa' => 'brand',
        'binance' => 'brand', 'coinbase' => 'brand', 'okx' => 'brand',
        // 监管 / 政府
        'gov' => 'authority', 'admin' => 'authority', 'official' => 'authority',
        'support' => 'authority', 'police' => 'authority', 'bank' => 'authority',
        // 灰产
        'porn' => 'sensitive', 'casino' => 'sensitive', 'bet' => 'sensitive',
        'hack' => 'sensitive', 'crack' => 'sensitive', 'drug' => 'sensitive',
        // 加密币（钓鱼热点）
        'bitcoin' => 'crypto_phishing', 'btc' => 'crypto_phishing',
        'ethereum' => 'crypto_phishing', 'eth' => 'crypto_phishing',
        'usdt' => 'crypto_phishing', 'wallet' => 'crypto_phishing',
        'metamask' => 'crypto_phishing', 'airdrop' => 'crypto_phishing',
    ];

    private function config(string $key, $default)
    {
        return PluginConfig::get($key, $default);
    }

    private static function fail(string $layer, string $code, string $reason, string $publicMsg): array
    {
        return [
            'ok'         => false,
            'layer'      => $layer,
            'code'       => $code,
            'reason'     => $reason,
            'public_msg' => $publicMsg,
        ];
    }
}
