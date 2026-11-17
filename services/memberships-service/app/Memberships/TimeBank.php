<?php

namespace App\Memberships;

use App\Models\TimeBalance;
use App\Models\TimeMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Time of the two time modules:
 *  study_rooms → hours (study_rooms.hours included per cycle)
 *  library     → minutes (library.minutes included per cycle)
 * Included time is used first and resets each cycle; extra time is bought with credits and does not expire.
 * Everything is stored in seconds and every movement is written to time_movements.
 */
class TimeBank
{
    public const MODULES = [
        'study_rooms' => ['feature' => 'study_rooms.hours', 'label' => 'Cubículos de estudio', 'unit' => 'hours'],
        'library' => ['feature' => 'library.minutes', 'label' => 'Biblioteca digital', 'unit' => 'minutes'],
    ];

    public function __construct(private AccessService $access, private CreditLedger $ledger) {}

    public function status(int $userId, string $module): array
    {
        $def = $this->def($module);
        $context = $this->access->context($userId);
        $f = $this->access->feature($userId, $def['feature'], $context);
        $extra = (int) (TimeBalance::where('user_id', $userId)->where('module', $module)->value('extra_seconds') ?? 0);

        return [
            'module' => $module,
            'label' => $def['label'],
            'unit' => $def['unit'],
            'plan' => $context['plan']->name,
            'cycle_start' => $context['start']->toIso8601String(),
            'cycle_end' => $context['end']->toIso8601String(),
            'included' => Duration::parts($f['included_seconds']),
            'included_used' => Duration::parts($f['used']),
            'included_remaining' => Duration::parts($f['remaining']),
            'extra' => Duration::parts($extra),
            'available' => Duration::parts($f['remaining'] + $extra),
            'extra_price' => $this->price($module),
        ];
    }

    public function available(int $userId, string $module): int
    {
        return $this->status($userId, $module)['available']['seconds'];
    }

    /**
     * Uses time: first the included time of the cycle, then the extra time.
     * $partial = true uses what is left when there is not enough (a library session that ran out).
     *
     * @return array{included:int, extra:int, cycle_start:CarbonImmutable}
     */
    public function spend(int $userId, string $module, int $seconds, string $concept, ?string $sourceType = null, ?int $sourceId = null, bool $partial = false): array
    {
        $def = $this->def($module);

        return DB::transaction(function () use ($userId, $module, $def, $seconds, $concept, $sourceType, $sourceId, $partial) {
            $context = $this->access->context($userId);
            $f = $this->access->feature($userId, $def['feature'], $context);
            $balance = $this->lockBalance($userId, $module);

            $available = $f['remaining'] + $balance->extra_seconds;
            if ($available < $seconds && ! $partial) {
                throw MembershipException::insufficientTime($module, $seconds, $available);
            }
            $seconds = min($seconds, $available);

            $included = min($seconds, $f['remaining']);
            $extra = $seconds - $included;

            if ($included > 0) {
                $this->access->addUsage($userId, $def['feature'], $included, $context['start']);
            }
            if ($extra > 0) {
                $balance->extra_seconds -= $extra;
                $balance->save();
            }
            $this->move($userId, $module, 'use', -$included, -$extra, $concept, $sourceType, $sourceId);

            return ['included' => $included, 'extra' => $extra, 'cycle_start' => $context['start']];
        });
    }

    /** Gives time back. Included time only returns if its cycle is still the current one. */
    public function refund(int $userId, string $module, int $included, int $extra, \DateTimeInterface $cycleStart, string $concept, ?string $sourceType = null, ?int $sourceId = null): array
    {
        $def = $this->def($module);

        return DB::transaction(function () use ($userId, $module, $def, $included, $extra, $cycleStart, $concept, $sourceType, $sourceId) {
            $back = $included > 0 ? $this->access->release($userId, $def['feature'], $included, CarbonImmutable::instance($cycleStart)) : 0;
            if ($extra > 0) {
                $balance = $this->lockBalance($userId, $module);
                $balance->extra_seconds += $extra;
                $balance->save();
            }
            $this->move($userId, $module, 'refund', $back, $extra, $concept, $sourceType, $sourceId);

            return ['included' => $back, 'extra' => $extra];
        });
    }

    /** Buys extra time with credits: hours for study rooms, blocks of 30 minutes for the library. */
    public function buy(int $userId, string $module, int $quantity, array $actor): array
    {
        $this->def($module);
        $max = (int) config('memberships.max_purchase_quantity', 20);
        abort_if($quantity < 1 || $quantity > $max, 422, "La cantidad debe estar entre 1 y {$max}.");

        if ($module === 'study_rooms') {
            // Extra hours are useless if the plan cannot book rooms at all.
            $this->access->requireAvailable($userId, 'study_rooms.max_hours', 'Tu plan no incluye reservar cubículos. Mejora tu plan para comprar horas extra.');
        }

        $price = $this->price($module);
        $seconds = $quantity * $price['seconds'];
        $credits = $quantity * $price['credits'];

        return DB::transaction(function () use ($userId, $module, $seconds, $credits, $actor) {
            $label = Duration::human($seconds).' extra de '.mb_strtolower(self::MODULES[$module]['label']);
            $tx = $this->ledger->debit($userId, $credits, "Compra de {$label}", $actor, 'time_purchase');

            $balance = $this->lockBalance($userId, $module);
            $balance->extra_seconds += $seconds;
            $balance->save();
            $this->move($userId, $module, 'purchase', 0, $seconds, "Compra de {$label} ({$credits} créditos)", 'credit_transaction', $tx?->id);

            return ['seconds' => $seconds, 'credits' => $credits, 'status' => $this->status($userId, $module)];
        });
    }

    /** @return array{credits:int, seconds:int, label:string} price of one unit of extra time */
    public function price(string $module): array
    {
        return $module === 'study_rooms'
            ? ['credits' => (int) config('memberships.study_rooms.extra_hour_cost'), 'seconds' => 3600, 'label' => '1 hora']
            : ['credits' => (int) config('memberships.library.extra_block_cost'), 'seconds' => 60 * (int) config('memberships.library.extra_block_minutes'),
                'label' => config('memberships.library.extra_block_minutes').' minutos'];
    }

    private function def(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 422, 'Módulo de tiempo desconocido.');

        return self::MODULES[$module];
    }

    private function move(int $userId, string $module, string $kind, int $included, int $extra, string $concept, ?string $sourceType, ?int $sourceId): void
    {
        if ($included === 0 && $extra === 0) {
            return;
        }
        TimeMovement::create([
            'user_id' => $userId, 'module' => $module, 'kind' => $kind,
            'included_seconds' => $included, 'extra_seconds' => $extra,
            'concept' => mb_substr($concept, 0, 160), 'source_type' => $sourceType, 'source_id' => $sourceId,
            'created_at' => now(),
        ]);
    }

    private function lockBalance(int $userId, string $module): TimeBalance
    {
        try {
            TimeBalance::firstOrCreate(['user_id' => $userId, 'module' => $module], ['extra_seconds' => 0]);
        } catch (UniqueConstraintViolationException) {
        }

        return TimeBalance::where('user_id', $userId)->where('module', $module)->lockForUpdate()->firstOrFail();
    }
}
