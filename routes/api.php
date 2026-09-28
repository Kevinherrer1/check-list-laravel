<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SshRevisionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // servers

    Route::get('/servers', [ServerController::class, 'index']);
    Route::post('/servers', [ServerController::class, 'store']);
    Route::get('/servers/{server}', [ServerController::class, 'show']);
    Route::patch('/servers/{server}', [ServerController::class, 'update']);
    Route::put('/servers/{server}', [ServerController::class, 'update']);
    Route::delete('/servers/{server}', [ServerController::class, 'destroy']);

    // reviews
    Route::get('/reviews/{date}', [ReviewController::class, 'show']);
    Route::get('/reviews/{date}/pdf', [ReviewController::class, 'pdf']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/csv', [ReportController::class, 'csv']);
    Route::get('/reports/mail-options', [ReportController::class, 'mailOptions']);
    Route::post('/reports/send-pdf', [ReportController::class, 'sendPdf']);

    // checks
    Route::get('/checks/{check}', [CheckController::class, 'show']);
    Route::patch('/checks/{check}', [CheckController::class, 'update']);
    Route::post('/checks/{check}/ping', [CheckController::class, 'ping']);
    Route::post('/checks/{check}/ssh', [SshRevisionController::class, 'store']);
    Route::get('/ssh-revisions/{jobId}', [SshRevisionController::class, 'show']);

});
