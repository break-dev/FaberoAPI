<?php

namespace App\Modules\RecepcionUnidades\Services;

use App\Models\RecepcionUnidad;
use App\Models\RecepcionVisita;
use App\Models\RecepcionVisitaDetalle;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use App\Modules\RecepcionVisitas\Services\RecepcionVisitasService;

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
     */
    public static function crear_recepcion(array $data, array $archivos, ?array $visitaData = null): array
    {
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
     * Registrar la salida de una unidad.
     */
    public static function registrar_salida(int $id, string $estadoSalida, ?string $observacionSalida, array $evidencias = []): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $nowStr = now()->toDateTimeString();

        $uploadedUrls = [];
        if (! empty($evidencias)) {
            $uploaded = ArchivoHelper::guardarArchivos('evidencias/salida', $evidencias);
            if (! empty($uploaded)) {
                $uploadedUrls = array_map(fn ($f) => $f['url'], $uploaded);
            }
        }

        if (! empty($uploadedUrls)) {
            $rawEv = $recepcion->evidencias;
            $existingEv = is_array($rawEv)
                ? $rawEv
                : (is_string($rawEv) ? (json_decode($rawEv, true) ?? []) : []);
            $mergedEv = array_values(array_unique(array_merge($existingEv, $uploadedUrls)));
            $recepcion->evidencias = $mergedEv;
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
     * Generar un nuevo lote para la recepción de unidad indicada.
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

    /**
     * Listar programaciones.
     */
    public static function get_programaciones(bool $soloPendientes = false): array
    {
        $data = RecepcionUnidadesData::get_programaciones($soloPendientes);

        return ApiResponse::success($data, 'Programaciones obtenidas correctamente');
    }

    /**
     * Detalle completo de una programación (cabecera + visita + vehículos + visitantes).
     */
    public static function get_programacion(int $id): array
    {
        $data = RecepcionUnidadesData::get_programacion_full($id);
        if ($data === null) {
            return ApiResponse::error('No se encontró la programación.', 404);
        }

        return ApiResponse::success($data, 'Programación obtenida correctamente');
    }

    /**
     * Crear una programación de recepción de unidad.
     */
    public static function crear_programacion(array $data): array
    {
        $id = RecepcionUnidadesData::crear_programacion($data);
        $nueva = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($nueva, 'Programación registrada correctamente');
    }

    /**
     * Actualizar una programación (solo si NO está confirmada).
     */
    public static function actualizar_programacion(int $id, array $data): array
    {
        $ok = RecepcionUnidadesData::actualizar_programacion($id, $data);
        if (! $ok) {
            return ApiResponse::error('No se pudo actualizar la programación (puede estar confirmada o no existir).', 400);
        }
        $actualizada = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($actualizada, 'Programación actualizada correctamente');
    }

    /**
     * Confirmar una programación. Marca la fila como 'En Planta' y registra id_empleado_recepcion.
     */
    public static function confirmar_programacion(int $id, int $idEmpleadoRecepcion, array $overrides = []): array
    {
        $ok = RecepcionUnidadesData::confirmar_programacion($id, $idEmpleadoRecepcion, $overrides);
        if (! $ok) {
            return ApiResponse::error('No se pudo confirmar la programación (ya estaba confirmada o no existe).', 400);
        }
        $actualizada = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($actualizada, 'Programación confirmada correctamente');
    }
}
