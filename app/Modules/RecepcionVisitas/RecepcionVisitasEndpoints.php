<?php

use App\Modules\RecepcionVisitas\Controllers\RecepcionVisitasController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('recepcion-visitas')->controller(RecepcionVisitasController::class)->group(function () {
        Route::get('/', 'get_recepciones');
        Route::post('/', 'crear_recepcion');
        Route::put('/{id}/salida', 'registrar_salida');
        Route::post('/{id}/salida', 'registrar_salida');
        Route::post('/{id}/salida-general', 'registrar_salida_general');
        Route::put('/{id}/salida-general', 'registrar_salida_general');
        Route::post('/por-programacion', 'crear_para_programacion');
    });
});
