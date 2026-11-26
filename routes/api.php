<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZktecoBridgeController;


Route::get('auth/session', [\App\Http\Controllers\AuthController::class, 'sessionStatus'])
    ->middleware([\Illuminate\Cookie\Middleware\EncryptCookies::class, \App\Http\Middleware\ReadOnlySession::class]);
Route::prefix('integrations/zkteco')
    ->middleware(['zkteco.bridge', 'throttle:1200,1'])
    ->group(function () {
        Route::post('events', [ZktecoBridgeController::class, 'ingest']);
        Route::post('heartbeat', [ZktecoBridgeController::class, 'heartbeat']);
    });

// Meta must be able to fetch a scoped, expiring media URL without a browser cookie.
Route::get('social/media/{id}', [\App\Http\Controllers\InstagramController::class,'media'])
    ->where('id','[A-Za-z0-9]+')->middleware('throttle:120,1');
// These stateless callbacks use Meta's HMAC signed_request; never exempt the private write endpoints from CSRF.
Route::post('social/instagram/deauthorize', [\App\Http\Controllers\InstagramController::class,'revoke'])->middleware('throttle:60,1');
Route::post('social/instagram/data-deletion', [\App\Http\Controllers\InstagramController::class,'erase'])->middleware('throttle:60,1');
Route::get('social/instagram/deletion-status/{id}', [\App\Http\Controllers\InstagramController::class,'deletionStatus'])
    ->where('id','[A-Za-z0-9]+')->middleware('throttle:60,1');

Route::get('hr/certificates/verify/{token}', [\App\Http\Controllers\R29Controller::class,'verify'])->where('token','[a-f0-9]{48}')->middleware('throttle:30,1');

Route::get('social/facebook/media/{id}', [\App\Http\Controllers\FacebookController::class,'media'])->where('id','[A-Za-z0-9]+')->middleware('throttle:120,1');
Route::post('social/facebook/deauthorize', [\App\Http\Controllers\FacebookController::class,'revoke'])->middleware('throttle:60,1');
Route::post('social/facebook/data-deletion', [\App\Http\Controllers\FacebookController::class,'erase'])->middleware('throttle:60,1');
Route::get('social/facebook/deletion-status/{id}', [\App\Http\Controllers\FacebookController::class,'deletionStatus'])->where('id','[a-f0-9]{48}')->middleware('throttle:60,1');
