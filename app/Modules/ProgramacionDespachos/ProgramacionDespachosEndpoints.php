<?php

use App\Modules\ProgramacionDespachos\Controllers\ProgramacionDespachosController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('programacion-despachos')->controller(ProgramacionDespachosController::class)->group(function () {
        Route::get('/items-disponibles', 'get_items_disponibles');

        Route::get('/', 'get_despachos');
        Route::post('/', 'crear_despacho');
        Route::get('/{id}', 'get_despacho');
        Route::patch('/{id}/anular', 'anular_despacho');

        Route::post('/{id}/distribuciones', 'crear_distribucion');
        Route::patch('/distribuciones/{id}/confirmar', 'confirmar_distribucion');
        Route::patch('/distribuciones/{id}/salida', 'registrar_salida');
        Route::patch('/distribuciones/{id}/llegada', 'registrar_llegada');
        Route::post('/distribuciones/{id}/detalles/{idDetalle}/pesar', 'pesar_distribucion_detalle');
    });
});