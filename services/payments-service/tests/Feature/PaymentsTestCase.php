<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class PaymentsTestCase extends TestCase
{
    use RefreshDatabase;

    protected const GOOD_CARD = ['number' => '4111 1111 1111 1111', 'holder_name' => 'Alumno Demo', 'exp_month' => 12, 'exp_year' => 2030, 'cvv' => '123'];

    protected function setUp(): void
    {
        parent::setUp();

        // Document types and test cards from the seeder; the demo references are removed.
        $this->seed();
        DB::table('charge_audit_logs')->delete();
        DB::table('charges')->delete();

        // Catalog-service contract used for ownership checks: user 30 -> student 1, user 31 -> student 2.
        Http::fake(function ($request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/students/by-user/30' => Http::response(['id' => 1]),
                '/api/students/by-user/31' => Http::response(['id' => 2]),
                default => Http::response(['message' => 'Not found'], 404),
            };
        });
    }

    protected function type(string $code = 'constancia_estudios'): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    /** The student (user 30 → student 1) generates a reference. */
    protected function newReference(string $code = 'constancia_estudios'): Charge
    {
        $id = $this->asGateway(30, 'student')
            ->postJson('/api/charges', ['document_type_id' => $this->type($code)->id])
            ->assertCreated()
            ->json('id');

        return Charge::findOrFail($id);
    }

    protected function payWithCard(Charge $charge, array $card = [], int $userId = 30)
    {
        return $this->asGateway($userId, 'student')->postJson("/api/charges/{$charge->id}/pay", [
            'method' => 'card',
            'card' => array_merge(self::GOOD_CARD, $card),
        ]);
    }

    protected function expire(Charge $charge): Charge
    {
        $charge->forceFill(['expires_at' => now()->subHour()])->save();

        return $charge->fresh();
    }
}
