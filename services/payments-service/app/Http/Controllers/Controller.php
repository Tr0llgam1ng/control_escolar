<?php

namespace App\Http\Controllers;

use App\Services\CatalogClient;
use Illuminate\Http\Request;

abstract class Controller
{
    /** @return array{id: int|null, role: string|null} */
    protected function actor(Request $request): array
    {
        return $request->attributes->get('actor', ['id' => null, 'role' => null]);
    }

    /** Defense in depth: the gateway checks roles, the service checks them again (RNF-03). */
    protected function requireRole(Request $request, string ...$roles): void
    {
        abort_unless(in_array($this->actor($request)['role'], $roles, true), 403, 'No tienes permiso para esta operación.');
    }

    /**
     * Internal call = another trusted service, not a user routed by the gateway:
     * it names itself in X-Service-Name (allow-listed) and carries no user role.
     */
    protected function internalCaller(Request $request): ?string
    {
        $name = (string) $request->header('X-Service-Name', '');

        return $name !== '' && $this->actor($request)['role'] === null
            && in_array($name, config('services.internal_callers', []), true) ? $name : null;
    }

    protected function requireInternal(Request $request): string
    {
        $caller = $this->internalCaller($request);
        abort_unless($caller !== null, 403, 'Este recurso solo está disponible para servicios internos.');

        return $caller;
    }

    /** @return array{type: string, id: string|int|null} actor in audit-log form */
    protected function auditActor(Request $request): array
    {
        if ($caller = $this->internalCaller($request)) {
            return ['type' => 'service', 'id' => $caller];
        }

        return ['type' => 'user', 'id' => $this->actor($request)['id']];
    }

    /** Catalog student record of the logged student (asked to catalog-service over HTTP). */
    protected function ownStudentId(Request $request): int
    {
        $actor = $this->actor($request);
        abort_unless($actor['role'] === 'student' && $actor['id'] !== null, 403, 'Solo un alumno puede realizar esta operación.');
        $own = app(CatalogClient::class)->studentIdByUser((int) $actor['id'], $actor);
        abort_if($own === null, 404, 'Tu cuenta no tiene expediente de alumno.');

        return $own;
    }

    /** Admin, or the student whose catalog record is $studentId. */
    protected function requireOwnerOrAdmin(Request $request, int $studentId): void
    {
        if ($this->actor($request)['role'] === 'admin') {
            return;
        }

        abort_unless($this->ownStudentId($request) === $studentId, 403, 'Solo puedes consultar tus propios pagos.');
    }
}
