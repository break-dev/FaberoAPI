<?php

namespace App\Modules\ValidacionDistribucion\Data;

use Illuminate\Support\Facades\DB;

class ValidacionDistribucionData
{
    /**
     * Listar todos los lotes pendientes de partición.
     * El campo "excedente" (peso_neto - capacidad) es informativo: no bloquea el flujo.
     *
     * @param  array{id_sucursal?: int|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     * @return array<int, object>
     */
    public static function get_lotes_pendientes(array $filtros): array
    {
        $sql = '
            SELECT
                lm.id AS id_lote_mineral,
                lm.correlativo AS lote_correlativo,
                lm.peso_neto AS lote_peso_neto,
                lm.peso_final AS lote_peso_final,
                lm.peso_inicial AS lote_peso_inicial,
                lm.fecha_hora_peso_inicial AS lote_fecha_peso_inicial,
                lm.fecha_hora_peso_final AS lote_fecha_peso_final,
                lm.tiene_particion,
                lm.esta_validado AS lote_esta_validado,
                lm.id_empleado_valida AS lote_id_empleado_valida,
                lm.fecha_hora_validacion AS lote_fecha_hora_validacion,
                ru.id AS id_recepcion_unidad,
                v.id AS id_vehiculo,
                v.placa AS vehiculo_placa,
                v.capacidad AS vehiculo_capacidad,
                (lm.peso_neto - v.capacidad) AS excedente,
                tb.correlativo AS ticket_correlativo,
                lm.created_at AS lote_fecha_creacion
            FROM lote_mineral lm
            INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
            INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
            LEFT JOIN ticket_balanza tb ON tb.id = lm.id_ticket_balanza
            WHERE (lm.estado IS NULL OR lm.estado != "Eliminado")
        ';

        $params = [];

        if (! empty($filtros['id_sucursal'])) {
            $sql .= ' AND ru.id_sucursal = :id_sucursal';
            $params['id_sucursal'] = (int) $filtros['id_sucursal'];
        }
        if (! empty($filtros['fecha_inicio'])) {
            $sql .= ' AND DATE(lm.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }
        if (! empty($filtros['fecha_fin'])) {
            $sql .= ' AND DATE(lm.created_at) <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }

        $sql .= ' ORDER BY lm.created_at DESC LIMIT 200';

        $rows = DB::select($sql, $params);

        return array_map(function ($r) {
            $r->id_lote_mineral = (int) $r->id_lote_mineral;
            $r->lote_peso_neto = (float) ($r->lote_peso_neto ?? 0);
            $r->lote_peso_final = (float) ($r->lote_peso_final ?? 0);
            $r->lote_peso_inicial = (float) ($r->lote_peso_inicial ?? 0);
            $r->tiene_particion = (bool) $r->tiene_particion;
            $r->lote_esta_validado = (bool) ($r->lote_esta_validado ?? 0);
            $r->lote_id_empleado_valida = $r->lote_id_empleado_valida !== null ? (int) $r->lote_id_empleado_valida : null;
            $r->lote_fecha_hora_validacion = $r->lote_fecha_hora_validacion !== null
                ? (string) $r->lote_fecha_hora_validacion
                : null;
            $r->id_recepcion_unidad = $r->id_recepcion_unidad !== null ? (int) $r->id_recepcion_unidad : null;
            $r->id_vehiculo = $r->id_vehiculo !== null ? (int) $r->id_vehiculo : null;
            $r->vehiculo_capacidad = $r->vehiculo_capacidad !== null ? (float) $r->vehiculo_capacidad : null;
            $r->excedente = $r->excedente !== null ? (float) $r->excedente : null;

            return $r;
        }, $rows);
    }

    /**
     * Obtener un lote por id con datos de vehiculo y resumen de particiones.
     */
    public static function get_lote_con_vehiculo(int $idLote): ?object
    {
        $sql = '
            SELECT
                lm.id AS id_lote_mineral,
                lm.correlativo AS lote_correlativo,
                lm.peso_neto AS lote_peso_neto,
                lm.tiene_particion,
                ru.id AS id_recepcion_unidad,
                v.id AS id_vehiculo,
                v.placa AS vehiculo_placa,
                v.capacidad AS vehiculo_capacidad,
                (lm.peso_neto - v.capacidad) AS excedente
            FROM lote_mineral lm
            INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
            INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
            WHERE lm.id = :id
            LIMIT 1
        ';

        $row = DB::selectOne($sql, ['id' => $idLote]);

        return $row ?: null;
    }

    /**
     * Verifica si la columna `es_bloqueado` existe en la tabla.
     * Usado para queries defensivas ante migraciones parciales.
     */
    public static function has_column_es_bloqueado(): bool
    {
        $rows = DB::select(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'particion_lote_mineral'
               AND COLUMN_NAME = 'es_bloqueado'"
        );

        return ((int) ($rows[0]->c ?? 0)) > 0;
    }

    /**
     * Listar todas las particiones de un lote, ordenadas por letra/numero.
     *
     * @return array<int, object>
     */
    public static function get_particiones(int $idLote): array
    {
        $bloqueadoExpr = self::has_column_es_bloqueado()
            ? 'plm.es_bloqueado'
            : '0 AS es_bloqueado';

        $sql = "
            SELECT
                plm.id,
                plm.id_lote_mineral,
                plm.id_ticket_balanza,
                plm.id_recepcion_unidad,
                plm.correlativo,
                plm.particion,
                plm.peso_inicial,
                plm.fecha_hora_peso_inicial,
                plm.peso_final,
                plm.fecha_hora_peso_final,
                plm.peso_neto,
                plm.estado,
                plm.esta_validado,
                plm.id_empleado_valida,
                plm.fecha_hora_validacion,
                {$bloqueadoExpr},
                tb.correlativo AS ticket_correlativo,
                ru.id_vehiculo,
                ru.id_conductor,
                ru.id_sucursal,
                ru.id_empresa_transporte,
                ru.id_tipo_vehiculo,
                ru.id_proveedor_minero,
                ru.fecha_hora_ingreso,
                ru.fecha_hora_salida,
                v.placa AS vehiculo_placa,
                v.tara AS vehiculo_tara,
                v.capacidad AS vehiculo_capacidad
            FROM particion_lote_mineral plm
            LEFT JOIN ticket_balanza tb ON tb.id = plm.id_ticket_balanza
            LEFT JOIN recepcion_unidad ru ON ru.id = plm.id_recepcion_unidad
            LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
            WHERE plm.id_lote_mineral = :id_lote
            ORDER BY plm.id ASC
        ";

        $rows = DB::select($sql, ['id_lote' => $idLote]);

        return array_map(function ($r) {
            $r->id = (int) $r->id;
            $r->id_lote_mineral = (int) $r->id_lote_mineral;
            $r->id_ticket_balanza = $r->id_ticket_balanza !== null ? (int) $r->id_ticket_balanza : null;
            $r->id_recepcion_unidad = $r->id_recepcion_unidad !== null ? (int) $r->id_recepcion_unidad : null;
            $r->peso_inicial = (float) ($r->peso_inicial ?? 0);
            $r->peso_final = (float) ($r->peso_final ?? 0);
            $r->peso_neto = (float) ($r->peso_neto ?? 0);
            $r->es_bloqueado = (bool) $r->es_bloqueado;
            $r->esta_validado = (bool) ($r->esta_validado ?? 0);
            $r->id_empleado_valida = $r->id_empleado_valida !== null ? (int) $r->id_empleado_valida : null;
            $r->fecha_hora_validacion = $r->fecha_hora_validacion !== null ? (string) $r->fecha_hora_validacion : null;
            $r->id_vehiculo = $r->id_vehiculo !== null ? (int) $r->id_vehiculo : null;
            $r->id_conductor = $r->id_conductor !== null ? (int) $r->id_conductor : null;
            $r->id_sucursal = $r->id_sucursal !== null ? (int) $r->id_sucursal : null;
            $r->id_empresa_transporte = $r->id_empresa_transporte !== null ? (int) $r->id_empresa_transporte : null;
            $r->id_tipo_vehiculo = $r->id_tipo_vehiculo !== null ? (int) $r->id_tipo_vehiculo : null;
            $r->id_proveedor_minero = $r->id_proveedor_minero !== null ? (int) $r->id_proveedor_minero : null;
            $r->vehiculo_tara = $r->vehiculo_tara !== null ? (float) $r->vehiculo_tara : null;
            $r->vehiculo_capacidad = $r->vehiculo_capacidad !== null ? (float) $r->vehiculo_capacidad : null;

            return $r;
        }, $rows);
    }

    /**
     * Suma de peso_neto de particiones de un lote.
     */
    public static function get_suma_peso_neto_particiones(int $idLote): float
    {
        $sum = DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->sum('peso_neto');

        return (float) ($sum ?? 0);
    }

    /**
     * Contar particiones existentes de un lote.
     */
    public static function count_particiones(int $idLote): int
    {
        return (int) DB::table('particion_lote_mineral')
            ->where('id_lote_mineral', $idLote)
            ->count();
    }

    /**
     * Devuelve la siguiente letra de particion (A, B, C, ..., Z, AA, AB, ...).
     */
    public static function get_siguiente_letra_particion(int $idLote): string
    {
        $count = self::count_particiones($idLote);

        $letra = '';
        $n = $count;
        $n++;
        while ($n > 0) {
            $n--;
            $letra = chr(65 + ($n % 26)).$letra;
            $n = intdiv($n, 26);
        }

        return $letra;
    }

    /**
     * Obtener la información completa para el Ticket de Balanza de una partición en formato PDF.
     */
    public static function get_ticket_balanza_particion(int $idParticion): ?array
    {
        $sql = "
        SELECT
            plm.id_lote_mineral AS id_lote,
            plm.correlativo AS correlativo,
            tb.id AS ticket_numero,
            tb.created_at AS fecha_impresion,
            
            vh.placa AS placa,
            
            lot.tipo_producto,
            lot.tipo_mineral,
            
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

            plm.fecha_hora_peso_inicial,
            plm.peso_inicial AS peso_bruto,
            plm.fecha_hora_peso_final,
            plm.peso_final AS peso_tara,
            plm.peso_neto AS peso_neto,

            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador

        FROM particion_lote_mineral plm
        INNER JOIN lote_mineral lot ON lot.id = plm.id_lote_mineral
        LEFT JOIN ticket_balanza tb ON tb.id = plm.id_ticket_balanza
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lot.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        LEFT JOIN recepcion_unidad rec ON rec.id = plm.id_recepcion_unidad
        LEFT JOIN vehiculo vh ON vh.id = COALESCE(rec.id_vehiculo, gui.id_vehiculo)
        LEFT JOIN proveedor pr ON pr.id = COALESCE(rec.id_proveedor_minero, gui.id_proveedor, lot.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(rec.id_conductor, gui.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(rec.id_empresa_transporte, gui.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(rec.id_sucursal, gui.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
        LEFT JOIN concesion cns_origen ON cns_origen.id = gui.id_concesion
        LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
        LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
        LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
        LEFT JOIN zona_origen zo ON zo.id = lot.id_zona_origen
        LEFT JOIN empleado eml ON eml.id = rec.id_empleado_recepcion
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE plm.id = :id_particion
        LIMIT 1
        ";

        $item = DB::selectOne($sql, ['id_particion' => $idParticion]);
        if ($item) {
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;

            return (array) $item;
        }

        return null;
    }

    /**
     * Trae, en una sola query, las particiones activas + datos de recepcion + lote
     * para evaluar reglas de validacion sobre uno o varios lotes.
     *
     * Devuelve un array indexado por id_lote_mineral con la estructura:
     *   [
     *     id_lote_mineral => [
     *       'lote_correlativo' => string,
     *       'peso_neto_lote' => float,
     *       'suma_pesos_netos' => float,
     *       'diferencia_suma' => float,
     *       'cumple_suma' => bool,
     *       'particiones' => [
     *         id_particion => [
     *           'id' => int,
     *           'particion' => string,
     *           'estado' => string,
     *           'cumple_pesos' => bool,
     *           'cumple_fechas' => bool,
     *           'cumple_recepcion' => bool,
     *           'cumple' => bool,
     *           'campos_faltantes' => string[],
     *         ],
     *       ],
     *       'lote_cumple' => bool,
     *     ],
     *   ]
     *
     * Reglas por particion:
     * - cumple_pesos: peso_inicial > 0, peso_final > 0, peso_neto > 0.
     * - cumple_fechas: fecha_hora_peso_inicial y fecha_hora_peso_final no nulos.
     * - cumple_recepcion: id_vehiculo, id_conductor, id_empresa_transporte,
     *                    id_tipo_vehiculo, id_proveedor_minero,
     *                    fecha_hora_ingreso, fecha_hora_salida no nulos.
     * - cumple: cumple_pesos AND cumple_fechas AND cumple_recepcion.
     *
     * Reglas a nivel lote:
     * - cumple_suma: ABS(SUM(participaciones.peso_neto) - lote.peso_neto) <= 0.01.
     * - lote_cumple: cumple_suma AND TODAS las particiones activas cumplen.
     *
     * @param  array<int, int>  $idLotes
     * @return array<int, array<string, mixed>>
     */
    public static function get_evaluacion_validacion(array $idLotes): array
    {
        $idLotes = array_values(array_unique(array_filter(array_map('intval', $idLotes), fn ($v) => $v > 0)));
        if (empty($idLotes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($idLotes), '?'));
        $sql = "
            SELECT
                plm.id AS id_particion,
                plm.id_lote_mineral,
                lm.correlativo AS lote_correlativo,
                lm.peso_neto AS lote_peso_neto,
                plm.particion,
                plm.peso_inicial,
                plm.peso_final,
                plm.peso_neto,
                plm.fecha_hora_peso_inicial,
                plm.fecha_hora_peso_final,
                plm.estado,
                ru.id_vehiculo,
                ru.id_conductor,
                ru.id_empresa_transporte,
                ru.id_tipo_vehiculo,
                ru.id_proveedor_minero,
                ru.fecha_hora_ingreso,
                ru.fecha_hora_salida
            FROM particion_lote_mineral plm
            INNER JOIN lote_mineral lm ON lm.id = plm.id_lote_mineral
            LEFT JOIN recepcion_unidad ru ON ru.id = plm.id_recepcion_unidad
            WHERE plm.id_lote_mineral IN ({$placeholders})
              AND plm.estado = 'Activo'
            ORDER BY plm.id_lote_mineral ASC, plm.id ASC
        ";

        $rows = DB::select($sql, $idLotes);

        // Acumular por lote.
        $evaluacion = [];
        foreach ($idLotes as $id) {
            $evaluacion[$id] = [
                'lote_correlativo' => null,
                'peso_neto_lote' => 0.0,
                'suma_pesos_netos' => 0.0,
                'diferencia_suma' => 0.0,
                'cumple_suma' => false,
                'particiones' => [],
                'lote_cumple' => false,
            ];
        }

        foreach ($rows as $r) {
            $idLote = (int) $r->id_lote_mineral;
            $idPart = (int) $r->id_particion;

            $evaluacion[$idLote]['lote_correlativo'] = (string) ($r->lote_correlativo ?? '');
            $evaluacion[$idLote]['peso_neto_lote'] = (float) ($r->lote_peso_neto ?? 0);
            $evaluacion[$idLote]['suma_pesos_netos'] += (float) ($r->peso_neto ?? 0);

            $pesoInicial = (float) ($r->peso_inicial ?? 0);
            $pesoFinal = (float) ($r->peso_final ?? 0);
            $pesoNeto = (float) ($r->peso_neto ?? 0);

            $camposFaltantes = [];

            if ($pesoInicial <= 0) {
                $camposFaltantes[] = 'peso_inicial';
            }
            if ($pesoFinal <= 0) {
                $camposFaltantes[] = 'peso_final';
            }
            if ($pesoNeto <= 0) {
                $camposFaltantes[] = 'peso_neto';
            }

            $cumplePesos = empty($camposFaltantes);

            if (empty($r->fecha_hora_peso_inicial)) {
                $camposFaltantes[] = 'fecha_hora_peso_inicial';
            }
            if (empty($r->fecha_hora_peso_final)) {
                $camposFaltantes[] = 'fecha_hora_peso_final';
            }

            $cumpleFechas = $cumplePesos
                && ! in_array('fecha_hora_peso_inicial', $camposFaltantes, true)
                && ! in_array('fecha_hora_peso_final', $camposFaltantes, true);

            if (empty($r->id_vehiculo)) {
                $camposFaltantes[] = 'recepcion.id_vehiculo';
            }
            if (empty($r->id_conductor)) {
                $camposFaltantes[] = 'recepcion.id_conductor';
            }
            if (empty($r->id_empresa_transporte)) {
                $camposFaltantes[] = 'recepcion.id_empresa_transporte';
            }
            if (empty($r->id_tipo_vehiculo)) {
                $camposFaltantes[] = 'recepcion.id_tipo_vehiculo';
            }
            if (empty($r->id_proveedor_minero)) {
                $camposFaltantes[] = 'recepcion.id_proveedor_minero';
            }
            if (empty($r->fecha_hora_ingreso)) {
                $camposFaltantes[] = 'recepcion.fecha_hora_ingreso';
            }
            if (empty($r->fecha_hora_salida)) {
                $camposFaltantes[] = 'recepcion.fecha_hora_salida';
            }

            $cumpleRecepcion = ! in_array('recepcion.id_vehiculo', $camposFaltantes, true)
                && ! in_array('recepcion.id_conductor', $camposFaltantes, true)
                && ! in_array('recepcion.id_empresa_transporte', $camposFaltantes, true)
                && ! in_array('recepcion.id_tipo_vehiculo', $camposFaltantes, true)
                && ! in_array('recepcion.id_proveedor_minero', $camposFaltantes, true)
                && ! in_array('recepcion.fecha_hora_ingreso', $camposFaltantes, true)
                && ! in_array('recepcion.fecha_hora_salida', $camposFaltantes, true);

            $cumple = $cumplePesos && $cumpleFechas && $cumpleRecepcion;

            $evaluacion[$idLote]['particiones'][$idPart] = [
                'id' => $idPart,
                'particion' => (string) ($r->particion ?? ''),
                'estado' => (string) ($r->estado ?? ''),
                'cumple_pesos' => $cumplePesos,
                'cumple_fechas' => $cumpleFechas,
                'cumple_recepcion' => $cumpleRecepcion,
                'cumple' => $cumple,
                'campos_faltantes' => array_values(array_unique($camposFaltantes)),
            ];
        }

        // Reglas a nivel lote.
        foreach ($evaluacion as $idLote => &$eval) {
            $suma = round((float) $eval['suma_pesos_netos'], 2);
            $pesoLote = round((float) $eval['peso_neto_lote'], 2);
            $diferencia = round(abs($pesoLote - $suma), 2);

            $eval['suma_pesos_netos'] = $suma;
            $eval['diferencia_suma'] = $diferencia;
            $eval['cumple_suma'] = $diferencia <= 0.01;

            $particionesActivas = $eval['particiones'];
            $todasCumplen = ! empty($particionesActivas)
                && array_reduce(
                    $particionesActivas,
                    fn ($carry, $p) => $carry && $p['cumple'],
                    true,
                );

            $eval['lote_cumple'] = $eval['cumple_suma'] && $todasCumplen;
        }
        unset($eval);

        // Si un lote no tiene particiones activas (no se incluyo en el WHERE),
        // marcamos cumple_suma=true si lote.peso_neto=0 (caso degenerado) y lote_cumple=false.
        // El caller decidira si permite o no validar lotes sin particiones.
        foreach ($evaluacion as $idLote => &$eval) {
            if (empty($eval['particiones'])) {
                $eval['cumple_suma'] = round((float) $eval['peso_neto_lote'], 2) === 0.0;
                $eval['lote_cumple'] = false;
            }
        }
        unset($eval);

        return $evaluacion;
    }

    /**
     * Obtener la información completa para el Ticket de Balanza de un lote padre en formato PDF.
     */
    public static function get_ticket_balanza_lote(int $idLote): ?array
    {
        $sql = "
        SELECT
            lot.id AS id_lote,
            lot.correlativo AS correlativo,
            tb.id AS ticket_numero,
            tb.created_at AS fecha_impresion,

            vh.placa AS placa,

            lot.tipo_producto,
            lot.tipo_mineral,

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

            lot.fecha_hora_peso_inicial,
            lot.peso_inicial AS peso_bruto,
            lot.fecha_hora_peso_final,
            lot.peso_final AS peso_tara,
            lot.peso_neto AS peso_neto,

            CONCAT(COALESCE(eml.apellido, ''), ' ', COALESCE(eml.nombre, '')) AS operador,
            eml.dni AS dni_operador,
            cr.nombre AS cargo_operador

        FROM lote_mineral lot
        LEFT JOIN ticket_balanza tb ON tb.id = lot.id_ticket_balanza
        LEFT JOIN lote_guia ltg ON ltg.id_lote_mineral = lot.id
        LEFT JOIN guia_primer_tramo gui ON gui.id = ltg.id_guia_primer_tramo
        LEFT JOIN recepcion_unidad rec ON rec.id = lot.id_recepcion_unidad
        LEFT JOIN vehiculo vh ON vh.id = COALESCE(rec.id_vehiculo, gui.id_vehiculo)
        LEFT JOIN proveedor pr ON pr.id = COALESCE(rec.id_proveedor_minero, gui.id_proveedor, lot.id_proveedor_minero)
        LEFT JOIN conductor cnd ON cnd.id = COALESCE(rec.id_conductor, gui.id_conductor)
        LEFT JOIN empresa_transporte emp ON emp.id = COALESCE(rec.id_empresa_transporte, gui.id_empresa_transporte)
        LEFT JOIN sucursal sc ON sc.id = COALESCE(rec.id_sucursal, gui.id_sucursal)
        LEFT JOIN departamento dep_sc ON dep_sc.id = sc.id_departamento
        LEFT JOIN provincia prv_sc ON prv_sc.id = sc.id_provincia
        LEFT JOIN distrito dis_sc ON dis_sc.id = sc.id_distrito
        LEFT JOIN concesion cns_origen ON cns_origen.id = gui.id_concesion
        LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
        LEFT JOIN provincia    prv_cori ON prv_cori.id = cns_origen.id_provincia
        LEFT JOIN distrito     dis_cori ON dis_cori.id = cns_origen.id_distrito
        LEFT JOIN zona_origen zo ON zo.id = lot.id_zona_origen
        LEFT JOIN empleado eml ON eml.id = rec.id_empleado_recepcion
        LEFT JOIN cargo cr ON cr.id = eml.id_cargo
        WHERE lot.id = :id_lote
        LIMIT 1
        ";

        $item = DB::selectOne($sql, ['id_lote' => $idLote]);
        if ($item) {
            $item->peso_bruto = $item->peso_bruto !== null ? (float) $item->peso_bruto : null;
            $item->peso_tara = $item->peso_tara !== null ? (float) $item->peso_tara : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;

            return (array) $item;
        }

        return null;
    }
}
