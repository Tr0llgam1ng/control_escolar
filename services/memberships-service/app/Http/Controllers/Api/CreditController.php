<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\AccessService;
use App\Memberships\CreditLedger;
use App\Memberships\CreditPurchaseService;
use App\Models\CreditPack;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contract: credits (money → credits, accountability)
 *  GET  /api/credit-packs                      any user (with the bonus of the student's plan)
 *  GET  /api/me/wallet                         student: balance, ledger, purchases (pending ones are synced first)
 *  POST /api/credit-purchases                  student {charge_id, student_id, pack_code} — registers a pack reference
 *  POST /api/credit-purchases/{id}/sync        owner student | admin — asks payments-service again
 *  GET  /api/credit-purchases                  admin (?status)
 *  POST /api/credit-purchases/{id}/resolve     admin {action: credit|reject, reason}
 *  GET  /api/wallets                           admin
 *  POST /api/wallets/{userId}/adjust           admin {amount (±), reason}
 *  GET  /api/credit-transactions               admin (?user_id, ?type)
 *  GET  /api/summary                           admin dashboard of memberships
 */
class CreditController extends Controller
{
    public function packs(Request $request, AccessService $access): JsonResponse
    {
        $actor = $this->actor($request);
        $bonus = AccessService::appliesTo($actor['role']) ? $access->value((int) $actor['id'], 'credits.bonus_pct') : 0;

        $packs = CreditPack::where('is_active', true)->orderBy('price')->get()->map(fn (CreditPack $p) => [
            ...$p->toArray(),
            'bonus_pct' => $bonus,
            'bonus_credits' => intdiv($p->credits * $bonus, 100),
            'total_credits' => $p->credits + intdiv($p->credits * $bonus, 100),
            'credits_per_peso' => round($p->credits / max(0.01, (float) $p->price), 2),
        ]);

        return response()->json(['data' => $packs, 'bonus_pct' => $bonus]);
    }

    public function wallet(Request $request, CreditLedger $ledger, CreditPurchaseService $purchases): JsonResponse
    {
        $userId = $this->studentId($request);
        $synced = 0;
        try {
            $synced = $purchases->syncPending($userId);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            // payments-service down: the wallet still answers with what we know
        }

        return response()->json([
            'balance' => $ledger->balance($userId),
            'synced' => $synced,
            'transactions' => CreditTransaction::where('user_id', $userId)->orderByDesc('id')->limit(100)->get(),
            'purchases' => CreditPurchase::with('pack')->where('user_id', $userId)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function registerPurchase(Request $request, CreditPurchaseService $purchases): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate([
            'charge_id' => ['required', 'integer', 'min:1'],
            'student_id' => ['required', 'integer', 'min:1'],
            'pack_code' => ['required', 'string', 'max:40'],
        ]);

        [$purchase, $created] = $purchases->register($userId, $data['student_id'], $data['charge_id'], $data['pack_code']);

        return response()->json($purchase->load('pack'), $created ? 201 : 200);
    }

    public function sync(Request $request, CreditPurchaseService $purchases, int $id): JsonResponse
    {
        $purchase = CreditPurchase::findOrFail($id);
        abort_unless($this->isAdmin($request) || $purchase->user_id === $this->studentId($request), 403, 'Solo puedes consultar tus compras.');

        return response()->json($purchases->sync($purchase));
    }

    public function purchases(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $filters = $request->validate(['status' => ['nullable', Rule::in(CreditPurchase::STATUSES)], 'user_id' => ['nullable', 'integer']]);

        return response()->json(['data' => CreditPurchase::with('pack')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->orderByRaw("case when status = 'review' then 0 else 1 end")->orderByDesc('id')->limit(500)->get()]);
    }

    public function resolve(Request $request, CreditPurchaseService $purchases, int $id): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'action' => ['required', Rule::in(['credit', 'reject'])],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ], ['reason.required' => 'Escribe el motivo de la decisión.']);

        return response()->json($purchases->resolve(CreditPurchase::findOrFail($id), $data['action'], $data['reason'], $this->ledgerActor($request)));
    }

    public function wallets(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $plans = Subscription::with('plan:id,code,name,color')->where('status', Subscription::ACTIVE)->where('ends_at', '>', now())->get()->keyBy('user_id');

        $users = Wallet::pluck('user_id')->merge($plans->keys())->unique()->values();
        $balances = Wallet::pluck('balance', 'user_id');

        return response()->json(['data' => $users->map(fn ($u) => [
            'user_id' => (int) $u,
            'balance' => (int) ($balances[$u] ?? 0),
            'plan' => $plans->get($u)?->plan?->only(['code', 'name', 'color']) ?? ['code' => Plan::BASIC, 'name' => 'Básico', 'color' => 'slate'],
            'plan_ends_at' => $plans->get($u)?->ends_at,
            'auto_renew' => $plans->get($u)?->auto_renew,
        ])->sortByDesc('balance')->values()]);
    }

    public function adjust(Request $request, CreditLedger $ledger, int $userId): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', 'string', 'min:5', 'max:150'],
        ], ['amount.not_in' => 'El ajuste no puede ser 0.', 'reason.required' => 'Escribe el motivo del ajuste.']);

        $concept = 'Ajuste de Control Escolar: '.$data['reason'];
        $tx = $data['amount'] > 0
            ? $ledger->credit($userId, $data['amount'], CreditTransaction::ADJUSTMENT, $concept, $this->ledgerActor($request), 'adjustment')
            : $ledger->debit($userId, -$data['amount'], $concept, $this->ledgerActor($request), 'adjustment', null, CreditTransaction::ADJUSTMENT);

        return response()->json(['transaction' => $tx, 'balance' => $ledger->balance($userId)], 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $filters = $request->validate(['user_id' => ['nullable', 'integer'], 'type' => ['nullable', Rule::in(CreditTransaction::TYPES)]]);

        return response()->json(['data' => CreditTransaction::query()
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')->limit(500)->get()]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $active = Subscription::with('plan:id,code,name')->where('status', Subscription::ACTIVE)->where('ends_at', '>', now())->get();
        $monthStart = now()->startOfMonth();

        return response()->json([
            'members_by_plan' => Plan::orderBy('tier')->get(['id', 'code', 'name', 'color'])->map(fn (Plan $p) => [
                ...$p->only(['code', 'name', 'color']),
                'members' => $p->code === Plan::BASIC ? null : $active->where('plan_id', $p->id)->count(),
            ]),
            'active_subscriptions' => $active->count(),
            'credits_in_wallets' => (int) Wallet::sum('balance'),
            'credits_sold' => (int) CreditTransaction::whereIn('type', [CreditTransaction::PURCHASE, CreditTransaction::BONUS])->sum('amount'),
            'money_collected' => number_format((float) CreditPurchase::where('status', CreditPurchase::CREDITED)->sum('amount'), 2, '.', ''),
            'credits_spent_month' => (int) -CreditTransaction::where('type', CreditTransaction::SPEND)->where('created_at', '>=', $monthStart)->sum('amount'),
            'purchases_in_review' => CreditPurchase::where('status', CreditPurchase::REVIEW)->count(),
            'purchases_pending' => CreditPurchase::where('status', CreditPurchase::PENDING)->count(),
        ]);
    }
}
