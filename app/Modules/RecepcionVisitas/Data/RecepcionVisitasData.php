<?php

namespace App\Modules\RecepcionVisitas\Data;

use App\Models\RecepcionVisita;
use App\Shared\Enums\_Generic\EstadoVisita;
use Illuminate\Support\Facades\DB;

class RecepcionVisitasData
{
    /**
     * Obtener listado de recepciones de visitas con filtros.
     */
    public static function get_recepciones(array $filters = []): array
    {
        $sql = '
        SELECT
            rv.id,
            rv.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            rv.id_motivo_ingreso,
            mi.nombre AS motivo_ingreso_nombre,
            rv.fecha_hora_ingreso,
            rv.observacion,
            rv.con_vehiculo,
            rv.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_contacto_nombre,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_nombre,
            rv.id_recepcion_unidad,
            rv.fecha_hora_salida,
            rv.observacion_salida,
            rv.evidencias_ingreso,
            rv.evidencias_salida,
            rv.estado
        FROM
            recepcion_visita rv
        LEFT JOIN empleado emp_reg ON emp_reg.id = rv.id_empleado_registro
        LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
        LEFT JOIN empleado emp_aut ON emp_aut.id = rv.id_empleado_autoriza
        WHERE 1 = 1
        ';

        $params = [];

        // Filtro por fecha de ingreso (Rango)
        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND rv.fecha_hora_ingreso >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND rv.fecha_hora_ingreso <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'].' 23:59:59';
        }

        $sql .= ' ORDER BY rv.fecha_hora_ingreso DESC;';

        $results = DB::select($sql, $params);

        // Cargar visitantes y vehículos de forma masiva para evitar consultas N+1
        $visitIds = array_column($results, 'id');
        if (! empty($visitIds)) {
            $idsString = implode(',', array_map('intval', $visitIds));

            $detalles = DB::select("
                SELECT
                    rvd.id_recepcion_visita,
                    rvd.id AS id_detalle,
                    rvd.id_visitante,
                    rvd.id_visita_vehiculo,
                    vv.placa AS vehiculo_placa,
                    rvd.es_conductor,
                    v.nombre AS visitante_nombre,
                    v.apellido AS visitante_apellido,
                    v.dni AS visitante_dni,
                    v.telefono AS visitante_telefono,
                    rvd.url_foto_documento,
                    rvd.fecha_hora_salida,
                    rvd.observacion_salida,
                    COALESCE(rvd.estado, 'En Planta') AS estado,
                    rvd.evidencias_salida
                FROM
                    recepcion_visita_detalle rvd
                INNER JOIN visitante v ON v.id = rvd.id_visitante
                LEFT JOIN visita_vehiculo vv ON vv.id = rvd.id_visita_vehiculo
                WHERE
                    rvd.id_recepcion_visita IN ($idsString)
            ");

            $vehiculosLista = DB::select("
                SELECT
                    id,
                    id_recepcion_visita,
                    placa,
                    cantidad_personas,
                    url_foto
                FROM
                    visita_vehiculo
                WHERE
                    id_recepcion_visita IN ($idsString)
            ");

            $detallesAgrupados = [];
            foreach ($detalles as $det) {
                $detallesAgrupados[$det->id_recepcion_visita][] = (array) $det;
            }

            $vehiculosAgrupados = [];
            foreach ($vehiculosLista as $veh) {
                $vArr = (array) $veh;
                $val = $vArr['url_foto'] ?? null;
                if (! empty($val)) {
                    $decoded = json_decode($val, true);
                    $vArr['url_foto'] = is_array($decoded) ? $decoded : [$val];
                } else {
                    $vArr['url_foto'] = [];
                }
                $vehiculosAgrupados[$veh->id_recepcion_visita][] = $vArr;
            }

            // Asignar los visitantes y vehículos a cada recepción
            foreach ($results as $item) {
                $item->visitantes = $detallesAgrupados[$item->id] ?? [];
                $item->vehiculos = $vehiculosAgrupados[$item->id] ?? [];

                foreach ($item->visitantes as &$v) {
                    $val = $v['url_foto_documento'] ?? null;
                    if (! empty($val)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $v['url_foto_documento'] = $decoded;
                        } else {
                            $v['url_foto_documento'] = [$val];
                        }
                    } else {
                        $v['url_foto_documento'] = [];
                    }

                    $salida = $v['evidencias_salida'] ?? null;
                    if (! empty($salida)) {
                        $decoded = json_decode($salida, true);
                        $v['evidencias_salida'] = is_array($decoded) ? $decoded : [$salida];
                    } else {
                        $v['evidencias_salida'] = [];
                    }

                    if (isset($v['es_conductor'])) {
                        $v['es_conductor'] = (int) $v['es_conductor'] === 1;
                    }
                }
            }
        }

        return array_map(function ($item) {
            return (array) $item;
        }, $results);
    }

