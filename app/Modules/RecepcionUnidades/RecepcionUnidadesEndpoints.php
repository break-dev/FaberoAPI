<?php

use App\Modules\RecepcionUnidades\Controllers\RecepcionUnidadesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('recepcion-unidades')->controller(RecepcionUnidadesController::class)->group(function () {
        Route::get('/', 'get_recepciones');
        Route::get('/{id}', 'get_recepcion');
        Route::post('/', 'crear_recepcion');
        Route::put('/{id}/salida', 'registrar_salida');

        // Lotes de la recepción de unidad
        Route::get('/{id}/lotes', 'get_lotes');
        Route::post('/{id}/lotes', 'crear_lote');
        Route::delete('/lotes/{lote}', 'eliminar_lote');
    });
});
