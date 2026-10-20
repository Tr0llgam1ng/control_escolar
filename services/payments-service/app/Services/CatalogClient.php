<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service-to-service client. The Payments Service never reads the catalog database;
 * it only asks "which student record belongs to this user?" to check ownership.
 */
class CatalogClient
{
    public function studentIdByUser(int $userId, array $actor): ?int
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.catalog.url'), '/'))
                ->acceptJson()
                ->timeout(5)
                ->withHeaders(array_filter([
                    'X-Service-Key' => config('services.internal.key'),
                    'X-User-Id' => $actor['id'] ?? null,
                    'X-User-Role' => $actor['role'] ?? null,
                ], fn ($v) => $v !== null))
                ->get("/api/students/by-user/{$userId}");
        } catch (ConnectionException) {
            throw new HttpException(503, 'El servicio de catálogo no está disponible.');
        }

        if ($response->status() === 404) {
            return null;
        }
        if ($response->failed()) {
            throw new HttpException(502, 'Error al consultar el servicio de catálogo.');
        }

        return isset($response['id']) ? (int) $response['id'] : null;
    }
}
