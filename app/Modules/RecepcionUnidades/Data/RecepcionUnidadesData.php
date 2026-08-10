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
            ru.tipo_carga,
            ru.segunda_placa,
            ru.fecha_hora_ingreso,
            ru.evidencias,
            ru.observacion,
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
            ru.serie_guia_remitente,
            ru.numero_guia_remitente,
            ru.serie_guia_transportista,
            ru.numero_guia_transportista
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

        // Filtro por fecha (Rango: usa fecha_hora_ingreso o fecha_estimada_llegada para programaciones)
        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) <= :fecha_fin';
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
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
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
            ru.tipo_carga,
            ru.segunda_placa,
            ru.fecha_hora_ingreso,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida,
            ru.id_sucursal AS id_sucursal,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.validacion_datos,
            ru.estado_pesaje,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.fecha_estimada_llegada,
            ru.serie_guia_remitente,
            ru.numero_guia_remitente,
            ru.serie_guia_transportista,
            ru.numero_guia_transportista
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
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            if (isset($item->validacion_datos)) {
                $item->validacion_datos = json_decode($item->validacion_datos, true) ?? [];
            }
        }

        return $item ? (array) $item : null;
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
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Unidad',
            'tipo_carga' => $data['tipo_carga'] ?? 'Granel',
            'segunda_placa' => $data['segunda_placa'] ?? null,
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'evidencias' => $data['evidencias'] ?? [],
            'observacion' => $data['observacion'] ?? null,
            'estado' => EstadoVisita::EnPlanta->value,
            'id_sucursal' => $data['id_sucursal'],
            'estado_pesaje' => EstadoPesaje::SinPesar->value,
            'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
            'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
            'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
            'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
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
            lm.observacion_peso_inicial,
            lm.peso_final,
            lm.fecha_hora_peso_final,
            lm.observacion_peso_final
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
            'numero' => null,
            'created_at' => now(),
        ]);
        DB::table('ticket_balanza')->where('id', $ticketId)->update(['numero' => $ticketId]);

        $lote = LoteMineral::create([
            'id_recepcion_unidad' => $idRecepcionUnidad,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'correlativo' => $correlativoData['correlativo'],
            'numero_correlativo' => $correlativoData['numero_correlativo'],
            'id_ticket_balanza' => $ticketId,
            'created_at' => now()->toDateTimeString(),
            'id_vehiculo' => $recepcion->id_vehiculo,
            'id_empresa_transporte' => $recepcion->id_empresa_transporte,
            'id_tipo_vehiculo' => $recepcion->id_tipo_vehiculo,
            'id_conductor' => $recepcion->id_conductor,
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
            ru.tipo_carga,
            ru.serie_guia_remitente,
            ru.numero_guia_remitente,
            ru.serie_guia_transportista,
            ru.numero_guia_transportista,
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
     * Obtener detalle completo de una programación + visita asociada + vehículos + visitantes.
     */
    public static function get_programacion_full(int $id): ?array
    {
        $programacion = self::get_recepcion_by_id($id);
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
            'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
            'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
            'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
            'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
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
