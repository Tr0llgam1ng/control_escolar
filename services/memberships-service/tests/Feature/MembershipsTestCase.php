<?php

namespace Tests\Feature;

use App\Memberships\CreditLedger;
use App\Memberships\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class MembershipsTestCase extends TestCase
{
    use RefreshDatabase;

    /** Demo student account (auth user 30 → catalog student 1). */
    protected const STUDENT = 30;

    /** Charges of payments-service, faked per test: id => status payload. */
    protected array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(10, 0));
        $this->seed();

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (preg_match('#^/api/charges/(\d+)/status$#', $path, $m)) {
                // The memberships service must call it as an internal service, without a user.
                if ($request->header('X-Service-Name')[0] !== 'memberships-service' || $request->hasHeader('X-User-Id')) {
                    return Http::response(['message' => 'forbidden'], 403);
                }

                return isset($this->charges[$m[1]]) ? Http::response($this->charges[$m[1]]) : Http::response(['message' => 'Not found'], 404);
            }

            return match ($path) {
                '/api/teachers/by-user/20' => Http::response(['id' => 5]),
                '/api/teachers/by-user/21' => Http::response(['id' => 6]),
                default => Http::response(['message' => 'Not found'], 404),
            };
        });
    }

    protected function student(int $userId = self::STUDENT): static
    {
        return $this->asGateway($userId, 'student');
    }

    protected function giveCredits(int $amount, int $userId = self::STUDENT): void
    {
        app(CreditLedger::class)->credit($userId, $amount, 'adjustment', 'Saldo de prueba', ['type' => 'service', 'id' => 'test']);
    }

    protected function balance(int $userId = self::STUDENT): int
    {
        return app(CreditLedger::class)->balance($userId);
    }

    /** Gives the credits and buys the plan. */
    protected function onPlan(string $code, int $userId = self::STUDENT): void
    {
        $price = \App\Models\Plan::where('code', $code)->value('price_credits');
        $this->giveCredits($price, $userId);
        app(SubscriptionService::class)->subscribe($userId, $code, ['type' => 'service', 'id' => 'test']);
    }

    protected function fakeCharge(int $id, array $override = []): void
    {
        $this->charges[$id] = array_merge([
            'id' => $id, 'reference' => '1234567890123', 'student_id' => 1, 'status' => 'pending', 'paid' => false,
            'paid_at' => null, 'is_expired' => false, 'amount' => '500.00', 'currency' => 'MXN',
            'document_type_code' => 'creditos_550', 'category' => 'credits',
        ], $override);
    }
}
