<?php

use App\Http\Controllers\Api\MobileApiController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Mobile app API (Sanctum token auth). Login is public; everything
// else requires a Bearer token. Super Admin gets no token (see the
// controller) — platform accounts do not use the mobile app.
Route::post('/login', [MobileApiController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [MobileApiController::class, 'logout']);
    Route::get('/me', [MobileApiController::class, 'me']);
    Route::get('/timetable/mine', [MobileApiController::class, 'timetableMine']);
    Route::get('/marks/mine', [MobileApiController::class, 'marksMine']);
    Route::get('/attendance/mine', [MobileApiController::class, 'attendanceMine']);
    Route::get('/notifications', [MobileApiController::class, 'notificationsMine']);
    Route::get('/messages', [MobileApiController::class, 'messagesMine']);
});

// Platform health check: lives in the api group on purpose — no session,
// no cookie encryption, so it answers even before app variables are set.
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'app' => config('app.name')]);
});
