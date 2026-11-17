<?php

use App\Http\Controllers\Api\ChargeController;
use App\Http\Controllers\Api\InvoiceController;
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

    // Invoicing: CFDI 4.0 stamped by the PAC (TimbraCFDI). Details in docs/invoicing-design.md
    Route::get('invoicing/catalogs', [InvoiceController::class, 'catalogs']);
    Route::get('charges/{id}/invoice', [InvoiceController::class, 'forCharge'])->whereNumber('id');
    Route::post('charges/{id}/invoice', [InvoiceController::class, 'store'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
    Route::get('invoices/{id}/xml', [InvoiceController::class, 'xml'])->whereNumber('id');
    Route::post('invoices/{id}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('id');
    Route::get('invoices/{id}/sat-status', [InvoiceController::class, 'satStatus'])->whereNumber('id');
});
