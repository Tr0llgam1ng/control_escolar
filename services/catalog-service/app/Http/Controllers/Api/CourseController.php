<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CRUD of courses (class sections) — the "rooms" of the reservation example. */
class CourseController extends Controller
{
    /** GET /api/courses?period_id=&teacher_id=&ids=1,2&status= */
    public function index(Request $request): JsonResponse
    {
        $courses = Course::with(['subject', 'period', 'teacher'])
            ->when($request->query('period_id'), fn ($q, $id) => $q->where('period_id', $id))
            ->when($request->query('teacher_id'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('ids'), fn ($q, $ids) => $q->whereIn('id', array_filter(explode(',', $ids))))
            ->get()
            ->sortBy(fn ($c) => [$c->group_name, $c->subject->name])
            ->values();

        return response()->json(['data' => $courses]);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json($course->load(['subject', 'period', 'teacher']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $course = Course::create($this->validated($request));

        return response()->json($course->load(['subject', 'period', 'teacher']), 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $course->update($this->validated($request, $course));

        return response()->json($course->load(['subject', 'period', 'teacher']));
    }

    /** The assigned teacher can change the class status and the number of units (RF-12). */
    public function settings(Request $request, Course $course): JsonResponse
    {
        $actor = $this->actor($request);
        $isOwner = $actor['role'] === 'teacher'
            && $course->teacher_id
            && Teacher::whereKey($course->teacher_id)->where('user_id', $actor['id'])->exists();

        abort_unless($actor['role'] === 'admin' || $isOwner, 403, 'Esta clase no está asignada a ti.');

        $course->update($request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'units_count' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]));

        return response()->json($course->load(['subject', 'period', 'teacher']));
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $course->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Course $course = null): array
    {
        return $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'period_id' => ['required', 'exists:periods,id'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'group_name' => [
                'required', 'string', 'max:20',
                Rule::unique('courses')
                    ->where(fn ($q) => $q->where('subject_id', $request->input('subject_id'))->where('period_id', $request->input('period_id')))
                    ->ignore($course?->id),
            ],
            'classroom' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'units_count' => ['required', 'integer', 'min:1', 'max:10'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ], ['group_name.unique' => 'Ya existe esa materia para ese grupo en el periodo.']);
    }
}
