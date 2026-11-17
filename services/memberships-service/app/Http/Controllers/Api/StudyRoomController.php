<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\RoomBookingService;
use App\Models\RoomBooking;
use App\Models\StudyRoom;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contract: time module 1 — study rooms (hours)
 *  GET  /api/study-rooms                         any user (+ opening hours)
 *  GET  /api/study-rooms/{id}/bookings?date=     busy slots of one day (no names)
 *  GET  /api/room-bookings                       student: mine · admin: all (?date)
 *  POST /api/room-bookings                       student {study_room_id, starts_at, minutes}
 *  POST /api/room-bookings/{id}/cancel           owner student | admin
 */
class StudyRoomController extends Controller
{
    public function rooms(): JsonResponse
    {
        $cfg = config('memberships.study_rooms');

        return response()->json([
            'data' => StudyRoom::where('is_active', true)->orderBy('name')->get(),
            'opens' => $cfg['opens'], 'closes' => $cfg['closes'], 'slot_minutes' => $cfg['slot_minutes'],
        ]);
    }

    public function busy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $day = CarbonImmutable::parse($data['date']);
        StudyRoom::findOrFail($id);

        return response()->json(['data' => RoomBooking::where('study_room_id', $id)->where('status', RoomBooking::BOOKED)
            ->where('starts_at', '>=', $day->format('Y-m-d 00:00:00'))->where('starts_at', '<', $day->addDay()->format('Y-m-d 00:00:00'))
            ->orderBy('starts_at')->get(['id', 'starts_at', 'ends_at'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = RoomBooking::with('room')->orderByDesc('starts_at');
        if ($this->isAdmin($request)) {
            $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
            $query->when($date, fn ($q, $d) => $q->whereDate('starts_at', $d));
        } else {
            $query->where('user_id', $this->studentId($request));
        }

        return response()->json(['data' => $query->limit(300)->get()]);
    }

    public function store(Request $request, RoomBookingService $service): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate([
            'study_room_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:30', 'max:720'],
        ], ['starts_at.required' => 'Elige la fecha y hora de inicio.', 'minutes.required' => 'Elige la duración.']);

        return response()->json($service->book($userId, $data['study_room_id'], $data['starts_at'], $data['minutes']), 201);
    }

    public function cancel(Request $request, RoomBookingService $service, int $id): JsonResponse
    {
        $booking = RoomBooking::findOrFail($id);
        abort_unless($this->isAdmin($request) || $booking->user_id === $this->studentId($request), 403, 'Solo puedes cancelar tus reservaciones.');

        return response()->json($service->cancel($booking));
    }
}
