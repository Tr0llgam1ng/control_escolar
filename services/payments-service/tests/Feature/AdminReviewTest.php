<?php

namespace Tests\Feature;

use App\Models\ChargeAuditLog;

class AdminReviewTest extends PaymentsTestCase
{
    public function test_admin_sees_new_references_and_marks_them_reviewed(): void
    {
        $charge = $this->newReference();
        $this->newReference('kardex');

        $this->asGateway(1, 'admin')->getJson('/api/charges?new=1')->assertOk()->assertJsonCount(2, 'data');
        $this->asGateway(1, 'admin')->getJson('/api/charges/summary')->assertJsonPath('new', 2)->assertJsonPath('pending', 2);

        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/review")->assertOk()->assertJsonPath('is_new', false);
        $this->asGateway(1, 'admin')->getJson('/api/charges?new=1')->assertJsonCount(1, 'data');

        $this->asGateway(30, 'student')->getJson('/api/charges')->assertForbidden();
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/review")->assertForbidden();
    }

    public function test_cash_received_must_cover_the_total(): void
    {
        $charge = $this->newReference(); // 150.00
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'cash'])->assertOk();

        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '100'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La cantidad recibida ($100.00) es menor al total de la referencia ($150.00).');
        $this->assertSame('pending', $charge->fresh()->status);

        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '200'])
            ->assertOk()
            ->assertJsonPath('charge.status', 'approved')
            ->assertJsonPath('charge.is_new', false)
            ->assertJsonPath('transaction.change_given', '50.00')
            ->assertJsonPath('message', 'Pago en efectivo registrado. Cambio: $50.00.');

        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '150'])->assertStatus(409);
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '150'])->assertForbidden();
    }

    public function test_admin_cannot_switch_a_card_reference_to_cash(): void
    {
        $charge = $this->newReference();
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", [
            'method' => 'card',
            'card' => ['number' => '4000000000000002'] + self::GOOD_CARD, // declined: insufficient funds
        ])->assertOk()->assertJsonPath('result', 'rejected')->assertJsonPath('charge.payment_method', 'card');

        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '150'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'El alumno eligió pagar con tarjeta; no se puede registrar efectivo en esta referencia.');
        $this->assertSame('pending', $charge->fresh()->status);
    }

    public function test_cash_is_not_received_for_an_expired_reference(): void
    {
        $charge = $this->expire($this->newReference());
        $this->asGateway(1, 'admin')->postJson("/api/charges/{$charge->id}/cash", ['amount_received' => '150'])->assertStatus(409);
    }

    public function test_status_rules(): void
    {
        $alive = $this->newReference();
        $url = "/api/charges/{$alive->id}/status";

        // Approved only with a verified payment; rejected only when expired; pending only while alive.
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'approved'])->assertUnprocessable();
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'rejected'])->assertUnprocessable();
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'pending'])->assertOk()->assertJsonPath('is_new', false);
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'paid'])->assertUnprocessable();

        $expired = $this->expire($this->newReference('kardex'));
        $this->asGateway(1, 'admin')->patchJson("/api/charges/{$expired->id}/status", ['status' => 'pending'])->assertUnprocessable();
        $this->asGateway(1, 'admin')->patchJson("/api/charges/{$expired->id}/status", ['status' => 'rejected', 'reason' => 'No pagó'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('status_label', 'Rechazado');

        // Card payment: already approved by the bank simulator; the admin confirms it and cannot reject it.
        $this->payWithCard($alive)->assertJsonPath('result', 'approved');
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'rejected'])->assertStatus(409);
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'pending'])->assertStatus(409);
        $this->asGateway(1, 'admin')->patchJson($url, ['status' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertTrue(ChargeAuditLog::where('charge_id', $expired->id)->where('to_status', 'rejected')->exists());
        $this->asGateway(30, 'student')->patchJson($url, ['status' => 'approved'])->assertForbidden();
    }

    public function test_admin_filters_and_detail(): void
    {
        $card = $this->newReference();
        $this->payWithCard($card);
        $cash = $this->newReference('kardex');
        $this->asGateway(30, 'student')->postJson("/api/charges/{$cash->id}/pay", ['method' => 'cash']);
        $this->expire($this->newReference('boleta'));

        $this->asGateway(1, 'admin')->getJson('/api/charges?method=card')->assertJsonCount(1, 'data');
        $this->asGateway(1, 'admin')->getJson('/api/charges?method=cash')->assertJsonCount(1, 'data');
        $this->asGateway(1, 'admin')->getJson('/api/charges?status=approved')->assertJsonCount(1, 'data');
        $this->asGateway(1, 'admin')->getJson('/api/charges?expired=1')->assertJsonCount(1, 'data');
        $this->asGateway(1, 'admin')->getJson('/api/charges?q='.substr($card->reference, 0, 8))->assertJsonPath('data.0.id', $card->id);
        $this->asGateway(1, 'admin')->getJson('/api/charges/summary')
            ->assertJsonPath('approved', 1)->assertJsonPath('pending', 1)->assertJsonPath('expired', 1)->assertJsonPath('collected', '150.00');

        $this->asGateway(1, 'admin')->getJson("/api/charges/{$card->id}")
            ->assertOk()
            ->assertJsonPath('transactions.0.method', 'card')
            ->assertJsonPath('transactions.0.status', 'approved')
            ->assertJsonPath('audit_logs.1.action', 'card_approved');
    }

    public function test_internal_status_endpoint(): void
    {
        $charge = $this->newReference();
        $this->payWithCard($charge);

        $this->asService('enrollment-service')->getJson("/api/charges/{$charge->id}/status")->assertOk()->assertJsonPath('paid', true);
        $this->asGateway(1, 'admin')->getJson("/api/charges/{$charge->id}/status")->assertForbidden();
        $this->withHeaders(['X-Service-Key' => 'wrong'])->getJson("/api/charges/{$charge->id}/status")->assertUnauthorized();
    }
}
