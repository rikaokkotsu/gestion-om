<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ReferentialController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
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

    Route::get('/imports/{type}/template', [ImportController::class, 'template']);
    Route::post('/imports/{type}', [ImportController::class, 'store']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
