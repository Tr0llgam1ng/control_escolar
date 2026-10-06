<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Period::withCount('courses')->orderByDesc('start_date')->get()]);
    }

    public function current(): JsonResponse
    {
        $period = Period::where('is_current', true)->first()
            ?? Period::whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->first();

        return $period ? response()->json($period) : response()->json(['message' => 'No hay periodo actual.'], 404);
    }

    public function show(Period $period): JsonResponse
    {
        return response()->json($period);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $period = Period::create($this->validated($request));

        if ($request->boolean('is_current') || Period::count() === 1) {
            $period->makeCurrent();
        }

        return response()->json($period->fresh(), 201);
    }

    public function update(Request $request, Period $period): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $period->update($this->validated($request));

        if ($request->boolean('is_current')) {
            $period->makeCurrent();
        }

        return response()->json($period->fresh());
    }

    public function destroy(Request $request, Period $period): JsonResponse
    {
        $this->requireRole($request, 'admin');

        if ($period->courses()->exists()) {
            return response()->json(['message' => 'El periodo tiene clases registradas.'], 409);
        }

        $period->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'grades_open_from' => ['nullable', 'date'],
            'grades_open_until' => ['nullable', 'date', 'after_or_equal:grades_open_from'],
        ]);
    }
}
