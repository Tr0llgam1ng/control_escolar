<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Services\CatalogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Reservation" logic of the school system: enrolling students in courses.
 *
 * Contract: Enrollment Service v1
 *  POST   /api/enrollments        {course_id, student_id}     admin
 *  POST   /api/enrollments/bulk   {course_id, student_ids[]}  admin
 *  DELETE /api/enrollments/{id}                               admin
 *  GET    /api/enrollments/counts?course_ids=1,2              any (occupancy)
 *  GET    /api/students/{studentId}/enrollments               admin | that student
 *  DELETE /api/students/{studentId}/enrollments               admin (student removed from catalog)
 */
class EnrollmentController extends Controller
{
    public function __construct(private CatalogClient $catalog) {}

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'course_id' => ['required', 'integer'],
            'student_id' => ['required', 'integer'],
        ]);

        $course = $this->catalog->forActor($this->actor($request))->course($data['course_id']);
        [$ok, $result, $status] = $this->enroll($request, $course, (int) $data['student_id']);

        return $ok
            ? response()->json($result, 201)
            : response()->json(['message' => $result], $status);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $data = $request->validate([
            'course_id' => ['required', 'integer'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer'],
        ]);

        $course = $this->catalog->forActor($this->actor($request))->course($data['course_id']);
        $results = [];

        foreach (array_unique($data['student_ids']) as $studentId) {
            [$ok, $result] = $this->enroll($request, $course, (int) $studentId);
            $results[] = ['student_id' => (int) $studentId, 'enrolled' => $ok, 'message' => $ok ? 'Inscrito' : $result];
        }

        return response()->json([
            'enrolled' => collect($results)->where('enrolled', true)->count(),
            'results' => $results,
        ]);
    }

    public function destroy(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $enrollment->delete();

        return response()->json(null, 204);
    }

    public function counts(Request $request): JsonResponse
    {
        $ids = array_filter(explode(',', (string) $request->query('course_ids')));

        $counts = Enrollment::query()
            ->when($ids, fn ($q) => $q->whereIn('course_id', $ids))
            ->selectRaw('course_id, count(*) as total')
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        return response()->json(['data' => $counts]);
    }

    public function forStudent(Request $request, int $studentId): JsonResponse
    {
        $actor = $this->actor($request);

        if ($actor['role'] !== 'admin') {
            // RN-04: a student only sees their own enrollments
            $own = $actor['role'] === 'student' ? $this->catalog->forActor($actor)->studentByUser((int) $actor['id']) : null;
            abort_unless($own && (int) $own['id'] === $studentId, 403, 'Solo puedes consultar tu propia información.');
        }

        $enrollments = Enrollment::with('grades')->where('student_id', $studentId)->orderBy('id')->get();

        return response()->json(['data' => $enrollments]);
    }

    public function destroyForStudent(Request $request, int $studentId): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $deleted = Enrollment::where('student_id', $studentId)->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Business rules of an enrollment ("reservation"):
     * course exists and is active, its period has not ended, the student exists and is active,
     * no duplicates and the course still has seats.
     *
     * @return array{0: bool, 1: mixed, 2?: int}
     */
    private function enroll(Request $request, ?array $course, int $studentId): array
    {
        if (! $course) {
            return [false, 'La clase no existe.', 422];
        }
        if (($course['status'] ?? null) !== 'active') {
            return [false, 'La clase no está activa.', 409];
        }
        if (isset($course['period']['end_date']) && today()->gt($course['period']['end_date'])) {
            return [false, 'El periodo de la clase ya terminó.', 409];
        }

        $student = $this->catalog->forActor($this->actor($request))->student($studentId);

        if (! $student) {
            return [false, 'El alumno no existe.', 422];
        }
        if (($student['status'] ?? 'active') !== 'active') {
            return [false, 'El alumno está dado de baja.', 409];
        }
        if (Enrollment::where('course_id', $course['id'])->where('student_id', $studentId)->exists()) {
            return [false, 'El alumno ya está inscrito en esta clase.', 409];
        }
        if (Enrollment::where('course_id', $course['id'])->count() >= (int) $course['capacity']) {
            return [false, 'La clase ya no tiene cupo.', 409];
        }

        $enrollment = Enrollment::create([
            'course_id' => $course['id'],
            'student_id' => $studentId,
            'enrolled_by' => $this->actor($request)['id'],
        ]);

        return [true, $enrollment->fresh(), 201];
    }
}
