<?php

namespace Plugin\InviteAlias\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\InviteAlias\Services\AliasResolver;
use Plugin\InviteAlias\Services\AliasValidator;
use Plugin\InviteAlias\Services\AdminNotifier;

/**
 * 用户端别名控制器
 *
 * 两阶段提交（防 TG bot 扣分与 XBoard 写库不一致）：
 *  1. POST /precheck         L1-L5 校验 + 占位检查（不写库）
 *  2. POST /redeem-pending   再走 L1-L5 + 创建 pending 占位（status=0）+ 返回 alias_id
 *  3. [TG bot 扣 gambling_points 1888]
 *  4. POST /confirm          扣分成功后激活（status=1）
 *  5. POST /release-pending  扣分失败回滚释放
 *
 * 兜底：CleanupPending cron 每分钟扫超时未 confirm 的，自动 release
 */
class AliasController extends Controller
{
    const TYPE_INVITE_CODE   = 1;
    const TYPE_ISOLATED_SUB  = 2;  // *.i.yue.to
    const TYPE_BRAND_SUB     = 3;  // *.yue.to

    public function __construct(
        private AliasValidator $validator,
        private AliasResolver $resolver
    ) {}

    /* ─────── GET /policy ─────── */

    public function policy(Request $request)
    {
        $user = $request->user();

        // 当前用户已邀请人数
        $invitedCount = DB::table('v2_user')
            ->where('invite_user_id', $user->id)
            ->count();

        $accountAgeDays = max(0, (int) ((time() - ($user->created_at ?? time())) / 86400));

        return response([
            'data' => [
                'tiers' => [
                    [
                        'type' => self::TYPE_INVITE_CODE,
                        'name' => '银 · 自定义邀请码',
                        'price' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('price_invite_code', 888),
                        'min_invite_count' => 0,
                        'min_account_age_days' => 0,
                        'preview' => 'https://my.yue.to/#/register?code=<你的名字>',
                        'aff_strength' => 2,
                    ],
                    [
                        'type' => self::TYPE_ISOLATED_SUB,
                        'name' => '金 · 隔离子域',
                        'price' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('price_subdomain_isolated', 1888),
                        'min_invite_count' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('min_invite_count_isolated', 3),
                        'min_account_age_days' => 0,
                        'preview' => 'https://<你的名字>.i.yue.to',
                        'aff_strength' => 4,
                    ],
                    [
                        'type' => self::TYPE_BRAND_SUB,
                        'name' => '铂金 · 主域子域',
                        'price' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('price_subdomain_brand', 8888),
                        'min_invite_count' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('min_invite_count_brand', 10),
                        'min_account_age_days' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('min_account_age_days_brand', 30),
                        'preview' => 'https://<你的名字>.yue.to',
                        'aff_strength' => 5,
                    ],
                ],
                'user' => [
                    'invited_count' => $invitedCount,
                    'account_age_days' => $accountAgeDays,
                    'invite_code' => $this->fetchInviteCode($user->id),
                ],
                'rules' => [
                    'alias_min_length' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('alias_min_length', 3),
                    'alias_max_length' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('alias_max_length', 20),
                    'cooldown_days' => (int) \Plugin\InviteAlias\Services\PluginConfig::get('user_cooldown_days', 7),
                    'cookie_domain' => \Plugin\InviteAlias\Services\PluginConfig::get('cookie_domain', '.yue.to'),
                    'note' => '永久持有，需保持账号活跃。一旦兑换不可修改不可转让。',
                ],
            ],
        ]);
    }

    /* ─────── GET /mine ─────── */

