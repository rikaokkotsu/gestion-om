<?php

use App\Http\Controllers\Api\AnomalyController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ControlController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MissionOrderController;
use App\Http\Controllers\Api\PerDiemController;
use App\Http\Controllers\Api\RateVersionController;
use App\Http\Controllers\Api\ReferentialController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me/password', [MeController::class, 'updatePassword']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);

    Route::get('/referentials', [ReferentialController::class, 'all']);
    Route::get('/referentials/{referential}', [ReferentialController::class, 'index']);
    Route::post('/referentials/{referential}', [ReferentialController::class, 'store']);
    Route::patch('/referentials/{referential}/{id}', [ReferentialController::class, 'update'])->whereNumber('id');

    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);

    Route::get('/employees/{employee}/mission-orders', [MissionOrderController::class, 'forEmployee']);

    Route::get('/mission-orders', [MissionOrderController::class, 'index']);
    Route::post('/mission-orders', [MissionOrderController::class, 'store']);
    Route::get('/mission-orders/{missionOrder}', [MissionOrderController::class, 'show']);
    Route::patch('/mission-orders/{missionOrder}', [MissionOrderController::class, 'update']);
    Route::delete('/mission-orders/{missionOrder}', [MissionOrderController::class, 'destroy']);
    Route::post('/mission-orders/{missionOrder}/control', [ControlController::class, 'control']);
    Route::get('/mission-orders/{missionOrder}/anomalies', [AnomalyController::class, 'forOrder']);

    Route::get('/anomalies', [AnomalyController::class, 'index']);
    Route::post('/anomalies', [AnomalyController::class, 'store']);
    Route::get('/anomalies/{anomaly}', [AnomalyController::class, 'show']);
    Route::patch('/anomalies/{anomaly}', [AnomalyController::class, 'update']);
    Route::post('/anomalies/{anomaly}/transition', [AnomalyController::class, 'transition']);
    Route::post('/anomalies/{anomaly}/comments', [AnomalyController::class, 'comment']);

    Route::get('/control-rules', [ControlController::class, 'rules']);
    Route::patch('/control-rules/{code}', [ControlController::class, 'updateRule']);
    Route::get('/control-runs', [ControlController::class, 'runs']);
    Route::post('/control-runs', [ControlController::class, 'storeRun']);
    Route::get('/control-runs/{controlRun}', [ControlController::class, 'showRun']);

    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::middleware('throttle:reports')->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/mission-orders/{missionOrder}', [ReportController::class, 'missionOrder']);
        Route::get('/exports/mission-orders', [ReportController::class, 'exportMissionOrders']);
        Route::get('/exports/anomalies', [ReportController::class, 'exportAnomalies']);
    });

    Route::get('/imports/{type}/template', [ImportController::class, 'template']);
    Route::post('/imports/{type}', [ImportController::class, 'store']);

    Route::get('/rate-versions', [RateVersionController::class, 'index']);
    Route::post('/rate-versions', [RateVersionController::class, 'store']);
    Route::get('/rate-versions/{rateVersion}', [RateVersionController::class, 'show']);
    Route::patch('/rate-versions/{rateVersion}', [RateVersionController::class, 'update']);
    Route::delete('/rate-versions/{rateVersion}', [RateVersionController::class, 'destroy']);
    Route::post('/per-diem/simulate', [PerDiemController::class, 'simulate']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
