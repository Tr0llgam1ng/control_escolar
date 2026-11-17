<?php

namespace Tests\Feature;

/** Credits module 1: tutoring · Credits module 2: academic analysis. */
class CreditModulesTest extends MembershipsTestCase
{
    private function tutoring(array $override = []): array
    {
        return array_merge([
            'teacher_id' => 5, 'teacher_name' => 'Profesor Demo', 'student_name' => 'Alumno Demo',
            'subject' => 'Bases de datos', 'topic' => 'Normalización hasta 3FN', 'scheduled_at' => '2026-11-18 10:00',
        ], $override);
    }

    public function test_basic_has_no_tutoring(): void
    {
        $this->giveCredits(100);
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring())->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');
        $this->assertSame(100, $this->balance());
    }

    public function test_silver_pays_with_discount_and_teacher_rejection_refunds(): void
    {
        $this->onPlan('silver');                 // 20% off, 4 per cycle
        $this->giveCredits(100);
        $this->student()->getJson('/api/tutoring/quote')->assertJsonPath('cost', 48);

        $id = $this->student()->postJson('/api/tutoring-sessions', $this->tutoring())
            ->assertCreated()->assertJsonPath('credits_cost', 48)->assertJsonPath('status', 'requested')->json('id');
        $this->assertSame(52, $this->balance());

        // Same teacher, overlapping time.
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring(['scheduled_at' => '2026-11-18 10:30']))->assertStatus(409);
        // Less than 12 h ahead / outside hours.
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring(['scheduled_at' => '2026-11-16 18:00']))->assertUnprocessable();
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring(['scheduled_at' => '2026-11-18 20:00']))->assertUnprocessable();

        // Only the assigned teacher (user 20 → teacher 5) sees it and decides.
        $this->asGateway(21, 'teacher')->patchJson("/api/tutoring-sessions/{$id}", ['status' => 'confirmed'])->assertForbidden();
        $this->assertCount(1, $this->asGateway(20, 'teacher')->getJson('/api/tutoring-sessions')->assertOk()->json('data'));
        $this->asGateway(20, 'teacher')->patchJson("/api/tutoring-sessions/{$id}", ['status' => 'completed'])->assertStatus(409);
        $this->asGateway(20, 'teacher')->patchJson("/api/tutoring-sessions/{$id}", ['status' => 'rejected', 'notes' => 'Tengo junta'])
            ->assertOk()->assertJsonPath('refunded', true);

        $this->assertSame(100, $this->balance());
        $this->student()->getJson('/api/tutoring/quote')->assertJsonPath('sessions.remaining', 4);
    }

    public function test_late_cancellation_has_no_refund(): void
    {
        $this->onPlan('bronze');                 // no discount: 60 credits
        $this->giveCredits(60);
        $id = $this->student()->postJson('/api/tutoring-sessions', $this->tutoring())->assertCreated()->json('id');

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(2, 0)); // 8 h before
        $this->student()->postJson("/api/tutoring-sessions/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('refunded', false);
        $this->assertSame(0, $this->balance());
    }

    public function test_bronze_tutoring_quota(): void
    {
        $this->onPlan('bronze');
        $this->giveCredits(300);
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring(['scheduled_at' => '2026-11-18 09:00']))->assertCreated();
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring(['scheduled_at' => '2026-11-18 11:00']))->assertCreated();
        $this->student()->postJson('/api/tutoring-sessions', $this->tutoring(['scheduled_at' => '2026-11-18 13:00']))
            ->assertForbidden()->assertJsonPath('code', 'PLAN_LIMIT');
        $this->assertSame(180, $this->balance());
    }

    public function test_academic_analysis(): void
    {
        $items = [
            ['subject' => 'Bases de datos', 'units_count' => 4, 'status' => 'in_progress', 'final_grade' => null,
                'grades' => [['unit' => 1, 'score' => 9], ['unit' => 2, 'score' => 8]]],
            ['subject' => 'Redes', 'units_count' => 4, 'status' => 'in_progress', 'final_grade' => null,
                'grades' => [['unit' => 1, 'score' => 4], ['unit' => 2, 'score' => 3], ['unit' => 3, 'score' => 5]]],
            ['subject' => 'Cálculo', 'units_count' => 3, 'status' => 'approved', 'final_grade' => 8.5, 'grades' => []],
        ];

        $this->student()->postJson('/api/analytics-reports', ['items' => $items])->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');

        $this->onPlan('bronze');                 // 1 per cycle, 30 credits
        $this->student()->postJson('/api/analytics-reports', ['items' => $items])->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_CREDITS');

        $this->giveCredits(30);
        $result = $this->student()->postJson('/api/analytics-reports', ['items' => $items])->assertCreated()->json('result');
        $this->assertSame(8.5, $result['general_average']);
        $this->assertSame('Bases de datos', $result['best']);
        $bd = collect($result['subjects'])->firstWhere('subject', 'Bases de datos');
        $this->assertSame(5.5, $bd['needed_average']);   // (7*4 - 17) / 2
        $redes = collect($result['subjects'])->firstWhere('subject', 'Redes');
        $this->assertSame('alto', $redes['risk']);       // needs 16 in the last unit
        $this->assertFalse($redes['reachable']);
        $this->assertSame(0, $this->balance());

        $this->giveCredits(30);
        $this->student()->postJson('/api/analytics-reports', ['items' => $items])->assertForbidden()->assertJsonPath('code', 'PLAN_LIMIT');
    }
}