    public function mine(Request $request)
    {
        $user = $request->user();
        $rows = DB::table('v2_invite_alias')
            ->where('user_id', $user->id)
            ->whereIn('status', [1, 2])  // active / dormant
            ->select('id','alias_type','alias','zone','status','cost_points','click_count','conv_count','last_subscribed_at','dormant_at','created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response(['data' => $rows]);
    }

    /* ─────── POST /precheck ─────── */

    public function precheck(Request $request)
    {
        $user = $request->user();
        $alias = strtolower(trim($request->input('alias', '')));
        $aliasType = (int) $request->input('alias_type', 0);

        if (!in_array($aliasType, [self::TYPE_INVITE_CODE, self::TYPE_ISOLATED_SUB, self::TYPE_BRAND_SUB], true)) {
            abort(400, '无效的兑换档位');
        }

        // 2026-05-26: precheck 仅 L1-L4 (名字本身可用性), 跳 L5 行为风控.
        // 用户在"检查并开通"时, 应该先看"名字可用", 真正确认兑换走 redeemPending 才检查行为限制.
        // 之前 precheck 直接跑 L5 导致 NAT IP 共享场景误伤"检测到异常注册行为", UX 差.
        $r = $this->validator->validate($alias, (int) $user->id, $aliasType, $request->ip(), false);
        if (!$r['ok']) {
            return response([
                'data' => [
                    'available' => false,
                    'reason' => $r['public_msg'],
                    'layer' => $r['layer'],
                    'code' => $r['code'],
                ],
            ]);
        }

        // L6 唯一性 dry check（不锁，只查可用）
        // pending(0) 已过期视同空位（cleanup 每分钟跑，但此处提前 filter 避免 0-60s 误判 taken）
        $zone = $this->zoneFor($aliasType);
        $now = time();
        $taken = DB::table('v2_invite_alias')
            ->where('zone', $zone)
            ->where('alias_lower', $alias)
            ->whereIn('status', [0, 1, 2])
            ->where(function ($q) use ($now) {
                $q->where('status', '!=', 0)
                  ->orWhereNull('pending_expires_at')
                  ->orWhere('pending_expires_at', '>', $now);
            })
            ->exists();

        if ($taken) {
            return response([
                'data' => [
                    'available' => false,
                    'reason' => '该名称已被他人占用，请换一个',
                    'layer' => 'L6',
                    'code' => 'taken',
                ],
            ]);
        }

        // 释放冷却期
        $cooldownDays = (int) \Plugin\InviteAlias\Services\PluginConfig::get('release_cooldown_days', 30);
        $coolingDown = DB::table('v2_invite_alias')
            ->where('zone', $zone)
            ->where('alias_lower', $alias)
            ->where('status', 4)  // released
            ->where('released_at', '>=', time() - $cooldownDays * 86400)
            ->exists();

        if ($coolingDown) {
            return response([
                'data' => [
                    'available' => false,
                    'reason' => "该名称在冷却期内（释放后 {$cooldownDays} 天），尚不可申请",
                    'layer' => 'L6',
                    'code' => 'cooling',
                ],
            ]);
        }

        $price = $this->priceFor($aliasType);

        return response([
            'data' => [
                'available' => true,
                'alias' => $alias,
                'alias_type' => $aliasType,
                'zone' => $zone,
                'preview' => $this->previewFor($aliasType, $alias, $this->fetchInviteCode($user->id)),
                'cost_points' => $price,
            ],
        ]);
    }

    /* ─────── POST /redeem-pending ─────── */

    public function redeemPending(Request $request)
    {
        $user = $request->user();
        $alias = strtolower(trim($request->input('alias', '')));
        $aliasType = (int) $request->input('alias_type', 0);

        if (!in_array($aliasType, [self::TYPE_INVITE_CODE, self::TYPE_ISOLATED_SUB, self::TYPE_BRAND_SUB], true)) {
            abort(400, '无效的兑换档位');
        }

        $r = $this->validator->validate($alias, (int) $user->id, $aliasType, $request->ip());
        if (!$r['ok']) {
            return response(['error' => ['layer' => $r['layer'], 'code' => $r['code'], 'message' => $r['public_msg']]], 400);
        }

        $zone = $this->zoneFor($aliasType);
        $price = $this->priceFor($aliasType);
        $ttlMin = (int) \Plugin\InviteAlias\Services\PluginConfig::get('pending_ttl_minutes', 30);
        $now = time();

        try {
            $aliasId = DB::transaction(function () use ($user, $alias, $aliasType, $zone, $price, $ttlMin, $now, $request) {
                // 内联回收：同 zone+alias 下已过期 pending → release，避免 cleanup 60s 间隔
                // 误把 INSERT 撞到 uq_alias_active_zone（与 precheck 的 B17 filter 保持一致）
                DB::table('v2_invite_alias')
                    ->where('zone', $zone)
                    ->where('alias_lower', $alias)
                    ->where('status', 0)
                    ->whereNotNull('pending_expires_at')
                    ->where('pending_expires_at', '<', $now)
                    ->update([
                        'status' => 4,
                        'released_at' => $now,
                        'pending_expires_at' => null,
                        'updated_at' => $now,
                    ]);

                // 抢锁式插入：partial unique index 保证并发安全
                // 如果撞已存在 active/pending → 抛 QueryException
                $id = DB::table('v2_invite_alias')->insertGetId([
                    'user_id' => $user->id,
                    'alias_type' => $aliasType,
                    'alias' => $alias,
                    'alias_lower' => $alias,
                    'zone' => $zone,
                    'status' => 0,  // pending
                    'cost_points' => $price,
                    'pending_expires_at' => $now + $ttlMin * 60,
                    'register_ip' => $request->ip(),
                    'register_ua' => $request->userAgent(),
                    'audit_trail' => json_encode([
                        'precheck_ok' => true,
                        'created_via' => 'redeem-pending',
                        'created_at' => $now,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return $id;
            });

            return response([
                'data' => [
                    'alias_id' => $aliasId,
                    'alias' => $alias,
                    'alias_type' => $aliasType,
                    'zone' => $zone,
                    'cost_points' => $price,
                    'pending_expires_at' => $now + $ttlMin * 60,
                    'next' => '请在 TG bot 完成积分扣除后调 /confirm',
                ],
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            // 23505 = PG unique_violation
            if (str_contains($e->getMessage(), 'uq_alias_active_zone')
                || str_contains((string) $e->getCode(), '23505')) {
                return response(['error' => ['code' => 'taken', 'message' => '该名称已被占用']], 409);
            }
            throw $e;
        }
    }

    /* ─────── POST /confirm ─────── */

    public function confirm(Request $request)
    {
        $user = $request->user();
        $aliasId = (int) $request->input('alias_id', 0);

        if ($aliasId <= 0) abort(400, '缺少 alias_id');

        $now = time();

        try {
            $result = DB::transaction(function () use ($user, $aliasId, $now) {
                // 锁行
                $row = DB::table('v2_invite_alias')
                    ->where('id', $aliasId)
                    ->where('user_id', $user->id)
                    ->where('status', 0)  // 必须是 pending
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    return ['ok' => false, 'reason' => 'pending 别名不存在或已过期'];
                }

                if ($row->pending_expires_at && $row->pending_expires_at < $now) {
                    return ['ok' => false, 'reason' => 'pending 已过期，请重新发起兑换'];
                }

                // 全部档位都 INSERT v2_invite_code（与 XBoard 标准邀请流统一）
                //   - type=1: alias 本身就是邀请码
                //   - type=2/3: alias 同时作为邀请码（nginx 302 → /#/register?code= 通过此表归属）
                //
                // ⚠️ status 必须 = false（UNUSED）。XBoard RegisterService::handleInviteCode
                //    查的是 `WHERE status = false`（未被标记 USED），admin_setting('invite_never_expire')=1
                //    时找到后不会改 status，所以永久有效。写 status=true 会让归属彻底失效。
                $codeExists = DB::table('v2_invite_code')
                    ->where('code', $row->alias)
                    ->where('status', false)  // 仅检查 active code（USED 的码相当于已废弃，可复用）
                    ->exists();
                if ($codeExists) {
                    return ['ok' => false, 'reason' => '该名称已作为邀请码占用，请换一个'];
                }
                DB::table('v2_invite_code')->insert([
                    'user_id'    => $user->id,
                    'code'       => $row->alias,
                    'status'     => false,   // UNUSED，handleInviteCode 才能找到
                    'pv'         => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 激活 alias
                DB::table('v2_invite_alias')
                    ->where('id', $aliasId)
                    ->update([
                        'status' => 1,  // active
                        'pending_expires_at' => null,
                        'last_subscribed_at' => $now,  // 兑换时视为活跃，开始 90d 倒计时
                        'audit_trail' => DB::raw("COALESCE(audit_trail, '{}'::jsonb) || '" . json_encode(['confirmed_at' => $now]) . "'::jsonb"),
                        'updated_at' => $now,
                    ]);

                return [
                    'ok' => true,
                    'alias_id' => $aliasId,
                    'alias' => $row->alias,
                    'alias_type' => $row->alias_type,
                    'zone' => $row->zone,
                ];
            });

            if (!$result['ok']) {
                return response(['error' => ['message' => $result['reason']]], 400);
            }

            // 缓存失效
            if ($result['zone'] !== '-') {
                $this->resolver->invalidate($result['zone'], strtolower($result['alias']));
            }

            // 重要兑换告警 admin（type=3 主域 / type=2 隔离域均通知，type=1 不打扰）
            if ((int) $result['alias_type'] >= 2) {
                AdminNotifier::send('新 alias 兑换', [
                    'user_id' => $user->id,
                    'alias' => $result['alias'],
                    'tier' => $result['alias_type'],
                    'zone' => $result['zone'],
                ]);
            }

            return response([
                'data' => [
                    'alias_id' => $result['alias_id'],
                    'alias' => $result['alias'],
                    'alias_type' => $result['alias_type'],
                    'zone' => $result['zone'],
                    'public_url' => $this->previewFor($result['alias_type'], $result['alias'], $this->fetchInviteCode($user->id)),
                ],
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            // 23505 = PG unique_violation。confirm 阶段 INSERT v2_invite_code 撞 uq_invite_code_active
            // 是极罕见 race（precheck/redeem 已查过，但 race window 仍可能命中）—— 给前端友好提示
            $msg = $e->getMessage();
            Log::warning('[InviteAlias] confirm db conflict', [
                'alias_id' => $aliasId,
                'user_id' => $user->id,
                'sqlstate' => $e->getCode(),
            ]);
            if (str_contains($msg, 'uq_invite_code_active') || str_contains((string) $e->getCode(), '23505')) {
                return response(['error' => ['code' => 'taken', 'message' => '该名称已被占用，请重新选择']], 409);
            }
            return response(['error' => ['code' => 'db_error', 'message' => '激活失败，请稍后重试']], 500);
        } catch (\Throwable $e) {
            Log::error('[InviteAlias] confirm failure', [
                'alias_id' => $aliasId,
                'user_id' => $user->id,
                'err' => $e->getMessage(),
            ]);
            // 不外泄内部错误信息（防 PG 错误链路暴露表/列结构）
            return response(['error' => ['code' => 'internal', 'message' => '激活失败，请稍后重试']], 500);
        }
    }

    /* ─────── POST /release-pending ─────── */

    public function releasePending(Request $request)
    {
        $user = $request->user();
        $aliasId = (int) $request->input('alias_id', 0);

        $affected = DB::table('v2_invite_alias')
            ->where('id', $aliasId)
            ->where('user_id', $user->id)
            ->where('status', 0)
            ->update([
                'status' => 4,  // released
                'released_at' => time(),
                'pending_expires_at' => null,
                'updated_at' => time(),
            ]);

        return response(['data' => ['released' => $affected > 0]]);
    }

    /* ─────── POST /appeal (被 ban 后申诉) ─────── */

    public function appeal(Request $request)
    {
        $user = $request->user();
        $aliasId = (int) $request->input('alias_id', 0);
        $reason = trim($request->input('reason', ''));

        if (!$aliasId || strlen($reason) < 10 || strlen($reason) > 500) {
            abort(400, '申诉理由 10-500 字');
        }

        $row = DB::table('v2_invite_alias')
            ->where('id', $aliasId)
            ->where('user_id', $user->id)
            ->where('status', 3)  // banned
            ->first();

        if (!$row) abort(404, '没有可申诉的 alias');

        // 申诉只有 1 次机会
        $meta = is_string($row->meta) ? json_decode($row->meta, true) : ($row->meta ?: []);
        if (!empty($meta['appealed'])) {
            return response(['error' => ['message' => '本 alias 已申诉过 1 次，不可再申诉']], 400);
        }

        // 调 AI 二审
        // verdict.source = 'ai'(真实裁决) | 'unavailable'(服务故障，本次不消耗机会)
        $verdict = $this->aiAppeal($row->alias, $row->ban_reason, $reason);

        // AI 服务异常：不持久化 appealed 标记，让用户稍后重试
        if (($verdict['source'] ?? 'ai') === 'unavailable') {
            return response([
                'error' => [
                    'code' => 'appeal_unavailable',
                    'message' => $verdict['reason'] ?? 'AI 审核服务暂不可用，请稍后再试（本次申诉机会未消耗）',
                ],
            ], 503);
        }

        DB::transaction(function () use ($row, $verdict, $reason, $meta) {
            $now = time();
            $newMeta = array_merge($meta, [
                'appealed' => true,
                'appeal_at' => $now,
                'appeal_text' => $reason,
                'appeal_verdict' => $verdict,
            ]);

            if ($verdict['valid']) {
                DB::table('v2_invite_alias')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 1,  // 恢复 active
                        'banned_at' => null,
                        'ban_reason' => null,
                        'meta' => json_encode($newMeta),
                        'updated_at' => $now,
                    ]);
                // ★ 关键修复：同步把 v2_invite_code.status 打回 false（UNUSED）
                //   ban() 时设了 status=true 让 RegisterService::handleInviteCode 找不到；
                //   appeal 通过若不打回，alias 显示"已恢复"但归属链仍断
                DB::table('v2_invite_code')
                    ->where('user_id', $row->user_id)
                    ->where('code', $row->alias)
                    ->update(['status' => false, 'updated_at' => $now]);
            } else {
                DB::table('v2_invite_alias')
                    ->where('id', $row->id)
                    ->update([
                        'meta' => json_encode($newMeta),
                        'updated_at' => $now,
                    ]);
            }
        });

        if ($verdict['valid'] && $row->zone !== '-') {
            $this->resolver->invalidate($row->zone, strtolower($row->alias));
        }

        return response([
            'data' => [
                'valid' => $verdict['valid'],
                'verdict_reason' => $verdict['reason'] ?? '',
                'restored' => $verdict['valid'],
            ],
        ]);
    }

    /* ─────── POST /internal/resolve (供 yueops/TG bot 内部查询) ─────── */

    public function internalResolve(Request $request)
    {
        // IP 白名单 + token（部署时配置）
        $ip = $request->ip();
        if (!in_array($ip, ['127.0.0.1', '::1', '23.80.91.14'], true)) {
            abort(403);
        }

        $alias = strtolower(trim($request->input('alias', '')));
        $aliasType = (int) $request->input('alias_type', 0);
        $zone = $this->zoneFor($aliasType);

        $info = $this->resolver->resolve($zone, $alias);
        return response(['data' => $info]);
    }

    /* ─────── helpers ─────── */

    private function zoneFor(int $aliasType): string
    {
        return match ($aliasType) {
            self::TYPE_ISOLATED_SUB => 'i.yue.to',
            self::TYPE_BRAND_SUB    => 'yue.to',
            default                 => '-',  // invite_code 没有 zone
        };
    }

    private function priceFor(int $aliasType): int
    {
        return (int) match ($aliasType) {
            self::TYPE_INVITE_CODE  => \Plugin\InviteAlias\Services\PluginConfig::get('price_invite_code', 888),
            self::TYPE_ISOLATED_SUB => \Plugin\InviteAlias\Services\PluginConfig::get('price_subdomain_isolated', 1888),
            self::TYPE_BRAND_SUB    => \Plugin\InviteAlias\Services\PluginConfig::get('price_subdomain_brand', 8888),
        };
    }

    private function previewFor(int $aliasType, string $alias, ?string $inviteCode = null): string
    {
        return match ($aliasType) {
            self::TYPE_INVITE_CODE  => "https://my.yue.to/#/register?code={$alias}",
            self::TYPE_ISOLATED_SUB => "https://{$alias}.i.yue.to",
            self::TYPE_BRAND_SUB    => "https://{$alias}.yue.to",
        };
    }

    /**
     * 取用户当前活跃邀请码（XBoard 用 v2_invite_code 表）
     *
     * status 语义（XBoard 原生）：
     *   false = UNUSED  —— 用户面板显示的活跃邀请码，handleInviteCode 可命中
     *   true  = USED    —— 已废弃码（注册一次性消费场景），不应展示给用户
     *
     * 必须取 status=false，否则 policy().user.invite_code 返回的是历史 USED 码，
     * widget 展示给用户会造成困惑（与 XBoard 邀请页所列码不一致）
     */
    private function fetchInviteCode(int $userId): ?string
    {
        return DB::table('v2_invite_code')
            ->where('user_id', $userId)
            ->where('status', false)
            ->orderBy('id', 'desc')
            ->value('code');
    }

    /**
     * 调 AI 二审。返回字段：
     *   valid  : bool      —— 申诉是否成立（仅 source=ai 时有意义）
     *   reason : string    —— 说明（展示给用户）
     *   source : 'ai'|'unavailable' —— ai=真实裁决（无论 valid 都计 1 次申诉）；
     *                                  unavailable=服务故障，调用方应跳过 appealed 标记
     */
    private function aiAppeal(string $alias, ?string $banReason, string $userReason): array
    {
        $apiKey = \Plugin\InviteAlias\Services\PluginConfig::get('ai_review_api_key', '');
        if (!$apiKey) {
            // 配置缺失视为服务不可用（用户不该因为运维没配 key 就吃掉申诉机会）
            return [
                'valid' => false,
                'reason' => 'AI 审核未配置，请稍后再试或联系运营',
                'source' => 'unavailable',
            ];
        }

        $endpoint = \Plugin\InviteAlias\Services\PluginConfig::get('ai_review_endpoint', 'https://openrouter.ai/api/v1/chat/completions');
        $model = \Plugin\InviteAlias\Services\PluginConfig::get('ai_review_model', 'anthropic/claude-haiku-4.5');

        $prompt = <<<EOT
你是申诉审核员。alias "{$alias}" 因 "{$banReason}" 被下架。
用户申诉理由："{$userReason}"

判断申诉是否成立：
- 用户合理解释了具体使用场景且无风险 → valid=true
- 申诉空泛（如"我不会乱用"）/ 与下架理由冲突 / 仍有风险 → valid=false

仅返回 JSON: {"valid": true/false, "reason": "50字内说明"}
EOT;

        try {
            $resp = Http::timeout(15)
                ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => '严格审核员，只返回 JSON'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 200,
                    'temperature' => 0.1,
                    'reasoning' => ['exclude' => true],
                ]);

            if (!$resp->ok()) {
                return [
                    'valid' => false,
                    'reason' => 'AI 服务暂不可用，请稍后再试',
                    'source' => 'unavailable',
                ];
            }

            $content = $resp->json('choices.0.message.content', '');
            $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
            if (preg_match('/\{[^{}]*"valid"[^{}]*\}/s', $content, $m)) {
                $content = $m[0];
            }
            $verdict = json_decode($content, true);
            if (!is_array($verdict) || !isset($verdict['valid'])) {
                // 解析失败也按服务故障处理（不消耗机会）
                Log::warning('[InviteAlias] appeal ai parse failure', ['raw' => $content]);
                return [
                    'valid' => false,
                    'reason' => 'AI 返回格式异常，请稍后再试',
                    'source' => 'unavailable',
                ];
            }
            return [
                'valid' => (bool) $verdict['valid'],
                'reason' => (string) ($verdict['reason'] ?? ''),
                'source' => 'ai',
            ];
        } catch (\Throwable $e) {
            Log::warning('[InviteAlias] appeal ai exception', ['err' => $e->getMessage()]);
            return [
                'valid' => false,
                'reason' => 'AI 调用异常，请稍后再试',
                'source' => 'unavailable',
            ];
        }
    }
}
