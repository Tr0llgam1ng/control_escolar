<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** @return array{id: int|null, role: string|null} */
    protected function actor(Request $request): array
    {
        return $request->attributes->get('actor', ['id' => null, 'role' => null]);
    }

    /** Defense in depth: the gateway checks roles, the service checks them again. */
    protected function requireRole(Request $request, string ...$roles): void
    {
        abort_unless(in_array($this->actor($request)['role'], $roles, true), 403, 'No tienes permiso para esta operación.');
    }

    /** Memberships belong to students: returns the user id of the logged student. */
    protected function studentId(Request $request): int
    {
        $actor = $this->actor($request);
        abort_unless($actor['role'] === 'student' && $actor['id'] !== null, 403, 'Las membresías son para alumnos.');

        return (int) $actor['id'];
    }

    protected function isAdmin(Request $request): bool
    {
        return $this->actor($request)['role'] === 'admin';
    }

    /** @return array{type: string, id: string|int|null} actor in ledger form */
    protected function ledgerActor(Request $request): array
    {
        $actor = $this->actor($request);

        return $actor['id'] !== null ? ['type' => 'user', 'id' => $actor['id']] : ['type' => 'service', 'id' => $request->header('X-Service-Name', 'gateway')];
    }
}
