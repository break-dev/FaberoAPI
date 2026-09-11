<?php

use App\Modules\Empresas\EmpresasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoints para la vista de empresas
|--------------------------------------------------------------------------
*/

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('empresas')->controller(EmpresasController::class)->group(function () {

        // Listar todas las empresas
        Route::get('/', 'get_empresas');

        // Crear una nueva empresa
        Route::post('/', 'crear_empresa');

        // Actualizar datos de empresa (sin logo)
        Route::put('/{id}', 'actualizar_empresa');

        // Actualizar logo de empresa
        Route::post('{id}/logo', 'actualizar_logo');
    });
});
