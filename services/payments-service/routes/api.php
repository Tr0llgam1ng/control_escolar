<?php

use App\Http\Controllers\Api\ChargeController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

/*
| Payments Service — document requests, payment references and payments (card / cash).
| Only callable with X-Service-Key (the API Gateway or another trusted service).
*/
Route::middleware('service.key')->group(function () {
    Route::get('/', fn () => ['service' => 'payments-service', 'version' => '2.0']);

    Route::get('document-types', [ChargeController::class, 'documentTypes']);

    Route::get('charges', [ChargeController::class, 'index']);
    Route::post('charges', [ChargeController::class, 'store']);
    Route::get('charges/summary', [ChargeController::class, 'summary']);
    Route::get('charges/by-reference/{reference}', [ChargeController::class, 'byReference']);
    Route::get('charges/{id}', [ChargeController::class, 'show'])->whereNumber('id');
    Route::get('charges/{id}/status', [ChargeController::class, 'status'])->whereNumber('id');
    Route::patch('charges/{id}/status', [ChargeController::class, 'changeStatus'])->whereNumber('id');
    Route::post('charges/{id}/review', [ChargeController::class, 'review'])->whereNumber('id');
    Route::get('students/{studentId}/charges', [ChargeController::class, 'forStudent'])->whereNumber('studentId');

    Route::post('charges/{id}/pay', [PaymentController::class, 'pay'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('charges/{id}/cash', [PaymentController::class, 'receiveCash'])->whereNumber('id');
});
