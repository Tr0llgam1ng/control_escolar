<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\AccessService;
use App\Memberships\TutoringService;
use App\Models\TutoringSession;
use App\Services\CatalogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contract: credits module 1 — tutoring with a teacher
 *  GET   /api/tutoring/quote                    student: price with the plan discount and sessions left
 *  GET   /api/tutoring-sessions                 student: mine · teacher: the ones asked to me · admin: all
 *  POST  /api/tutoring-sessions                 student {teacher_id, teacher_name, student_name?, subject, topic, scheduled_at}
 *  POST  /api/tutoring-sessions/{id}/cancel     owner student
 *  PATCH /api/tutoring-sessions/{id}            assigned teacher {status: confirmed|rejected|completed, notes?}
 */
class TutoringController extends Controller
{
    public function quote(Request $request, AccessService $access): JsonResponse
    {
        $userId = $this->studentId($request);
        $base = (int) config('memberships.tutoring.base_cost');
        $sessions = $access->feature($userId, 'tutoring.sessions');
        $discount = $access->value($userId, 'tutoring.discount_pct');

        return response()->json([
            'base_cost' => $base,
            'discount_pct' => $discount,
            'cost' => $base - intdiv($base * $discount, 100),
            'sessions' => $sessions,
            'upgrade_to' => $sessions['available'] ? [] : $access->plansWith('tutoring.sessions'),
            'rules' => config('memberships.tutoring'),
        ]);
    }

    public function index(Request $request, CatalogClient $catalog): JsonResponse
    {
        $actor = $this->actor($request);
        $query = TutoringSession::orderByDesc('scheduled_at');

        match ($actor['role']) {
            'admin' => null,
            'teacher' => $query->where('teacher_id', $this->ownTeacherId($request, $catalog)),
            default => $query->where('user_id', $this->studentId($request)),
        };

        return response()->json(['data' => $query->limit(300)->get()]);
    }

    public function store(Request $request, TutoringService $service): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
            'teacher_name' => ['nullable', 'string', 'max:120'],
            'student_name' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:120'],
            'topic' => ['required', 'string', 'min:5', 'max:255'],
            'scheduled_at' => ['required', 'date'],
        ], [
            'teacher_id.required' => 'Elige al profesor.',
            'topic.required' => 'Describe brevemente el tema que quieres ver.',
            'topic.min' => 'Describe un poco más el tema (mínimo 5 caracteres).',
            'scheduled_at.required' => 'Elige la fecha y hora.',
        ]);

        return response()->json($service->request($userId, $data['student_name'] ?? null, $data, $this->ledgerActor($request)), 201);
    }

    public function cancel(Request $request, TutoringService $service, int $id): JsonResponse
    {
        $session = TutoringSession::findOrFail($id);
        abort_unless($session->user_id === $this->studentId($request), 403, 'Solo puedes cancelar tus asesorías.');

        return response()->json($service->cancelByStudent($session, $this->ledgerActor($request)));
    }

    public function update(Request $request, TutoringService $service, CatalogClient $catalog, int $id): JsonResponse
    {
        $this->requireRole($request, 'teacher');
        $session = TutoringSession::findOrFail($id);
        abort_unless($session->teacher_id === $this->ownTeacherId($request, $catalog), 403, 'Esta asesoría no está asignada a ti.');

        $data = $request->validate([
            'status' => ['required', Rule::in([TutoringSession::CONFIRMED, TutoringSession::REJECTED, TutoringSession::COMPLETED])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($service->updateByTeacher($session, $data['status'], $data['notes'] ?? null, $this->ledgerActor($request)));
    }

    private function ownTeacherId(Request $request, CatalogClient $catalog): int
    {
        $actor = $this->actor($request);
        abort_unless($actor['role'] === 'teacher' && $actor['id'] !== null, 403, 'Solo un profesor puede hacer esto.');
        $id = $catalog->teacherIdByUser((int) $actor['id'], $actor);
        abort_if($id === null, 404, 'Tu cuenta no tiene expediente de profesor.');

        return $id;
    }
}
