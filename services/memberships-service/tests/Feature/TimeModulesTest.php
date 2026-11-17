<?php

namespace Tests\Feature;

use App\Models\StudyRoom;

/** Time module 1: study rooms (hours) · Time module 2: digital library (minutes). */
class TimeModulesTest extends MembershipsTestCase
{
    private function room(): int
    {
        return StudyRoom::orderBy('id')->value('id');
    }

    public function test_basic_cannot_book_rooms(): void
    {
        $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 10:00', 'minutes' => 60])
            ->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');
    }

    public function test_bronze_books_with_its_included_hours_and_limits(): void
    {
        $this->onPlan('bronze');   // 2 h per cycle, 1 h per booking, 2 days in advance

        $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 10:00', 'minutes' => 90])
            ->assertForbidden()->assertJsonPath('code', 'PLAN_LIMIT');
        $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-25 10:00', 'minutes' => 60])
            ->assertForbidden()->assertJsonPath('feature', 'study_rooms.advance_days');
        $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 22:00', 'minutes' => 60])->assertUnprocessable();
        $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 10:15', 'minutes' => 60])->assertUnprocessable();

        $id = $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 10:00', 'minutes' => 60])
            ->assertCreated()->assertJsonPath('included_seconds', 3600)->json('id');

        // Same room and time: taken (another student on gold).
        $this->onPlan('gold', 31);
        $this->student(31)->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 10:30', 'minutes' => 60])->assertStatus(409);

        $this->student()->getJson('/api/me/time')->assertJsonPath('modules.study_rooms.available.human', '1 h');

        // Cancel before it starts: the hour comes back.
        $this->student()->postJson("/api/room-bookings/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->student()->getJson('/api/me/time')->assertJsonPath('modules.study_rooms.available.human', '2 h');
    }

    public function test_extra_hours_are_bought_with_credits_and_used_after_the_included_ones(): void
    {
        $this->onPlan('bronze');
        $this->student()->postJson('/api/me/time/purchase', ['module' => 'study_rooms', 'quantity' => 2])->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_CREDITS');

        $this->giveCredits(50);
        $this->student()->postJson('/api/me/time/purchase', ['module' => 'study_rooms', 'quantity' => 2])
            ->assertCreated()->assertJsonPath('credits', 50)->assertJsonPath('status.available.human', '4 h');

        foreach (['2026-11-17 08:00', '2026-11-17 09:00', '2026-11-17 11:00'] as $start) {
            $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => $start, 'minutes' => 60])->assertCreated();
        }
        $last = $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 12:00', 'minutes' => 60])
            ->assertCreated()->json();
        $this->assertSame(0, $last['included_seconds']);
        $this->assertSame(3600, $last['extra_seconds']);

        $this->student()->postJson('/api/room-bookings', ['study_room_id' => $this->room(), 'starts_at' => '2026-11-17 13:00', 'minutes' => 60])
            ->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_TIME');
    }

    public function test_library_session_charges_the_minutes_used(): void
    {
        // Basic: 30 minutes per month.
        $id = $this->student()->postJson('/api/library-sessions', ['resource' => 'ieee'])->assertCreated()->assertJsonPath('limit_seconds', 1800)->json('id');
        $this->student()->postJson('/api/library-sessions', ['resource' => 'springer'])->assertStatus(409);

        $this->travel(9)->minutes();
        $this->travel(20)->seconds();
        $this->student()->postJson("/api/library-sessions/{$id}/close")->assertOk()
            ->assertJsonPath('status', 'closed')->assertJsonPath('used_seconds', 600);   // 9 min 20 s → 10 min

        $this->student()->getJson('/api/me/time')->assertJsonPath('modules.library.available.human', '20 min');
    }

    public function test_library_session_closes_itself_at_the_limit(): void
    {
        $this->student()->postJson('/api/library-sessions', ['resource' => 'oreilly'])->assertCreated();
        $this->travel(2)->hours();

        $this->student()->getJson('/api/library-sessions')->assertOk()
            ->assertJsonPath('open', null)
            ->assertJsonPath('data.0.closed_by', 'limit')
            ->assertJsonPath('data.0.used_seconds', 1800);

        $this->student()->postJson('/api/library-sessions', ['resource' => 'ieee'])->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_TIME');

        $this->giveCredits(10);
        $this->student()->postJson('/api/me/time/purchase', ['module' => 'library', 'quantity' => 1])->assertCreated();
        $this->student()->postJson('/api/library-sessions', ['resource' => 'ieee'])->assertCreated()->assertJsonPath('limit_seconds', 1800);
    }
}
