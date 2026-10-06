<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Simulate a request coming from the API Gateway on behalf of a user. */
    protected function asGateway(?int $userId = 1, ?string $role = 'admin'): static
    {
        return $this->withHeaders(array_filter([
            'X-Service-Key' => 'test-service-key',
            'X-User-Id' => $userId,
            'X-User-Role' => $role,
            'Accept' => 'application/json',
        ], fn ($v) => $v !== null));
    }
}
