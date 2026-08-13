<?php

use App\Controllers\IAController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->prefix('ia')->group(function () {
    Route::post('/chat',            [IAController::class, 'chat']);
    Route::post('/chat-structured', [IAController::class, 'chatStructured']);
    Route::post('/analyze-file',    [IAController::class, 'analyzeFile']);
    Route::get('/health',           [IAController::class, 'health']);
});