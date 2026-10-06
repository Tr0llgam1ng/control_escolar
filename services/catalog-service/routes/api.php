<?php

use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

/*
| Catalog Service — academic catalog CRUD (periods, subjects, teachers, students, courses).
| Only callable by the API Gateway / other services (X-Service-Key).
*/
Route::middleware('service.key')->group(function () {
    Route::get('/', fn () => ['service' => 'catalog-service', 'version' => '1.0']);

    Route::get('periods/current', [PeriodController::class, 'current']);
    Route::apiResource('periods', PeriodController::class);
    Route::apiResource('subjects', SubjectController::class);

    Route::get('teachers/by-user/{userId}', [TeacherController::class, 'byUser'])->whereNumber('userId');
    Route::apiResource('teachers', TeacherController::class);

    Route::get('students/by-user/{userId}', [StudentController::class, 'byUser'])->whereNumber('userId');
    Route::apiResource('students', StudentController::class);

    Route::patch('courses/{course}/settings', [CourseController::class, 'settings']);
    Route::apiResource('courses', CourseController::class);
});
