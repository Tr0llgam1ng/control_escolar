<?php

namespace App\Memberships;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Authorization by plan. Answers "can this user do X?" and keeps the usage of the cycle.
 *
 * Cycle = the running subscription (starts_at → ends_at), or the calendar month for Basic.
 * Usage counters are keyed by the start of the cycle, so they reset when a new cycle starts.
 * Only students have memberships: other roles are never limited by a plan.
 */
class AccessService
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public static function appliesTo(?string $role): bool
    {
        return $role === 'student';
    }

    /** @return array{plan: Plan, subscription: ?Subscription, start: CarbonImmutable, end: CarbonImmutable} */
    public function context(int $userId): array
    {
        $subscription = $this->subscriptions->current($userId);
        $plan = $subscription?->plan ?? Plan::with('features')->where('code', Plan::BASIC)->firstOrFail();

        if ($subscription) {
            $start = CarbonImmutable::parse($subscription->starts_at);
            $end = CarbonImmutable::parse($subscription->ends_at);
        } else {
            $start = CarbonImmutable::now()->startOfMonth();
            $end = $start->addMonth();
        }

        return ['plan' => $plan, 'subscription' => $subscription, 'start' => $start, 'end' => $end];
    }

    public function cycleStart(int $userId): CarbonImmutable
    {
        return $this->context($userId)['start'];
    }

    /** Feature of the user's plan, with used / remaining in the cycle for quota and time features. */
    public function feature(int $userId, string $key, ?array $context = null): array
    {
        abort_unless(Features::exists($key), 422, "Función desconocida: {$key}.");
        $context ??= $this->context($userId);
        $row = $context['plan']->feature($key);
        $info = Features::describe($key, $row?->enabled ?? false, $row?->value);

        if (in_array($info['type'], ['quota', 'time'], true)) {
            $used = (int) UsageCounter::where('user_id', $userId)->where('feature', $key)
                ->where('cycle_start', $this->stamp($context['start']))->value('used');
            $info['used'] = $used;
            if ($info['type'] === 'quota') {
                $info['remaining'] = $info['unlimited'] ? null : max(0, (int) $info['value'] - $used);
            } else {
                $included = $info['enabled'] ? (int) $info['value'] * Features::unitSeconds($key) : 0;
                $info['included_seconds'] = $included;
                $info['remaining'] = max(0, $included - $used); // seconds
            }
        }

        return $info;
    }

    /** Value of a percent / credits / number feature (0 when not included). */
    public function value(int $userId, string $key): int
    {
        $f = $this->feature($userId, $key);

        return $f['enabled'] ? (int) $f['value'] : 0;
    }

    /** Everything the app needs to draw the membership screen. */
    public function snapshot(int $userId): array
    {
        $context = $this->context($userId);

        return [
            'plan' => $context['plan']->only(['id', 'code', 'name', 'tier', 'color', 'price_credits', 'duration_days']),
            'subscription' => $context['subscription']?->only(['id', 'status', 'starts_at', 'ends_at', 'auto_renew', 'cancelled_at', 'price_credits', 'remaining_seconds']),
            'subscription_remaining' => $context['subscription'] ? Duration::parts($context['subscription']->remaining_seconds) : null,
            'cycle' => ['start' => $context['start']->toIso8601String(), 'end' => $context['end']->toIso8601String(),
                'source' => $context['subscription'] ? 'subscription' : 'month'],
            'features' => collect(array_keys(Features::DEFINITIONS))->mapWithKeys(fn ($key) => [$key => $this->feature($userId, $key, $context)])->all(),
        ];
    }

    /** Throws PLAN_REQUIRED unless the feature is usable with the current plan. */
    public function requireAvailable(int $userId, string $key, ?string $message = null): array
    {
        $f = $this->feature($userId, $key);
        if (! $f['available']) {
            throw MembershipException::planRequired($key, $message ?? "Tu plan no incluye: {$f['label']}.", $this->plansWith($key));
        }

        return $f;
    }

    /**
     * Uses $amount of a quota feature in the current cycle (a lock on the counter row avoids races).
     * Call it inside the same DB transaction as the operation it pays for, or release() on failure.
     *
     * @return array{feature:string, cycle_start:string, used:int, remaining:?int, unlimited:bool}
     */
    public function consume(int $userId, string $key, int $amount = 1): array
    {
        abort_unless(Features::exists($key) && Features::type($key) === 'quota', 422, "{$key} no es una función con límite de usos.");
        abort_if($amount < 1, 422, 'Cantidad inválida.');

        return DB::transaction(function () use ($userId, $key, $amount) {
            $context = $this->context($userId);
            $f = $this->feature($userId, $key, $context);
            $plans = $this->plansWith($key, $context['plan']->tier);

            if (! $f['available']) {
                throw MembershipException::planRequired($key, "Tu plan {$context['plan']->name} no incluye: {$f['label']}.", $plans);
            }

            $counter = $this->lockCounter($userId, $key, $context['start']);
            if (! $f['unlimited'] && $counter->used + $amount > (int) $f['value']) {
                throw MembershipException::planLimit($key,
                    "Alcanzaste el límite de tu plan {$context['plan']->name}: {$f['value']} ".mb_strtolower($f['label']).' por ciclo. Se renueva el '
                    .$context['end']->format('d/m/Y').'.', $plans);
            }

            $counter->used += $amount;
            $counter->save();

            return [
                'feature' => $key,
                'cycle_start' => $this->stamp($context['start']),
                'used' => $counter->used,
                'remaining' => $f['unlimited'] ? null : (int) $f['value'] - $counter->used,
                'unlimited' => $f['unlimited'],
            ];
        });
    }

    /** Compensation: gives back uses of a cycle (never below zero). Uses from a cycle that already ended are not returned. */
    public function release(int $userId, string $key, int $amount, string|\DateTimeInterface|null $cycleStart = null): int
    {
        return DB::transaction(function () use ($userId, $key, $amount, $cycleStart) {
            $current = $this->cycleStart($userId);
            $start = $cycleStart ? CarbonImmutable::parse($cycleStart) : $current;
            if ($this->stamp($start) !== $this->stamp($current)) {
                return 0; // that cycle is over: its included uses are gone
            }
            $counter = UsageCounter::where('user_id', $userId)->where('feature', $key)->where('cycle_start', $this->stamp($start))->lockForUpdate()->first();
            if (! $counter) {
                return 0;
            }
            $back = min($amount, $counter->used);
            $counter->used -= $back;
            $counter->save();

            return $back;
        });
    }

    /** Adds seconds to a time counter (TimeBank). */
    public function addUsage(int $userId, string $key, int $amount, CarbonImmutable $cycleStart): void
    {
        $counter = $this->lockCounter($userId, $key, $cycleStart);
        $counter->used += $amount;
        $counter->save();
    }

    /** Plans (higher than $aboveTier) where the feature is available — what to suggest in "upgrade". */
    public function plansWith(string $key, int $aboveTier = -1): array
    {
        return Plan::with('features')->where('is_active', true)->where('tier', '>', $aboveTier)->orderBy('tier')->get()
            ->filter(function (Plan $p) use ($key) {
                $row = $p->feature($key);

                return Features::available($key, $row?->enabled ?? false, $row?->value);
            })
            ->map(fn (Plan $p) => ['code' => $p->code, 'name' => $p->name])->values()->all();
    }

    public function stamp(\DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->format('Y-m-d H:i:s');
    }

    private function lockCounter(int $userId, string $key, CarbonImmutable $start): UsageCounter
    {
        $stamp = $this->stamp($start);
        try {
            UsageCounter::firstOrCreate(['user_id' => $userId, 'feature' => $key, 'cycle_start' => $stamp], ['used' => 0]);
        } catch (UniqueConstraintViolationException) {
            // created by a concurrent request
        }

        return UsageCounter::where('user_id', $userId)->where('feature', $key)->where('cycle_start', $stamp)->lockForUpdate()->firstOrFail();
    }
}
