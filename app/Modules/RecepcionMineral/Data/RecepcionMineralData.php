<?php

namespace App\Modules\RecepcionMineral\Data;

use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoPesaje;
use Illuminate\Support\Facades\DB;

class RecepcionMineralData
{
    /**
     * Obtener el listado de recepciones para el módulo de mineral, filtradas por sucursal
     */
    public static function get_recepciones_mineral(array $filters)
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            ru.tipo_ingreso,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.estado_pesaje,
            ru.id_sucursal AS id_sucursal,
            ru.es_recepcion_ficticia,
            ru.es_programacion,
            ru.documentos_programacion
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        WHERE ru.id_sucursal = :id_sucursal
          AND ru.estado = "En Planta"
        ';

        $params = ['id_sucursal' => (int) $filters['id_sucursal']];

        if (! empty($filters['estado_pesaje'])) {
            $sql .= ' AND ru.estado_pesaje = :estado_pesaje';
            $params['estado_pesaje'] = $filters['estado_pesaje'];
        } else {
            $sql .= ' AND ru.estado_pesaje IN ("Sin Pesar", "En Proceso")';
        }

        $sql .= ' AND ru.es_recepcion_ficticia = 0';

        $sql .= ' ORDER BY ru.fecha_hora_ingreso DESC;';

        $results = DB::select($sql, $params);

