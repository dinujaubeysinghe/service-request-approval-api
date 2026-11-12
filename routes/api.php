<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ServiceRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/requests', [ServiceRequestController::class, 'index']);
    Route::post('/requests', [ServiceRequestController::class, 'store']);
    Route::get('/requests/{serviceRequest}', [ServiceRequestController::class, 'show']);
    Route::put('/requests/{serviceRequest}', [ServiceRequestController::class, 'update']);
    Route::post('/requests/{serviceRequest}/submit', [ServiceRequestController::class, 'submit']);

    Route::post('/requests/{serviceRequest}/decision', [ServiceRequestController::class, 'decide']);
    Route::post('/requests/{serviceRequest}/assign', [ServiceRequestController::class, 'assign']);
    Route::post('/requests/{serviceRequest}/status', [ServiceRequestController::class, 'changeStatus']);
    Route::post('/requests/{serviceRequest}/comments', [CommentController::class, 'store']);
});
