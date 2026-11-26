<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CommunicationController as C;
Route::prefix('communication')->group(function(){
 Route::get('directory',[C::class,'directory']);Route::get('counts',[C::class,'counts']);Route::get('messages',[C::class,'index']);Route::post('messages',[C::class,'send']);
 Route::post('chat-groups',[C::class,'createGroup']);
 Route::post('read',[C::class,'read']);Route::get('messages/{id}',[C::class,'show']);Route::patch('messages/{id}',[C::class,'state']);
 Route::get('folders',[C::class,'folders']);Route::post('folders',[C::class,'saveFolder']);Route::patch('folders/{id}',[C::class,'saveFolder']);Route::delete('folders/{id}',[C::class,'deleteFolder']);
 Route::get('drafts',[C::class,'drafts']);Route::post('drafts',[C::class,'saveDraft']);Route::delete('drafts/{id}',[C::class,'deleteDraft']);
});

Route::get('hr/archived-employees',[\App\Http\Controllers\EmployeeLifecycleController::class,'index']);
Route::post('hr/employee-lifecycle',[\App\Http\Controllers\EmployeeLifecycleController::class,'update']);