        // IDs de recepciones tipo Despacho para cargar sus detalles de distribución.
        $despachoIds = [];
        foreach ($results as $r) {
            if (($r->tipo_ingreso ?? null) === 'Despacho de Mineral') {
                $despachoIds[] = (int) $r->id;
            }
        }
        $distribucionesPorRecepcion = self::get_distribucion_detalles_by_recepciones($despachoIds);

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->documentos_programacion = RecepcionUnidadesData::normalizar_documentos_programacion(
                $item->documentos_programacion ?? null,
            );
            // Obtener los lotes de esta recepción
            $item->lotes = self::get_lotes_by_recepcion($item->id);
            // Adjuntar los detalles de distribución (vacío si no es despacho o no hay detalles)
            $item->distribucion_detalles = $distribucionesPorRecepcion[(int) $item->id] ?? [];
        }

        return $results;
    }

    /**
     * Obtener los detalles de distribución para varias recepciones (solo aplica a recepciones tipo Despacho).
     *
     * @param  array<int, int>  $idRecepcionesUnidad
     * @return array<int, array<int, object>> indexado por id de recepción_unidad
     */
    public static function get_distribucion_detalles_by_recepciones(array $idRecepcionesUnidad): array
    {
        $out = [];
        if (empty($idRecepcionesUnidad)) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($idRecepcionesUnidad), '?'));
        $sql = "
            SELECT
                ddt.id,
                ddt.id_distribucion,
                ddt.id_despacho_detalle,
                ddt.numero_particion,
                ddt.peso_tomado,
                ddt.id_ticket_balanza,
                ddt.peso_tara,
                ddt.fecha_hora_peso_tara,
                ddt.peso_bruto,
                ddt.fecha_hora_peso_bruto,
                ddt.peso_neto,
                ddt.peso_tara_confirmado,
                ddt.peso_bruto_confirmado,
                ru.id AS recepcion_unidad_id,
                dd.id_lote_mineral AS detalle_id_lote_mineral,
                dd.id_blending AS detalle_id_blending,
                lm.correlativo AS lote_correlativo,
                lm.ley_humedad AS lote_ley_humedad,
                b.correlativo AS blending_correlativo,
                b.ley_humedad AS blending_ley_humedad,
                pr.razon_social AS proveedor_razon_social,
                tb.correlativo AS ticket_correlativo,
                d.correlativo AS despacho_correlativo,
                d.es_anulado AS despacho_es_anulado
            FROM distribucion_detalle ddt
            INNER JOIN distribucion di ON di.id = ddt.id_distribucion
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            INNER JOIN despacho d ON d.id = dd.id_despacho
            INNER JOIN recepcion_unidad ru ON ru.id_distribucion = di.id
            LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN blending b ON b.id = dd.id_blending
            LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
            LEFT JOIN ticket_balanza tb ON tb.id = ddt.id_ticket_balanza
            WHERE ru.id IN ($placeholders)
            ORDER BY ru.id, ddt.id ASC
        ";
        $rows = DB::select($sql, $idRecepcionesUnidad);

        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_distribucion = (int) $r->id_distribucion;
            $r->id_despacho_detalle = (int) $r->id_despacho_detalle;
            $r->numero_particion = $r->numero_particion !== null ? (int) $r->numero_particion : null;
            $r->peso_tomado = (float) $r->peso_tomado;
            $r->id_ticket_balanza = $r->id_ticket_balanza !== null ? (int) $r->id_ticket_balanza : null;
            $r->peso_tara = $r->peso_tara !== null ? (float) $r->peso_tara : null;
            $r->fecha_hora_peso_tara = $r->fecha_hora_peso_tara !== null ? (string) $r->fecha_hora_peso_tara : null;
            $r->peso_bruto = $r->peso_bruto !== null ? (float) $r->peso_bruto : null;
            $r->fecha_hora_peso_bruto = $r->fecha_hora_peso_bruto !== null ? (string) $r->fecha_hora_peso_bruto : null;
            $r->peso_neto = $r->peso_neto !== null ? (float) $r->peso_neto : null;
            $r->peso_tara_confirmado = (bool) $r->peso_tara_confirmado;
            $r->peso_bruto_confirmado = (bool) $r->peso_bruto_confirmado;
            $r->lote_ley_humedad = $r->lote_ley_humedad !== null ? (float) $r->lote_ley_humedad : null;
            $r->blending_ley_humedad = $r->blending_ley_humedad !== null ? (float) $r->blending_ley_humedad : null;
            $r->ticket_correlativo = $r->ticket_correlativo !== null ? (string) $r->ticket_correlativo : null;
            $r->despacho_correlativo = $r->despacho_correlativo !== null ? (string) $r->despacho_correlativo : null;
            $r->despacho_es_anulado = (bool) $r->despacho_es_anulado;
            $r->detalle_id_lote_mineral = $r->detalle_id_lote_mineral !== null ? (int) $r->detalle_id_lote_mineral : null;
            $r->detalle_id_blending = $r->detalle_id_blending !== null ? (int) $r->detalle_id_blending : null;
            $r->recepcion_unidad_id = (int) $r->recepcion_unidad_id;

            $out[$r->recepcion_unidad_id][] = $r;
        }

        return $out;
    }

    /**
     * Obtener los lotes de mineral asociados a una recepción de unidad
     */
    public static function get_lotes_by_recepcion(int $recepcionUnidadId): array
    {
        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.id_empresa,
            emp_tit.razon_social AS empresa_nombre,
            lm.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            p.telefono AS proveedor_telefono,
            lm.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            lm.id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            lm.correlativo,
            lm.numero_correlativo,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.log_cambios,
            lm.evidencias,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
                        lm.peso_final,
            lm.fecha_hora_peso_final,
                        lm.peso_neto,
            lm.peso_actual,
            lm.tiene_particion,
            lm.estado,
            ru.id_vehiculo,
            v_lote.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et_lote.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv_lote.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c_lote.nombre, " ", c_lote.apellido) AS conductor_nombre_completo,
            c_lote.dni AS conductor_dni,
            lm.created_at
        FROM
            lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN empleado emp ON emp.id = lm.id_empleado_registro
        LEFT JOIN empresa emp_tit ON emp_tit.id = lm.id_empresa
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN vehiculo v_lote ON v_lote.id = ru.id_vehiculo
        LEFT JOIN empresa_transporte et_lote ON et_lote.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv_lote ON tv_lote.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c_lote ON c_lote.id = ru.id_conductor
        WHERE
            lm.id_recepcion_unidad = :recepcion_unidad_id
            AND (lm.estado IS NULL OR lm.estado != "Eliminado")
        ORDER BY lm.correlativo ASC
        ';

        $results = DB::select($sql, ['recepcion_unidad_id' => $recepcionUnidadId]);

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->id_empresa = $item->id_empresa !== null ? (int) $item->id_empresa : null;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->peso_actual = $item->peso_actual !== null ? (float) $item->peso_actual : null;
            $item->tiene_particion = $item->tiene_particion !== null ? (bool) $item->tiene_particion : false;
            $item->id_vehiculo = $item->id_vehiculo !== null ? (int) $item->id_vehiculo : null;
            $item->id_empresa_transporte = $item->id_empresa_transporte !== null ? (int) $item->id_empresa_transporte : null;
            $item->id_tipo_vehiculo = $item->id_tipo_vehiculo !== null ? (int) $item->id_tipo_vehiculo : null;
            $item->id_conductor = $item->id_conductor !== null ? (int) $item->id_conductor : null;
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];
        }

        return $results;
    }

    /**
     * Obtener un lote específico por su ID
     */
    public static function get_lote_by_id(int $id)
    {
        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.id_empresa,
            emp_tit.razon_social AS empresa_nombre,
            lm.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            p.telefono AS proveedor_telefono,
            lm.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            lm.id_zona_origen,
            zo.nombre AS zona_origen_nombre,
            lm.correlativo,
            lm.numero_correlativo,
            lm.numero_contacto,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.condicion_ingreso,
            lm.log_cambios,
            lm.evidencias,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
                        lm.peso_final,
            lm.fecha_hora_peso_final,
                        lm.peso_neto,
            lm.peso_actual,
            lm.tiene_particion,
            lm.estado,
            ru.id_vehiculo,
            v_lote.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et_lote.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv_lote.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c_lote.nombre, " ", c_lote.apellido) AS conductor_nombre_completo,
            c_lote.dni AS conductor_dni,
            c_lote.numero_licencia AS conductor_licencia,
            lm.created_at
        FROM
            lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN empleado emp ON emp.id = lm.id_empleado_registro
        LEFT JOIN empresa emp_tit ON emp_tit.id = lm.id_empresa
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN vehiculo v_lote ON v_lote.id = ru.id_vehiculo
        LEFT JOIN empresa_transporte et_lote ON et_lote.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv_lote ON tv_lote.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c_lote ON c_lote.id = ru.id_conductor
        WHERE
            lm.id = :id
        LIMIT 1
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->id_empresa = $item->id_empresa !== null ? (int) $item->id_empresa : null;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->peso_actual = $item->peso_actual !== null ? (float) $item->peso_actual : null;
            $item->tiene_particion = $item->tiene_particion !== null ? (bool) $item->tiene_particion : false;
            $item->id_vehiculo = $item->id_vehiculo !== null ? (int) $item->id_vehiculo : null;
            $item->id_empresa_transporte = $item->id_empresa_transporte !== null ? (int) $item->id_empresa_transporte : null;
            $item->id_tipo_vehiculo = $item->id_tipo_vehiculo !== null ? (int) $item->id_tipo_vehiculo : null;
            $item->id_conductor = $item->id_conductor !== null ? (int) $item->id_conductor : null;
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener una recepción de unidad específica por su ID con sus lotes
     */
    public static function get_recepcion_by_id_with_lotes(int $id)
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            NULL AS vehiculo_serie,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            ru.tipo_ingreso,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.evidencias,
            ru.observacion,
            ru.estado,
            ru.estado_salida,
            ru.estado_pesaje,
            ru.id_sucursal AS id_sucursal,
            ru.es_recepcion_ficticia,
            ru.es_programacion,
            ru.documentos_programacion
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        WHERE ru.id = :id
        LIMIT 1
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = json_decode($item->evidencias, true) ?? [];
            }
            $item->documentos_programacion = RecepcionUnidadesData::normalizar_documentos_programacion(
                $item->documentos_programacion ?? null,
            );
            $item->lotes = self::get_lotes_by_recepcion($id);
            if (($item->tipo_ingreso ?? null) === 'Despacho de Mineral') {
                $detalles = self::get_distribucion_detalles_by_recepciones([$id]);
                $item->distribucion_detalles = $detalles[$id] ?? [];
            } else {
                $item->distribucion_detalles = [];
            }

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener el resumen de balanza con UNION ALL de:
     *   - Bloque A: lote_mineral pesados en recepciones (Recepción de Mineral).
     *   - Bloque B: distribucion_detalle pesadas en despachos (Despacho de Mineral).
     *
     * El campo `tipo_pesaje` (`LOTE_RECEPCION` | `DISTRIBUCION_DETALLE`) discrimina la fila.
     * El campo `origen_tipo` (`LOTE` | `BLENDING`) sólo aplica a Bloque B e indica de qué se
     * generó el despacho_detalle (id_lote_mineral vs id_blending).
     */
    public static function get_resumen_balanza(array $filters): array
    {
        $estadoPesado = EstadoPesaje::Pesado->value;
        $idSucursal = (int) $filters['id_sucursal'];

        // ────────────────────────────────────────────────────────────────────
        // Bloque A: LOTE_RECEPCION (lote_mineral pesados al recibir mineral)
        // ────────────────────────────────────────────────────────────────────
        $sqlA = "
        SELECT
            'LOTE_RECEPCION'                            AS tipo_pesaje,
            'LOTE'                                     AS origen_tipo,
            NULL                                       AS id_distribucion_detalle,
            NULL                                       AS id_distribucion,
            NULL                                       AS id_despacho,
            NULL                                       AS id_despacho_detalle,
            NULL                                       AS despacho_correlativo,
            NULL                                       AS numero_particion,
            NULL                                       AS origen_correlativo,
            NULL                                       AS id_lote_origen,
            NULL                                       AS id_blending_origen,
            CONCAT('L-', lm.id)                         AS id_row,

            lm.id                                      AS id_lote,
            lm.correlativo                             AS lote_correlativo,
            lm.numero_correlativo                      AS lote_numero_correlativo,
            lm.numero_contacto                         AS lote_numero_contacto,
            lm.tipo_producto                           AS lote_tipo_producto,
            lm.tipo_mineral                            AS lote_tipo_mineral,
            lm.condicion_ingreso                       AS lote_condicion_ingreso,
            lm.evidencias                              AS lote_evidencias,
            lm.log_cambios                             AS lote_log_cambios,
            lm.created_at                              AS lote_fecha_creacion,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
                        lm.peso_final,
            lm.fecha_hora_peso_final,
                        lm.peso_neto,
            -- Aliases canónicos: para RECEPCIÓN el camión llega cargado (BRUTO = inicial)
            -- y retorna vacío (TARA = final).
            lm.peso_inicial                            AS peso_bruto,
            lm.fecha_hora_peso_inicial                 AS fecha_hora_peso_bruto,
            lm.peso_final                              AS peso_tara,
            lm.fecha_hora_peso_final                   AS fecha_hora_peso_tara,
            lm.id_ticket_balanza,
            lm.created_at                              AS fecha_pesaje,

            ru.tipo_ingreso,
            ru.id                                      AS id_recepcion_unidad,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.id_vehiculo_carreta,
            vc.placa                                   AS vehiculo_carreta_placa,
            ru.estado_pesaje,

            ru.id_vehiculo,
            v.placa                                    AS vehiculo_placa,

            ru.id_empresa_transporte,
            et.razon_social                            AS empresa_transporte_razon_social,

            ru.id_tipo_vehiculo,
            tv.nombre                                  AS tipo_vehiculo_nombre,

            p.id                                       AS id_proveedor,
            p.razon_social                             AS proveedor_razon_social,

            zo.id                                      AS id_zona_origen,
            zo.nombre                                  AS zona_origen_nombre,

            ru.id_conductor,
            CONCAT(c.nombre, ' ', c.apellido)          AS conductor_nombre_completo,
            c.dni                                      AS conductor_dni,
            c.numero_licencia                          AS conductor_licencia,

            CONCAT(emp_reg.nombre, ' ', emp_reg.apellido) AS empleado_registro_nombre,

            NULL                                       AS observacion_peso_inicial,
            NULL                                       AS observacion_peso_final
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        LEFT JOIN vehiculo v          ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc         ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv    ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN proveedor p        ON p.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo     ON zo.id = lm.id_zona_origen
        LEFT JOIN conductor c        ON c.id = ru.id_conductor
        LEFT JOIN empleado emp_reg   ON emp_reg.id = lm.id_empleado_registro
        WHERE ru.id_sucursal = :id_sucursal_a
          AND ru.estado_pesaje = :estado_pesaje_a
          AND ru.tipo_ingreso = 'Recepción de Mineral'
          AND (lm.estado IS NULL OR lm.estado != 'Eliminado')
        ";

        $paramsA = [
            'id_sucursal_a' => $idSucursal,
            'estado_pesaje_a' => $estadoPesado,
        ];

        if (! empty($filters['fecha_inicio'])) {
            $sqlA .= ' AND DATE(lm.created_at) >= :fecha_inicio_a';
            $paramsA['fecha_inicio_a'] = $filters['fecha_inicio'];
        }
        if (! empty($filters['fecha_fin'])) {
            $sqlA .= ' AND DATE(lm.created_at) <= :fecha_fin_a';
            $paramsA['fecha_fin_a'] = $filters['fecha_fin'];
        }
        if (! empty($filters['tipo_ingreso']) && $filters['tipo_ingreso'] === 'Despacho de Mineral') {
            $sqlA .= ' AND 1=0'; // filtro excluye este bloque
        }
        if (! empty($filters['placa'])) {
            $sqlA .= ' AND v.placa = :placa_a';
            $paramsA['placa_a'] = $filters['placa'];
        }
        if (! empty($filters['lote_correlativo'])) {
            $sqlA .= ' AND lm.correlativo LIKE :lote_correlativo_a';
            $paramsA['lote_correlativo_a'] = '%'.$filters['lote_correlativo'].'%';
        }
        if (! empty($filters['id_empresa_transporte'])) {
            $sqlA .= ' AND ru.id_empresa_transporte = :id_empresa_transporte_a';
            $paramsA['id_empresa_transporte_a'] = (int) $filters['id_empresa_transporte'];
        }

        // ────────────────────────────────────────────────────────────────────
        // Bloque B: DISTRIBUCION_DETALLE (despachos ya pesados)
        //   origen_tipo = 'LOTE' cuando dd.id_lote_mineral IS NOT NULL
        //   origen_tipo = 'BLENDING' cuando dd.id_blending IS NOT NULL
        // ────────────────────────────────────────────────────────────────────
        $sqlB = "
        SELECT
            'DISTRIBUCION_DETALLE'                     AS tipo_pesaje,
            CASE WHEN dd.id_lote_mineral IS NOT NULL THEN 'LOTE' ELSE 'BLENDING' END AS origen_tipo,
            ddt.id                                     AS id_distribucion_detalle,
            d.id                                       AS id_distribucion,
            ds.id                                      AS id_despacho,
            dd.id                                      AS id_despacho_detalle,
            ds.correlativo                             AS despacho_correlativo,
            ddt.numero_particion,
            COALESCE(lm_origen.correlativo, b_origen.correlativo) AS origen_correlativo,
            dd.id_lote_mineral                         AS id_lote_origen,
            dd.id_blending                             AS id_blending_origen,
            CONCAT('D-', ddt.id)                       AS id_row,

            NULL                                       AS id_lote,
            NULL                                       AS lote_correlativo,
            NULL                                       AS lote_numero_correlativo,
            NULL                                       AS lote_numero_contacto,
            NULL                                       AS lote_tipo_producto,
            NULL                                       AS lote_tipo_mineral,
            NULL                                       AS lote_condicion_ingreso,
            NULL                                       AS lote_evidencias,
            NULL                                       AS lote_log_cambios,
            d.created_at                               AS lote_fecha_creacion,
            -- Aliases LOTE-style: el modal de edición los consume (peso_inicial/final).
            ddt.peso_tara                              AS peso_inicial,
            ddt.fecha_hora_peso_tara                   AS fecha_hora_peso_inicial,
            ddt.peso_bruto                             AS peso_final,
            ddt.fecha_hora_peso_bruto                  AS fecha_hora_peso_final,
            ddt.peso_neto,
            -- Aliases canónicos (orden debe coincidir con Bloque A: peso_bruto antes de peso_tara)
            ddt.peso_bruto                             AS peso_bruto,
            ddt.fecha_hora_peso_bruto                  AS fecha_hora_peso_bruto,
            ddt.peso_tara                              AS peso_tara,
            ddt.fecha_hora_peso_tara                   AS fecha_hora_peso_tara,
            ddt.id_ticket_balanza,
            COALESCE(ddt.fecha_hora_peso_bruto, ddt.fecha_hora_peso_tara, d.created_at) AS fecha_pesaje,

            ru.tipo_ingreso,
            ru.id                                      AS id_recepcion_unidad,
            ru.fecha_hora_ingreso,
            ru.fecha_hora_salida,
            ru.id_vehiculo_carreta,
            vc.placa                                   AS vehiculo_carreta_placa,
            ru.estado_pesaje,

            ru.id_vehiculo,
            v.placa                                    AS vehiculo_placa,

            ru.id_empresa_transporte,
            et.razon_social                            AS empresa_transporte_razon_social,

            ru.id_tipo_vehiculo,
            tv.nombre                                  AS tipo_vehiculo_nombre,

            p_origen.id                                AS id_proveedor,
            p_origen.razon_social                      AS proveedor_razon_social,

            zo_origen.id                               AS id_zona_origen,
            zo_origen.nombre                           AS zona_origen_nombre,

            ru.id_conductor,
            CONCAT(c.nombre, ' ', c.apellido)          AS conductor_nombre_completo,
            c.dni                                      AS conductor_dni,
            c.numero_licencia                          AS conductor_licencia,

            CONCAT(emp_reg.nombre, ' ', emp_reg.apellido) AS empleado_registro_nombre,

            NULL                                       AS observacion_peso_inicial,
            NULL                                       AS observacion_peso_final
        FROM distribucion_detalle ddt
        INNER JOIN distribucion d            ON d.id  = ddt.id_distribucion
        INNER JOIN despacho ds               ON ds.id = d.id_despacho
        INNER JOIN despacho_detalle dd       ON dd.id = ddt.id_despacho_detalle
        INNER JOIN recepcion_unidad ru       ON ru.id_distribucion = d.id
        LEFT JOIN vehiculo v                 ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc                ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN empresa_transporte et      ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv           ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN lote_mineral lm_origen     ON lm_origen.id = dd.id_lote_mineral
        LEFT JOIN blending b_origen          ON b_origen.id = dd.id_blending
        LEFT JOIN proveedor p_origen         ON p_origen.id = lm_origen.id_proveedor_minero
        LEFT JOIN zona_origen zo_origen      ON zo_origen.id = lm_origen.id_zona_origen
        LEFT JOIN conductor c                ON c.id = ru.id_conductor
        LEFT JOIN empleado emp_reg           ON emp_reg.id = ru.id_empleado_recepcion
        WHERE ru.id_sucursal = :id_sucursal_b
          AND ru.estado_pesaje = :estado_pesaje_b
          AND ru.tipo_ingreso = 'Despacho de Mineral'
          AND ddt.peso_neto IS NOT NULL
          AND ds.es_anulado = 0
        ";

        $paramsB = [
            'id_sucursal_b' => $idSucursal,
            'estado_pesaje_b' => $estadoPesado,
        ];

        if (! empty($filters['fecha_inicio'])) {
            $sqlB .= ' AND DATE(COALESCE(ddt.fecha_hora_peso_bruto, ddt.fecha_hora_peso_tara, d.created_at)) >= :fecha_inicio_b';
            $paramsB['fecha_inicio_b'] = $filters['fecha_inicio'];
        }
        if (! empty($filters['fecha_fin'])) {
            $sqlB .= ' AND DATE(COALESCE(ddt.fecha_hora_peso_bruto, ddt.fecha_hora_peso_tara, d.created_at)) <= :fecha_fin_b';
            $paramsB['fecha_fin_b'] = $filters['fecha_fin'];
        }
        if (! empty($filters['tipo_ingreso']) && $filters['tipo_ingreso'] === 'Recepción de Mineral') {
            $sqlB .= ' AND 1=0'; // filtro excluye este bloque
        }
        if (! empty($filters['placa'])) {
            $sqlB .= ' AND v.placa = :placa_b';
            $paramsB['placa_b'] = $filters['placa'];
        }
        if (! empty($filters['lote_correlativo'])) {
            // Aplica a la columna de origen del despacho_detalle (sea lote o blending).
            $sqlB .= ' AND (lm_origen.correlativo LIKE :lote_correlativo_b1 OR b_origen.correlativo LIKE :lote_correlativo_b2)';
            $paramsB['lote_correlativo_b1'] = '%'.$filters['lote_correlativo'].'%';
            $paramsB['lote_correlativo_b2'] = '%'.$filters['lote_correlativo'].'%';
        }
        if (! empty($filters['id_empresa_transporte'])) {
            $sqlB .= ' AND ru.id_empresa_transporte = :id_empresa_transporte_b';
            $paramsB['id_empresa_transporte_b'] = (int) $filters['id_empresa_transporte'];
        }

        // Unificar params y SQL
        $sql = $sqlA."\n UNION ALL \n".$sqlB."\n ORDER BY fecha_pesaje DESC";
        $params = array_merge($paramsA, $paramsB);

        $results = DB::select($sql, $params);

        foreach ($results as $item) {
            // JSON decode condicional
            if (isset($item->lote_evidencias) && is_string($item->lote_evidencias)) {
                $item->lote_evidencias = json_decode($item->lote_evidencias, true) ?? [];
            } else {
                $item->lote_evidencias = $item->lote_evidencias ?? null;
            }
            if (isset($item->lote_log_cambios) && is_string($item->lote_log_cambios)) {
                $item->lote_log_cambios = json_decode($item->lote_log_cambios, true) ?? [];
            } else {
                $item->lote_log_cambios = $item->lote_log_cambios ?? null;
            }

            // Casteos numéricos
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->id_recepcion_unidad = (int) $item->id_recepcion_unidad;
            $item->id_ticket_balanza = $item->id_ticket_balanza !== null ? (int) $item->id_ticket_balanza : null;
            $item->id_despacho = $item->id_despacho !== null ? (int) $item->id_despacho : null;
            $item->id_despacho_detalle = $item->id_despacho_detalle !== null ? (int) $item->id_despacho_detalle : null;
            $item->id_distribucion = $item->id_distribucion !== null ? (int) $item->id_distribucion : null;
            $item->id_distribucion_detalle = $item->id_distribucion_detalle !== null ? (int) $item->id_distribucion_detalle : null;
            $item->id_lote_origen = $item->id_lote_origen !== null ? (int) $item->id_lote_origen : null;
            $item->id_blending_origen = $item->id_blending_origen !== null ? (int) $item->id_blending_origen : null;
            $item->numero_particion = $item->numero_particion !== null ? (int) $item->numero_particion : null;
        }

        return $results;
    }

    /**
     * Obtener metadatos únicos para los filtros de la sucursal
     */
    public static function get_resumen_filtros(int $idSucursal): array
    {
        // 1. Obtener lotes de la sucursal
        $lotesSql = '
        SELECT DISTINCT lm.id, lm.correlativo
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        WHERE ru.id_sucursal = :id_sucursal
          AND (lm.estado IS NULL OR lm.estado != "Eliminado")
        ORDER BY lm.correlativo DESC;
        ';
        $lotes = DB::select($lotesSql, ['id_sucursal' => $idSucursal]);

        // 2. Obtener vehículos de la sucursal (de la recepción de unidad)
        $vehiculosSql = '
        SELECT DISTINCT v.id, v.placa
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        WHERE ru.id_sucursal = :id_sucursal
        ORDER BY v.placa ASC;
        ';
        $vehiculos = DB::select($vehiculosSql, ['id_sucursal' => $idSucursal]);

        // 3. Obtener condiciones de ingreso de la sucursal
        $condicionesSql = '
        SELECT DISTINCT ru.tipo_ingreso
        FROM recepcion_unidad ru
        WHERE ru.id_sucursal = :id_sucursal
          AND ru.tipo_ingreso IS NOT NULL
          AND ru.tipo_ingreso != ""
        ORDER BY ru.tipo_ingreso ASC;
        ';
        $condiciones = DB::select($condicionesSql, ['id_sucursal' => $idSucursal]);

        return [
            'lotes' => $lotes,
            'vehiculos' => $vehiculos,
            'condiciones_ingreso' => array_column($condiciones, 'tipo_ingreso'),
        ];
    }

    /**
     * Obtener la información completa para el Ticket de Balanza en formato PDF
     */
    public static function get_ticket_balanza_info(int $loteId)
    {
        $sql = "
        SELECT
            lot.id AS id_lote,
            lot.correlativo AS correlativo,
            tb.id AS ticket_numero,
            tb.correlativo AS ticket_correlativo,
            tb.created_at AS fecha_impresion,

            CASE WHEN du.unidad_id IS NOT NULL THEN veh_unidad.placa ELSE vh.placa END AS placa,

            lot.tipo_producto,
            lot.tipo_mineral,

            gui.guia_remitente AS guia_remision,

            pr.ruc AS ruc_proveedor,
            pr.razon_social AS proveedor,

            CASE WHEN du.unidad_id IS NOT NULL THEN
                CONCAT(COALESCE(cnd_unidad.apellido, ''), ' ', COALESCE(cnd_unidad.nombre, ''))
            ELSE
                CONCAT(COALESCE(cnd.apellido, ''), ' ', COALESCE(cnd.nombre, ''))
            END AS conductor,
            CASE WHEN du.unidad_id IS NOT NULL THEN cnd_unidad.numero_licencia ELSE cnd.numero_licencia END AS licencia_conductor,

            CASE WHEN du.unidad_id IS NOT NULL THEN emp_unidad.razon_social ELSE emp.razon_social END AS empresa_transporte,

            CASE WHEN gui.sin_guia_transportista = 1 OR gui.guia_transportista IS NULL OR gui.guia_transportista = '' THEN NULL ELSE gui.guia_transportista END AS guia_transporte,

            -- sucursal (Destino)
            sc.nombre AS nombre_sucursal,
            sc.direccion AS direccion_sucursal,
            dep_sc.nombre AS departamento_sucursal,
            prv_sc.nombre AS provincia_sucursal,
            dis_sc.nombre AS distrito_sucursal,

            -- ORIGEN: concesion de la guia; si la guia no trae, usar la del proveedor
            cns_origen.nombre          AS nombre_concesion,
            cns_origen.codigo_reinfo   AS codigo_reinfo_concesion,
            dep_cori.nombre            AS departamento_concesion,
            prv_cori.nombre            AS provincia_concesion,
            dis_cori.nombre            AS distrito_concesion,
            zo.nombre AS zona_origen_nombre,

            -- observaciones
            NULL AS observacion_peso_inicial,
            NULL AS observacion_peso_final,

            -- pesos y sus fechas (priorizar distribucion_detalle si existe pesaje, sino caer al lote)
            COALESCE(ddt.peso_bruto, lot.peso_inicial) AS peso_bruto,
            COALESCE(ddt.fecha_hora_peso_bruto, lot.fecha_hora_peso_inicial) AS fecha_hora_peso_bruto,
            COALESCE(ddt.peso_tara, lot.peso_final) AS peso_tara,
            COALESCE(ddt.fecha_hora_peso_tara, lot.fecha_hora_peso_final) AS fecha_hora_peso_tara,
            COALESCE(ddt.peso_neto, lot.peso_neto) AS peso_neto,

            -- Datos de distribución (última distribución activa del lote)
            desp_info.despacho_correlativo AS despacho_correlativo,
            desp_info.planta_destino_nombre AS planta_destino_nombre,

            -- operador
            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador

        FROM lote_mineral lot
        LEFT JOIN ticket_balanza tb ON tb.id = lot.id_ticket_balanza
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lot.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        LEFT JOIN recepcion_unidad rec ON rec.id = lot.id_recepcion_unidad
        LEFT JOIN vehiculo vh ON vh.id = COALESCE(gui.id_vehiculo, rec.id_vehiculo)
        LEFT JOIN proveedor pr ON pr.id = COALESCE(gui.id_proveedor, lot.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(gui.id_conductor, rec.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(gui.id_empresa_transporte, rec.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(gui.id_sucursal, rec.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
            LEFT JOIN concesion_proveedor cp
                ON cp.id_proveedor = pr.id
            LEFT JOIN concesion cns_origen
                ON cns_origen.id = COALESCE(
                    gui.id_concesion,
                    (
                        SELECT cp2.id_concesion
                        FROM concesion_proveedor cp2
                        WHERE cp2.id_proveedor = pr.id
                        ORDER BY cp2.id ASC
                        LIMIT 1
                    )
                )
            LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
            LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
            LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
            LEFT JOIN zona_origen zo ON zo.id = lot.id_zona_origen
        -- Último pesaje con ticket del lote (de distribución si existe)
        LEFT JOIN (
            SELECT dd.id_lote_mineral, ddt.peso_tara, ddt.fecha_hora_peso_tara, ddt.peso_bruto, ddt.fecha_hora_peso_bruto, ddt.peso_neto
            FROM distribucion_detalle ddt
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            WHERE dd.id_lote_mineral = ? AND ddt.id_ticket_balanza IS NOT NULL
            ORDER BY ddt.id DESC
            LIMIT 1
        ) ddt ON ddt.id_lote_mineral = lot.id
        -- Última distribución activa del lote (para contexto de despacho)
        LEFT JOIN (
            SELECT
                dd.id_lote_mineral,
                d.correlativo AS despacho_correlativo,
                pd.razon_social AS planta_destino_nombre
            FROM distribucion_detalle ddt
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            INNER JOIN distribucion di ON di.id = ddt.id_distribucion
            INNER JOIN despacho d ON d.id = di.id_despacho
            LEFT JOIN planta_destino pd ON pd.id = d.id_planta_destino
            WHERE dd.id_lote_mineral = ?
            ORDER BY di.created_at DESC, ddt.id DESC
            LIMIT 1
        ) desp_info ON desp_info.id_lote_mineral = lot.id
        -- Unidad del despacho (cuando el pesaje fue vía distribución): lleva la placa,
        -- transportista y conductor que el usuario ingresó al pesar, no los del lote original.
        LEFT JOIN (
            SELECT
                dd.id_lote_mineral,
                ru_unidad.id AS unidad_id,
                ru_unidad.id_vehiculo AS unidad_vehiculo_id,
                ru_unidad.id_empresa_transporte AS unidad_emp_trans_id,
                ru_unidad.id_conductor AS unidad_conductor_id
            FROM distribucion_detalle ddt_unidad
            INNER JOIN distribucion di_unidad ON di_unidad.id = ddt_unidad.id_distribucion
            INNER JOIN despacho_detalle dd ON dd.id = ddt_unidad.id_despacho_detalle
            INNER JOIN recepcion_unidad ru_unidad ON ru_unidad.id_distribucion = di_unidad.id
            WHERE dd.id_lote_mineral = ?
              AND ddt_unidad.id_ticket_balanza IS NOT NULL
            ORDER BY ddt_unidad.id DESC
            LIMIT 1
        ) du ON du.id_lote_mineral = lot.id
        LEFT JOIN vehiculo veh_unidad ON veh_unidad.id = du.unidad_vehiculo_id
        LEFT JOIN empresa_transporte emp_unidad ON emp_unidad.id = du.unidad_emp_trans_id
        LEFT JOIN conductor cnd_unidad ON cnd_unidad.id = du.unidad_conductor_id
        LEFT JOIN empleado eml ON eml.id = lot.id_empleado_registro
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE lot.id = ?
        LIMIT 1
        ";

        $item = DB::selectOne($sql, [$loteId, $loteId, $loteId, $loteId]);
        if ($item) {
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->despacho_correlativo = $item->despacho_correlativo !== null ? (string) $item->despacho_correlativo : null;
            $item->planta_destino_nombre = $item->planta_destino_nombre !== null ? (string) $item->planta_destino_nombre : null;

            return (array) $item;
        }

        return null;
    }

    /**
     * Obtener la información completa para el Ticket de Balanza en formato PDF partiendo
     * de una `distribucion_detalle` (filas del Bloque B del Resumen de Balanza).
     *
     * Funciona tanto cuando el despacho_detalle proviene de un lote_mineral como de un
     * blending. El `correlativo` mostrado es el del origen (lote o blending).
     */
    public static function get_ticket_balanza_info_by_distribucion_detalle(int $idDistribucionDetalle)
    {
        $sql = "
        SELECT
            ddt.id AS id_distribucion_detalle,

            COALESCE(lm.id, b.id)                    AS id_lote,
            COALESCE(lm.correlativo, b.correlativo)  AS correlativo,
            CASE WHEN lm.id IS NOT NULL THEN 'LOTE' ELSE 'BLENDING' END AS origen_tipo,

            tb.id AS ticket_numero,
            tb.correlativo AS ticket_correlativo,
            tb.created_at AS fecha_impresion,

            veh.placa AS placa,

            lm.tipo_producto,
            lm.tipo_mineral,

            gui.guia_remitente AS guia_remision,

            pr.ruc AS ruc_proveedor,
            pr.razon_social AS proveedor,

            CONCAT(COALESCE(cnd.apellido, ''), ' ', COALESCE(cnd.nombre, '')) AS conductor,
            cnd.numero_licencia AS licencia_conductor,

            emp.razon_social AS empresa_transporte,

            CASE WHEN gui.sin_guia_transportista = 1 OR gui.guia_transportista IS NULL OR gui.guia_transportista = '' THEN NULL ELSE gui.guia_transportista END AS guia_transporte,

            sc.nombre AS nombre_sucursal,
            sc.direccion AS direccion_sucursal,
            dep_sc.nombre AS departamento_sucursal,
            prv_sc.nombre AS provincia_sucursal,
            dis_sc.nombre AS distrito_sucursal,

            cns_origen.nombre          AS nombre_concesion,
            cns_origen.codigo_reinfo   AS codigo_reinfo_concesion,
            dep_cori.nombre            AS departamento_concesion,
            prv_cori.nombre            AS provincia_concesion,
            dis_cori.nombre            AS distrito_concesion,
            zo.nombre AS zona_origen_nombre,

            NULL AS observacion_peso_inicial,
            NULL AS observacion_peso_final,

            ddt.peso_bruto,
            ddt.fecha_hora_peso_bruto,
            ddt.peso_tara,
            ddt.fecha_hora_peso_tara,
            ddt.peso_neto,

            d.correlativo AS despacho_correlativo,
            pd.razon_social AS planta_destino_nombre,

            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador,

            ddt.numero_particion

        FROM distribucion_detalle ddt
        INNER JOIN ticket_balanza tb ON tb.id = ddt.id_ticket_balanza
        INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
        LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
        LEFT JOIN blending b ON b.id = dd.id_blending
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lm.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        INNER JOIN distribucion di ON di.id = ddt.id_distribucion
        INNER JOIN despacho d ON d.id = di.id_despacho
        LEFT JOIN planta_destino pd ON pd.id = d.id_planta_destino
        INNER JOIN recepcion_unidad rec ON rec.id_distribucion = di.id
        LEFT JOIN vehiculo veh ON veh.id = rec.id_vehiculo
        LEFT JOIN proveedor pr ON pr.id = COALESCE(gui.id_proveedor, lm.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(gui.id_conductor, rec.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(gui.id_empresa_transporte, rec.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(gui.id_sucursal, rec.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
        LEFT JOIN concesion_proveedor cp ON cp.id_proveedor = pr.id
        LEFT JOIN concesion cns_origen ON cns_origen.id = COALESCE(
            gui.id_concesion,
            (
                SELECT cp2.id_concesion
                FROM concesion_proveedor cp2
                WHERE cp2.id_proveedor = pr.id
                ORDER BY cp2.id ASC
                LIMIT 1
            )
        )
        LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
        LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
        LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        LEFT JOIN empleado eml ON eml.id = rec.id_empleado_recepcion
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE ddt.id = ?
        LIMIT 1
        ";

        $item = DB::selectOne($sql, [$idDistribucionDetalle]);
        if ($item) {
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->despacho_correlativo = $item->despacho_correlativo !== null ? (string) $item->despacho_correlativo : null;
            $item->planta_destino_nombre = $item->planta_destino_nombre !== null ? (string) $item->planta_destino_nombre : null;

            return (array) $item;
        }

        return null;
    }
}