    /**
     * Obtener una recepción de visita por ID.
     */
    public static function get_recepcion_by_id(int $id): ?array
    {
        $sql = '
        SELECT
            rv.id,
            rv.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            rv.id_motivo_ingreso,
            mi.nombre AS motivo_ingreso_nombre,
            rv.fecha_hora_ingreso,
            rv.observacion,
            rv.con_vehiculo,
            rv.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_contacto_nombre,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_nombre,
            rv.id_recepcion_unidad,
            rv.fecha_hora_salida,
            rv.observacion_salida,
            rv.evidencias_ingreso,
            rv.evidencias_salida,
            rv.estado
        FROM
            recepcion_visita rv
        LEFT JOIN empleado emp_reg ON emp_reg.id = rv.id_empleado_registro
        LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
        LEFT JOIN empleado emp_aut ON emp_aut.id = rv.id_empleado_autoriza
        WHERE rv.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if (! $item) {
            return null;
        }

        // Cargar visitantes de esta recepción
        $detalles = DB::select("
            SELECT
                rvd.id AS id_detalle,
                rvd.id_visitante,
                rvd.id_visita_vehiculo,
                vv.placa AS vehiculo_placa,
                rvd.es_conductor,
                v.nombre AS visitante_nombre,
                v.apellido AS visitante_apellido,
                v.dni AS visitante_dni,
                v.telefono AS visitante_telefono,
                rvd.url_foto_documento,
                rvd.fecha_hora_salida,
                rvd.observacion_salida,
                COALESCE(rvd.estado, 'En Planta') AS estado,
                rvd.evidencias_salida
            FROM
                recepcion_visita_detalle rvd
            INNER JOIN visitante v ON v.id = rvd.id_visitante
            LEFT JOIN visita_vehiculo vv ON vv.id = rvd.id_visita_vehiculo
            WHERE
                rvd.id_recepcion_visita = :id
        ", ['id' => $id]);

        $vehiculosLista = DB::select("
            SELECT id, id_recepcion_visita, placa, cantidad_personas, url_foto
            FROM visita_vehiculo
            WHERE id_recepcion_visita = :id
        ", ['id' => $id]);

        $item->vehiculos = array_map(function ($veh) {
            $vArr = (array) $veh;
            $val = $vArr['url_foto'] ?? null;
            if (! empty($val)) {
                $decoded = json_decode($val, true);
                $vArr['url_foto'] = is_array($decoded) ? $decoded : [$val];
            } else {
                $vArr['url_foto'] = [];
            }

            return $vArr;
        }, $vehiculosLista);

        $item->visitantes = array_map(function ($det) {
            $v = (array) $det;
            $val = $v['url_foto_documento'] ?? null;
            if (! empty($val)) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    $v['url_foto_documento'] = $decoded;
                } else {
                    $v['url_foto_documento'] = [$val];
                }
            } else {
                $v['url_foto_documento'] = [];
            }

            $salida = $v['evidencias_salida'] ?? null;
            if (! empty($salida)) {
                $decoded = json_decode($salida, true);
                $v['evidencias_salida'] = is_array($decoded) ? $decoded : [$salida];
            } else {
                $v['evidencias_salida'] = [];
            }

            if (isset($v['es_conductor'])) {
                $v['es_conductor'] = (int) $v['es_conductor'] === 1;
            }

            return $v;
        }, $detalles);

        return (array) $item;
    }

    /**
     * Crear un nuevo registro de recepción de visita.
     */
    public static function crear_recepcion(array $data): int
    {
        $recepcion = RecepcionVisita::create([
            'id_empleado_registro' => $data['id_empleado_registro'],
            'id_motivo_ingreso' => $data['id_motivo_ingreso'],
            'observacion' => $data['observacion'] ?? null,
            'con_vehiculo' => (bool) ($data['con_vehiculo'] ?? false),
            'id_empleado_autoriza' => $data['id_empleado_autoriza'] ?? $data['id_empleado_contacto'] ?? null,
            'id_recepcion_unidad' => $data['id_recepcion_unidad'] ?? null,
            'evidencias_ingreso' => $data['evidencias_ingreso'] ?? null,
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'estado' => $data['estado'] ?? EstadoVisita::EnPlanta->value,
        ]);

        return $recepcion->id;
    }
}
