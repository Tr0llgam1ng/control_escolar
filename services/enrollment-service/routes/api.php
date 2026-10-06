<?php

use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\GradeController;
use Illuminate\Support\Facades\Route;

/*
| Enrollment Service — enrollment ("reservation") and grading logic.
| Talks to the Catalog Service over HTTP; only callable with X-Service-Key.
*/
Route::middleware('service.key')->group(function () {
    Route::get('/', fn () => ['service' => 'enrollment-service', 'version' => '1.0']);

    Route::get('enrollments/counts', [EnrollmentController::class, 'counts']);
    Route::post('enrollments', [EnrollmentController::class, 'store']);
    Route::post('enrollments/bulk', [EnrollmentController::class, 'bulk']);
    Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy']);
    Route::get('students/{studentId}/enrollments', [EnrollmentController::class, 'forStudent'])->whereNumber('studentId');
    Route::delete('students/{studentId}/enrollments', [EnrollmentController::class, 'destroyForStudent'])->whereNumber('studentId');

    Route::get('courses/{courseId}/roster', [GradeController::class, 'roster'])->whereNumber('courseId');
    Route::put('courses/{courseId}/grades', [GradeController::class, 'update'])->whereNumber('courseId');
});
