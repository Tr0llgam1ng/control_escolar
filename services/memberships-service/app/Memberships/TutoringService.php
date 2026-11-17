<?php

namespace App\Memberships;

use App\Models\CreditTransaction;
use App\Models\TutoringSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Credits module 1 — tutoring with a teacher.
 * The plan decides if it is included, how many per cycle and the discount; the price is in credits.
 * The teacher confirms, rejects (full refund) or marks it as given. The student can cancel with
 * full refund up to refund_hours before.
 */
class TutoringService
{
    public function __construct(private AccessService $access, private CreditLedger $ledger) {}

    public function request(int $userId, ?string $studentName, array $data, array $actor): TutoringSession
    {
        $cfg = config('memberships.tutoring');
        $at = CarbonImmutable::parse($data['scheduled_at'], config('app.timezone'))->setTimezone(config('app.timezone'))->startOfMinute();

        abort_if($at->lessThan(now()->addHours($cfg['min_hours_ahead'])), 422, "Solicita la asesoría con al menos {$cfg['min_hours_ahead']} horas de anticipación.");
        abort_if($at->greaterThan(now()->addDays($cfg['max_days_ahead'])), 422, "Solo puedes agendar con hasta {$cfg['max_days_ahead']} días de anticipación.");
        abort_if($at->minute % 30 !== 0 || $at->hour < $cfg['opens'] || $at->copy()->addMinutes($cfg['duration_minutes'])->greaterThan($at->setTime($cfg['closes'], 0)), 422,
            sprintf('Las asesorías son de %02d:00 a %02d:00, en punto o y media.', $cfg['opens'], $cfg['closes']));

        return DB::transaction(function () use ($userId, $studentName, $data, $actor, $at, $cfg) {
            $busy = TutoringSession::where('teacher_id', $data['teacher_id'])
                ->whereIn('status', [TutoringSession::REQUESTED, TutoringSession::CONFIRMED])
                ->where('scheduled_at', '>', $at->subMinutes($cfg['duration_minutes'])->format('Y-m-d H:i:s'))
                ->where('scheduled_at', '<', $at->addMinutes($cfg['duration_minutes'])->format('Y-m-d H:i:s'))
                ->exists();
            abort_if($busy, 409, 'El profesor ya tiene una asesoría en ese horario. Elige otra hora.');

            $usage = $this->access->consume($userId, 'tutoring.sessions');
            $discount = $this->access->value($userId, 'tutoring.discount_pct');
            $cost = $cfg['base_cost'] - intdiv($cfg['base_cost'] * $discount, 100);

            $session = TutoringSession::create([
                'user_id' => $userId,
                'student_name' => $studentName,
                'teacher_id' => (int) $data['teacher_id'],
                'teacher_name' => $data['teacher_name'] ?? null,
                'subject' => $data['subject'],
                'topic' => $data['topic'],
                'scheduled_at' => $at,
                'duration_minutes' => $cfg['duration_minutes'],
                'credits_cost' => $cost,
                'discount_pct' => $discount,
                'cycle_start' => $usage['cycle_start'],
                'status' => TutoringSession::REQUESTED,
            ]);

            $this->ledger->debit($userId, $cost, "Asesoría de {$data['subject']} con ".($data['teacher_name'] ?? 'profesor')
                .' el '.$at->format('d/m/Y H:i').($discount ? " ({$discount}% de descuento)" : ''), $actor, 'tutoring_session', $session->id);

            return $session;
        });
    }

    public function cancelByStudent(TutoringSession $session, array $actor): TutoringSession
    {
        return DB::transaction(function () use ($session, $actor) {
            $session = TutoringSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($session->status, [TutoringSession::REQUESTED, TutoringSession::CONFIRMED], true), 409, 'La asesoría ya no se puede cancelar.');
            abort_unless($session->scheduled_at->isFuture(), 409, 'La asesoría ya pasó.');

            $hours = (int) config('memberships.tutoring.refund_hours');
            $refund = now()->diffInHours($session->scheduled_at, false) >= $hours;
            $session->status = TutoringSession::CANCELLED;
            $session->save();
            if ($refund) {
                $this->refund($session, 'Cancelación de la asesoría', $actor);
            }

            return $session;
        });
    }

    /** Teacher: requested → confirmed | rejected · confirmed → completed | rejected. */
    public function updateByTeacher(TutoringSession $session, string $status, ?string $notes, array $actor): TutoringSession
    {
        return DB::transaction(function () use ($session, $status, $notes, $actor) {
            $session = TutoringSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $allowed = [
                TutoringSession::REQUESTED => [TutoringSession::CONFIRMED, TutoringSession::REJECTED],
                TutoringSession::CONFIRMED => [TutoringSession::COMPLETED, TutoringSession::REJECTED],
            ];
            abort_unless(in_array($status, $allowed[$session->status] ?? [], true), 409,
                "No se puede pasar de «{$session->status_label}» a «".(TutoringSession::LABELS[$status] ?? $status).'».');
            abort_if($status === TutoringSession::COMPLETED && $session->scheduled_at->isFuture(), 422, 'Solo puedes marcarla como impartida a partir de la hora programada.');

            $session->status = $status;
            $session->teacher_notes = $notes;
            $session->save();

            if ($status === TutoringSession::REJECTED) {
                $this->refund($session, 'Asesoría rechazada por el profesor', $actor);
            }

            return $session;
        });
    }

    private function refund(TutoringSession $session, string $why, array $actor): void
    {
        if ($session->credits_cost > 0) {
            $this->ledger->credit($session->user_id, $session->credits_cost, CreditTransaction::REFUND,
                "{$why}: {$session->subject} del ".$session->scheduled_at->format('d/m/Y H:i'), $actor, 'tutoring_session', $session->id);
        }
        $this->access->release($session->user_id, 'tutoring.sessions', 1, $session->cycle_start);
        $session->refunded = true;
        $session->save();
    }
}
