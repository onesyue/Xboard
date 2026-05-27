<?php

namespace Plugin\CommissionTier\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\CommissionTier\Services\TierService;

class TierController extends Controller
{
    public function info(Request $request, TierService $svc): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $tier = $svc->resolve($userId);
        $cfg = $svc->config();

        return response()->json([
            'data' => [
                'enabled' => $cfg['enabled'],
                'window_days' => $cfg['window_days'],
                'level' => $tier['level'],
                'name' => $tier['name'],
                'badge' => $tier['badge'],
                'color' => $tier['color'],
                'rate' => $tier['rate'],
                'current_amount' => $tier['current'],
                'next_level' => $tier['next_level'],
                'next_threshold' => $tier['next_threshold'],
                'gap_to_next' => $tier['next_threshold'] !== null
                    ? max(0, $tier['next_threshold'] - $tier['current'])
                    : null,
                'peak_level' => $tier['peak_level'],
                'tiers' => array_map(fn($t) => [
                    'level' => (int) $t['level'],
                    'name' => $t['name'],
                    'badge' => $t['badge'] ?? '',
                    'color' => $t['color'] ?? '#9ca3af',
                    'threshold' => (int) $t['threshold'],
                    'rate' => (int) $t['rate'],
                ], $cfg['tiers']),
            ],
        ]);
    }
}
