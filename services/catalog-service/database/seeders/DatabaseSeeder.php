<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Seeder;

/**
 * Academic records for the base accounts created by auth-service
 * (user 2 = Profesor Demo, user 3 = Alumno Demo). Everything else is captured from the app.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Teacher::updateOrCreate(['user_id' => 2], [
            'employee_number' => 'EMP00001', 'name' => 'Profesor Demo', 'email' => 'profesor@escolar.test',
            'department' => 'Ingeniería en Software',
        ]);

        Student::updateOrCreate(['user_id' => 3], [
            'enrollment_number' => '20260001', 'name' => 'Alumno Demo', 'email' => 'alumno@escolar.test',
            'program' => 'Ingeniería en Software', 'semester' => 9, 'group_name' => '9B', 'status' => 'active',
        ]);
    }
}
