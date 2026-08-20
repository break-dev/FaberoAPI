<?php

namespace App\Modules\RecepcionUnidades\Controllers;

use App\Modules\RecepcionUnidades\Services\RecepcionUnidadesService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class RecepcionUnidadesController extends Controller
{
    /**
     * Obtener listado de recepciones filtradas.
     */
    public function get_recepciones(Request $request): JsonResponse
    {
        $filters = [
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
            'placa' => $request->query('placa'),
            'id_empresa_transporte' => $request->query('id_empresa_transporte'),
            'tipo_ingreso' => $request->query('tipo_ingreso'),
        ];

        return response()->json(RecepcionUnidadesService::get_recepciones($filters));
    }

    /**
     * Obtener una recepción puntual con sus lotes asociados.
     */
    public function get_recepcion(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_recepcion($id));
    }

    /**
     * Registrar un nuevo ingreso/recepción de unidad.
     */
    public function crear_recepcion(Request $request): JsonResponse
    {
        $request->validate([
            'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
            'id_conductor' => 'required|integer|exists:conductor,id',
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'tipo_ingreso' => 'nullable|string|max:50',
            'segunda_placa' => 'nullable|string|max:15',
            'observacion' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'id_sucursal' => 'required|integer|exists:sucursal,id',
            'guia_remitente' => 'nullable|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'id_motivo_ingreso' => 'nullable|integer|exists:motivo_ingreso,id',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar el ingreso.'), 401);
        }

        $data = [
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'id_vehiculo' => $request->input('id_vehiculo') ? (int) $request->input('id_vehiculo') : null,
            'id_empresa_transporte' => (int) $request->input('id_empresa_transporte'),
            'id_tipo_vehiculo' => (int) $request->input('id_tipo_vehiculo'),
            'id_conductor' => (int) $request->input('id_conductor'),
            'id_proveedor_minero' => $request->input('id_proveedor_minero') ? (int) $request->input('id_proveedor_minero') : null,
            'tipo_ingreso' => $request->input('tipo_ingreso', 'Recepción de Unidad'),
            'segunda_placa' => $request->input('segunda_placa'),
            'observacion' => $request->input('observacion'),
            'id_sucursal' => (int) $request->input('id_sucursal'),
            'guia_remitente' => $request->input('guia_remitente'),
            'guia_transportista' => $request->input('guia_transportista'),
        ];

        // Obtener archivos subidos
        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        // Parsear visitantes y sus fotos
        $visitantes = $request->input('visitantes', []);
        $archivosPorIndice = [];
        foreach ($visitantes as $index => $v) {
            $fileKey = "visitantes.{$index}.foto_documento";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosPorIndice[$index] = is_array($files) ? $files : [$files];
            }
        }

        // Parsear vehículos acompañantes y sus fotos
        $vehiculos = $request->input('vehiculos', []);
        $archivosVehiculos = [];
        foreach ($vehiculos as $vIndex => $v) {
            $fileKey = "vehiculos.{$vIndex}.archivos";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosVehiculos[$vIndex] = is_array($files) ? $files : [$files];
            }
        }

        $visitaData = [
            'id_motivo_ingreso' => $request->input('id_motivo_ingreso'),
            'observacion' => $request->input('observacion'),
            'visitantes' => $visitantes,
            'archivosPorIndice' => $archivosPorIndice,
            'vehiculos' => $vehiculos,
            'archivosVehiculos' => $archivosVehiculos,
        ];

        return response()->json(RecepcionUnidadesService::crear_recepcion($data, $archivos, $visitaData));
    }

    /**
     * Registrar la salida de la unidad (estado y observación).
     */
    public function registrar_salida(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado_salida' => 'required|string|max:50',
            'observacion_salida' => 'nullable|string',
        ]);

        $evidencias = [];
        if ($request->hasFile('evidencias')) {
            $files = $request->file('evidencias');
            $evidencias = is_array($files) ? $files : [$files];
        } else if ($request->hasFile('evidencias_salida')) {
            $files = $request->file('evidencias_salida');
            $evidencias = is_array($files) ? $files : [$files];
        }

        $result = RecepcionUnidadesService::registrar_salida(
            $id,
            $request->input('estado_salida'),
            $request->input('observacion_salida'),
            $evidencias
        );

        return response()->json($result);
    }

    /**
     * Listar los lotes asociados a una recepción de unidad.
     */
    public function get_lotes(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_lotes($id));
    }

    /**
     * Generar un nuevo lote para la recepción de unidad indicada.
     * El backend debe retornar el correlativo asignado (ej. LOT-26-00005).
     */
    public function crear_lote(int $id): JsonResponse
    {
        $authUser = request()->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para generar el lote.'), 401);
        }

        return response()->json(RecepcionUnidadesService::crear_lote($id, (int) $authUser->id_empleado));
    }

    /**
     * Eliminar un lote generado.
     */
    public function eliminar_lote(int $lote): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::eliminar_lote($lote));
    }
}
