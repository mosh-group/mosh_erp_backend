<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InstagramController;
Route::prefix('social')->group(function () {
    Route::get('instagram', [InstagramController::class, 'index']);
    Route::match(['GET','PUT'], 'instagram/configuration', [InstagramController::class, 'configuration']);
    Route::post('instagram/connect', [InstagramController::class, 'start'])->middleware('throttle:20,1');
    Route::get('instagram/callback', [InstagramController::class, 'callback']);
    Route::post('accounts/{id}/disconnect', [InstagramController::class, 'disconnect'])->where('id','[A-Za-z0-9]+');
    Route::get('accounts/{id}/insights', [InstagramController::class, 'insights'])->where('id','[A-Za-z0-9]+')->middleware('throttle:30,1');
    Route::post('posts', [InstagramController::class, 'savePost']);
    Route::patch('posts/{id}', [InstagramController::class, 'savePost'])->where('id','[A-Za-z0-9]+');
    Route::post('posts/{id}/decision', [InstagramController::class, 'decide'])->where('id','[A-Za-z0-9]+');
    Route::post('posts/{id}/check', [InstagramController::class, 'check'])->where('id','[A-Za-z0-9]+')->middleware('throttle:60,1');
});
