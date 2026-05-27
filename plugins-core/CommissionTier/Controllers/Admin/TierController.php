<?php

namespace Plugin\CommissionTier\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugin\CommissionTier\Services\TierService;

class TierController extends Controller
{
    /**
     * GET /api/v2/admin/commission-tier/stats
     *  - 各 tier 当前在线人数分布
     *  - 窗口总 GMV / 总返利
     *  - Top 20 inviter
     */
    public function stats(Request $request, TierService $svc): JsonResponse
    {
        $cfg = $svc->config();
        $tiers = $cfg['tiers'];
        $windowDays = $cfg['window_days'];
        $since = time() - $windowDays * 86400;

        $distribution = DB::table('commission_tier_user')
            ->select('current_tier', DB::raw('COUNT(*) as users'))
            ->groupBy('current_tier')
            ->orderBy('current_tier')
            ->get()
            ->keyBy('current_tier');

        $rows = [];
        foreach ($tiers as $t) {
            $level = (int) $t['level'];
            $rows[] = [
                'level' => $level,
                'name' => $t['name'],
                'badge' => $t['badge'] ?? '',
                'rate' => (int) $t['rate'],
                'threshold_yuan' => (int) $t['threshold'] / 100,
                'users' => (int) ($distribution[$level]->users ?? 0),
            ];
        }

        $totalCommission = (int) DB::table('v2_commission_log')
            ->where('created_at', '>', $since)
            ->sum('get_amount');
        $totalGmv = (int) DB::table('v2_order')
            ->whereNotNull('invite_user_id')
            ->where('status', 3)
            ->where(function ($q) use ($since) {
                $q->where('paid_at', '>', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('paid_at')->where('created_at', '>', $since);
                    });
            })
            ->sum('total_amount'); /* 2026-05-27 policy: balance 不算返佣 GMV */

        $top = DB::table('v2_order as o')
            ->select('o.invite_user_id as user_id',
                DB::raw('SUM(o.total_amount) as total'),
                DB::raw('COUNT(DISTINCT o.user_id) as invites'))
            ->whereNotNull('o.invite_user_id')
            ->where('o.status', 3)
            ->where(function ($q) use ($since) {
                $q->where('o.paid_at', '>', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('o.paid_at')->where('o.created_at', '>', $since);
                    });
            })
            ->groupBy('o.invite_user_id')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(function ($r) use ($svc) {
                $email = (string) DB::table('v2_user')->where('id', $r->user_id)->value('email');
                $tier = $svc->resolve((int) $r->user_id);
                return [
                    'user_id' => (int) $r->user_id,
                    'email_masked' => $this->maskEmail($email),
                    'gmv_yuan' => (int) $r->total / 100,
                    'invites' => (int) $r->invites,
                    'tier' => $tier['level'],
                    'tier_name' => $tier['name'],
                ];
            });

        return response()->json([
            'data' => [
                'config' => $cfg,
                'window_days' => $windowDays,
                'distribution' => $rows,
                'total_commission_yuan' => $totalCommission / 100,
                'total_gmv_yuan' => $totalGmv / 100,
                'effective_rate' => $totalGmv > 0 ? round($totalCommission * 100.0 / $totalGmv, 2) : 0,
                'top_inviters' => $top,
            ],
        ]);
    }

    /**
     * POST /api/v2/admin/commission-tier/recompute  → 立刻跑一次重算（避免等到次日 03:30）
     */
    public function recomputeNow(Request $request): JsonResponse
    {
        \Artisan::call('commission-tier:recompute');
        return response()->json(['data' => ['ok' => true, 'output' => \Artisan::output()]]);
    }

    /**
     * GET /api/v2/admin/commission-tier/user/{id}  → 单个用户详情（debug）
     */
    public function userDetail(Request $request, TierService $svc, int $id): JsonResponse
    {
        $tier = $svc->resolve($id, true);
        $row = DB::table('commission_tier_user')->where('user_id', $id)->first();
        return response()->json(['data' => [
            'realtime' => $tier,
            'stored' => $row,
        ]]);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }
        [$u, $d] = explode('@', $email, 2);
        $masked = strlen($u) <= 2 ? $u : substr($u, 0, 2) . str_repeat('*', max(1, strlen($u) - 2));
        return $masked . '@' . $d;
    }
}
