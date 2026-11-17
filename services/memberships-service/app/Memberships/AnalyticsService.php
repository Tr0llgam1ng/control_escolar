<?php

namespace App\Memberships;

use App\Models\AnalyticsReport;
use Illuminate\Support\Facades\DB;

/**
 * Credits module 2 — academic analysis. The gateway sends the student's report (catalog + enrollment,
 * composed there); this service computes averages, risk per subject and the grade needed in the
 * units still without a grade to pass, charges the credits of the plan and stores the result.
 */
class AnalyticsService
{
    public function __construct(private AccessService $access, private CreditLedger $ledger) {}

    /** @param list<array{subject:string, code?:?string, period?:?string, units_count:int, grades:list<array{unit:int, score:numeric}>, final_grade:?numeric, status:string}> $items */
    public function generate(int $userId, array $items, array $actor): AnalyticsReport
    {
        return DB::transaction(function () use ($userId, $items, $actor) {
            $this->access->consume($userId, 'analytics.reports');
            $context = $this->access->context($userId);
            $cost = $this->access->value($userId, 'analytics.cost');

            $report = AnalyticsReport::create([
                'user_id' => $userId,
                'credits_cost' => $cost,
                'plan_code' => $context['plan']->code,
                'result' => $this->compute($items),
            ]);
            $this->ledger->debit($userId, $cost, 'Análisis académico #'.$report->id, $actor, 'analytics_report', $report->id);

            return $report;
        });
    }

    public function compute(array $items): array
    {
        $passing = (float) config('memberships.analytics.passing_grade', 7);
        $subjects = [];

        foreach ($items as $item) {
            $units = max(0, (int) ($item['units_count'] ?? 0));
            $scores = collect($item['grades'] ?? [])->filter(fn ($g) => is_numeric($g['score'] ?? null))
                ->mapWithKeys(fn ($g) => [(int) $g['unit'] => (float) $g['score']]);
            $captured = $scores->count();
            $sum = $scores->sum();
            $average = $captured ? round($sum / $captured, 2) : null;
            $missing = max(0, $units - $captured);
            $inProgress = ($item['status'] ?? '') === 'in_progress';

            // Average needed in the missing units so the final grade reaches the passing grade.
            $needed = $inProgress && $missing > 0 ? round(($passing * $units - $sum) / $missing, 2) : null;

            $risk = match (true) {
                ! $inProgress => null,
                $needed !== null && $needed > 10 => 'alto',
                $needed === null && $average !== null && $average < $passing => 'alto',
                $average !== null && $average < $passing => 'medio',
                $needed !== null && $needed >= 9 => 'medio',
                default => 'bajo',
            };

            $subjects[] = [
                'subject' => (string) ($item['subject'] ?? 'Materia'),
                'code' => $item['code'] ?? null,
                'period' => $item['period'] ?? null,
                'status' => $item['status'] ?? null,
                'units' => $units,
                'captured_units' => $captured,
                'average' => $average,
                'final_grade' => isset($item['final_grade']) && is_numeric($item['final_grade']) ? (float) $item['final_grade'] : null,
                'needed_average' => $needed !== null ? max(0, $needed) : null,
                'reachable' => $needed === null ? null : $needed <= 10,
                'risk' => $risk,
            ];
        }

        $closed = collect($subjects)->whereNotNull('final_grade')->whereIn('status', ['approved', 'failed']);
        $current = collect($subjects)->where('status', 'in_progress')->whereNotNull('average');
        $ranked = $current->sortByDesc('average')->values();

        $recommendations = [];
        foreach (collect($subjects)->where('risk', 'alto') as $s) {
            $recommendations[] = $s['reachable'] === false
                ? "{$s['subject']}: con las unidades restantes ya no alcanza {$passing}; habla con tu profesor sobre recuperación."
                : "{$s['subject']}: riesgo alto. Considera una asesoría antes de la siguiente unidad.";
        }
        foreach (collect($subjects)->where('risk', 'medio') as $s) {
            $recommendations[] = $s['needed_average'] !== null
                ? "{$s['subject']}: necesitas promedio de {$s['needed_average']} en las unidades que faltan."
                : "{$s['subject']}: tu promedio está por debajo de {$passing}.";
        }
        if (! $recommendations) {
            $recommendations[] = $current->isEmpty() ? 'Aún no hay calificaciones en curso para analizar.' : 'Vas bien en todas tus materias en curso.';
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'passing_grade' => $passing,
            'general_average' => $closed->isEmpty() ? null : round($closed->avg('final_grade'), 2),
            'current_average' => $current->isEmpty() ? null : round($current->avg('average'), 2),
            'counts' => [
                'subjects' => count($subjects),
                'in_progress' => collect($subjects)->where('status', 'in_progress')->count(),
                'approved' => collect($subjects)->where('status', 'approved')->count(),
                'failed' => collect($subjects)->where('status', 'failed')->count(),
                'at_risk' => collect($subjects)->whereIn('risk', ['alto', 'medio'])->count(),
            ],
            'best' => $ranked->first()['subject'] ?? null,
            'weakest' => $ranked->count() > 1 ? $ranked->last()['subject'] : null,
            'subjects' => $subjects,
            'recommendations' => $recommendations,
        ];
    }
}
