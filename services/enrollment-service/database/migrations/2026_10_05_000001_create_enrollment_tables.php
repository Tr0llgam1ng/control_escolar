<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Enrollment Service owns enrollments and grades.
     * course_id / student_id belong to the Catalog Service: they are plain ids, not foreign keys.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->decimal('final_grade', 4, 2)->nullable();
            $table->string('status', 20)->default('in_progress'); // in_progress, approved, failed
            $table->unsignedBigInteger('enrolled_by')->nullable(); // auth user id
            $table->timestamps();
            $table->unique(['course_id', 'student_id']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('unit');
            $table->decimal('score', 4, 2);
            $table->unsignedBigInteger('captured_by')->nullable(); // auth user id
            $table->timestamps();
            $table->unique(['enrollment_id', 'unit']);
        });

        Schema::create('grade_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('unit');
            $table->decimal('old_score', 4, 2)->nullable();
            $table->decimal('new_score', 4, 2)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_logs');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('enrollments');
    }
};
