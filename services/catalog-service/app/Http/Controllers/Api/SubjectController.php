<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subjects = Subject::withCount('courses')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $subjects]);
    }

    public function show(Subject $subject): JsonResponse
    {
        return response()->json($subject);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');

        return response()->json(Subject::create($this->validated($request)), 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $subject->update($this->validated($request, $subject));

        return response()->json($subject);
    }

    public function destroy(Request $request, Subject $subject): JsonResponse
    {
        $this->requireRole($request, 'admin');

        if ($subject->courses()->exists()) {
            return response()->json(['message' => 'La materia tiene clases registradas.'], 409);
        }

        $subject->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Subject $subject = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('subjects')->ignore($subject?->id)],
            'name' => ['required', 'string', 'max:255'],
            'credits' => ['required', 'integer', 'min:0', 'max:30'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:15'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
