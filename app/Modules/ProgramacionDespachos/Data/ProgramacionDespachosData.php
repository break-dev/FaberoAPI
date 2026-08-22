<?php

namespace App\Modules\ProgramacionDespachos\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class ProgramacionDespachosData
{
    /**
     * Listar despachos con filtros opcionales por planta destino y rango de fechas.
     *
     * @param  array{id_planta_destino?: int|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     * @return array<object>
     */
    public static function get_despachos(array $filtros = []): array
    {
        $sql = '
        SELECT
            d.id,
            d.id_planta_destino,
            pd.razon_social AS planta_destino_razon_social,
            pd.ruc AS planta_destino_ruc,
            d.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            d.id_empleado_anulacion,
            d.fecha_hora_anulacion,
            d.correlativo,
            d.numero_correlativo,
            d.es_anulado,
            d.created_at,
            (SELECT COUNT(*) FROM distribucion WHERE id_despacho = d.id) AS total_distribuciones,
            (SELECT COALESCE(SUM(peso_tomado), 0) FROM despacho_detalle WHERE id_despacho = d.id) AS peso_total_tomado,
            (SELECT COALESCE(SUM(peso_actual), 0) FROM despacho_detalle WHERE id_despacho = d.id) AS peso_total_pendiente
        FROM despacho d
        INNER JOIN planta_destino pd ON pd.id = d.id_planta_destino
        LEFT JOIN empleado emp_reg ON emp_reg.id = d.id_empleado_registro
        WHERE 1 = 1
        ';

        $params = [];

        if (! empty($filtros['id_planta_destino'])) {
            $sql .= ' AND d.id_planta_destino = :id_planta_destino';
            $params['id_planta_destino'] = (int) $filtros['id_planta_destino'];
        }

        if (! empty($filtros['fecha_inicio'])) {
            $sql .= ' AND DATE(d.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }

        if (! empty($filtros['fecha_fin'])) {
            $sql .= ' AND DATE(d.created_at) <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }

        $sql .= ' ORDER BY d.created_at DESC;';

        $results = DB::select($sql, $params);

        foreach ($results as $row) {
            $row->id = (int) $row->id;
            $row->id_planta_destino = (int) $row->id_planta_destino;
            $row->id_empleado_registro = (int) $row->id_empleado_registro;
            $row->id_empleado_anulacion = $row->id_empleado_anulacion !== null ? (int) $row->id_empleado_anulacion : null;
            $row->numero_correlativo = (int) $row->numero_correlativo;
            $row->es_anulado = (int) $row->es_anulado === 1;
            $row->total_distribuciones = (int) $row->total_distribuciones;
            $row->peso_total_tomado = (float) $row->peso_total_tomado;
            $row->peso_total_pendiente = (float) $row->peso_total_pendiente;
        }

        return $results;
    }

    /**
     * Obtener el detalle completo de un despacho: cabecera, detalles, distribuciones y cada distribución con sus detalles y la recepción_unidad relacionada.
     *
     * @return array<string, mixed>|null
     */
    public static function get_despacho_full(int $id): ?array
    {
        $sqlDespacho = '
        SELECT
            d.id,
            d.id_planta_destino,
            pd.razon_social AS planta_destino_razon_social,
            pd.ruc AS planta_destino_ruc,
            d.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            d.id_empleado_anulacion,
            CONCAT(emp_anu.nombre, " ", emp_anu.apellido) AS empleado_anulacion_nombre,
            d.fecha_hora_anulacion,
            d.correlativo,
            d.numero_correlativo,
            d.es_anulado,
            d.created_at
        FROM despacho d
        INNER JOIN planta_destino pd ON pd.id = d.id_planta_destino
        LEFT JOIN empleado emp_reg ON emp_reg.id = d.id_empleado_registro
        LEFT JOIN empleado emp_anu ON emp_anu.id = d.id_empleado_anulacion
        WHERE d.id = :id
        LIMIT 1
        ';

        $cabecera = DB::selectOne($sqlDespacho, ['id' => $id]);
        if (! $cabecera) {
            return null;
        }

        $cabecera->id = (int) $cabecera->id;
        $cabecera->id_planta_destino = (int) $cabecera->id_planta_destino;
        $cabecera->id_empleado_registro = (int) $cabecera->id_empleado_registro;
        $cabecera->id_empleado_anulacion = $cabecera->id_empleado_anulacion !== null ? (int) $cabecera->id_empleado_anulacion : null;
        $cabecera->numero_correlativo = (int) $cabecera->numero_correlativo;
        $cabecera->es_anulado = (int) $cabecera->es_anulado === 1;

        $sqlDetalles = '
        SELECT
            dd.id,
            dd.id_despacho,
            dd.id_blending,
            dd.id_lote_mineral,
            dd.peso_tomado,
            dd.peso_actual,
            b.correlativo AS blending_correlativo,
            b.peso_neto AS blending_peso_neto,
            lm.correlativo AS lote_correlativo,
            lm.peso_neto AS lote_peso_neto,
            lm.tipo_producto AS lote_tipo_producto,
            lm.tipo_mineral AS lote_tipo_mineral,
            pr.razon_social AS proveedor_razon_social
        FROM despacho_detalle dd
        LEFT JOIN blending b ON b.id = dd.id_blending
        LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
        LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
        WHERE dd.id_despacho = :id
        ORDER BY dd.id ASC
        ';

        $detalles = DB::select($sqlDetalles, ['id' => $id]);
        foreach ($detalles as $d) {
            $d->id = (int) $d->id;
            $d->id_despacho = (int) $d->id_despacho;
            $d->id_blending = $d->id_blending !== null ? (int) $d->id_blending : null;
            $d->id_lote_mineral = $d->id_lote_mineral !== null ? (int) $d->id_lote_mineral : null;
            $d->peso_tomado = (float) $d->peso_tomado;
            $d->peso_actual = (float) $d->peso_actual;
            $d->blending_peso_neto = $d->blending_peso_neto !== null ? (float) $d->blending_peso_neto : null;
            $d->lote_peso_neto = $d->lote_peso_neto !== null ? (float) $d->lote_peso_neto : null;
        }

        $sqlDistribuciones = '
        SELECT
            di.id,
            di.id_despacho,
            di.id_sucursal,
            s.nombre AS sucursal_nombre,
            di.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            di.id_vehiculo,
            v.placa AS vehiculo_placa,
            di.id_empresa_transporte_carreta,
            et2.razon_social AS empresa_transporte_carreta_razon_social,
            di.id_vehiculo_carreta,
            v2.placa AS vehiculo_carreta_placa,
            di.id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            di.fecha_estimada_llegada,
            di.log_cambios,
            di.estado,
            di.created_at,
            ru.id AS id_recepcion_unidad,
            ru.estado AS recepcion_estado,
            ru.estado_pesaje AS recepcion_estado_pesaje,
            ru.estado_salida AS recepcion_estado_salida,
            ru.fecha_hora_ingreso AS recepcion_fecha_hora_ingreso,
            ru.fecha_hora_salida AS recepcion_fecha_hora_salida,
            tv.nombre AS tipo_vehiculo_nombre,
            c.id AS id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo
        FROM distribucion di
        LEFT JOIN sucursal s ON s.id = di.id_sucursal
        LEFT JOIN empresa_transporte et ON et.id = di.id_empresa_transporte
        LEFT JOIN vehiculo v ON v.id = di.id_vehiculo
        LEFT JOIN empresa_transporte et2 ON et2.id = di.id_empresa_transporte_carreta
        LEFT JOIN vehiculo v2 ON v2.id = di.id_vehiculo_carreta
        LEFT JOIN empleado emp_reg ON emp_reg.id = di.id_empleado_registro
        LEFT JOIN recepcion_unidad ru ON ru.id_tipo_vehiculo = v.id_tipo_vehiculo
            AND ru.id_empresa_transporte = di.id_empresa_transporte
            AND ru.id_vehiculo = di.id_vehiculo
            AND ru.es_programacion = 1
            AND ru.es_recepcion_ficticia = 0
            AND ru.tipo_ingreso = "Despacho de Mineral"
            AND ru.id_sucursal = di.id_sucursal
            AND ru.fecha_estimada_llegada = di.fecha_estimada_llegada
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        WHERE di.id_despacho = :id
        ORDER BY di.created_at DESC
        ';

        $distribucionesRaw = DB::select($sqlDistribuciones, ['id' => $id]);

        foreach ($distribucionesRaw as $dist) {
            $dist->id = (int) $dist->id;
            $dist->id_despacho = (int) $dist->id_despacho;
            $dist->id_sucursal = $dist->id_sucursal !== null ? (int) $dist->id_sucursal : null;
            $dist->id_empresa_transporte = (int) $dist->id_empresa_transporte;
            $dist->id_vehiculo = (int) $dist->id_vehiculo;
            $dist->id_empresa_transporte_carreta = $dist->id_empresa_transporte_carreta !== null ? (int) $dist->id_empresa_transporte_carreta : null;
            $dist->id_vehiculo_carreta = $dist->id_vehiculo_carreta !== null ? (int) $dist->id_vehiculo_carreta : null;
            $dist->id_empleado_registro = (int) $dist->id_empleado_registro;
            $dist->log_cambios = $dist->log_cambios ? json_decode($dist->log_cambios, true) : null;
            $dist->id_recepcion_unidad = $dist->id_recepcion_unidad !== null ? (int) $dist->id_recepcion_unidad : null;
            $dist->id_conductor = $dist->id_conductor !== null ? (int) $dist->id_conductor : null;
            $dist->capacidad_vehiculo = self::get_capacidad_vehiculo((int) $dist->id_vehiculo);
        }

        $distIds = array_column($distribucionesRaw, 'id');
        $distribucionesDetalle = [];
        if (! empty($distIds)) {
            $placeholders = implode(',', array_fill(0, count($distIds), '?'));
            $sqlDetallesDist = "
            SELECT
                ddt.id,
                ddt.id_distribucion,
                ddt.id_despacho_detalle,
                ddt.numero_particion,
                ddt.peso_tomado,
                dd.id_lote_mineral AS detalle_id_lote_mineral,
                dd.id_blending AS detalle_id_blending,
                lm.correlativo AS lote_correlativo,
                b.correlativo AS blending_correlativo,
                pr.razon_social AS proveedor_razon_social
            FROM distribucion_detalle ddt
            INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
            LEFT JOIN lote_mineral lm ON lm.id = dd.id_lote_mineral
            LEFT JOIN blending b ON b.id = dd.id_blending
            LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
            WHERE ddt.id_distribucion IN ($placeholders)
            ORDER BY ddt.id ASC
            ";
            $rows = DB::select($sqlDetallesDist, $distIds);
            foreach ($rows as $row) {
                $row->id = (int) $row->id;
                $row->id_distribucion = (int) $row->id_distribucion;
                $row->id_despacho_detalle = (int) $row->id_despacho_detalle;
                $row->numero_particion = $row->numero_particion !== null ? (int) $row->numero_particion : null;
                $row->peso_tomado = (float) $row->peso_tomado;
                $row->detalle_id_lote_mineral = $row->detalle_id_lote_mineral !== null ? (int) $row->detalle_id_lote_mineral : null;
                $row->detalle_id_blending = $row->detalle_id_blending !== null ? (int) $row->detalle_id_blending : null;
                $distribucionesDetalle[$row->id_distribucion][] = $row;
            }
        }

        foreach ($distribucionesRaw as $dist) {
            $dist->detalles = $distribucionesDetalle[$dist->id] ?? [];
        }

        return [
            'cabecera' => (array) $cabecera,
            'detalles' => array_map(static fn ($d) => (array) $d, $detalles),
            'distribuciones' => array_map(static fn ($d) => (array) $d, $distribucionesRaw),
        ];
    }

    /**
     * Obtener la capacidad (TN) de un vehículo para validaciones de advertencia.
     */
    public static function get_capacidad_vehiculo(int $id_vehiculo): ?float
    {
        $row = DB::selectOne('SELECT capacidad FROM vehiculo WHERE id = :id', ['id' => $id_vehiculo]);
        if (! $row || $row->capacidad === null) {
            return null;
        }

        return (float) $row->capacidad;
    }

    /**
     * Listar lotes y blendings con peso_actual > 0 que aún NO han sido despachados.
     * Excluye los que ya están en un despacho_detalle cuyo despacho NO está anulado.
     *
     * @return array<int, object>
     */
    public static function get_items_disponibles(): array
    {
        $estadoGuiaActivo = EstadoBase::Activo->value;

        $sqlLotes = '
        SELECT
            "LOTE" AS tipo_item,
            lm.id AS id_lote_mineral,
            NULL AS id_blending,
            lm.correlativo,
            lm.numero_correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.peso_neto,
            lm.peso_actual,
            lm.created_at,
            pr.razon_social AS proveedor_razon_social
        FROM lote_mineral lm
        LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
        WHERE lm.peso_actual > 0
          AND lm.esta_validado = 1
          AND lm.estado = :estado_activo
        ';

        $sqlBlendings = '
        SELECT
            "BLENDING" AS tipo_item,
            NULL AS id_lote_mineral,
            b.id AS id_blending,
            b.correlativo,
            b.numero_correlativo,
            NULL AS tipo_producto,
            NULL AS tipo_mineral,
            b.peso_neto,
            b.peso_actual,
            b.created_at,
            NULL AS proveedor_razon_social
        FROM blending b
        WHERE b.peso_actual > 0
        ';

        $results = [];

        foreach (DB::select($sqlLotes, ['estado_activo' => $estadoGuiaActivo]) as $row) {
            $row->id_lote_mineral = (int) $row->id_lote_mineral;
            $row->id_blending = null;
            $row->numero_correlativo = (int) $row->numero_correlativo;
            $row->peso_neto = (float) $row->peso_neto;
            $row->peso_actual = (float) $row->peso_actual;
            $row->id = (int) $row->id_lote_mineral;
            $results[] = (array) $row;
        }

        foreach (DB::select($sqlBlendings) as $row) {
            $row->id_blending = (int) $row->id_blending;
            $row->id_lote_mineral = null;
            $row->numero_correlativo = (int) $row->numero_correlativo;
            $row->peso_neto = (float) $row->peso_neto;
            $row->peso_actual = (float) $row->peso_actual;
            $row->id = (int) $row->id_blending;
            $results[] = (array) $row;
        }

        usort($results, static fn ($a, $b) => strcmp((string) ($a['correlativo'] ?? ''), (string) ($b['correlativo'] ?? '')));

        return $results;
    }

    /**
     * Crear cabecera de despacho. Retorna el ID.
     */
    public static function crear_despacho(
        int $idEmpleadoRegistro,
        int $idPlantaDestino,
        string $correlativo,
        int $numeroCorrelativo,
    ): int {
        return DB::table('despacho')->insertGetId([
            'id_planta_destino' => $idPlantaDestino,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'correlativo' => $correlativo,
            'numero_correlativo' => $numeroCorrelativo,
            'es_anulado' => 0,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Insertar un item en despacho_detalle.
     */
    public static function insertar_despacho_detalle(
        int $idDespacho,
        ?int $idBlending,
        ?int $idLoteMineral,
        float $pesoTomado,
    ): int {
        return DB::table('despacho_detalle')->insertGetId([
            'id_despacho' => $idDespacho,
            'id_blending' => $idBlending,
            'id_lote_mineral' => $idLoteMineral,
            'peso_tomado' => $pesoTomado,
            'peso_actual' => $pesoTomado,
        ]);
    }

    /**
     * Obtener la cantidad de distribuciones que consumen un despacho_detalle (para calcular numero_particion).
     */
    public static function count_distribuciones_por_despacho_detalle(int $idDespachoDetalle): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total FROM distribucion_detalle WHERE id_despacho_detalle = :id',
            ['id' => $idDespachoDetalle]
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * Contar distribuciones de un despacho con una fecha_estimada_llegada dada.
     * Usado para validar unicidad de fecha por despacho antes de crear una distribucion.
     */
    public static function count_distribuciones_by_despacho_and_fecha(int $idDespacho, string $fechaEstimada): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total FROM distribucion
             WHERE id_despacho = :id_despacho
               AND fecha_estimada_llegada = :fecha',
            [
                'id_despacho' => $idDespacho,
                'fecha' => $fechaEstimada,
            ]
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * Decrementar peso_actual de un despacho_detalle.
     */
    public static function decrementar_peso_actual_despacho_detalle(int $idDespachoDetalle, float $delta): bool
    {
        return DB::table('despacho_detalle')
            ->where('id', $idDespachoDetalle)
            ->update(['peso_actual' => DB::raw('peso_actual - '.(float) $delta)]) > 0;
    }

    /**
     * Obtener un despacho_detalle por su ID (para validaciones).
     *
     * @return array<string, mixed>|null
     */
    public static function get_despacho_detalle(int $id): ?array
    {
        $row = DB::selectOne('SELECT * FROM despacho_detalle WHERE id = :id', ['id' => $id]);
        if (! $row) {
            return null;
        }
        $row->id = (int) $row->id;
        $row->id_despacho = (int) $row->id_despacho;
        $row->peso_tomado = (float) $row->peso_tomado;
        $row->peso_actual = (float) $row->peso_actual;

        return (array) $row;
    }

    /**
     * Insertar cabecera de distribución. Devuelve el ID.
     *
     * @param  array<string, mixed>  $payload  Campos a insertar (sin log_cambios).
     * @param  array<int, array{campo_bd: string|null, campo: string|null, valor_anterior: mixed, valor_nuevo: mixed}>  $logCambiosInicial
     */
    public static function crear_distribucion(array $payload, array $logCambiosInicial): int
    {
        return DB::table('distribucion')->insertGetId([
            'id_despacho' => $payload['id_despacho'],
            'id_sucursal' => $payload['id_sucursal'],
            'id_empresa_transporte' => $payload['id_empresa_transporte'],
            'id_vehiculo' => $payload['id_vehiculo'],
            'id_empresa_transporte_carreta' => $payload['id_empresa_transporte_carreta'] ?? null,
            'id_vehiculo_carreta' => $payload['id_vehiculo_carreta'] ?? null,
            'id_empleado_registro' => $payload['id_empleado_registro'],
            'fecha_estimada_llegada' => $payload['fecha_estimada_llegada'] ?? null,
            'log_cambios' => json_encode($logCambiosInicial),
            'created_at' => now()->toDateTimeString(),
            'estado' => $payload['estado'],
        ]);
    }

    /**
     * Insertar un detalle de distribución.
     */
    public static function insertar_distribucion_detalle(
        int $idDistribucion,
        int $idDespachoDetalle,
        ?int $numeroParticion,
        float $pesoTomado,
    ): int {
        return DB::table('distribucion_detalle')->insertGetId([
            'id_distribucion' => $idDistribucion,
            'id_despacho_detalle' => $idDespachoDetalle,
            'numero_particion' => $numeroParticion,
            'peso_tomado' => $pesoTomado,
        ]);
    }

    /**
     * Insertar recepcion_unidad automática para la distribución.
     *
     * @param  array<string, mixed>  $data
     */
    public static function crear_recepcion_unidad_despacho(array $data): int
    {
        return DB::table('recepcion_unidad')->insertGetId($data);
    }

    /**
     * Verificar si ya existe una distribución (en este mismo despacho) con la misma fecha_estimada_llegada (para warning no bloqueante).
     *
     * @return array<int, object>
     */
    public static function get_distribuciones_con_misma_fecha_estimada(int $idDespacho, string $fechaEstimada, ?int $idDistribucionExcluir = null): array
    {
        $sql = '
        SELECT id, id_vehiculo, fecha_estimada_llegada, estado
        FROM distribucion
        WHERE id_despacho = :id_despacho
          AND fecha_estimada_llegada = :fecha
        ';
        $params = ['id_despacho' => $idDespacho, 'fecha' => $fechaEstimada];
        if ($idDistribucionExcluir !== null) {
            $sql .= ' AND id <> :id_excluir';
            $params['id_excluir'] = $idDistribucionExcluir;
        }
        $sql .= ';';

        return DB::select($sql, $params);
    }

    /**
     * Obtener una distribución por su ID.
     *
     * @return array<string, mixed>|null
     */
    public static function get_distribucion(int $id): ?array
    {
        $sql = '
        SELECT
            di.id,
            di.id_despacho,
            di.id_sucursal,
            di.id_empresa_transporte,
            di.id_vehiculo,
            di.id_empresa_transporte_carreta,
            di.id_vehiculo_carreta,
            di.id_empleado_registro,
            di.fecha_estimada_llegada,
            di.log_cambios,
            di.created_at,
            di.estado
        FROM distribucion di
        WHERE di.id = :id
        LIMIT 1
        ';
        $row = DB::selectOne($sql, ['id' => $id]);
        if (! $row) {
            return null;
        }
        $row->id = (int) $row->id;
        $row->id_despacho = (int) $row->id_despacho;
        $row->id_sucursal = $row->id_sucursal !== null ? (int) $row->id_sucursal : null;
        $row->id_empresa_transporte = (int) $row->id_empresa_transporte;
        $row->id_vehiculo = (int) $row->id_vehiculo;
        $row->id_empleado_anulacion = $row->id_empleado_anulacion ?? null;
        $row->log_cambios = $row->log_cambios ? json_decode($row->log_cambios, true) : null;

        return (array) $row;
    }

    /**
     * Verificar si todas las distribuciones del despacho están en En Espera (para anular).
     */
    public static function all_distribuciones_en_estado(int $idDespacho, string $estadoEsperado): bool
    {
        $row = DB::selectOne('
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado = :estado THEN 1 ELSE 0 END) AS ok
            FROM distribucion
            WHERE id_despacho = :id_despacho
        ', ['id_despacho' => $idDespacho, 'estado' => $estadoEsperado]);

        if (! $row || (int) $row->total === 0) {
            return true;
        }

        return (int) $row->total === (int) $row->ok;
    }

    /**
     * Marcar despacho como anulado.
     */
    public static function anular_despacho(int $id, int $idEmpleadoAnulacion): bool
    {
        return DB::table('despacho')
            ->where('id', $id)
            ->where('es_anulado', 0)
            ->update([
                'es_anulado' => 1,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now()->toDateTimeString(),
            ]) > 0;
    }

    /**
     * Restaurar peso_actual de los despacho_detalle (al anular).
     */
    public static function restaurar_peso_actual_despacho_detalles(int $idDespacho): void
    {
        DB::statement('
            UPDATE despacho_detalle dd
            INNER JOIN distribucion_detalle ddt ON ddt.id_despacho_detalle = dd.id
            INNER JOIN distribucion di ON di.id = ddt.id_distribucion
            SET dd.peso_actual = dd.peso_actual + ddt.peso_tomado
            WHERE di.id_despacho = :id
        ', ['id' => $idDespacho]);
    }

    /**
     * Actualizar el estado + log_cambios de una distribución.
     *
     * @param  array<string, mixed>  $updates  Campos a actualizar (estado, log_cambios).
     */
    public static function update_distribucion(int $id, array $updates): bool
    {
        return DB::table('distribucion')
            ->where('id', $id)
            ->update($updates) > 0;
    }

    /**
     * Actualizar una recepcion_unidad (estado, estado_pesaje, etc.).
     *
     * @param  array<string, mixed>  $updates
     */
    public static function update_recepcion_unidad(int $id, array $updates): bool
    {
        return DB::table('recepcion_unidad')
            ->where('id', $id)
            ->update($updates) > 0;
    }
}