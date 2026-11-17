<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Subscription;

/** Plans are bought with credits and last N days (time in days). */
class SubscriptionsTest extends MembershipsTestCase
{
    public function test_buying_a_plan_needs_credits(): void
    {
        $this->student()->postJson('/api/me/subscription', ['plan_code' => 'bronze'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INSUFFICIENT_CREDITS')
            ->assertJsonPath('needed', 120);
        $this->assertSame(0, Subscription::count());

        $this->giveCredits(200);
        $this->student()->postJson('/api/me/subscription', ['plan_code' => 'bronze'])
            ->assertCreated()
            ->assertJsonPath('charged', 120)
            ->assertJsonPath('membership.plan.code', 'bronze')
            ->assertJsonPath('membership.cycle.source', 'subscription')
            ->assertJsonPath('membership.subscription_remaining.days', 30)
            ->assertJsonPath('membership.balance', 80);

        $line = CreditTransaction::where('type', 'spend')->first();
        $this->assertSame(-120, $line->amount);
        $this->assertSame(80, $line->balance_after);
        $this->assertSame('subscription', $line->source_type);

        $this->student()->postJson('/api/me/subscription', ['plan_code' => 'bronze'])->assertStatus(409);
        $this->student()->postJson('/api/me/subscription', ['plan_code' => 'basic'])->assertUnprocessable();
    }

    public function test_upgrading_refunds_the_unused_days(): void
    {
        $this->onPlan('bronze');                 // 120 credits, 30 days
        $this->travel(15)->days();               // half used
        $this->giveCredits(250);

        $this->student()->postJson('/api/me/subscription', ['plan_code' => 'silver'])
            ->assertCreated()
            ->assertJsonPath('refunded', 60)
            ->assertJsonPath('membership.plan.code', 'silver')
            ->assertJsonPath('membership.balance', 60);

        $this->assertSame(Subscription::REPLACED, Subscription::orderBy('id')->first()->status);
        $this->assertSame(1, CreditTransaction::where('type', 'refund')->count());
    }

    public function test_plan_renews_with_credits_or_falls_back_to_basic(): void
    {
        $this->onPlan('bronze');
        $this->giveCredits(120);                 // enough for one renewal

        $this->travel(31)->days();
        $this->student()->getJson('/api/me/membership')->assertJsonPath('plan.code', 'bronze')->assertJsonPath('balance', 0);
        $this->assertSame(1, Subscription::where('status', 'expired')->count());

        $this->travel(31)->days();               // no credits left
        $this->student()->getJson('/api/me/membership')->assertJsonPath('plan.code', 'basic')->assertJsonPath('subscription', null);
    }

    public function test_cancel_keeps_the_plan_until_it_ends(): void
    {
        $this->onPlan('silver');
        $this->giveCredits(1000);

        $this->student()->postJson('/api/me/subscription/cancel')->assertOk()->assertJsonPath('subscription.auto_renew', false);
        $this->student()->getJson('/api/me/membership')->assertJsonPath('plan.code', 'silver');

        $this->travel(31)->days();
        $this->student()->getJson('/api/me/membership')->assertJsonPath('plan.code', 'basic')->assertJsonPath('balance', 1000);
    }
}
