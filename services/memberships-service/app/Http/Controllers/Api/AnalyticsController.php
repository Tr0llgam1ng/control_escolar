<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\AccessService;
use App\Memberships\AnalyticsService;
use App\Models\AnalyticsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contract: credits module 2 — academic analysis
 *  GET  /api/analytics/quote             student: cost and reports left in the cycle
 *  GET  /api/analytics-reports           student: mine
 *  POST /api/analytics-reports           student {items: [...]} (the gateway composes them from catalog + enrollment)
 *  GET  /api/analytics-reports/{id}      owner student | admin
 */
class AnalyticsController extends Controller
{
    public function quote(Request $request, AccessService $access): JsonResponse
    {
        $userId = $this->studentId($request);
        $reports = $access->feature($userId, 'analytics.reports');

        return response()->json([
            'cost' => $access->value($userId, 'analytics.cost'),
            'reports' => $reports,
            'upgrade_to' => $reports['available'] ? [] : $access->plansWith('analytics.reports'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $this->studentId($request);

        return response()->json(['data' => AnalyticsReport::where('user_id', $userId)->orderByDesc('id')->limit(50)->get(['id', 'credits_cost', 'plan_code', 'created_at'])]);
    }

    public function store(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:200'],
            'items.*.subject' => ['required', 'string', 'max:160'],
            'items.*.code' => ['nullable', 'string', 'max:40'],
            'items.*.period' => ['nullable', 'string', 'max:80'],
            'items.*.units_count' => ['required', 'integer', 'min:0', 'max:20'],
            'items.*.status' => ['required', 'string', 'max:20'],
            'items.*.final_grade' => ['nullable', 'numeric', 'between:0,10'],
            'items.*.grades' => ['present', 'array'],
            'items.*.grades.*.unit' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.grades.*.score' => ['required', 'numeric', 'between:0,10'],
        ]);

        return response()->json($analytics->generate($userId, $data['items'], $this->ledgerActor($request)), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $report = AnalyticsReport::findOrFail($id);
        abort_unless($this->isAdmin($request) || $report->user_id === $this->studentId($request), 403, 'Solo puedes ver tus análisis.');

        return response()->json($report);
    }
}
