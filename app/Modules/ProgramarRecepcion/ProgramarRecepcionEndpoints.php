<?php

use App\Modules\ProgramarRecepcion\Controllers\ProgramarRecepcionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('programar-recepcion')->controller(ProgramarRecepcionController::class)->group(function () {
        Route::get('/', 'get_programaciones');
        Route::get('/{id}', 'get_programacion');
        Route::post('/', 'crear_programacion');
        Route::put('/{id}', 'actualizar_programacion');
        Route::post('/{id}/confirmar', 'confirmar_programacion');
    });
});
