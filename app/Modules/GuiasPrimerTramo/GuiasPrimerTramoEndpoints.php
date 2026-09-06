<?php

use App\Modules\GuiasPrimerTramo\Controllers\GuiasPrimerTramoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('guias-primer-tramo')->controller(GuiasPrimerTramoController::class)->group(function () {
        Route::get('/', 'get_guias');
        Route::get('/filtros-metadata', 'get_filtros_metadata');
        Route::post('/validar-duplicado', 'validar_duplicado');
        Route::get('/{id}', 'get_guia_by_id');
        Route::post('/', 'crear_guia');
        Route::post('/{id}/update', 'actualizar_guia');
        Route::patch('/{id}/anular', 'anular_guia');
    });
});
