<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\R34WorkflowController as W;
Route::prefix('r34')->group(function(){
    Route::get('hierarchy',[W::class,'hierarchy']);Route::put('hierarchy',[W::class,'saveHierarchy']);
    Route::get('approvals',[W::class,'inbox']);Route::post('approvals/hr',[W::class,'hrDecision']);
    Route::post('damage',[W::class,'damage']);Route::post('damage/{id}/decision',[W::class,'damageDecision']);Route::post('damage/{id}/execute',[W::class,'damageExecute']);
    Route::get('chat',[W::class,'chat']);Route::post('chat/send',[W::class,'send']);Route::post('chat/change',[W::class,'change']);
    Route::get('cancellations',[W::class,'cancellations']);Route::post('cancellations',[W::class,'cancelRequest'])->middleware('throttle:20,60');
    Route::get('cancellations/{id}/code',[W::class,'code'])->middleware('throttle:10,1');
    Route::post('cancellations/{id}/confirm',[W::class,'confirm'])->middleware('throttle:6,1');
    Route::post('cancellations/{id}/reject',[W::class,'reject']);Route::get('cancelled-tasks',[W::class,'cancelled']);
    Route::get('deadline',[W::class,'deadline']);
});
