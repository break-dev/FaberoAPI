<?php

namespace App\Modules\ProgramarRecepcion\Controllers;

use App\Modules\ProgramarRecepcion\Services\ProgramarRecepcionService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class ProgramarRecepcionController extends Controller
{
    /**
     * Listar programaciones (opcionalmente solo pendientes).
     */
    public function get_programaciones(Request $request): JsonResponse
    {
        $estadoConfirmacion = $request->query('estado_confirmacion');
        $soloPendientes = null;
        if ($request->has('solo_pendientes')) {
            $soloPendientes = filter_var($request->query('solo_pendientes'), FILTER_VALIDATE_BOOLEAN);
        }

        $filtros = [
            'estado_confirmacion' => $estadoConfirmacion,
            'solo_pendientes' => $soloPendientes,
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        return response()->json(ProgramarRecepcionService::get_programaciones($filtros));
    }

    /**
     * Detalle completo de una programación: cabecera + visita asociada + vehículos + visitantes.
     */
    public function get_programacion(int $id): JsonResponse
    {
        return response()->json(ProgramarRecepcionService::get_programacion($id));
    }

    /**
     * Crear una nueva programación.
     */
    public function crear_programacion(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
            'id_tipo_vehiculo' => 'nullable|integer|exists:tipo_vehiculo,id',
            'id_conductor' => 'nullable|integer|exists:conductor,id',
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_sucursal' => 'nullable|integer|exists:sucursal,id',
            'fecha_estimada_llegada' => 'nullable|date',
            'guia_remitente' => 'nullable|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'guia_remitente_file' => 'nullable|file',
            'guia_transportista_file' => 'nullable|file',
            'documentos_programacion_existentes' => 'nullable|string',
            'observacion' => 'nullable|string',
            'tipo_ingreso' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para autorizar la programación.'), 401);
        }

        $data = $request->all();
        $data['id_empleado_autoriza'] = (int) $authUser->id_empleado;
        $data['guia_remitente_file'] = $request->file('guia_remitente_file');
        $data['guia_transportista_file'] = $request->file('guia_transportista_file');

        if ($request->filled('documentos_programacion_existentes')) {
            $decoded = json_decode($request->input('documentos_programacion_existentes'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data['documentos_programacion_existentes'] = $decoded;
            }
        }

        return response()->json(ProgramarRecepcionService::crear_programacion($data));
    }

    /**
     * Actualizar una programación (solo si aún no fue confirmada).
     */
    public function actualizar_programacion(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_empresa_transporte' => 'nullable|integer|exists:empresa_transporte,id',
            'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
            'id_tipo_vehiculo' => 'nullable|integer|exists:tipo_vehiculo,id',
            'id_conductor' => 'nullable|integer|exists:conductor,id',
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'fecha_estimada_llegada' => 'nullable|date',
            'guia_remitente' => 'nullable|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'guia_remitente_file' => 'nullable|file',
            'guia_transportista_file' => 'nullable|file',
            'documentos_programacion_existentes' => 'nullable|string',
            'observacion' => 'nullable|string',
            'tipo_ingreso' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $data = $request->all();
        $data['guia_remitente_file'] = $request->file('guia_remitente_file');
        $data['guia_transportista_file'] = $request->file('guia_transportista_file');

        if ($request->filled('documentos_programacion_existentes')) {
            $decoded = json_decode($request->input('documentos_programacion_existentes'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data['documentos_programacion_existentes'] = $decoded;
            }
        }

        return response()->json(ProgramarRecepcionService::actualizar_programacion($id, $data));
    }

    /**
     * Confirmar una programación (la marca como 'En Planta' y registra id_empleado_recepcion).
     * Acepta opcionalmente `observacion` y archivos `evidencias[]` (multipart) para
     * persistir también en `recepcion_unidad` con su entrada en `log_cambios`.
     */
    public function confirmar_programacion(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para confirmar la programación.'), 401);
        }

        $request->validate([
            'observacion' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $overrides = [];
        if ($request->input('id_vehiculo')) {
            $overrides['id_vehiculo'] = (int) $request->input('id_vehiculo');
        }
        if ($request->input('id_tipo_vehiculo')) {
            $overrides['id_tipo_vehiculo'] = (int) $request->input('id_tipo_vehiculo');
        }
        if ($request->input('id_sucursal')) {
            $overrides['id_sucursal'] = (int) $request->input('id_sucursal');
        }
        if ($request->input('id_conductor')) {
            $overrides['id_conductor'] = (int) $request->input('id_conductor');
        }
        if ($request->input('id_proveedor_minero')) {
            $overrides['id_proveedor_minero'] = (int) $request->input('id_proveedor_minero');
        }
        if ($request->input('id_empresa_transporte')) {
            $overrides['id_empresa_transporte'] = (int) $request->input('id_empresa_transporte');
        }
        if ($request->filled('guia_remitente')) {
            $overrides['guia_remitente'] = $request->input('guia_remitente');
        }
        if ($request->filled('guia_transportista')) {
            $overrides['guia_transportista'] = $request->input('guia_transportista');
        }

        $observacion = $request->input('observacion');
        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(ProgramarRecepcionService::confirmar_programacion(
            $id,
            (int) $authUser->id_empleado,
            $overrides,
            $observacion,
            $archivos,
        ));
    }

    /**
     * Editar la observación y/o evidencias de una recepción ya confirmada.
     * Persiste los cambios en `recepcion_unidad` con su entrada en `log_cambios`.
     */
    public function actualizar_observacion_evidencias(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar los cambios.'), 401);
        }

        $request->validate([
            'observacion' => 'nullable|string',
            'observacion_salida' => 'nullable|string',
            'evidencias_existentes' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'motivo' => 'nullable|string|max:500',
        ]);

        $evidenciasExistentes = [];
        if ($request->filled('evidencias_existentes')) {
            $decoded = json_decode($request->input('evidencias_existentes'), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return response()->json(ApiResponse::error('El campo evidencias_existentes no es un JSON válido.'), 422);
            }
            $evidenciasExistentes = $decoded;
        }

        $archivosNuevos = [];
        if ($request->hasFile('evidencias')) {
            $archivosNuevos = $request->file('evidencias');
            if (! is_array($archivosNuevos)) {
                $archivosNuevos = [$archivosNuevos];
            }
        }

        return response()->json(ProgramarRecepcionService::actualizar_observacion_evidencias(
            $id,
            $request->input('observacion'),
            $request->input('observacion_salida'),
            $evidenciasExistentes,
            $archivosNuevos,
            (int) $authUser->id_empleado,
            $request->input('motivo'),
        ));
    }
}
