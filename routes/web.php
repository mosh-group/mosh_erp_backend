<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController,AccountController,BootstrapController,ResourceController,TransactionController,FileController,ReportController,ZktecoAdminController};
Route::prefix('api')->group(function () {
    Route::get('auth/status',[AuthController::class,'status']);
    Route::post('auth/setup',[AuthController::class,'setup'])->middleware('throttle:5,1');
    Route::post('auth/login',[AuthController::class,'login'])->middleware('throttle:auth-login');
    Route::middleware(['api.session','throttle:600,1'])->group(function () {
        require __DIR__.'/ui09.php';
        require __DIR__.'/r19_hr.php';
        require __DIR__.'/r19_notifications.php';
        require __DIR__.'/r24_admin.php';
        require __DIR__.'/r28_instagram.php';
        require __DIR__.'/r29.php';
        require __DIR__.'/r31.php';
        require __DIR__.'/r34.php';
        require __DIR__.'/r34_facebook.php';
        Route::get('ui/branding',[\App\Http\Controllers\R35BrandingController::class,'show']);
        Route::put('ui/branding',[\App\Http\Controllers\R35BrandingController::class,'update'])->middleware('throttle:12,1');
        Route::post('auth/logout',[AuthController::class,'logout']);
        Route::get('auth/me',[AuthController::class,'me']);
        Route::post('auth/welcome',[AuthController::class,'acknowledgeWelcome']);
        Route::patch('auth/password',[AuthController::class,'password']);
        Route::match(['GET','PATCH'],'auth/security',[AuthController::class,'security']);
        Route::match(['GET','DELETE'],'auth/audit',[AuthController::class,'audit']);
        Route::put('users/{id}/permissions',[AccountController::class,'permissions']);
        Route::post('users/{id}/password',[AccountController::class,'password']);
        foreach (['users','roles'] as $kind) {
            Route::get($kind,[AccountController::class,'index'])->defaults('accounts',$kind);
            Route::post($kind,[AccountController::class,'store'])->defaults('accounts',$kind);
            Route::get($kind.'/{id}',[AccountController::class,'show'])->defaults('accounts',$kind);
            Route::patch($kind.'/{id}',[AccountController::class,'update'])->defaults('accounts',$kind);
            Route::delete($kind.'/{id}',[AccountController::class,'destroy'])->defaults('accounts',$kind);
        }
        Route::put('hr/department-managers', [\App\Http\Controllers\DepartmentManagerController::class, 'update']);
        Route::put('hr/department-managers/backup', [\App\Http\Controllers\DepartmentManagerController::class, 'updateBackup']);
        Route::get('hr/account-status/{employeeId}', [\App\Http\Controllers\HrAccountStatusController::class, 'show'])->where('employeeId', '.+');
        Route::put('hr/account-status/{employeeId}', [\App\Http\Controllers\HrAccountStatusController::class, 'update'])->where('employeeId', '.+');
        Route::get('bootstrap',BootstrapController::class);
        Route::get('marketing/task-tracking', \App\Http\Controllers\MarketingTaskTrackingController::class);
        Route::prefix('zkteco')->group(function(){ Route::get('status',[ZktecoAdminController::class,'status']); Route::get('events',[ZktecoAdminController::class,'events']); Route::get('mappings',[ZktecoAdminController::class,'mappings']); Route::post('mappings',[ZktecoAdminController::class,'map']); Route::post('events/{id}/replay',[ZktecoAdminController::class,'replay']); });
        Route::post('transactions',[TransactionController::class,'store']);
        Route::post('transactions/{id}/undo',[TransactionController::class,'undo']);
        Route::post('files',[FileController::class,'store']);
        Route::get('files/{key}/content',[FileController::class,'content'])->where('key','.*');
        Route::get('files/{key}',[FileController::class,'show'])->where('key','.*');
        Route::delete('files/{key}',[FileController::class,'destroy'])->where('key','.*');
        Route::post('quotations/{id}/email',[ReportController::class,'email'])->where('id','.*');
        Route::get('audit-events',[ReportController::class,'audit']);
        Route::get('audit-events/{id}',[ReportController::class,'auditDetail']);
        Route::get('workday',[ReportController::class,'workday']);
        Route::get('dashboard',[ReportController::class,'dashboard']);
        Route::get('departments/{department}/dashboard',[ReportController::class,'dashboard']);
        Route::get('reports/{report}',[ReportController::class,'report']);
        Route::post('task-views/reserve', \App\Http\Controllers\TaskIdReservationController::class);
        Route::get('task-views/summary', [ResourceController::class, 'taskSummary']);
        Route::get('task-views/mine', [ResourceController::class, 'ownTasks']);
        Route::get('task-views/family/{id}', [ResourceController::class, 'taskFamily'])->where('id', '.*');
        foreach (config('mosh.spec.resources') as $resource=>$spec) {
            Route::get($resource,[ResourceController::class,'index'])->defaults('resource',$resource);
            Route::post($resource,[ResourceController::class,'store'])->defaults('resource',$resource);
            Route::get($resource.'/{id}',[ResourceController::class,'show'])->defaults('resource',$resource)->where('id','.*');
            Route::patch($resource.'/{id}',[ResourceController::class,'update'])->defaults('resource',$resource)->where('id','.*');
            if ($spec['delete']) Route::delete($resource.'/{id}',[ResourceController::class,'destroy'])->defaults('resource',$resource)->where('id','.*');
        }
        foreach (config('mosh.spec.settings') as $key=>$spec) Route::match(['GET','PUT'],'settings/'.$key,[ResourceController::class,'setting'])->defaults('setting',$key);
    });
});
