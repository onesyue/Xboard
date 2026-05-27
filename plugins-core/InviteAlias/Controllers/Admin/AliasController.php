<?php

namespace Plugin\InviteAlias\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugin\InviteAlias\Services\AliasResolver;

/**
 * 管理端控制器（v1.0 stub —— 提供基本只读 + ban）
 *
 * v1.5 完整实现：refund / forceDormant / 列表 + 风控视图
 */
class AliasController extends Controller
{
    public function __construct(private AliasResolver $resolver) {}

    public function list(Request $request)
    {
        $status = $request->input('status');
        $type = $request->input('alias_type');

        $q = DB::table('v2_invite_alias')
            ->select('id', 'user_id', 'alias_type', 'alias', 'zone', 'status',
                     'cost_points', 'click_count', 'conv_count', 'created_at', 'banned_at');

        if ($status !== null && $status !== '') {
            $q->where('status', (int) $status);
        }
        if ($type !== null && $type !== '') {
            $q->where('alias_type', (int) $type);
        }

        $rows = $q->orderBy('created_at', 'desc')->limit(200)->get();

        return response(['data' => $rows]);
    }

    public function stats(Request $request)
    {
        $stats = DB::select("
            SELECT
              alias_type,
              status,
              COUNT(*) AS cnt,
              COALESCE(SUM(click_count), 0) AS total_clicks,
              COALESCE(SUM(conv_count), 0)  AS total_convs
            FROM v2_invite_alias
            GROUP BY alias_type, status
            ORDER BY alias_type, status
        ");

        return response(['data' => $stats]);
    }

    public function detail(int $id)
    {
        $row = DB::table('v2_invite_alias')->where('id', $id)->first();
        if (!$row) abort(404);
        return response(['data' => $row]);
    }

    public function ban(Request $request, int $id)
    {
        $reason = trim($request->input('reason', '管理员手动下架'));
        $now = time();

        $row = DB::table('v2_invite_alias')->where('id', $id)->first();
        if (!$row) abort(404);

        DB::transaction(function () use ($row, $reason, $now, $id) {
            DB::table('v2_invite_alias')->where('id', $id)->update([
                'status' => 3,  // banned
                'banned_at' => $now,
                'ban_reason' => $reason,
                'updated_at' => $now,
            ]);
            // 同步禁用 v2_invite_code.status=true，让 handleInviteCode 找不到
            DB::table('v2_invite_code')
                ->where('user_id', $row->user_id)
                ->where('code', $row->alias)
                ->update(['status' => true, 'updated_at' => $now]);
        });

        if ($row->zone !== '-') {
            $this->resolver->invalidate($row->zone, strtolower($row->alias));
        }

        return response(['data' => ['banned' => true, 'alias_id' => $id]]);
    }

    public function unban(int $id)
    {
        $row = DB::table('v2_invite_alias')->where('id', $id)->first();
        if (!$row || $row->status !== 3) abort(404);
        $now = time();

        DB::transaction(function () use ($row, $now, $id) {
            DB::table('v2_invite_alias')->where('id', $id)->update([
                'status' => 1,  // active
                'banned_at' => null,
                'ban_reason' => null,
                'updated_at' => $now,
            ]);
            // 同步恢复 v2_invite_code.status=false，handleInviteCode 重新可用
            DB::table('v2_invite_code')
                ->where('user_id', $row->user_id)
                ->where('code', $row->alias)
                ->update(['status' => false, 'updated_at' => $now]);
        });

        if ($row->zone !== '-') {
            $this->resolver->invalidate($row->zone, strtolower($row->alias));
        }

        return response(['data' => ['unbanned' => true, 'alias_id' => $id]]);
    }

    public function forceDormant(int $id)
    {
        // dormant 不影响归属（仅前端展示告警）；alias 仍可正常用，鼓励用户续订恢复
        // 仅允许从 active(1) → dormant(2)。banned/released/pending 都不该直接转 dormant：
        //   - banned 应先 unban（恢复 invite_code 后再考虑 dormant 的 lifecycle）
        //   - released 是终态，转 dormant 会让冷却期紊乱
        //   - pending 应该走 release-pending 释放
        $row = DB::table('v2_invite_alias')->where('id', $id)->first();
        if (!$row) abort(404);
        if ((int) $row->status !== 1) {
            return response(['error' => ['message' => '只有 active 状态可强制转 dormant，当前 status=' . $row->status]], 400);
        }
        DB::table('v2_invite_alias')->where('id', $id)->update([
            'status' => 2,
            'dormant_at' => time(),
            'updated_at' => time(),
        ]);
        return response(['data' => ['ok' => true]]);
    }

    public function refund(Request $request, int $id)
    {
        // v1.0：仅标记 audit_meta，实际退积分需 ops 手工在 user_account 表 add_user_points
        // v1.5：将通过 yueops 内部 API 直接调 yue bot 的 dao.gambling.add_user_points
        $pct = (int) $request->input('pct', 100);
        DB::table('v2_invite_alias')->where('id', $id)->update([
            'meta' => DB::raw("COALESCE(meta, '{}'::jsonb) || '" . json_encode(['refund_marked' => true, 'refund_pct' => $pct, 'refund_at' => time()]) . "'::jsonb"),
            'updated_at' => time(),
        ]);
        return response(['data' => ['marked' => true, 'note' => 'v1.0 仅标记 audit；实际退分需 ops 手工执行（v1.5 自动化）']]);
    }
}
