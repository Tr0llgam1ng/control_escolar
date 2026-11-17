<?php

namespace Tests\Feature;

use App\Models\Plan;

/** Plans (Basic, Bronze, Silver, Gold) and the authorization filter by plan. */
class PlansAndAccessTest extends MembershipsTestCase
{
    public function test_four_plans_with_the_feature_matrix(): void
    {
        $plans = collect($this->student()->getJson('/api/plans')->assertOk()->json('data'));
        $this->assertSame(['basic', 'bronze', 'silver', 'gold'], $plans->pluck('code')->all());

        $gold = collect($plans->firstWhere('code', 'gold')['features'])->keyBy('key');
        $this->assertTrue($gold['paperwork.requests']['unlimited']);
        $this->assertSame('Ilimitado', $gold['invoicing.invoices']['display']);

        $basic = collect($plans->firstWhere('code', 'basic')['features'])->keyBy('key');
        $this->assertFalse($basic['invoicing.invoices']['available']);
        $this->assertSame('2 por ciclo', $basic['paperwork.requests']['display']);
    }

    public function test_a_new_student_is_on_basic_with_monthly_cycle(): void
    {
        $response = $this->student()->getJson('/api/me/membership')
            ->assertOk()
            ->assertJsonPath('plan.code', 'basic')
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('cycle.source', 'month')
            ->assertJsonPath('balance', 0);
        $this->assertSame(2, $response->json('features')['paperwork.requests']['remaining']);

        // Memberships are only for students.
        $this->asGateway(1, 'admin')->getJson('/api/me/membership')->assertForbidden();
    }

    public function test_quota_is_consumed_and_the_limit_answers_plan_limit(): void
    {
        $this->student()->postJson('/api/access/consume', ['feature' => 'paperwork.requests'])->assertOk()->assertJsonPath('remaining', 1);
        $this->student()->postJson('/api/access/consume', ['feature' => 'paperwork.requests'])->assertOk()->assertJsonPath('remaining', 0);

        $this->student()->postJson('/api/access/consume', ['feature' => 'paperwork.requests'])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_LIMIT')
            ->assertJsonPath('upgrade_to.0.code', 'bronze');

        // Compensation (the routed call failed): the use comes back.
        $this->student()->postJson('/api/access/release', ['feature' => 'paperwork.requests'])->assertOk()->assertJsonPath('released', 1);
        $this->student()->getJson('/api/access/check?feature=paperwork.requests')->assertJsonPath('allowed', true)->assertJsonPath('feature.remaining', 1);
    }

    public function test_feature_not_in_the_plan_answers_plan_required(): void
    {
        $this->student()->postJson('/api/access/consume', ['feature' => 'invoicing.invoices'])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_REQUIRED')
            ->assertJsonPath('upgrade_to.0.code', 'silver');

        $this->student()->getJson('/api/access/check?feature=weather.forecast')->assertJsonPath('allowed', false);
        $this->onPlan('silver');
        $this->student()->getJson('/api/access/check?feature=weather.forecast')->assertJsonPath('allowed', true);
        $this->student()->postJson('/api/access/consume', ['feature' => 'invoicing.invoices'])->assertOk()->assertJsonPath('remaining', 2);
    }

    public function test_other_roles_are_not_limited_and_gold_is_unlimited(): void
    {
        $this->asGateway(20, 'teacher')->postJson('/api/access/consume', ['feature' => 'cafeteria.orders'])->assertOk()->assertJsonPath('applies', false);

        $this->onPlan('gold');
        foreach (range(1, 12) as $_) {
            $this->student()->postJson('/api/access/consume', ['feature' => 'paperwork.requests'])->assertOk()->assertJsonPath('unlimited', true);
        }
    }

    public function test_basic_usage_resets_next_month(): void
    {
        $this->student()->postJson('/api/access/consume', ['feature' => 'cafeteria.orders', 'amount' => 3])->assertOk();
        $this->student()->postJson('/api/access/consume', ['feature' => 'cafeteria.orders'])->assertForbidden();

        $this->travelTo(now()->addMonth()->startOfMonth()->addHour());
        $this->student()->postJson('/api/access/consume', ['feature' => 'cafeteria.orders'])->assertOk()->assertJsonPath('remaining', 2);
    }

    public function test_admin_edits_a_plan_and_basic_stays_free(): void
    {
        $silver = Plan::where('code', 'silver')->first();
        $this->asGateway(1, 'admin')->putJson("/api/plans/{$silver->id}", [
            'price_credits' => 300,
            'features' => ['paperwork.requests' => ['enabled' => true, 'value' => null], 'credits.bonus_pct' => ['enabled' => true, 'value' => 10]],
        ])->assertOk()->assertJsonPath('price_credits', 300);
        $this->assertNull($silver->fresh()->feature('paperwork.requests')->value);

        $basic = Plan::where('code', 'basic')->first();
        $this->asGateway(1, 'admin')->putJson("/api/plans/{$basic->id}", ['price_credits' => 10])->assertUnprocessable();
        $this->asGateway(1, 'admin')->putJson("/api/plans/{$silver->id}", ['features' => ['credits.bonus_pct' => ['enabled' => true, 'value' => 150]]])->assertUnprocessable();
        $this->student()->putJson("/api/plans/{$silver->id}", ['price_credits' => 1])->assertForbidden();
    }
}
