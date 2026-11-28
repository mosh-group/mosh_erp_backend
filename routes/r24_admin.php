<?php
// Additive routes, installed inside the existing authenticated api.session group.
use App\Http\Controllers\R24AdminController;
use Illuminate\Support\Facades\Route;
Route::prefix('r24/admin')->group(function(){
    Route::get('overview',[R24AdminController::class,'overview']);
    Route::get('tasks/{id}',[R24AdminController::class,'task']);
    Route::post('tasks/takeover', [R24AdminController::class, 'takeoverTask']);
    Route::get('alerts',[R24AdminController::class,'alerts']);
    Route::post('alerts',[R24AdminController::class,'alertSend']);
    Route::get('alerts/{id}',[R24AdminController::class,'alertRecord']);
    Route::post('alerts/{id}/reply',[R24AdminController::class,'alertReply']);
    Route::post('alerts/{id}/seen',[R24AdminController::class,'alertSeen']);
    Route::get('approvals',[R24AdminController::class,'approvals']);
    Route::post('approvals/disciplinary/{id}',[R24AdminController::class,'approvalDecision']);
    Route::get('purchases',[R24AdminController::class,'purchases']);
    Route::get('signature',[R24AdminController::class,'signature']);
    Route::post('signature',[R24AdminController::class,'signatureSave']);
    Route::post('notices',[R24AdminController::class,'noticeCreate']);
    Route::get('decisions',[R24AdminController::class,'decisions']);
    Route::get('decisions/{id}/recipient',[R24AdminController::class,'decisionRecipient']);
    Route::post('decisions',[R24AdminController::class,'decisionCreate']);
    Route::post('decisions/{id}/edit',[R24AdminController::class,'decisionUpdate']);
    Route::post('decisions/{id}/sign',[R24AdminController::class,'decisionSign']);
    Route::post('decisions/{id}/cancel',[R24AdminController::class,'decisionCancel']);
    Route::get('reports',[R24AdminController::class,'report']);
    Route::get('presets',[R24AdminController::class,'presets']);
    Route::post('presets',[R24AdminController::class,'presetSave']);
    Route::post('presets/{id}/delete',[R24AdminController::class,'presetDelete']);
});

// R24 opt-in purchasing approval routes; does not change existing purchase orders.
Route::prefix('r24/purchasing')->group(function(){
    Route::get('categories',[\App\Http\Controllers\R24PurchasingController::class,'categories']);
    Route::get('approvers',[\App\Http\Controllers\R24PurchasingController::class,'approvers']);
    Route::post('categories',[\App\Http\Controllers\R24PurchasingController::class,'saveCategories']);
    Route::post('requests',[\App\Http\Controllers\R24PurchasingController::class,'create']);
    Route::get('queue',[\App\Http\Controllers\R24PurchasingController::class,'queue']);
    Route::post('requests/{id}/decision',[\App\Http\Controllers\R24PurchasingController::class,'decision']);
    Route::post('requests/{id}/resubmit',[\App\Http\Controllers\R24PurchasingController::class,'resubmit']);
    Route::get('accounting',[\App\Http\Controllers\R24PurchasingController::class,'accounting']);
});

Route::prefix('r24/stationery')->group(function(){
    Route::get('seal',[\App\Http\Controllers\R24StationeryController::class,'info']);
    Route::post('seal/propose',[\App\Http\Controllers\R24StationeryController::class,'propose']);
    Route::post('seal/proposals/{id}/decision',[\App\Http\Controllers\R24StationeryController::class,'decide']);
});
