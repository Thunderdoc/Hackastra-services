<?php

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

// Platform health check: lives in the api group on purpose — no session,
// no cookie encryption, so it answers even before app variables are set.
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'app' => config('app.name')]);
});
