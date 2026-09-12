<?php

use App\Modules\ValorElementoQuimico\Controllers\ValorElementoQuimicoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('valor-elemento-quimico')->controller(ValorElementoQuimicoController::class)->group(function () {
        Route::get('/buscar', 'buscar_precio');
        Route::post('/', 'registrar_precio');
    });
});
