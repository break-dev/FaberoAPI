<?php

use App\Modules\ValidacionDistribucion\Controllers\ValidacionDistribucionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('validacion-distribucion')->controller(ValidacionDistribucionController::class)->group(function () {
        Route::get('/lotes-pendientes', 'get_lotes_pendientes');
        Route::get('/lotes/{idLote}/validar', 'get_validar_lote');
        Route::get('/lotes/{idLote}/particiones', 'get_particiones');
        Route::post('/lotes/{idLote}/particiones', 'crear_particion');
        Route::get('/particiones/{id}', 'get_particion');
        Route::put('/particiones/{id}', 'update_particion');
        Route::post('/particiones/{id}/peso-inicial', 'registrar_peso_inicial');
        Route::post('/particiones/{id}/peso-final', 'registrar_peso_final');
        Route::get('/particiones/{id}/ticket-balanza', 'get_ticket_balanza');
        Route::get('/lotes/{idLote}/ticket-balanza', 'get_ticket_balanza_lote');
        Route::get('/lotes/{idLote}/evaluacion-validacion', 'get_evaluacion_validacion_lote');
        Route::post('/particiones/{id}/validar', 'validar_particion');
        Route::post('/lotes/{idLote}/validar', 'validar_lote');
        Route::post('/lotes/validar', 'validar_lotes');
    });
});
