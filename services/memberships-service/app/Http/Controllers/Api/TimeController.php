<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\AccessService;
use App\Memberships\Duration;
use App\Memberships\TimeBank;
use App\Models\TimeMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contract: time balances (days / hours / minutes)
 *  GET  /api/me/time                       student: plan days left + study rooms (hours) + library (minutes)
 *  POST /api/me/time/purchase              student {module: study_rooms|library, quantity} — extra time with credits
 */
class TimeController extends Controller
{
    public function show(Request $request, TimeBank $time, AccessService $access): JsonResponse
    {
        $userId = $this->studentId($request);
        $context = $access->context($userId);

        return response()->json([
            'plan' => $context['plan']->only(['code', 'name']),
            // Time in days: what is left of the paid plan (Basic has no end).
            'plan_remaining' => $context['subscription'] ? Duration::parts($context['subscription']->remaining_seconds) : null,
            'cycle_end' => $context['end']->toIso8601String(),
            'modules' => [
                'study_rooms' => $time->status($userId, 'study_rooms'),
                'library' => $time->status($userId, 'library'),
            ],
            'movements' => TimeMovement::where('user_id', $userId)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function purchase(Request $request, TimeBank $time): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate([
            'module' => ['required', Rule::in(array_keys(TimeBank::MODULES))],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('memberships.max_purchase_quantity', 20)],
        ]);

        $result = $time->buy($userId, $data['module'], $data['quantity'], $this->ledgerActor($request));

        return response()->json([
            'message' => 'Compraste '.Duration::human($result['seconds']).' por '.$result['credits'].' créditos.',
            ...$result,
        ], 201);
    }
}
