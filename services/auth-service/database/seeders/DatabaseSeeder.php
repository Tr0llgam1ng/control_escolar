<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Base accounts. IDs 1-3 are referenced by the catalog-service seeder
 * (teacher user_id = 2, student user_id = 3). Password for all: password
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['id' => 1, 'name' => 'Control Escolar', 'email' => 'admin@escolar.test', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Profesor Demo', 'email' => 'profesor@escolar.test', 'role' => 'teacher'],
            ['id' => 3, 'name' => 'Alumno Demo', 'email' => 'alumno@escolar.test', 'role' => 'student'],
        ] as $user) {
            User::updateOrCreate(['id' => $user['id']], $user + ['password' => 'password', 'is_active' => true]);
        }
    }
}
