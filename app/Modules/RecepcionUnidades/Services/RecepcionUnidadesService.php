<?php

namespace App\Modules\RecepcionUnidades\Services;

use App\Models\RecepcionUnidad;
use App\Models\RecepcionVisita;
use App\Models\RecepcionVisitaDetalle;
use App\Modules\ProgramacionDespachos\Data\ProgramacionDespachosData;
use App\Modules\ProgramacionDespachos\Services\ProgramacionDespachosService;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use App\Modules\RecepcionVisitas\Services\RecepcionVisitasService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class RecepcionUnidadesService
{
    /**
     * Obtener listado de recepciones filtradas.
     */
    public static function get_recepciones(array $filters): array
    {
        $data = RecepcionUnidadesData::get_recepciones($filters);

        return ApiResponse::success($data, 'Recepciones obtenidas correctamente');
    }

    /**
     * Obtener una recepción puntual (incluye sus lotes).
     */
    public static function get_recepcion(int $id): array
    {
        $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $recepcion['lotes'] = RecepcionUnidadesData::get_lotes($id);

        return ApiResponse::success($recepcion, 'Recepción obtenida correctamente');
    }

    /**
     * Guardar archivos de evidencias y crear el registro de recepción.
     *
     * @param  array  $data  Datos básicos. Claves opcionales para archivos de guías:
     *                       - guia_remitente_file: UploadedFile|null
     *                       - guia_transportista_file: UploadedFile|null
     *                       - documentos_programacion_existentes: array con estado actual
     */
    public static function crear_recepcion(array $data, array $archivos, ?array $visitaData = null): array
    {
        // Resolver documentos_programacion a partir de archivos subidos + existentes.
        $data = self::resolver_documentos_programacion(
            $data,
            $data['guia_remitente_file'] ?? null,
            $data['guia_transportista_file'] ?? null,
            is_array($data['documentos_programacion_existentes'] ?? null) ? $data['documentos_programacion_existentes'] : null,
        );

        // Validar unicidad de guías por proveedor.
        $erroresUnicidad = RecepcionUnidadesData::validar_unicidad_guia_proveedor(
            $data['id_proveedor_minero'] ?? null,
            $data['guia_remitente'] ?? null,
            $data['guia_transportista'] ?? null,
            null,
        );
        if ($erroresUnicidad !== null) {
            return ApiResponse::error($erroresUnicidad, 409);
        }

        // Guardar los archivos de evidencias físicas en storage/app/public/recepciones
        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('recepciones', $archivos);
        }

        $data['evidencias'] = $evidenciasGuardadas;

        $id = RecepcionUnidadesData::crear_recepcion($data);

        // Solo crear recepción de visita si hay visitantes válidos o vehículos acompañantes
        $visitantesValidos = array_filter($visitaData['visitantes'] ?? [], function ($v) {
            $nombre = trim($v['nombre'] ?? '');
            $dni = trim($v['dni'] ?? '');
            return $nombre !== '' || $dni !== '' || ! empty($v['id_visitante']);
        });
        $hasVisitantes = ! empty($visitantesValidos);
        $hasVehiculos = ! empty($visitaData['vehiculos']);

        if ($visitaData && ! empty($visitaData['id_motivo_ingreso']) && ($hasVisitantes || $hasVehiculos)) {
            RecepcionVisitasService::crear_recepcion_para_programacion(
                $data['id_empleado_registro'],
                $id,
                (int) $visitaData['id_motivo_ingreso'],
                $visitaData['observacion'] ?? null,
                array_values($visitantesValidos),
                $visitaData['archivosPorIndice'] ?? [],
                $visitaData['vehiculos'] ?? [],
                $visitaData['archivosVehiculos'] ?? []
            );
        }

        $nuevaRecepcion = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($nuevaRecepcion, 'Recepción de unidad registrada correctamente');
    }

    /**
     * Resolver el estado final de documentos_programacion mezclando archivos existentes y nuevos.
     */
    private static function resolver_documentos_programacion(
        array $data,
        ?UploadedFile $guiaRemitente,
        ?UploadedFile $guiaTransportista,
        ?array $existentes,
    ): array {
        $existentes = $existentes ?? [];
        $documentos = [
            'guia_remitente' => $existentes['guia_remitente'] ?? null,
            'guia_transportista' => $existentes['guia_transportista'] ?? null,
        ];

        if ($guiaRemitente instanceof UploadedFile && $guiaRemitente->isValid()) {
            $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$guiaRemitente]);
            $documentos['guia_remitente'] = $subidos[0] ?? null;
        }
        if ($guiaTransportista instanceof UploadedFile && $guiaTransportista->isValid()) {
            $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$guiaTransportista]);
            $documentos['guia_transportista'] = $subidos[0] ?? null;
        }

        $data['documentos_programacion'] = ($documentos['guia_remitente'] || $documentos['guia_transportista'])
            ? $documentos
            : null;

        unset(
            $data['guia_remitente_file'],
            $data['guia_transportista_file'],
            $data['documentos_programacion_existentes'],
        );

        return $data;
    }

    /**
     * Registrar la salida de una unidad.
     */
    public static function registrar_salida(int $id, string $estadoSalida, ?string $observacionSalida, array $evidencias, int $idEmpleadoOperador): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $nowStr = now()->toDateTimeString();

        $uploaded = [];
        $uploadedUrls = [];
        if (! empty($evidencias)) {
            $uploaded = ArchivoHelper::guardarArchivos('evidencias/salida', $evidencias);
            if (! empty($uploaded)) {
                $uploadedUrls = array_map(fn ($f) => $f['url'], $uploaded);
            }
        }

        if (! empty($uploaded)) {
            $rawEv = $recepcion->evidencias;
            $existingEv = is_array($rawEv)
                ? $rawEv
                : (is_string($rawEv) ? (json_decode($rawEv, true) ?? []) : []);

            $normalizedExisting = array_map(function ($item) {
                if (is_string($item)) {
                    $nombre = pathinfo(parse_url($item, PHP_URL_PATH) ?? '', PATHINFO_FILENAME);
                    $ext = pathinfo(parse_url($item, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
                    return [
                        'url' => $item,
                        'path_relativo' => str_replace(asset('storage/').'/', '', $item),
                        'nombre_original' => $nombre ?: 'archivo',
                        'extension' => $ext ?: 'bin',
                    ];
                }
                return $item;
            }, $existingEv);

            $recepcion->evidencias = array_merge($normalizedExisting, $uploaded);
        }

        $recepcion->estado_salida = $estadoSalida;
        $recepcion->observacion_salida = $observacionSalida;
        $recepcion->fecha_hora_salida = $nowStr;
        $recepcion->estado = EstadoVisita::FueraDePlanta->value;
        $recepcion->save();

        // Registrar la salida en la visita y sus detalles vinculados a esta recepción de unidad
        $visitas = RecepcionVisita::where('id_recepcion_unidad', $id)->get();
        foreach ($visitas as $visita) {
            $visita->fecha_hora_salida = $nowStr;
            $visita->observacion_salida = $observacionSalida;
            if (! empty($uploadedUrls)) {
                $rawVisitaEv = $visita->evidencias_salida;
                $existingVisitaEv = is_array($rawVisitaEv)
                    ? $rawVisitaEv
                    : (is_string($rawVisitaEv) ? (json_decode($rawVisitaEv, true) ?? []) : []);
                $mergedVisitaEv = array_values(array_unique(array_merge($existingVisitaEv, $uploadedUrls)));
                $visita->evidencias_salida = json_encode($mergedVisitaEv);
            }
            $visita->estado = EstadoVisita::FueraDePlanta->value;
            $visita->save();

            $updateDetalleData = [
                'fecha_hora_salida' => $nowStr,
                'observacion_salida' => $observacionSalida,
                'estado' => EstadoVisita::FueraDePlanta->value,
            ];

            RecepcionVisitaDetalle::where('id_recepcion_visita', $visita->id)->update($updateDetalleData);
        }

        $distribucionId = ProgramacionDespachosData::get_distribucion_id_for_recepcion_unidad($id);
        if ($distribucionId !== null) {
            $result = ProgramacionDespachosService::registrar_salida(
                $distribucionId,
                $idEmpleadoOperador,
                $observacionSalida
            );
            if (! ($result['success'] ?? false)) {
                Log::warning('Auto-registrar salida de distribución {id} desde recepción unidad {ru} falló: {msg}', [
                    'id' => $distribucionId,
                    'ru' => $id,
                    'msg' => $result['message'] ?? 'unknown',
                ]);
            }
        }

        $updated = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($updated, 'Salida de unidad registrada correctamente');
    }

    /**
     * Listar lotes de una recepción de unidad.
     */
    public static function get_lotes(int $id): array
    {
        $data = RecepcionUnidadesData::get_lotes($id);

        return ApiResponse::success($data, 'Lotes obtenidos correctamente');
    }

    /**
     * Generar un nuevo lote para la recepción indicada.
     */
    public static function crear_lote(int $id, int $idEmpleado): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $lote = RecepcionUnidadesData::crear_lote($id, $idEmpleado);

        return ApiResponse::success($lote, 'Lote generado correctamente');
    }

    /**
     * Eliminar un lote.
     */
    public static function eliminar_lote(int $loteId): array
    {
        $deleted = RecepcionUnidadesData::eliminar_lote($loteId);
        if (! $deleted) {
            return ApiResponse::error('No se encontró el lote a eliminar.', 404);
        }

        return ApiResponse::success(null, 'Lote eliminado correctamente');
    }
}
