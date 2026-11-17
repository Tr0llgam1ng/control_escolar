<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\AccessService;
use App\Memberships\CreditLedger;
use App\Memberships\Duration;
use App\Memberships\Features;
use App\Memberships\SubscriptionService;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contract: plans and subscriptions
 *  GET  /api/plans                     any user (comparison table)
 *  PUT  /api/plans/{id}                admin {name?, description?, price_credits?, duration_days?, is_active?, features?: {key: {enabled, value}}}
 *  GET  /api/me/membership             student: plan, cycle, features with usage, wallet balance
 *  POST /api/me/subscription           student {plan_code} — buy / change plan with credits
 *  POST /api/me/subscription/cancel    student — turn auto renewal off
 *  POST /api/me/subscription/resume    student — turn it back on
 *  GET  /api/subscriptions             admin (?status=active|replaced|expired, ?user_id)
 */
class MembershipController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = Plan::with('features')->orderBy('tier')->get();

        return response()->json(['data' => $plans->map->toPublicArray()->values(), 'features' => Features::DEFINITIONS]);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $plan = Plan::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_credits' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'is_active' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
            'features.*.enabled' => ['required', 'boolean'],
            'features.*.value' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        foreach (array_keys($data['features'] ?? []) as $key) {
            abort_unless(Features::exists($key), 422, "Función desconocida: {$key}.");
            $type = Features::type($key);
            $value = $data['features'][$key]['value'] ?? null;
            abort_if($type === 'percent' && $value > 100, 422, 'Un porcentaje no puede pasar de 100.');
            abort_if(in_array($type, ['time', 'number', 'percent', 'credits'], true) && $value === null && $data['features'][$key]['enabled'], 422,
                'Indica un valor para '.Features::DEFINITIONS[$key]['label'].'.');
        }
        if ($plan->isBasic()) {
            abort_if(($data['price_credits'] ?? 0) !== 0, 422, 'El plan Básico es gratuito.');
            abort_if(array_key_exists('is_active', $data) && ! $data['is_active'], 422, 'El plan Básico no se puede desactivar.');
        }

        DB::transaction(function () use ($plan, $data) {
            $plan->fill(collect($data)->except('features')->all())->save();
            foreach ($data['features'] ?? [] as $key => $f) {
                $plan->features()->updateOrCreate(['feature' => $key], ['enabled' => $f['enabled'], 'value' => $f['value'] ?? null]);
            }
        });

        return response()->json($plan->fresh('features')->toPublicArray());
    }

    public function me(Request $request, AccessService $access, CreditLedger $ledger): JsonResponse
    {
        $userId = $this->studentId($request);

        return response()->json([...$access->snapshot($userId), 'balance' => $ledger->balance($userId)]);
    }

    public function subscribe(Request $request, SubscriptionService $subscriptions, AccessService $access, CreditLedger $ledger): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:20']], ['plan_code.required' => 'Elige un plan.']);

        $result = $subscriptions->subscribe($userId, $data['plan_code'], $this->ledgerActor($request));
        $plan = $result['subscription']->plan;
        $message = "¡Listo! Ahora tienes el plan {$plan->name} hasta el ".$result['subscription']->ends_at->format('d/m/Y H:i')
            ." ({$result['charged']} créditos".($result['refunded'] ? ", se te reembolsaron {$result['refunded']} del plan anterior" : '').').';

        return response()->json([
            'message' => $message,
            'refunded' => $result['refunded'],
            'charged' => $result['charged'],
            'membership' => [...$access->snapshot($userId), 'balance' => $ledger->balance($userId)],
        ], 201);
    }

    public function cancel(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $sub = $subscriptions->setAutoRenew($this->studentId($request), false);

        return response()->json(['message' => "Renovación desactivada. Conservas tu plan {$sub->plan->name} hasta el ".$sub->ends_at->format('d/m/Y H:i').'; después pasarás al plan Básico.', 'subscription' => $sub]);
    }

    public function resume(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $sub = $subscriptions->setAutoRenew($this->studentId($request), true);

        return response()->json(['message' => "Renovación automática activada: el {$sub->ends_at->format('d/m/Y')} se cobrarán {$sub->plan->price_credits} créditos.", 'subscription' => $sub]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Subscription::ACTIVE, Subscription::REPLACED, Subscription::EXPIRED])],
            'user_id' => ['nullable', 'integer'],
        ]);

        $rows = Subscription::with('plan:id,code,name,color')
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === Subscription::ACTIVE ? $q->where('status', $s)->where('ends_at', '>', now()) : $q->where('status', $s))
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->orderByDesc('id')->limit(500)->get()
            ->map(fn (Subscription $s) => [...$s->toArray(), 'remaining' => Duration::parts($s->isRunning() ? $s->remaining_seconds : 0)]);

        return response()->json(['data' => $rows]);
    }
}
