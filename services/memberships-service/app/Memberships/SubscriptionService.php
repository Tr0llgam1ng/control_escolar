<?php

namespace App\Memberships;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Buying, changing and renewing plans. Plans are paid with credits for duration_days (time in days).
 * Changing plan refunds the unused part of the current one (proportional, rounded down) and starts a new cycle.
 * Renewal is lazy: the first time the plan is needed after ends_at, it renews if auto_renew is on
 * and the wallet covers the price; otherwise it expires and the user falls back to Basic.
 */
class SubscriptionService
{
    public function __construct(private CreditLedger $ledger) {}

    public function current(int $userId): ?Subscription
    {
        return DB::transaction(function () use ($userId) {
            $sub = Subscription::with('plan.features')
                ->where('user_id', $userId)->where('status', Subscription::ACTIVE)
                ->orderByDesc('id')->lockForUpdate()->first();

            if (! $sub || $sub->ends_at->isFuture()) {
                return $sub;
            }

            $sub->status = Subscription::EXPIRED;
            $sub->save();

            $plan = $sub->plan;
            if ($sub->auto_renew && $plan->is_active && ! $plan->isBasic() && $this->ledger->balance($userId) >= $plan->price_credits) {
                $renewed = $this->open($userId, $plan, $sub->id);
                $this->ledger->debit($userId, $plan->price_credits, "Renovación automática del plan {$plan->name} ({$plan->duration_days} días)",
                    ['type' => 'service', 'id' => 'renewal'], 'subscription', $renewed->id);

                return $renewed->load('plan.features');
            }

            return null;
        });
    }

    public function plan(int $userId): Plan
    {
        return $this->current($userId)?->plan ?? Plan::with('features')->where('code', Plan::BASIC)->firstOrFail();
    }

    /** @return array{subscription: Subscription, refunded: int, charged: int} */
    public function subscribe(int $userId, string $planCode, array $actor): array
    {
        $plan = Plan::with('features')->where('code', $planCode)->where('is_active', true)->first();
        abort_if(! $plan, 404, 'El plan no existe o no está disponible.');
        abort_if($plan->isBasic(), 422, 'El plan Básico es gratuito: para volver a él desactiva la renovación de tu plan actual.');

        return DB::transaction(function () use ($userId, $plan, $actor) {
            $current = $this->current($userId);
            $refunded = 0;

            if ($current) {
                abort_if($current->plan_id === $plan->id, 409,
                    "Ya tienes el plan {$plan->name} activo hasta el ".$current->ends_at->format('d/m/Y H:i').'.');

                $total = max(1, (int) $current->starts_at->diffInSeconds($current->ends_at));
                $left = $current->remaining_seconds;
                $refunded = intdiv($current->price_credits * $left, $total);

                $current->status = Subscription::REPLACED;
                $current->ends_at = now();
                $current->save();

                if ($refunded > 0) {
                    $this->ledger->credit($userId, $refunded, 'refund',
                        "Reembolso proporcional del plan {$current->plan->name} (".Duration::human($left).' sin usar)', $actor, 'subscription', $current->id);
                }
            }

            $subscription = $this->open($userId, $plan, $current?->id);
            $this->ledger->debit($userId, $plan->price_credits, "Plan {$plan->name} por {$plan->duration_days} días", $actor, 'subscription', $subscription->id);

            return ['subscription' => $subscription->load('plan.features'), 'refunded' => $refunded, 'charged' => $plan->price_credits];
        });
    }

    /** Turn auto renewal off (the plan keeps working until ends_at) or back on. */
    public function setAutoRenew(int $userId, bool $on): Subscription
    {
        return DB::transaction(function () use ($userId, $on) {
            $sub = $this->current($userId);
            abort_if(! $sub, 404, 'No tienes un plan de pago activo.');
            $sub->auto_renew = $on;
            $sub->cancelled_at = $on ? null : now();
            $sub->save();

            return $sub;
        });
    }

    private function open(int $userId, Plan $plan, ?int $previousId): Subscription
    {
        return Subscription::create([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'status' => Subscription::ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->duration_days),
            'price_credits' => $plan->price_credits,
            'auto_renew' => true,
            'previous_id' => $previousId,
        ]);
    }
}
