<?php
// Included by routes/web.php inside its existing api.session group.
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\R19HrController;

Route::get('r19/hr', [R19HrController::class, 'index']);
Route::get('r19/hr/presence', [R19HrController::class, 'presence']);
Route::get('r19/hr/compensation', [R19HrController::class, 'compensation']);
Route::put('r19/hr/compensation', [R19HrController::class, 'saveCompensation']);
Route::get('r19/hr/employees/{id}/compensation', [R19HrController::class, 'compensation']);
Route::put('r19/hr/employees/{id}/compensation', [R19HrController::class, 'saveCompensation']);
Route::post('r19/hr/disciplinary', [R19HrController::class, 'store']);
Route::get('r19/hr/disciplinary/{id}', [R19HrController::class, 'show']);
Route::post('r19/hr/disciplinary/{id}/decision', [R19HrController::class, 'decide']);
Route::post('r19/hr/overtime/{id}/decision', [R19HrController::class, 'overtime']);
Route::put('r19/hr/policy', [R19HrController::class, 'policy']);
