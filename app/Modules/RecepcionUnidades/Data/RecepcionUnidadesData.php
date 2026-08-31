<?php

namespace App\Modules\RecepcionUnidades\Data;

use App\Models\LoteMineral;
use App\Models\RecepcionUnidad;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\CorrelativoHelper;
use Illuminate\Support\Facades\DB;

class RecepcionUnidadesData
{
    /**
     * Obtener lista de recepciones de unidades con filtros dinámicos.
     */
    public static function get_recepciones(array $filters = [])
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            c.numero_licencia AS conductor_numero_licencia,
            ru.tipo_ingreso,
            ru.segunda_placa,
            ru.fecha_hora_ingreso,
            ru.evidencias,
            ru.observacion,
            ru.log_cambios,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida,
            ru.estado_pesaje,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.fecha_estimada_llegada,
            ru.guia_remitente,
            ru.guia_transportista,
            ru.es_recepcion_ficticia
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        LEFT JOIN empleado emp_rec ON emp_rec.id = ru.id_empleado_recepcion
        WHERE 1 = 1
        ';

        $params = [];

        // Filtro por fecha:
        // A las recepciones programadas sin confirmar (es_programacion = 1 AND fecha_hora_ingreso IS NULL) NO les afecta el filtro de fecha.
        // A las confirmadas (fecha_hora_ingreso IS NOT NULL) y recepciones directas SÍ les afecta el filtro de fecha.
        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND ((ru.es_programacion = 1 AND ru.fecha_hora_ingreso IS NULL) OR COALESCE(ru.fecha_hora_ingreso, ru.created_at) >= :fecha_inicio)';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND ((ru.es_programacion = 1 AND ru.fecha_hora_ingreso IS NULL) OR COALESCE(ru.fecha_hora_ingreso, ru.created_at) <= :fecha_fin)';
            $params['fecha_fin'] = $filters['fecha_fin'].' 23:59:59';
        }

        // Filtro por placa
        if (! empty($filters['placa'])) {
            $sql .= ' AND v.placa LIKE :placa';
            $params['placa'] = '%'.$filters['placa'].'%';
        }

        // Filtro por transportista (empresa de transporte)
        if (! empty($filters['id_empresa_transporte'])) {
            $sql .= ' AND ru.id_empresa_transporte = :id_empresa_transporte';
            $params['id_empresa_transporte'] = (int) $filters['id_empresa_transporte'];
        }

        // Filtro por condición de ingreso (tipo_ingreso)
        if (! empty($filters['tipo_ingreso'])) {
            $sql .= ' AND ru.tipo_ingreso = :tipo_ingreso';
            $params['tipo_ingreso'] = $filters['tipo_ingreso'];
        }

        $sql .= ' ORDER BY COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) DESC;';

        $results = DB::select($sql, $params);

        // Decodificar la columna JSON de evidencias manualmente para que coincida con lo esperado por Eloquent
        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = self::normalizar_evidencias($item->evidencias);
            }
            if (isset($item->log_cambios)) {
                $item->log_cambios = isset($item->log_cambios) ? (is_array($item->log_cambios) ? $item->log_cambios : (json_decode($item->log_cambios, true) ?? [])) : [];
            }
        }

        return $results;
    }

    /**
     * Obtener recepción específica por su ID.
     */
    public static function get_recepcion_by_id(int $id)
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            c.numero_licencia AS conductor_numero_licencia,
            ru.tipo_ingreso,
            ru.segunda_placa,
            ru.fecha_hora_ingreso,
            ru.evidencias,
            ru.observacion,
            ru.log_cambios,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida,
            ru.id_sucursal AS id_sucursal,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.estado_pesaje,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.fecha_estimada_llegada,
            ru.guia_remitente,
            ru.guia_transportista,
            ru.es_recepcion_ficticia
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        LEFT JOIN empleado emp_rec ON emp_rec.id = ru.id_empleado_recepcion
        WHERE ru.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = self::normalizar_evidencias($item->evidencias);
            }
            if (isset($item->log_cambios)) {
                $item->log_cambios = isset($item->log_cambios) ? (is_array($item->log_cambios) ? $item->log_cambios : (json_decode($item->log_cambios, true) ?? [])) : [];
            }
        }

        return $item ? (array) $item : null;
    }

    /**
     * Normalizar evidencias a la estructura IArchivo[] esperada por el frontend.
     */
    private static function normalizar_evidencias(mixed $evidencias): array
    {
        if (empty($evidencias)) {
            return [];
        }
        $arr = is_string($evidencias) ? (json_decode($evidencias, true) ?? []) : (array) $evidencias;
        if (! is_array($arr)) {
            return [];
        }
        return array_values(array_map(function ($item) {
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
        }, $arr));
    }

    /**
     * Crear un registro de recepción.
     */
    public static function crear_recepcion(array $data): int
    {
        $recepcion = RecepcionUnidad::create([
            'id_empleado_recepcion' => $data['id_empleado_registro'],
            'id_vehiculo' => $data['id_vehiculo'] ?? null,
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'],
            'id_conductor' => $data['id_conductor'],
            'id_proveedor_minero' => $data['id_proveedor_minero'] ?? null,
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Mineral',
            'segunda_placa' => $data['segunda_placa'] ?? null,
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'evidencias' => $data['evidencias'] ?? [],
            'observacion' => $data['observacion'] ?? null,
            'estado' => EstadoVisita::EnPlanta->value,
            'id_sucursal' => $data['id_sucursal'],
            'estado_pesaje' => EstadoPesaje::SinPesar->value,
            'guia_remitente' => $data['guia_remitente'] ?? null,
            'guia_transportista' => $data['guia_transportista'] ?? null,
        ]);

        return $recepcion->id;
    }

    /**
     * Listar lotes de una recepción de unidad (usa la tabla compartida lote_mineral).
     */
    public static function get_lotes(int $idRecepcionUnidad): array
    {
        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.correlativo,
            lm.numero_correlativo,
            lm.created_at AS fecha_hora_registro,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
            lm.peso_final,
            lm.fecha_hora_peso_final,
            lm.peso_neto,
            lm.peso_actual,
            lm.tiene_particion,
            lm.estado
        FROM lote_mineral lm
        WHERE lm.id_recepcion_unidad = :id_recepcion_unidad
        ORDER BY lm.numero_correlativo ASC
        ';

        $results = DB::select($sql, ['id_recepcion_unidad' => $idRecepcionUnidad]);

        foreach ($results as $item) {
            $item->id = (int) $item->id;
            $item->id_recepcion_unidad = (int) $item->id_recepcion_unidad;
            $item->numero_correlativo = (int) $item->numero_correlativo;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
        }

        return $results;
    }

    /**
     * Generar un nuevo lote (vacío) para la recepción indicada.
     * Reutiliza la tabla compartida lote_mineral para mantener un correlativo único anual.
     */
    public static function crear_lote(int $idRecepcionUnidad, int $idEmpleadoRegistro): ?LoteMineral
    {
        $recepcion = RecepcionUnidad::find($idRecepcionUnidad);
        if (! $recepcion) {
            return null;
        }

        $correlativoData = CorrelativoHelper::generar(
            tabla: 'lote_mineral',
            prefijo: 'LOT',
            filtros: [],
            longitudCeros: 5,
            reseteo: Periodo::Anual,
        );

        // Crear automáticamente el registro en ticket_balanza al generar el lote
        $ticketId = DB::table('ticket_balanza')->insertGetId([
            'created_at' => now(),
        ]);

        $lote = LoteMineral::create([
            'id_recepcion_unidad' => $idRecepcionUnidad,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'correlativo' => $correlativoData['correlativo'],
            'numero_correlativo' => $correlativoData['numero_correlativo'],
            'id_ticket_balanza' => $ticketId,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $lote;
    }

    /**
     * Eliminar un lote por su ID.
     */
    public static function eliminar_lote(int $loteId): bool
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return false;
        }

        if ($lote->id_ticket_balanza) {
            DB::table('ticket_balanza')->where('id', $lote->id_ticket_balanza)->delete();
        }

        $lote->delete();

        return true;
    }
}
