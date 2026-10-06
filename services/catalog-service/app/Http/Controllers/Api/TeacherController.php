<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teachers = Teacher::withCount('courses')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $teachers]);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        return response()->json($teacher);
    }

    /** Used by the gateway and the Enrollment Service to resolve "who is this logged user". */
    public function byUser(int $userId): JsonResponse
    {
        $teacher = Teacher::where('user_id', $userId)->first();

        return $teacher ? response()->json($teacher) : response()->json(['message' => 'Profesor no encontrado.'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');

        return response()->json(Teacher::create($this->validated($request)), 201);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $teacher->update($this->validated($request, $teacher));

        return response()->json($teacher);
    }

    public function destroy(Request $request, Teacher $teacher): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $teacher->delete(); // courses keep existing with teacher_id = null

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Teacher $teacher = null): array
    {
        return $request->validate([
            'user_id' => [$teacher ? 'sometimes' : 'required', 'integer', Rule::unique('teachers')->ignore($teacher?->id)],
            'employee_number' => ['required', 'string', 'max:30', Rule::unique('teachers')->ignore($teacher?->id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
    }
}
