<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\GradeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $course;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.catalog.url' => 'http://catalog.test']);

        $this->course = [
            'id' => 10, 'teacher_id' => 7, 'capacity' => 2, 'units_count' => 2, 'status' => 'active',
            'period' => ['end_date' => now()->addMonth()->toDateString(), 'grade_capture_open' => true],
        ];
        $this->fakeCatalog();
    }

    private function fakeCatalog(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/courses/10' => Http::response($this->course),
                $path === '/api/students/1' => Http::response(['id' => 1, 'status' => 'active']),
                $path === '/api/students/2' => Http::response(['id' => 2, 'status' => 'active']),
                $path === '/api/students/3' => Http::response(['id' => 3, 'status' => 'active']),
                $path === '/api/students/4' => Http::response(['id' => 4, 'status' => 'inactive']),
                $path === '/api/teachers/by-user/20' => Http::response(['id' => 7]),
                $path === '/api/teachers/by-user/21' => Http::response(['id' => 8]),
                $path === '/api/students/by-user/30' => Http::response(['id' => 1]),
                default => Http::response(['message' => 'Not found'], 404),
            };
        });
    }

    public function test_enrollment_rules(): void
    {
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 1])->assertCreated();
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 1])->assertStatus(409)->assertJsonPath('message', 'El alumno ya está inscrito en esta clase.');
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 4])->assertStatus(409)->assertJsonPath('message', 'El alumno está dado de baja.');
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 99])->assertStatus(422);
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 55, 'student_id' => 1])->assertStatus(422);
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 2])->assertCreated();
        // capacity = 2
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 3])->assertStatus(409)->assertJsonPath('message', 'La clase ya no tiene cupo.');
        $this->asGateway()->getJson('/api/enrollments/counts?course_ids=10')->assertJsonPath('data.10', 2);
    }

    public function test_inactive_course_and_non_admin_are_rejected(): void
    {
        $this->asGateway(20, 'teacher')->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 1])->assertForbidden();

        $this->course['status'] = 'inactive';
        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 1])->assertStatus(409);
    }

    public function test_bulk_enrollment_reports_each_student(): void
    {
        $this->asGateway()->postJson('/api/enrollments/bulk', ['course_id' => 10, 'student_ids' => [1, 4, 2, 3]])
            ->assertOk()
            ->assertJsonPath('enrolled', 2)
            ->assertJsonPath('results.1.message', 'El alumno está dado de baja.')
            ->assertJsonPath('results.3.message', 'La clase ya no tiene cupo.');
    }

    public function test_assigned_teacher_captures_grades_and_final_status_is_computed(): void
    {
        $e = Enrollment::create(['course_id' => 10, 'student_id' => 1]);

        $this->asGateway(21, 'teacher')->getJson('/api/courses/10/roster')->assertForbidden();
        $this->asGateway(20, 'teacher')->getJson('/api/courses/10/roster')->assertOk()->assertJsonPath('units_count', 2);

        $this->asGateway(20, 'teacher')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 1, 'score' => 9],
        ]])->assertOk()->assertJsonPath('changes', 1);
        $this->assertSame('in_progress', $e->fresh()->status);

        $this->asGateway(20, 'teacher')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 2, 'score' => 4],
        ]]);
        $this->assertSame(6.5, $e->fresh()->final_grade);
        $this->assertSame('failed', $e->fresh()->status);

        $this->asGateway(20, 'teacher')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 2, 'score' => 8],
        ]]);
        $this->assertSame('approved', $e->fresh()->status);
        $this->assertSame(3, GradeLog::count());
    }

    public function test_grade_validation_and_capture_window(): void
    {
        $e = Enrollment::create(['course_id' => 10, 'student_id' => 1]);

        $this->asGateway(20, 'teacher')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 1, 'score' => 11],
        ]])->assertStatus(422);
        $this->asGateway(20, 'teacher')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 3, 'score' => 9],
        ]])->assertStatus(422);

        $this->course['period']['grade_capture_open'] = false;
        $this->asGateway(20, 'teacher')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 1, 'score' => 9],
        ]])->assertStatus(423);
        // Control Escolar can authorize a change outside the window (RN-02)
        $this->asGateway(1, 'admin')->putJson('/api/courses/10/grades', ['grades' => [
            ['enrollment_id' => $e->id, 'unit' => 1, 'score' => 9],
        ]])->assertOk();
    }

    public function test_student_only_reads_own_enrollments(): void
    {
        Enrollment::create(['course_id' => 10, 'student_id' => 1]);

        $this->asGateway(30, 'student')->getJson('/api/students/1/enrollments')->assertOk()->assertJsonCount(1, 'data');
        $this->asGateway(30, 'student')->getJson('/api/students/2/enrollments')->assertForbidden();

        $this->asGateway()->deleteJson('/api/students/1/enrollments')->assertJsonPath('deleted', 1);
    }

    public function test_catalog_down_returns_503(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->asGateway()->postJson('/api/enrollments', ['course_id' => 10, 'student_id' => 1])->assertStatus(503);
    }
}
