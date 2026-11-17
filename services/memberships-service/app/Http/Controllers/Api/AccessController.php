<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contract: authorization by plan (used by the API Gateway before routing to other services)
 *  GET  /api/access/check?feature=        → {allowed, applies, feature: {...}}
 *  POST /api/access/consume {feature, amount?}   → uses the quota; 403 PLAN_REQUIRED / PLAN_LIMIT
 *  POST /api/access/release {feature, amount?, cycle_start}  → compensation when the routed call failed
 * Only students are limited; for other roles "applies" is false and everything is allowed.
 */
class AccessController extends Controller
{
    public function check(Request $request, AccessService $access): JsonResponse
    {
        $data = $request->validate(['feature' => ['required', 'string', 'max:40']]);
        $actor = $this->actor($request);
        if (! AccessService::appliesTo($actor['role'])) {
            return response()->json(['allowed' => true, 'applies' => false]);
        }

        $feature = $access->feature((int) $actor['id'], $data['feature']);
        $allowed = $feature['available'] && ($feature['type'] !== 'quota' || $feature['unlimited'] || $feature['remaining'] > 0);

        return response()->json([
            'allowed' => $allowed,
            'applies' => true,
            'plan' => $access->context((int) $actor['id'])['plan']->only(['code', 'name']),
            'feature' => $feature,
            'upgrade_to' => $allowed ? [] : $access->plansWith($data['feature']),
        ]);
    }

    public function consume(Request $request, AccessService $access): JsonResponse
    {
        $data = $request->validate(['feature' => ['required', 'string', 'max:40'], 'amount' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $actor = $this->actor($request);
        if (! AccessService::appliesTo($actor['role'])) {
            return response()->json(['allowed' => true, 'applies' => false]);
        }

        return response()->json(['allowed' => true, 'applies' => true, ...$access->consume((int) $actor['id'], $data['feature'], $data['amount'] ?? 1)]);
    }

    public function release(Request $request, AccessService $access): JsonResponse
    {
        $data = $request->validate([
            'feature' => ['required', 'string', 'max:40'],
            'amount' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cycle_start' => ['nullable', 'date'],
        ]);
        $actor = $this->actor($request);
        if (! AccessService::appliesTo($actor['role'])) {
            return response()->json(['released' => 0]);
        }

        return response()->json(['released' => $access->release((int) $actor['id'], $data['feature'], $data['amount'] ?? 1, $data['cycle_start'] ?? null)]);
    }
}
