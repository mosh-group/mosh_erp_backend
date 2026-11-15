<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\R29Controller;
Route::prefix('r29')->group(function(){
    Route::get('hr',[R29Controller::class,'index']);
    Route::post('hr/employees',[R29Controller::class,'create']);
    Route::get('hr/employees/{id}',[R29Controller::class,'read'])->where('id','.*');
    Route::post('hr/employees/{id}',[R29Controller::class,'mutate'])->where('id','.*');
    Route::get('certificates/{id}/print',[R29Controller::class,'certificate'])->where('id','[A-Za-z0-9]+');
    Route::get('settlements/{id}/print',[R29Controller::class,'settlement'])->where('id','[A-Za-z0-9]+');
    Route::get('customers',[R29Controller::class,'customers']);
    Route::get('customers/{id}/work',[R29Controller::class,'tasks'])->where('id','.*');
    Route::post('customers/{id}/archive',[R29Controller::class,'archive'])->where('id','.*');
    Route::get('admin/tasks',[R29Controller::class,'adminTasks']);
});
