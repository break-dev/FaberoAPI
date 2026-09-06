<?php

use App\Controllers\AuxController;
use App\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

// Mantiene los contenedores despiertos y valida que la base de datos responde.
Route::get('/health', [HealthCheckController::class, 'check']);

Route::middleware('auth.jwt.custom')->group(function () {
    Route::prefix('aux')->group(function () {
        // empleados
        Route::get('/empleados', [AuxController::class, 'get_empleados']);

        // proveedores
        Route::get('/proveedores', [AuxController::class, 'get_proveedores']);

        // empresas
        Route::get('/empresas', [AuxController::class, 'get_empresas']);

        // marcas
        Route::get('/marcas', [AuxController::class, 'get_marcas']);
        Route::post('/marcas', [AuxController::class, 'crear_marca']);

        // conductores
        Route::get('/conductores', [AuxController::class, 'get_conductores']);
        Route::post('/conductores', [AuxController::class, 'crear_conductor']);

        // ubigeo
        Route::get('/departamentos', [AuxController::class, 'get_departamentos']);
        Route::get('/provincias', [AuxController::class, 'get_provincias']);
        Route::get('/distritos', [AuxController::class, 'get_distritos']);

        // tipos de vehiculo
        Route::get('/tipos-vehiculo', [AuxController::class, 'get_tipos_vehiculo']);
        Route::post('/tipos-vehiculo', [AuxController::class, 'crear_tipo_vehiculo']);
        Route::put('/tipos-vehiculo/{id}', [AuxController::class, 'editar_tipo_vehiculo']);
        Route::patch('/tipos-vehiculo/{id}/estado', [AuxController::class, 'cambiar_estado_tipo_vehiculo']);

        // empresas de transporte
        Route::get('/empresas-transporte', [AuxController::class, 'get_empresas_transporte']);

        // vehiculos
        Route::get('/vehiculos', [AuxController::class, 'get_vehiculos']);
        Route::post('/vehiculos', [AuxController::class, 'crear_vehiculo']);
        Route::put('/vehiculos/{id}', [AuxController::class, 'editar_vehiculo']);
        Route::patch('/vehiculos/{id}/capacidad', [AuxController::class, 'update_capacidad_vehiculo']);

        // motivos de ingreso
        Route::get('/motivos-ingreso', [AuxController::class, 'get_motivos_ingreso']);
        Route::post('/motivos-ingreso', [AuxController::class, 'crear_motivo_ingreso']);

        // visitantes
        Route::get('/visitantes/buscar', [AuxController::class, 'buscar_visitante_por_dni']);
        Route::get('/visitantes', [AuxController::class, 'listar_visitantes']);
        Route::post('/visitantes', [AuxController::class, 'crear_visitante']);

        // sucursales
        Route::get('/sucursales', [AuxController::class, 'get_sucursales']);

        // plantas destino activas (despachos)
        Route::get('/plantas-despachable', [AuxController::class, 'get_plantas_despachable']);

        // zonas de origen
        Route::get('/zonas-origen', [AuxController::class, 'get_zonas_origen']);
        Route::post('/zonas-origen', [AuxController::class, 'crear_zona_origen']);

        // lotes de mineral disponibles para guías
        Route::get('/lotes-mineral-disponibles', [AuxController::class, 'get_lotes_mineral_disponibles']);

        // archivos de guías (documentos_programacion) de una recepción específica.
        // Usado para autocompletar inputs al registrar una guía de primer tramo
        // cuando los items seleccionados pertenecen a una sola recepción.
        Route::get('/recepcion-unidad/{idRecepcion}/archivos-guias', [AuxController::class, 'get_archivos_guias_recepcion']);

        // valorizacion compra auxiliares
        Route::get('/proveedores-valorizacion', [AuxController::class, 'get_proveedores_valorizacion']);
        Route::get('/concesiones-proveedor', [AuxController::class, 'get_concesiones_proveedor']);
        Route::get('/cuentas-bancarias-proveedor', [AuxController::class, 'get_cuentas_bancarias_proveedor']);
        Route::get('/anticipos-proveedor', [AuxController::class, 'get_anticipos_proveedor']);
        Route::get('/lotes-disponibles-valorizacion', [AuxController::class, 'get_lotes_disponibles_valorizacion']);
        Route::get('/valorizaciones-aprobadas-proveedor', [AuxController::class, 'get_valorizaciones_aprobadas_por_proveedor']);

        // tipo de cambio
        Route::get('/tipos-cambio', [AuxController::class, 'get_tipos_cambio']);
        Route::get('/tipo-cambio', [AuxController::class, 'get_tipo_cambio_por_fecha']);
        Route::post('/tipo-cambio', [AuxController::class, 'crear_tipo_cambio']);

        // cuentas bancarias empresa filtradas por moneda (para módulo contabilidad-compra)
        Route::get('/cuentas-bancarias-empresa-moneda', [AuxController::class, 'get_cuentas_bancarias_empresa_por_moneda']);
    });
});
