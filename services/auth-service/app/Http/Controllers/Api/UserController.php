<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Contract: user accounts (admin only).
 *  GET    /api/users?role=&search=
 *  GET    /api/users/{id}
 *  POST   /api/users          {name, email, password, role, is_active?}
 *  PUT    /api/users/{id}     {name?, email?, password?, is_active?}
 *  DELETE /api/users/{id}
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireRole($request, 'admin');

        $users = User::query()
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): UserResource
    {
        $actor = $this->actor($request);
        abort_unless($actor['role'] === 'admin' || $actor['id'] === $user->id, 403);

        return new UserResource($user);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole($request, 'admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        return (new UserResource(User::create($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $actor = $this->actor($request);
        $isAdmin = $actor['role'] === 'admin';
        abort_unless($isAdmin || $actor['id'] === $user->id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! $isAdmin) {
            unset($data['is_active'], $data['email'], $data['name']); // users can only change their password
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        $user->update($data);

        if (($data['is_active'] ?? true) === false || isset($data['password'])) {
            $user->tokens()->delete(); // force re-login
        }

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->requireRole($request, 'admin');
        $user->delete();

        return response()->json(null, 204);
    }
}
