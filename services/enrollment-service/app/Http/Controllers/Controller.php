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

    /** Defense in depth: the gateway checks roles, the service checks them again (RNF-03). */
    protected function requireRole(Request $request, string ...$roles): void
    {
        abort_unless(in_array($this->actor($request)['role'], $roles, true), 403, 'Forbidden for role '.($this->actor($request)['role'] ?? 'guest').'.');
    }
}
