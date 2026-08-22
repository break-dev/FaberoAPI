<?php

use App\Middlewares\JwtAuthMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->prefix('api')->group(function () {
                require base_path('app/Modules/Login/LoginEndpoints.php');
                require base_path('app/Endpoints/MenuNavEndpoints.php');
                require base_path('app/Endpoints/ArchivoEndpoints.php');
                require base_path('app/Endpoints/AuxEndpoints.php');
                require base_path('app/Endpoints/IAEndpoints.php');
                require base_path('app/Modules/Empresas/EmpresasEndpoints.php');
                require base_path('app/Modules/Organigrama/OrganigramaEndpoints.php');
                require base_path('app/Modules/Empleados/EmpleadosEndpoints.php');
                require base_path('app/Modules/Roles/RolesEndpoints.php');
                require base_path('app/Modules/Cuentas/CuentasEndpoints.php');
                require base_path('app/Modules/Perfil/PerfilEndpoints.php');
                require base_path('app/Modules/Proveedores/ProveedoresEndpoints.php');
                require base_path('app/Modules/Bancos/BancosEndpoints.php');
                require base_path('app/Modules/CuentasBancariasProveedor/CuentasBancariasProveedorEndpoints.php');
                require base_path('app/Modules/CuentasBancariasEmpresa/CuentasBancariasEmpresaEndpoints.php');
                require base_path('app/Modules/ModoAuditoria/ModoAuditoriaEndpoints.php');
                require base_path('app/Modules/Sucursales/SucursalesEndpoints.php');
                require base_path('app/Modules/PlantasDestino/PlantasDestinoEndpoints.php');
                require base_path('app/Modules/CuentasBancariasPlantaDestino/CuentasBancariasPlantaDestinoEndpoints.php');
                require base_path('app/Modules/Conductores/ConductoresEndpoints.php');
                require base_path('app/Modules/EmpresasTransporte/EmpresasTransporteEndpoints.php');
                require base_path('app/Modules/Vehiculos/VehiculosEndpoints.php');
                require base_path('app/Modules/Marcas/MarcasEndpoints.php');
                require base_path('app/Endpoints/ConcesionesEndpoints.php');
                require base_path('app/Modules/RecepcionUnidades/RecepcionUnidadesEndpoints.php');
                require base_path('app/Modules/ProgramarRecepcion/ProgramarRecepcionEndpoints.php');
                require base_path('app/Modules/RecepcionVisitas/RecepcionVisitasEndpoints.php');
                require base_path('app/Modules/VisitaVehiculo/VisitaVehiculoEndpoints.php');
                require base_path('app/Modules/RecepcionMineral/RecepcionMineralEndpoints.php');
                require base_path('app/Modules/ValidacionDistribucion/ValidacionDistribucionEndpoints.php');
                require base_path('app/Modules/GuiasPrimerTramo/GuiasPrimerTramoEndpoints.php');
                require base_path('app/Modules/GestionLeyes/GestionLeyesEndpoints.php');
                require base_path('app/Modules/CierreLeyes/CierreLeyesEndpoints.php');
                require base_path('app/Modules/CondicionesComercialesProveedor/CondicionesComercialesProveedorEndpoints.php');
                require base_path('app/Modules/AnticiposProveedor/AnticiposProveedorEndpoints.php');
                require base_path('app/Modules/ValorizacionCompra/Endpoints/ValorizacionCompraEndpoints.php');
                require base_path('app/Modules/ContabilidadCompra/ContabilidadCompraEndpoints.php');
                require base_path('app/Modules/Blending/Endpoints/BlendingEndpoints.php');
                require base_path('app/Modules/ProgramacionDespachos/ProgramacionDespachosEndpoints.php');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt.custom' => JwtAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
