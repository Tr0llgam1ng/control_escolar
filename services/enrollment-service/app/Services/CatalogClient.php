<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service-to-service client: the Enrollment Service never reads the catalog database,
 * it asks the Catalog Service through its HTTP contract.
 */
class CatalogClient
{
    /** @param array{id:int|null, role:string|null} $actor */
    public function __construct(private array $actor = ['id' => null, 'role' => null]) {}

    public function forActor(array $actor): self
    {
        return new self($actor);
    }

    public function course(int $id): ?array
    {
        return $this->get("/api/courses/{$id}");
    }

    public function student(int $id): ?array
    {
        return $this->get("/api/students/{$id}");
    }

    public function teacherByUser(int $userId): ?array
    {
        return $this->get("/api/teachers/by-user/{$userId}");
    }

    public function studentByUser(int $userId): ?array
    {
        return $this->get("/api/students/by-user/{$userId}");
    }

    private function get(string $path): ?array
    {
        try {
            $response = $this->http()->get($path);
        } catch (ConnectionException) {
            throw new HttpException(503, 'El servicio de catálogo no está disponible.');
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new HttpException(502, 'Error al consultar el servicio de catálogo.');
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.catalog.url'), '/'))
            ->acceptJson()
            ->timeout(5)
            ->withHeaders(array_filter([
                'X-Service-Key' => config('services.internal.key'),
                'X-User-Id' => $this->actor['id'],
                'X-User-Role' => $this->actor['role'],
            ], fn ($v) => $v !== null));
    }
}
