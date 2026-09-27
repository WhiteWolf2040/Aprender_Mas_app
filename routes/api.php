<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ParentDashboardController;
use App\Http\Controllers\Api\ParentStudentController;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);
Route::get('/rankings', [RankingController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/subjects', [ContentController::class, 'subjects']);
    Route::post('/activities', [ContentController::class, 'storeActivity']);
    Route::put('/activities/{activity}', [ContentController::class, 'updateActivity']);
    Route::delete('/activities/{activity}', [ContentController::class, 'destroyActivity']);
    Route::post('/payments/checkout', [PaymentController::class, 'createCheckoutSession']);
    Route::post('/payments/confirm', [PaymentController::class, 'confirmCheckoutSession']);
    Route::post('/parent/profiles', [ParentStudentController::class, 'createProfile']);
    Route::post('/parent/activities/batch', [ContentController::class, 'storeParentActivityBatch']);
    Route::post('/subjects', [ContentController::class, 'storeSubject']);
    Route::put('/subjects/{subject}', [ContentController::class, 'updateSubject']);
    Route::delete('/subjects/{subject}', [ContentController::class, 'destroySubject']);
    Route::get('/activities', [ContentController::class, 'activities'])
        ->middleware('child.profile');
    Route::get('/parent/dashboard', [ParentDashboardController::class, 'index'])
        ->middleware('parent.dashboard');
    Route::middleware('child.profile')->group(function () {
        Route::get('/profile', [ProgressController::class, 'profile']);
        Route::post('/profile/purchase', [ProgressController::class, 'purchase']);
        Route::post('/progress/activity', [ProgressController::class, 'activity']);
        Route::post('/progress/missions/{mission}/complete', [ProgressController::class, 'mission']);
        Route::post('/profile/customize', [ProgressController::class, 'customize']);
        Route::get('/progress', [RankingController::class, 'progress']);
    });
    Route::middleware('role:maestro')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
    });
});
