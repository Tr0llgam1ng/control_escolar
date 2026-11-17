<?php

namespace App\Memberships;

use App\Models\RoomBooking;
use App\Models\StudyRoom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Time module 1 — study rooms booked by the hour.
 * Plan limits: hours included per cycle, max hours per booking and how many days in advance.
 * Rules: blocks of 30 min, opening hours, no overlap in the room nor with the user's own bookings.
 * Cancelling before the start gives the time back.
 */
class RoomBookingService
{
    public function __construct(private AccessService $access, private TimeBank $time) {}

    public function book(int $userId, int $roomId, string $startsAt, int $minutes): RoomBooking
    {
        $cfg = config('memberships.study_rooms');
        $slot = (int) $cfg['slot_minutes'];

        $max = $this->access->requireAvailable($userId, 'study_rooms.max_hours', 'Tu plan no incluye reservar cubículos de estudio. Mejora a Bronze o superior.');
        $plans = $this->access->plansWith('study_rooms.max_hours');
        if ($minutes > $max['value'] * 60) {
            throw MembershipException::planLimit('study_rooms.max_hours', "Tu plan permite reservar hasta {$max['value']} h por reservación.", $plans);
        }
        abort_if($minutes < $slot || $minutes % $slot !== 0, 422, "La duración debe ser en bloques de {$slot} minutos.");

        $start = CarbonImmutable::parse($startsAt, config('app.timezone'))->setTimezone(config('app.timezone'))->startOfMinute();
        $end = $start->addMinutes($minutes);
        abort_if($start->minute % $slot !== 0, 422, "La hora de inicio debe ser en punto o y media (bloques de {$slot} min).");
        abort_if($start->lessThanOrEqualTo(now()), 422, 'La reservación debe empezar en el futuro.');

        $advance = $this->access->value($userId, 'study_rooms.advance_days');
        if ($start->startOfDay()->greaterThan(CarbonImmutable::today()->addDays($advance))) {
            throw MembershipException::planLimit('study_rooms.advance_days', "Tu plan permite reservar con hasta {$advance} días de anticipación.", $plans);
        }
        $opens = $start->setTime((int) $cfg['opens'], 0);
        $closes = $start->setTime((int) $cfg['closes'], 0);
        abort_if($start->lessThan($opens) || $end->greaterThan($closes), 422,
            sprintf('Los cubículos se reservan de %02d:00 a %02d:00.', $cfg['opens'], $cfg['closes']));

        return DB::transaction(function () use ($userId, $roomId, $start, $end, $minutes) {
            $room = StudyRoom::whereKey($roomId)->where('is_active', true)->lockForUpdate()->first();
            abort_if(! $room, 404, 'El cubículo no existe o no está disponible.');

            $overlap = fn ($q) => $q->where('status', RoomBooking::BOOKED)
                ->where('starts_at', '<', $end->format('Y-m-d H:i:s'))->where('ends_at', '>', $start->format('Y-m-d H:i:s'));
            abort_if(RoomBooking::where('study_room_id', $room->id)->where($overlap)->exists(), 409, "El {$room->name} ya está reservado en ese horario.");
            abort_if(RoomBooking::where('user_id', $userId)->where($overlap)->exists(), 409, 'Ya tienes otra reservación que se cruza con ese horario.');

            $booking = RoomBooking::create([
                'user_id' => $userId, 'study_room_id' => $room->id,
                'starts_at' => $start, 'ends_at' => $end, 'minutes' => $minutes,
                'cycle_start' => now(), 'status' => RoomBooking::BOOKED,
            ]);

            $used = $this->time->spend($userId, 'study_rooms', $minutes * 60,
                "Reservación {$room->name} ".$start->format('d/m/Y H:i').'-'.$end->format('H:i'), 'room_booking', $booking->id);

            $booking->forceFill(['included_seconds' => $used['included'], 'extra_seconds' => $used['extra'], 'cycle_start' => $used['cycle_start']])->save();

            return $booking->load('room');
        });
    }

    public function cancel(RoomBooking $booking): RoomBooking
    {
        return DB::transaction(function () use ($booking) {
            $booking = RoomBooking::with('room')->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($booking->status === RoomBooking::BOOKED, 409, 'La reservación ya estaba cancelada.');
            abort_unless($booking->starts_at->isFuture(), 409, 'La reservación ya comenzó; no se puede cancelar.');

            $booking->status = RoomBooking::CANCELLED;
            $booking->cancelled_at = now();
            $booking->save();

            $this->time->refund($booking->user_id, 'study_rooms', $booking->included_seconds, $booking->extra_seconds, $booking->cycle_start,
                "Cancelación {$booking->room->name} ".$booking->starts_at->format('d/m/Y H:i'), 'room_booking', $booking->id);

            return $booking;
        });
    }
}
