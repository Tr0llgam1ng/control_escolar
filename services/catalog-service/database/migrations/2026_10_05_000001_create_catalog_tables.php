<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Catalog Service owns the academic catalog: periods, subjects, people records
     * and courses (class sections). user_id points to the Auth Service, it is NOT a
     * foreign key: each service has its own database.
     */
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('grades_open_from')->nullable();
            $table->date('grades_open_until')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('credits')->default(0);
            $table->unsignedTinyInteger('semester')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique(); // Auth Service user
            $table->string('employee_number', 30)->unique();
            $table->string('name');
            $table->string('email');
            $table->string('department')->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique(); // Auth Service user
            $table->string('enrollment_number', 30)->unique();
            $table->string('name');
            $table->string('email');
            $table->string('program');
            $table->unsignedTinyInteger('semester')->default(1);
            $table->string('group_name', 20)->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name', 20);
            $table->string('classroom', 50)->nullable();
            $table->unsignedSmallInteger('capacity')->default(30);
            $table->unsignedTinyInteger('units_count')->default(3);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
            $table->unique(['subject_id', 'period_id', 'group_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
        Schema::dropIfExists('students');
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('periods');
    }
};
