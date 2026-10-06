<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /** GET /api/students?ids=1,2,3&group=9B&status=active&search= */
    public function index(Request $request): JsonResponse
    {
        $students = Student::query()
            ->when($request->query('ids'), fn ($q, $ids) => $q->whereIn('id', array_filter(explode(',', $ids))))
            ->when($request->query('group'), fn ($q, $g) => $q->where('group_name', $g))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('enrollment_number', 'like', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $students]);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $actor = $this->actor($request);
        // RN-04: a student only sees their own record
        abort_if($actor['role'] === 'student' && $actor['id'] !== $student->user_id, 403);

        return response()->json($student);
    }

    public function byUser(int $userId): JsonResponse
    {
        $student = Student::where('user_id', $userId)->first();

        return $student ? response()->json($student) : response()->json(['message' => 'Alumno no encontrado.'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');

        return response()->json(Student::create($this->validated($request)), 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $student->update($this->validated($request, $student));

        return response()->json($student);
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $student->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Student $student = null): array
    {
        return $request->validate([
            'user_id' => [$student ? 'sometimes' : 'required', 'integer', Rule::unique('students')->ignore($student?->id)],
            'enrollment_number' => ['required', 'string', 'max:30', Rule::unique('students')->ignore($student?->id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'program' => ['required', 'string', 'max:255'],
            'semester' => ['required', 'integer', 'min:1', 'max:15'],
            'group_name' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
