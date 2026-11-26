<?php
use App\Http\Controllers\R19NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('r19/notifications', [R19NotificationController::class, 'index']);
Route::post('r19/administrative-circulars', [R19NotificationController::class, 'publishCircular']);
Route::post('r19/notifications/{id}/acknowledge', [R19NotificationController::class, 'acknowledge'])
    ->where('id', 'N19-[a-f0-9]{64}');
