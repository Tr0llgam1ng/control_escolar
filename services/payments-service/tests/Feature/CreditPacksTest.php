<?php

namespace Tests\Feature;

use App\Models\DocumentType;

/** Credit packs (memberships) are sold through the same reference + card/cash flow. */
class CreditPacksTest extends PaymentsTestCase
{
    public function test_paperwork_catalog_does_not_list_credit_packs(): void
    {
        $codes = collect($this->asGateway(30, 'student')->getJson('/api/document-types')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('kardex', $codes);
        $this->assertNotContains('creditos_100', $codes);

        $packs = collect($this->asGateway(30, 'student')->getJson('/api/document-types?category=credits')->assertOk()->json('data'));
        $this->assertSame(['creditos_100', 'creditos_550', 'creditos_1200'], $packs->pluck('code')->all());
        $this->assertTrue($packs->every(fn ($t) => $t['category'] === DocumentType::CREDITS));

        $this->asGateway(30, 'student')->getJson('/api/document-types?category=nope')->assertUnprocessable();
    }

    public function test_a_credit_pack_is_only_bought_with_category_credits(): void
    {
        $pack = $this->type('creditos_550');

        // As if it were paperwork: rejected.
        $this->asGateway(30, 'student')->postJson('/api/charges', ['document_type_id' => $pack->id])->assertUnprocessable();
        // Paperwork through the credits flow: rejected too.
        $this->asGateway(30, 'student')->postJson('/api/charges', ['document_type_id' => $this->type('kardex')->id, 'category' => 'credits'])->assertUnprocessable();

        $this->asGateway(30, 'student')
            ->postJson('/api/charges', ['document_type_id' => $pack->id, 'category' => 'credits'])
            ->assertCreated()
            ->assertJsonPath('amount', '500.00')
            ->assertJsonPath('document_type.category', 'credits');
    }

    public function test_memberships_service_reads_the_status_with_the_pack_code(): void
    {
        $id = $this->asGateway(30, 'student')
            ->postJson('/api/charges', ['document_type_id' => $this->type('creditos_100')->id, 'category' => 'credits'])
            ->json('id');

        $this->asService('memberships-service')->getJson("/api/charges/{$id}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('paid', false)
            ->assertJsonPath('student_id', 1)
            ->assertJsonPath('document_type_code', 'creditos_100')
            ->assertJsonPath('category', 'credits')
            ->assertJsonPath('amount', '100.00');

        $this->payWithCard(\App\Models\Charge::findOrFail($id))->assertJsonPath('result', 'approved');
        $this->asService('memberships-service')->getJson("/api/charges/{$id}/status")->assertJsonPath('paid', true);

        // Unknown callers are still refused.
        $this->asService('someone-else')->getJson("/api/charges/{$id}/status")->assertForbidden();
    }
}
