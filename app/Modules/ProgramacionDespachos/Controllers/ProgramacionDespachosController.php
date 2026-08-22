<?php

namespace App\Modules\ProgramacionDespachos\Controllers;

use App\Modules\ProgramacionDespachos\Services\ProgramacionDespachosService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class ProgramacionDespachosController extends Controller
{
    /**
     * Listar despachos (filtros: id_planta_destino, fecha_inicio, fecha_fin).
     */
    public function get_despachos(Request $request): JsonResponse
    {
        $idPlanta = $request->query('id_planta_destino') ? (int) $request->query('id_planta_destino') : null;
        $fechaInicio = $request->query('fecha_inicio') ?: null;
        $fechaFin = $request->query('fecha_fin') ?: null;

        return response()->json(
            ProgramacionDespachosService::get_despachos($idPlanta, $fechaInicio, $fechaFin)
        );
    }

    /**
     * Items (lotes y blendings) disponibles para despachar.
     */
    public function get_items_disponibles(Request $request): JsonResponse
    {
        return response()->json(ProgramacionDespachosService::get_items_disponibles());
    }

    /**
     * Detalle completo de un despacho.
     */
    public function get_despacho(Request $request, int $id): JsonResponse
    {
        return response()->json(ProgramacionDespachosService::get_despacho($id));
    }

    /**
     * Registrar un nuevo despacho con sus detalles (lotes / blendings).
     */
    public function crear_despacho(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_planta_destino' => 'required|integer',
            'detalles' => 'required|array|min:1',
            'detalles.*.id_lote_mineral' => 'nullable|integer',
            'detalles.*.id_blending' => 'nullable|integer',
            'detalles.*.peso_tomado' => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(
            ProgramacionDespachosService::crear_despacho($validator->validated(), (int) $authUser->id_empleado)
        );
    }

    /**
     * Anular un despacho (solo si todas las distribuciones están en "En Espera").
     */
    public function anular_despacho(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(
            ProgramacionDespachosService::anular_despacho($id, (int) $authUser->id_empleado)
        );
    }

    /**
     * Crear una distribución dentro de un despacho.
     */
    public function crear_distribucion(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_sucursal' => 'required|integer',
            'id_empresa_transporte' => 'required|integer',
            'id_vehiculo' => 'required|integer',
            'id_empresa_transporte_carreta' => 'nullable|integer',
            'id_vehiculo_carreta' => 'nullable|integer',
            'id_tipo_vehiculo' => 'required|integer',
            'id_conductor' => 'required|integer',
            'fecha_estimada_llegada' => 'nullable|date',
            'detalles' => 'required|array|min:1',
            'detalles.*.id_despacho_detalle' => 'required|integer',
            'detalles.*.peso_tomado' => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(
            ProgramacionDespachosService::crear_distribucion(
                $id,
                $validator->validated(),
                (int) $authUser->id_empleado
            )
        );
    }

    /**
     * Confirmar una distribución (En Espera → En Planta).
     */
    public function confirmar_distribucion(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(
            ProgramacionDespachosService::confirmar_distribucion($id, (int) $authUser->id_empleado)
        );
    }

    /**
     * Registrar salida de planta (En Planta → Salió de Planta).
     */
    public function registrar_salida(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $observacion = $request->input('observacion');
        $observacion = is_string($observacion) && $observacion !== '' ? $observacion : null;

        return response()->json(
            ProgramacionDespachosService::registrar_salida($id, (int) $authUser->id_empleado, $observacion)
        );
    }

    /**
     * Registrar llegada al cliente (Salió de Planta → Llegó al Cliente).
     */
    public function registrar_llegada(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(
            ProgramacionDespachosService::registrar_llegada($id, (int) $authUser->id_empleado)
        );
    }
}