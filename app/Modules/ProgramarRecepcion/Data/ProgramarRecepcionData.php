<?php

namespace App\Modules\ProgramarRecepcion\Data;

use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Enums\_Generic\EstadoVisita;
use Illuminate\Support\Facades\DB;

class ProgramarRecepcionData
{
    /**
     * Obtener programaciones (recepciones con es_programacion = 1).
     * Opcionalmente filtrar por estado de confirmación: las no confirmadas (id_empleado_recepcion IS NULL).
     */
    public static function get_programaciones(bool $soloPendientes = false): array
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            ru.tipo_ingreso,
            ru.guia_remitente,
            ru.guia_transportista,
            ru.fecha_estimada_llegada,
            ru.observacion,
            ru.es_programacion,
            ru.id_empleado_recepcion,
            ru.fecha_hora_ingreso,
            ru.estado,
            ru.created_at
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        WHERE ru.es_programacion = 1
        ';

        if ($soloPendientes) {
            $sql .= ' AND ru.id_empleado_recepcion IS NULL';
        }

        $sql .= ' ORDER BY ru.created_at DESC;';

        return DB::select($sql);
    }

    /**
     * Detalle completo de una programación + visita asociada + vehículos + visitantes.
     */
    public static function get_programacion_full(int $id): ?array
    {
        $programacion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if ($programacion === null) {
            return null;
        }

        // Buscar visita asociada (si fue confirmada)
        $visita = DB::selectOne('
            SELECT
                rv.id AS id_recepcion_visita,
                rv.id_motivo_ingreso,
                mi.nombre AS motivo_ingreso_nombre,
                rv.fecha_hora_ingreso,
                rv.observacion,
                rv.estado
            FROM recepcion_visita rv
            LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
            WHERE rv.id_recepcion_unidad = :id
            LIMIT 1
        ', ['id' => $id]);

        $visitaId = $visita->id_recepcion_visita ?? null;
        $visitaPayload = $visita ? (array) $visita : null;

        if ($visitaId !== null) {
            // Cargar vehículos acompañantes
            $vehiculos = DB::select('
                SELECT
                    id, id_recepcion_visita, placa, cantidad_personas, url_foto
                FROM visita_vehiculo
                WHERE id_recepcion_visita = :id
                ORDER BY id ASC
            ', ['id' => $visitaId]);

            foreach ($vehiculos as $vv) {
                $vv->url_foto = $vv->url_foto ? json_decode($vv->url_foto, true) : null;
            }

            // Cargar detalles (visitantes) de la visita
            $detalles = DB::select('
                SELECT
                    rvd.id AS id_detalle,
                    rvd.id_visitante,
                    rvd.id_visita_vehiculo,
                    rvd.es_conductor,
                    rvd.estado,
                    v.nombre AS visitante_nombre,
                    v.apellido AS visitante_apellido,
                    v.dni AS visitante_dni,
                    v.telefono AS visitante_telefono,
                    rvd.url_foto_documento
                FROM recepcion_visita_detalle rvd
                INNER JOIN visitante v ON v.id = rvd.id_visitante
                WHERE rvd.id_recepcion_visita = :id
                ORDER BY rvd.es_conductor DESC, rvd.id ASC
            ', ['id' => $visitaId]);

            foreach ($detalles as $d) {
                $d->url_foto_documento = $d->url_foto_documento ? json_decode($d->url_foto_documento, true) : null;
                if (isset($d->es_conductor)) {
                    $d->es_conductor = (int) $d->es_conductor === 1;
                }
            }

            $visitaPayload['vehiculos'] = $vehiculos;
            $visitaPayload['detalles'] = $detalles;
        }

        $programacion['visita'] = $visitaPayload;

        return $programacion;
    }

    /**
     * Crear una programación (recepcion_unidad con es_programacion = 1).
     */
    public static function crear_programacion(array $data): int
    {
        return DB::table('recepcion_unidad')->insertGetId([
            'id_empleado_autoriza' => $data['id_empleado_autoriza'],
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_vehiculo' => $data['id_vehiculo'] ?? null,
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'] ?? null,
            'id_conductor' => $data['id_conductor'] ?? null,
            'id_proveedor_minero' => $data['id_proveedor_minero'] ?? null,
            'id_sucursal' => $data['id_sucursal'] ?? null,
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Mineral',
            'guia_remitente' => $data['guia_remitente'] ?? null,
            'guia_transportista' => $data['guia_transportista'] ?? null,
            'fecha_estimada_llegada' => $data['fecha_estimada_llegada'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'es_programacion' => 1,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Actualizar programación (solo permitido mientras NO esté confirmada).
     */
    public static function actualizar_programacion(int $id, array $data): bool
    {
        return DB::table('recepcion_unidad')
            ->where('id', $id)
            ->where('es_programacion', 1)
            ->whereNull('id_empleado_recepcion')
            ->update($data) > 0;
    }

    /**
     * Confirmar una programación.
     */
    public static function confirmar_programacion(int $id, int $idEmpleadoRecepcion, array $overrides = []): bool
    {
        $update = array_merge([
            'id_empleado_recepcion' => $idEmpleadoRecepcion,
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'estado' => EstadoVisita::EnPlanta->value,
            'estado_pesaje' => EstadoPesaje::SinPesar->value,
        ], $overrides);

        return DB::table('recepcion_unidad')
            ->where('id', $id)
            ->where('es_programacion', 1)
            ->whereNull('id_empleado_recepcion')
            ->update($update) > 0;
    }
}
