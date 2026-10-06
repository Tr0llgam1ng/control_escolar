<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeLog;
use App\Services\CatalogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contract:
 *  GET /api/courses/{courseId}/roster   admin | assigned teacher
 *  PUT /api/courses/{courseId}/grades   {grades: [{enrollment_id, unit, score|null}]}
 */
class GradeController extends Controller
{
    public function __construct(private CatalogClient $catalog) {}

    public function roster(Request $request, int $courseId): JsonResponse
    {
        $course = $this->authorizeCourse($request, $courseId);

        $enrollments = Enrollment::with('grades')->where('course_id', $courseId)->orderBy('id')->get();

        return response()->json([
            'course_id' => $courseId,
            'units_count' => (int) $course['units_count'],
            'capacity' => (int) $course['capacity'],
            'passing_grade' => Enrollment::passingGrade(),
            'grade_capture_open' => (bool) ($course['period']['grade_capture_open'] ?? true),
            'data' => $enrollments,
        ]);
    }

    public function update(Request $request, int $courseId): JsonResponse
    {
        $course = $this->authorizeCourse($request, $courseId);
        $actor = $this->actor($request);
        $unitsCount = (int) $course['units_count'];

        // RN-02: teachers only inside the capture window; Control Escolar can authorize changes
        if ($actor['role'] === 'teacher' && ! ($course['period']['grade_capture_open'] ?? true)) {
            return response()->json(['message' => 'El periodo de captura de calificaciones está cerrado.'], 423);
        }

        $data = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*.enrollment_id' => ['required', 'integer'],
            'grades.*.unit' => ['required', 'integer', 'min:1', 'max:'.$unitsCount],
            'grades.*.score' => ['nullable', 'numeric', 'min:0', 'max:10'], // RN-03
        ], [
            'grades.*.score.min' => 'Las calificaciones van de 0 a 10.',
            'grades.*.score.max' => 'Las calificaciones van de 0 a 10.',
            'grades.*.unit.max' => "La clase solo tiene {$unitsCount} unidades.",
        ]);

        $enrollments = Enrollment::where('course_id', $courseId)->get()->keyBy('id');
        $changes = 0;

        DB::transaction(function () use ($data, $enrollments, $actor, $unitsCount, &$changes) {
            $touched = [];

            foreach ($data['grades'] as $row) {
                $enrollment = $enrollments->get($row['enrollment_id']);
                if (! $enrollment) {
                    continue; // not enrolled in this course
                }

                $grade = Grade::where('enrollment_id', $enrollment->id)->where('unit', $row['unit'])->first();
                $new = $row['score'] === null || $row['score'] === '' ? null : round((float) $row['score'], 2);
                $old = $grade?->score;

                if ($old === $new) {
                    continue;
                }

                if ($new === null) {
                    $grade?->delete();
                } else {
                    Grade::updateOrCreate(
                        ['enrollment_id' => $enrollment->id, 'unit' => $row['unit']],
                        ['score' => $new, 'captured_by' => $actor['id']],
                    );
                }

                GradeLog::create(['enrollment_id' => $enrollment->id, 'unit' => $row['unit'], 'old_score' => $old, 'new_score' => $new, 'user_id' => $actor['id']]);
                $touched[$enrollment->id] = $enrollment;
                $changes++;
            }

            foreach ($touched as $enrollment) {
                $enrollment->recalculate($unitsCount);
            }
        });

        return response()->json([
            'changes' => $changes,
            'data' => Enrollment::with('grades')->where('course_id', $courseId)->orderBy('id')->get(),
        ]);
    }

    /** RN-01: only the assigned teacher (or Control Escolar) works with a course. */
    private function authorizeCourse(Request $request, int $courseId): array
    {
        $actor = $this->actor($request);
        $catalog = $this->catalog->forActor($actor);
        $course = $catalog->course($courseId);

        abort_if(! $course, 404, 'La clase no existe.');

        if ($actor['role'] !== 'admin') {
            $teacher = $actor['role'] === 'teacher' ? $catalog->teacherByUser((int) $actor['id']) : null;
            abort_unless($teacher && (int) $teacher['id'] === (int) ($course['teacher_id'] ?? 0), 403, 'Esta clase no está asignada a ti.');
        }

        return $course;
    }
}
