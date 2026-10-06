<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Period;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): Course
    {
        $period = Period::create(['name' => 'Sep-Dic 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15', 'is_current' => true]);
        $subject = Subject::create(['code' => 'ISW-901', 'name' => 'Arquitectura SOA', 'credits' => 6]);
        $teacher = Teacher::create(['user_id' => 2, 'employee_number' => 'E1', 'name' => 'Profe', 'email' => 'p@e.test']);

        return Course::create([
            'subject_id' => $subject->id, 'period_id' => $period->id, 'teacher_id' => $teacher->id,
            'group_name' => '9B', 'capacity' => 30, 'units_count' => 3,
        ]);
    }

    public function test_service_key_is_required(): void
    {
        $this->getJson('/api/subjects')->assertUnauthorized();
    }

    public function test_admin_crud_for_subjects(): void
    {
        $id = $this->asGateway()->postJson('/api/subjects', ['code' => 'ISW-1', 'name' => 'Redes', 'credits' => 5])
            ->assertCreated()->json('id');

        $this->asGateway()->putJson("/api/subjects/{$id}", ['code' => 'ISW-1', 'name' => 'Redes II', 'credits' => 5])
            ->assertJsonPath('name', 'Redes II');
        $this->asGateway()->getJson('/api/subjects')->assertJsonCount(1, 'data');
        $this->asGateway()->deleteJson("/api/subjects/{$id}")->assertNoContent();
    }

    public function test_non_admin_cannot_write(): void
    {
        $this->asGateway(3, 'student')->postJson('/api/subjects', ['code' => 'X', 'name' => 'X', 'credits' => 1])->assertForbidden();
        $this->asGateway(2, 'teacher')->postJson('/api/periods', [])->assertForbidden();
    }

    public function test_course_is_unique_per_subject_period_and_group(): void
    {
        $course = $this->seedCatalog();

        $this->asGateway()->postJson('/api/courses', [
            'subject_id' => $course->subject_id, 'period_id' => $course->period_id, 'group_name' => '9B',
            'capacity' => 20, 'units_count' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors('group_name');
    }

    public function test_only_assigned_teacher_changes_course_settings(): void
    {
        $course = $this->seedCatalog();

        $this->asGateway(99, 'teacher')->patchJson("/api/courses/{$course->id}/settings", ['status' => 'inactive'])->assertForbidden();
        $this->asGateway(2, 'teacher')->patchJson("/api/courses/{$course->id}/settings", ['status' => 'inactive', 'units_count' => 4])
            ->assertOk()->assertJsonPath('status', 'inactive')->assertJsonPath('units_count', 4);
    }

    public function test_lookups_used_by_other_services(): void
    {
        $course = $this->seedCatalog();
        Student::create(['user_id' => 3, 'enrollment_number' => '1', 'name' => 'Ana', 'email' => 'a@e.test', 'program' => 'ISW', 'semester' => 9]);

        $this->asGateway()->getJson('/api/teachers/by-user/2')->assertJsonPath('employee_number', 'E1');
        $this->asGateway()->getJson('/api/students/by-user/3')->assertJsonPath('name', 'Ana');
        $this->asGateway()->getJson('/api/students/by-user/404')->assertNotFound();
        $this->asGateway()->getJson("/api/courses/{$course->id}")
            ->assertJsonPath('subject.code', 'ISW-901')
            ->assertJsonPath('period.grade_capture_open', true);
        $this->asGateway()->getJson('/api/periods/current')->assertJsonPath('name', 'Sep-Dic 2026');
    }

    public function test_student_cannot_read_another_student(): void
    {
        $other = Student::create(['user_id' => 50, 'enrollment_number' => '2', 'name' => 'B', 'email' => 'b@e.test', 'program' => 'ISW', 'semester' => 1]);

        $this->asGateway(3, 'student')->getJson("/api/students/{$other->id}")->assertForbidden();
    }
}
