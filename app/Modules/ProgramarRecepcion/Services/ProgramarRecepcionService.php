<?php

namespace App\Modules\ProgramarRecepcion\Services;

use App\Modules\ProgramarRecepcion\Data\ProgramarRecepcionData;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Responses\ApiResponse;

class ProgramarRecepcionService
{
    /**
     * Listar programaciones.
     */
    public static function get_programaciones(array $filtros): array
    {
        $data = ProgramarRecepcionData::get_programaciones(
            $filtros['solo_pendientes'] ?? true,
            $filtros,
        );

        return ApiResponse::success($data, 'Programaciones obtenidas correctamente');
    }

    /**
     * Detalle completo de una programación (cabecera + visita + vehículos + visitantes).
     */
    public static function get_programacion(int $id): array
    {
        $data = ProgramarRecepcionData::get_programacion_full($id);
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
        $id = ProgramarRecepcionData::crear_programacion($data);
        $nueva = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($nueva, 'Programación registrada correctamente');
    }

    /**
     * Actualizar una programación (solo si NO está confirmada).
     */
    public static function actualizar_programacion(int $id, array $data): array
    {
        $ok = ProgramarRecepcionData::actualizar_programacion($id, $data);
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
        $ok = ProgramarRecepcionData::confirmar_programacion($id, $idEmpleadoRecepcion, $overrides);
        if (! $ok) {
            return ApiResponse::error('No se pudo confirmar la programación (ya estaba confirmada o no existe).', 400);
        }
        $actualizada = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($actualizada, 'Programación confirmada correctamente');
    }
}
