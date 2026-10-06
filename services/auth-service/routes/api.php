<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| Auth Service — identities and access tokens.
| Only the API Gateway can call it (X-Service-Key).
*/
Route::middleware('service.key')->group(function () {
    Route::get('/', fn () => ['service' => 'auth-service', 'version' => '1.0']);

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/validate', [AuthController::class, 'validateToken']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('users', UserController::class);
});
