<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\StudyRoomController;
use App\Http\Controllers\Api\TimeController;
use App\Http\Controllers\Api\TutoringController;
use Illuminate\Support\Facades\Route;

/*
| Memberships Service — plans (Basic, Bronze, Silver, Gold), authorization by plan, credits
| (money → credits) and the modules that use credits or time.
| Only callable with X-Service-Key (the API Gateway or another trusted service).
*/
Route::middleware('service.key')->group(function () {
    Route::get('/', fn () => ['service' => 'memberships-service', 'version' => '1.0']);

    // Plans and subscriptions (time in days)
    Route::get('plans', [MembershipController::class, 'plans']);
    Route::put('plans/{id}', [MembershipController::class, 'updatePlan'])->whereNumber('id');
    Route::get('subscriptions', [MembershipController::class, 'subscriptions']);
    Route::get('me/membership', [MembershipController::class, 'me']);
    Route::post('me/subscription', [MembershipController::class, 'subscribe']);
    Route::post('me/subscription/cancel', [MembershipController::class, 'cancel']);
    Route::post('me/subscription/resume', [MembershipController::class, 'resume']);

    // Authorization by plan (the gateway asks before routing to other services)
    Route::get('access/check', [AccessController::class, 'check']);
    Route::post('access/consume', [AccessController::class, 'consume']);
    Route::post('access/release', [AccessController::class, 'release']);

    // Credits: money → credits, ledger
    Route::get('credit-packs', [CreditController::class, 'packs']);
    Route::get('me/wallet', [CreditController::class, 'wallet']);
    Route::post('credit-purchases', [CreditController::class, 'registerPurchase'])->middleware('throttle:20,1');
    Route::get('credit-purchases', [CreditController::class, 'purchases']);
    Route::post('credit-purchases/{id}/sync', [CreditController::class, 'sync'])->whereNumber('id');
    Route::post('credit-purchases/{id}/resolve', [CreditController::class, 'resolve'])->whereNumber('id');
    Route::get('wallets', [CreditController::class, 'wallets']);
    Route::post('wallets/{userId}/adjust', [CreditController::class, 'adjust'])->whereNumber('userId');
    Route::get('credit-transactions', [CreditController::class, 'transactions']);
    Route::get('summary', [CreditController::class, 'summary']);

    // Time balances (hours / minutes) and extra time bought with credits
    Route::get('me/time', [TimeController::class, 'show']);
    Route::post('me/time/purchase', [TimeController::class, 'purchase']);

    // Time module 1: study rooms (hours)
    Route::get('study-rooms', [StudyRoomController::class, 'rooms']);
    Route::get('study-rooms/{id}/bookings', [StudyRoomController::class, 'busy'])->whereNumber('id');
    Route::get('room-bookings', [StudyRoomController::class, 'index']);
    Route::post('room-bookings', [StudyRoomController::class, 'store']);
    Route::post('room-bookings/{id}/cancel', [StudyRoomController::class, 'cancel'])->whereNumber('id');

    // Time module 2: digital library (minutes)
    Route::get('library/resources', [LibraryController::class, 'resources']);
    Route::get('library-sessions', [LibraryController::class, 'index']);
    Route::post('library-sessions', [LibraryController::class, 'store']);
    Route::post('library-sessions/{id}/close', [LibraryController::class, 'close'])->whereNumber('id');

    // Credits module 1: tutoring with a teacher
    Route::get('tutoring/quote', [TutoringController::class, 'quote']);
    Route::get('tutoring-sessions', [TutoringController::class, 'index']);
    Route::post('tutoring-sessions', [TutoringController::class, 'store']);
    Route::post('tutoring-sessions/{id}/cancel', [TutoringController::class, 'cancel'])->whereNumber('id');
    Route::patch('tutoring-sessions/{id}', [TutoringController::class, 'update'])->whereNumber('id');

    // Credits module 2: academic analysis
    Route::get('analytics/quote', [AnalyticsController::class, 'quote']);
    Route::get('analytics-reports', [AnalyticsController::class, 'index']);
    Route::post('analytics-reports', [AnalyticsController::class, 'store']);
    Route::get('analytics-reports/{id}', [AnalyticsController::class, 'show'])->whereNumber('id');
});
