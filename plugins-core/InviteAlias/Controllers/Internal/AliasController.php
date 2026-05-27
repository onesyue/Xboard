<?php

namespace Plugin\InviteAlias\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Plugin\InviteAlias\Controllers\User\AliasController as UserAliasController;
use Plugin\InviteAlias\Services\AliasResolver;
use Plugin\InviteAlias\Services\AliasValidator;

/**
 * 内部接口（供 TG bot / yueops 调用）
 *
 * 鉴权：IP 白名单 (127.0.0.1, 23.80.91.14) + Header X-Internal-Token
 *
 * 与 User 端逻辑相同，但 user 身份从 body._account_id 解析（绕过 JWT）
 *
 * 路由：见 routes/api.php
 */
class AliasController extends Controller
{
    const ALLOWED_IPS = ['127.0.0.1', '::1', '23.80.91.14'];

    public function __construct(
        private AliasValidator $validator,
        private AliasResolver $resolver,
        private UserAliasController $userCtl
    ) {}

    /**
     * 鉴权 + 加载用户
     */
    private function authenticate(Request $request): User
    {
        if (!in_array($request->ip(), self::ALLOWED_IPS, true)) {
            abort(403, 'IP not in whitelist');
        }

        $token = $request->header('X-Internal-Token');
        $expected = \Plugin\InviteAlias\Services\PluginConfig::get('internal_token', '');
        if (!$expected || !hash_equals($expected, (string) $token)) {
            abort(403, 'invalid internal token');
        }

        $accountId = (int) $request->input('_account_id', 0);
        if ($accountId <= 0) abort(400, 'missing _account_id');

        $user = User::find($accountId);
        if (!$user) abort(404, 'user not found');

        return $user;
    }

    /**
     * Pseudo-bind user 到 request，让 UserController 方法把它当作 logged-in user
     */
    private function bindUserToRequest(Request $request, User $user): void
    {
        $request->setUserResolver(fn() => $user);
    }

    public function policy(Request $request)
    {
        $user = $this->authenticate($request);
        $this->bindUserToRequest($request, $user);
        return $this->userCtl->policy($request);
    }

    public function mine(Request $request)
    {
        $user = $this->authenticate($request);
        $this->bindUserToRequest($request, $user);
        return $this->userCtl->mine($request);
    }

    public function precheck(Request $request)
    {
        $user = $this->authenticate($request);
        $this->bindUserToRequest($request, $user);
        return $this->userCtl->precheck($request);
    }

    public function redeemPending(Request $request)
    {
        $user = $this->authenticate($request);
        $this->bindUserToRequest($request, $user);
        return $this->userCtl->redeemPending($request);
    }

    public function confirm(Request $request)
    {
        $user = $this->authenticate($request);
        $this->bindUserToRequest($request, $user);
        return $this->userCtl->confirm($request);
    }

    public function releasePending(Request $request)
    {
        $user = $this->authenticate($request);
        $this->bindUserToRequest($request, $user);
        return $this->userCtl->releasePending($request);
    }

    /**
     * 仅查询 alias → user_id（无需 _account_id）
     * 此方法不依赖用户身份
     */
    public function resolve(Request $request)
    {
        if (!in_array($request->ip(), self::ALLOWED_IPS, true)) {
            abort(403);
        }
        $token = $request->header('X-Internal-Token');
        $expected = \Plugin\InviteAlias\Services\PluginConfig::get('internal_token', '');
        if (!$expected || !hash_equals($expected, (string) $token)) {
            abort(403);
        }

        $alias = strtolower(trim($request->input('alias', '')));
        $aliasType = (int) $request->input('alias_type', 0);

        $zone = match ($aliasType) {
            2 => 'i.yue.to',
            3 => 'yue.to',
            default => '-',
        };

        $info = $this->resolver->resolve($zone, $alias);
        return response(['data' => $info]);
    }
}
