<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FacebookController as F;
Route::get('social/platforms',[F::class,'platforms']);
Route::prefix('social/facebook')->group(function(){
    Route::get('/',[F::class,'index']);Route::match(['GET','PUT'],'configuration',[F::class,'configuration']);
    Route::post('connect',[F::class,'start'])->middleware('throttle:10,1');Route::get('callback',[F::class,'callback']);
    Route::get('selections',[F::class,'selections']);Route::post('select-page',[F::class,'choose']);
    Route::post('accounts/{id}/disconnect',[F::class,'disconnect'])->where('id','[A-Za-z0-9]+');
    Route::get('accounts/{id}/insights',[F::class,'insights'])->where('id','[A-Za-z0-9]+')->middleware('throttle:15,1');
    Route::post('posts',[F::class,'save']);Route::patch('posts/{id}',[F::class,'save'])->where('id','[A-Za-z0-9]+');
    Route::post('posts/{id}/decision',[F::class,'decision'])->where('id','[A-Za-z0-9]+');Route::post('posts/{id}/check',[F::class,'check'])->where('id','[A-Za-z0-9]+')->middleware('throttle:30,1');
});
