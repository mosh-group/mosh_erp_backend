<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{R31UploadController,R31ItController};
Route::get('files-upload/limits',[R31UploadController::class,'limits']);
Route::post('files-upload/start',[R31UploadController::class,'begin']);
Route::put('files-upload/{id}/parts/{part}',[R31UploadController::class,'part'])->where('id','[a-f0-9]{64}')->where('part','[0-9]{1,12}');
Route::post('files-upload/{id}/complete',[R31UploadController::class,'complete'])->where('id','[a-f0-9]{64}');
Route::delete('files-upload/{id}',[R31UploadController::class,'cancel'])->where('id','[a-f0-9]{64}');
Route::prefix('it')->group(function(){
    Route::get('overview',[R31ItController::class,'overview']);
    Route::get('records/{kind}',[R31ItController::class,'index'])->where('kind','assets|accounts|subscriptions');
    Route::post('records/{kind}',[R31ItController::class,'save'])->where('kind','assets|accounts|subscriptions');
    Route::post('records/{kind}/{id}/event',[R31ItController::class,'event'])->where('kind','assets|accounts|subscriptions')->where('id','[A-Za-z0-9-]{8,60}');
    Route::post('accounts/{id}/secret',[R31ItController::class,'secret'])->where('id','[A-Za-z0-9-]{8,60}')->middleware('throttle:10,1');
});
