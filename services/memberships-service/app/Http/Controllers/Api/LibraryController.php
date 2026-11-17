<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Memberships\LibraryService;
use App\Models\LibrarySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contract: time module 2 — digital library (minutes)
 *  GET  /api/library/resources                  any user
 *  GET  /api/library-sessions                   student: mine (+ the open one) · admin: all
 *  POST /api/library-sessions                   student {resource}
 *  POST /api/library-sessions/{id}/close        owner student | admin
 */
class LibraryController extends Controller
{
    public function resources(): JsonResponse
    {
        return response()->json(['data' => collect(config('memberships.library.resources'))->map(fn ($r, $code) => ['code' => $code, ...$r])->values()]);
    }

    public function index(Request $request, LibraryService $library): JsonResponse
    {
        if ($this->isAdmin($request)) {
            return response()->json(['data' => LibrarySession::orderByDesc('id')->limit(300)->get()]);
        }
        $userId = $this->studentId($request);
        $library->closeExpired($userId);
        $sessions = LibrarySession::where('user_id', $userId)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $sessions, 'open' => $sessions->firstWhere('status', LibrarySession::OPEN)]);
    }

    public function store(Request $request, LibraryService $library): JsonResponse
    {
        $userId = $this->studentId($request);
        $data = $request->validate(['resource' => ['required', 'string', 'max:30']], ['resource.required' => 'Elige un recurso.']);

        return response()->json($library->start($userId, $data['resource']), 201);
    }

    public function close(Request $request, LibraryService $library, int $id): JsonResponse
    {
        $session = LibrarySession::findOrFail($id);
        abort_unless($this->isAdmin($request) || $session->user_id === $this->studentId($request), 403, 'Solo puedes cerrar tus sesiones.');

        return response()->json($library->close($session));
    }
}
