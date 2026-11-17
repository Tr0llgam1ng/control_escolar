<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\CreditTransaction;

/** Money → credits through a payments-service reference. */
class CreditPurchasesTest extends MembershipsTestCase
{
    public function test_credits_arrive_once_the_reference_is_paid(): void
    {
        $this->fakeCharge(10);
        $this->student()->postJson('/api/credit-purchases', ['charge_id' => 10, 'student_id' => 1, 'pack_code' => 'creditos_550'])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('credits', 550);
        $this->assertSame(0, $this->balance());

        // Paid with card in payments-service → the next wallet read credits it.
        $this->fakeCharge(10, ['status' => 'approved', 'paid' => true]);
        $this->student()->getJson('/api/me/wallet')->assertOk()->assertJsonPath('balance', 550)->assertJsonPath('synced', 1);

        // Never twice.
        $id = CreditPurchase::first()->id;
        $this->student()->postJson("/api/credit-purchases/{$id}/sync")->assertOk()->assertJsonPath('status', 'credited');
        $this->student()->postJson('/api/credit-purchases', ['charge_id' => 10, 'student_id' => 1, 'pack_code' => 'creditos_550'])->assertOk();
        $this->assertSame(550, $this->balance());
        $this->assertSame(1, CreditTransaction::where('type', 'purchase')->count());
    }

    public function test_the_plan_bonus_is_added(): void
    {
        $this->onPlan('gold');                   // +15%
        $this->fakeCharge(11, ['amount' => '1000.00', 'document_type_code' => 'creditos_1200', 'status' => 'approved', 'paid' => true]);

        $this->student()->postJson('/api/credit-purchases', ['charge_id' => 11, 'student_id' => 1, 'pack_code' => 'creditos_1200'])
            ->assertCreated()
            ->assertJsonPath('status', 'credited')
            ->assertJsonPath('bonus_credits', 180)
            ->assertJsonPath('total_credits', 1380);
        $this->assertSame(1380, $this->balance());
        $this->assertSame(180, CreditTransaction::where('type', 'bonus')->value('amount'));
    }

    public function test_wrong_owner_pack_or_amount_is_not_credited(): void
    {
        $this->fakeCharge(12, ['student_id' => 2]);
        $this->student()->postJson('/api/credit-purchases', ['charge_id' => 12, 'student_id' => 1, 'pack_code' => 'creditos_550'])->assertForbidden();

        $this->fakeCharge(13, ['document_type_code' => 'kardex', 'category' => 'paperwork']);
        $this->student()->postJson('/api/credit-purchases', ['charge_id' => 13, 'student_id' => 1, 'pack_code' => 'creditos_550'])->assertUnprocessable();

        // Paid, but the amount does not match the price of the pack: review, nothing credited.
        $this->fakeCharge(14, ['amount' => '450.00', 'status' => 'approved', 'paid' => true]);
        $id = $this->student()->postJson('/api/credit-purchases', ['charge_id' => 14, 'student_id' => 1, 'pack_code' => 'creditos_550'])
            ->assertJsonPath('status', 'review')->json('id');
        $this->assertSame(0, $this->balance());

        // Control Escolar decides.
        $this->student()->postJson("/api/credit-purchases/{$id}/resolve", ['action' => 'credit', 'reason' => 'Pago revisado'])->assertForbidden();
        $this->asGateway(1, 'admin')->postJson("/api/credit-purchases/{$id}/resolve", ['action' => 'credit', 'reason' => 'Pago revisado en ventanilla'])
            ->assertOk()->assertJsonPath('status', 'credited');
        $this->assertSame(550, $this->balance());
    }

    public function test_expired_reference_is_rejected(): void
    {
        $this->fakeCharge(15, ['is_expired' => true]);
        $this->student()->postJson('/api/credit-purchases', ['charge_id' => 15, 'student_id' => 1, 'pack_code' => 'creditos_550'])
            ->assertJsonPath('status', 'rejected');
    }

    public function test_admin_adjustments_are_in_the_ledger_and_never_leave_a_negative_balance(): void
    {
        $this->asGateway(1, 'admin')->postJson('/api/wallets/30/adjust', ['amount' => 100, 'reason' => 'Beca de créditos'])->assertCreated()->assertJsonPath('balance', 100);
        $this->asGateway(1, 'admin')->postJson('/api/wallets/30/adjust', ['amount' => -150, 'reason' => 'Corrección'])->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_CREDITS');
        $this->asGateway(1, 'admin')->postJson('/api/wallets/30/adjust', ['amount' => -40, 'reason' => 'Corrección'])->assertCreated()->assertJsonPath('balance', 60);

        $lines = $this->asGateway(1, 'admin')->getJson('/api/credit-transactions?user_id=30')->assertOk()->json('data');
        $this->assertSame([-40, 100], array_column($lines, 'amount'));
        $this->assertSame('1', $lines[0]['actor_id']);

        $this->asGateway(1, 'admin')->getJson('/api/summary')->assertOk()->assertJsonPath('credits_in_wallets', 60);
    }

    public function test_packs_show_the_bonus_of_the_plan(): void
    {
        $this->onPlan('silver');
        $packs = $this->student()->getJson('/api/credit-packs')->assertOk()->assertJsonPath('bonus_pct', 5)->json('data');
        $this->assertSame(['creditos_100', 'creditos_550', 'creditos_1200'], array_column($packs, 'code'));
        $this->assertSame(577, $packs[1]['total_credits']);
    }
}
