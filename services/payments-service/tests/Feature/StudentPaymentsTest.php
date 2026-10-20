<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\TestCard;
use App\Payments\ReferenceGenerator;

class StudentPaymentsTest extends PaymentsTestCase
{
    public function test_student_generates_a_reference_with_the_document_price(): void
    {
        $response = $this->asGateway(30, 'student')
            ->postJson('/api/charges', ['document_type_id' => $this->type('kardex')->id])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('status_label', 'Pendiente')
            ->assertJsonPath('amount', '120.00')
            ->assertJsonPath('student_id', 1)
            ->assertJsonPath('is_new', true);

        $this->assertTrue(ReferenceGenerator::isValid($response->json('reference')));

        // A second request for the same document while the reference is alive returns the same one.
        $this->asGateway(30, 'student')
            ->postJson('/api/charges', ['document_type_id' => $this->type('kardex')->id])
            ->assertOk()
            ->assertJsonPath('id', $response->json('id'));
        $this->assertSame(1, Charge::count());
    }

    public function test_only_students_and_admins_create_references(): void
    {
        $id = $this->type()->id;
        $this->asGateway(2, 'teacher')->postJson('/api/charges', ['document_type_id' => $id])->assertForbidden();
        $this->asGateway(30, 'student')->postJson('/api/charges', ['document_type_id' => $id, 'student_id' => 2])->assertUnprocessable();
        $this->asGateway(30, 'student')->postJson('/api/charges', ['document_type_id' => 999])->assertUnprocessable();
        $this->asGateway(1, 'admin')->postJson('/api/charges', ['document_type_id' => $id, 'student_id' => 2])->assertCreated()->assertJsonPath('student_id', 2);
    }

    public function test_card_payment_is_approved_and_debits_the_test_card(): void
    {
        $charge = $this->newReference();

        $response = $this->payWithCard($charge)
            ->assertOk()
            ->assertJsonPath('result', 'approved')
            ->assertJsonPath('charge.status', 'approved')
            ->assertJsonPath('charge.payment_method', 'card')
            ->assertJsonPath('transaction.card_last4', '1111')
            ->assertJsonMissingPath('transaction.card_balance_after');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('transaction.authorization_code'));
        $this->assertStringNotContainsString('4111111111111111', $response->getContent());
        $this->assertSame('19850.00', (string) TestCard::where('number', '4111111111111111')->value('balance'));

        // Already paid: cannot be paid twice.
        $this->payWithCard($charge)->assertStatus(409);
    }

    public function test_declined_cards_leave_the_reference_pending(): void
    {
        $charge = $this->newReference();

        $cases = [
            [['number' => '4000000000000002'], 'Fondos insuficientes'],
            [['number' => '4000000000000069', 'exp_month' => 1, 'exp_year' => 2024], 'vencida'],
            [['number' => '4000000000000119'], 'bloqueada'],
            [['number' => '4242424242424242'], 'no reconocida'],
            [['cvv' => '999'], 'CVV'],
            [['exp_month' => 1], 'fecha de vencimiento'],
        ];
        foreach ($cases as [$card, $reason]) {
            $this->payWithCard($charge, $card)
                ->assertOk()
                ->assertJsonPath('result', 'rejected')
                ->assertJsonPath('charge.status', 'pending')
                ->assertJsonPath('transaction.status', 'rejected');
            $this->assertStringContainsString($reason, $charge->transactions()->reorder('id', 'desc')->value('decline_reason'));
        }

        $this->assertSame('50.00', (string) TestCard::where('number', '4000000000000002')->value('balance'));
        $this->assertSame('pending', $charge->fresh()->status);

        // Retrying with a good card works.
        $this->payWithCard($charge)->assertJsonPath('result', 'approved');
        $this->assertSame(7, $charge->transactions()->count());
    }

    public function test_card_form_validation(): void
    {
        $charge = $this->newReference();
        $this->payWithCard($charge, ['number' => '4111111111111112'])->assertUnprocessable()->assertJsonValidationErrors('card.number');
        $this->payWithCard($charge, ['cvv' => '12'])->assertUnprocessable()->assertJsonValidationErrors('card.cvv');
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'card'])->assertUnprocessable();
        $this->asGateway(30, 'student')
            ->postJson("/api/charges/{$charge->id}/pay", ['method' => 'card', 'amount' => '1.00', 'card' => self::GOOD_CARD])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(0, $charge->transactions()->count());
    }

    public function test_cash_leaves_the_reference_pending(): void
    {
        $charge = $this->newReference();
        $expiresAt = $charge->expires_at->toDateTimeString();
        $this->travel(5)->minutes();

        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('result', 'pending')
            ->assertJsonPath('charge.status', 'pending')
            ->assertJsonPath('charge.payment_method', 'cash');
        // Updating the reference never moves its expiration (MySQL ON UPDATE CURRENT_TIMESTAMP regression).
        $this->assertSame($expiresAt, $charge->fresh()->expires_at->toDateTimeString());
        $this->assertStringContainsString($charge->reference, $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'cash'])->json('message'));
    }

    public function test_transfer_is_not_available(): void
    {
        $charge = $this->newReference();

        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'transfer'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El pago por transferencia no está disponible por el momento. Elige tarjeta o efectivo.');
        $this->assertNull($charge->fresh()->payment_method);
    }

    public function test_students_only_see_and_pay_their_own_references(): void
    {
        $charge = $this->newReference();

        $this->asGateway(31, 'student')->getJson("/api/charges/{$charge->id}")->assertForbidden();
        $this->asGateway(31, 'student')->getJson("/api/charges/by-reference/{$charge->reference}")->assertForbidden();
        $this->asGateway(31, 'student')->getJson('/api/students/1/charges')->assertForbidden();
        $this->payWithCard($charge, [], 31)->assertForbidden();

        $this->asGateway(30, 'student')->getJson('/api/students/1/charges')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.pending_total', '150.00');
        $this->asGateway(30, 'student')->getJson('/api/charges/by-reference/0000000000001')->assertUnprocessable();
    }

    public function test_expired_reference_cannot_be_paid_and_a_new_one_can_be_generated(): void
    {
        $charge = $this->expire($this->newReference());

        $this->payWithCard($charge)->assertStatus(409);
        $this->asGateway(30, 'student')->postJson("/api/charges/{$charge->id}/pay", ['method' => 'cash'])->assertStatus(409);

        $this->asGateway(30, 'student')->postJson('/api/charges', ['document_type_id' => $this->type()->id])->assertCreated();
        $this->assertSame(2, Charge::count());
    }
}
