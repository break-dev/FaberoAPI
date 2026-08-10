<?php

namespace App\Modules\RecepcionVisitas\Controllers;

use App\Modules\RecepcionVisitas\Services\RecepcionVisitasService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class RecepcionVisitasController extends Controller
{
    /**
     * Obtener listado de recepciones de visitas con filtros.
     */
    public function get_recepciones(Request $request): JsonResponse
    {
        $filters = [
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        return response()->json(RecepcionVisitasService::get_recepciones($filters));
    }

    /**
     * Registrar una recepción de visita.
     */
    public function crear_recepcion(Request $request): JsonResponse
    {
        $request->validate([
            'id_empleado_contacto' => 'nullable|integer|exists:empleado,id',
            'id_empleado_autoriza' => 'nullable|integer|exists:empleado,id',
            'id_motivo_ingreso' => 'required|integer|exists:motivo_ingreso,id',
            'observacion' => 'nullable|string',
            'con_vehiculo' => 'nullable|in:0,1,true,false',
            'placa' => 'nullable|string|max:20',
            'visitantes' => 'nullable|array',
            'visitantes.*.id_visitante' => 'nullable|integer|exists:visitante,id',
            'visitantes.*.nombre' => 'nullable|string|max:100',
            'visitantes.*.apellido' => 'nullable|string|max:100',
            'visitantes.*.dni' => 'nullable|string|max:8',
            'visitantes.*.telefono' => 'nullable|string|max:50',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar el ingreso.'), 401);
        }

        $conVehiculoInput = $request->input('con_vehiculo');
        $conVehiculo = ($conVehiculoInput === '1' || $conVehiculoInput === 1 || $conVehiculoInput === 'true' || $conVehiculoInput === true);

        $empContacto = $request->input('id_empleado_autoriza') ?? $request->input('id_empleado_contacto');
        $placaInput = $request->input('placa');

        $data = [
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'id_empleado_contacto' => $empContacto ? (int) $empContacto : null,
            'id_empleado_autoriza' => $empContacto ? (int) $empContacto : null,
            'id_motivo_ingreso' => (int) $request->input('id_motivo_ingreso'),
            'observacion' => $request->input('observacion'),
            'con_vehiculo' => $conVehiculo,
            'placa' => $conVehiculo ? $placaInput : null,
        ];

        // Obtener archivos de los visitantes vinculados por su índice correspondiente
        $visitantes = $request->input('visitantes', []);
        $archivos = [];
        foreach ($visitantes as $index => $v) {
            $fileKey = "visitantes.{$index}.foto_documento";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                if (is_array($files)) {
                    $archivos[$index] = $files;
                } else {
                    $archivos[$index] = [$files];
                }
            }
        }

        $vehiculos = $request->input('vehiculos', []);
        $archivosVehiculos = [];
        foreach ($vehiculos as $vIndex => $v) {
            $fileKey = "vehiculos.{$vIndex}.archivos";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosVehiculos[$vIndex] = is_array($files) ? $files : [$files];
            }
        }

        $evidencias = [];
        if ($request->hasFile('evidencias')) {
            $files = $request->file('evidencias');
            $evidencias = is_array($files) ? $files : [$files];
        } else if ($request->hasFile('evidencias_ingreso')) {
            $files = $request->file('evidencias_ingreso');
            $evidencias = is_array($files) ? $files : [$files];
        }

        return response()->json(RecepcionVisitasService::crear_recepcion($data, $visitantes, $archivos, $vehiculos, $archivosVehiculos, $evidencias));
    }

    /**
     * Registrar la salida de una visita.
     */
    public function registrar_salida(Request $request, int $id): JsonResponse
    {
        $request->validate([
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

        $result = RecepcionVisitasService::registrar_salida(
            $id,
            $request->input('observacion_salida'),
            $evidencias
        );

        return response()->json($result);
    }

    /**
     * Registrar salida general de la cabecera recepcion_visita.
     */
    public function registrar_salida_general(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'observacion_salida' => 'nullable|string',
        ]);

        $evidencias = [];
        if ($request->hasFile('evidencias_salida')) {
            $files = $request->file('evidencias_salida');
            $evidencias = is_array($files) ? $files : [$files];
        } elseif ($request->hasFile('evidencias')) {
            $files = $request->file('evidencias');
            $evidencias = is_array($files) ? $files : [$files];
        }

        $result = RecepcionVisitasService::registrar_salida_general(
            $id,
            $request->input('observacion_salida'),
            $evidencias
        );

        return response()->json($result);
    }

    /**
     * Crear la visita (cabecera + detalle) asociada a una programación de unidad.
     *
     * Flujo:
     *   1. Inserta la cabecera de recepcion_visita con id_recepcion_unidad.
     *   2. Por cada visitante: upsert en `visitante` + inserta recepcion_visita_detalle.
     *   3. Sube fotos del documento al storage.
     */
    public function crear_para_programacion(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_recepcion_unidad' => 'required|integer|exists:recepcion_unidad,id',
            'id_motivo_ingreso' => 'required|integer|exists:motivo_ingreso,id',
            'observacion' => 'nullable|string',
            'visitantes' => 'nullable|array',
            'visitantes.*.nombre' => 'nullable|string|max:100',
            'visitantes.*.apellido' => 'nullable|string|max:100',
            'visitantes.*.dni' => 'nullable|string|max:8',
            'visitantes.*.telefono' => 'nullable|string|max:50',
            'visitantes.*.id_visita_vehiculo' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $visitantes = $request->input('visitantes', []);
        $vehiculos = $request->input('vehiculos', []);

        $visitantesValidos = array_filter($visitantes, function ($v) {
            $nombre = trim($v['nombre'] ?? '');
            $dni = trim($v['dni'] ?? '');
            return $nombre !== '' || $dni !== '' || ! empty($v['id_visitante']);
        });

        if (empty($visitantesValidos) && empty($vehiculos)) {
            return response()->json(ApiResponse::success(null, 'No se generó recepción de visita al no haber visitantes ni vehículos acompañantes.'));
        }

        $archivosPorIndice = [];
        foreach ($visitantes as $index => $v) {
            $fileKey = "visitantes.{$index}.foto_documento";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosPorIndice[$index] = is_array($files) ? $files : [$files];
            }
        }

        $archivosVehiculos = [];
        foreach ($vehiculos as $vIndex => $v) {
            $fileKey = "vehiculos.{$vIndex}.archivos";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosVehiculos[$vIndex] = is_array($files) ? $files : [$files];
            }
        }

        $evidencias = [];
        if ($request->hasFile('evidencias')) {
            $files = $request->file('evidencias');
            $evidencias = is_array($files) ? $files : [$files];
        }

        $result = RecepcionVisitasService::crear_recepcion_para_programacion(
            (int) $authUser->id_empleado,
            (int) $request->input('id_recepcion_unidad'),
            (int) $request->input('id_motivo_ingreso'),
            $request->input('observacion'),
            $visitantes,
            $archivosPorIndice,
            $vehiculos,
            $archivosVehiculos,
            $evidencias
        );

        return response()->json($result);
    }
}
