<?php

namespace App\Modules\ContabilidadCompra\Data;

use App\Models\ComprobanteCompra;
use App\Models\ValorizacionCompra;
use App\Shared\Enums\ContabilidadCompra\EstadoComprobanteCompra;
use App\Shared\Enums\ContabilidadCompra\MedioPagoComprobante;
use App\Shared\Enums\ContabilidadCompra\TipoAprobacionComprobante;
use Illuminate\Support\Facades\DB;

class ContabilidadCompraData
{
    /**
     * Listar comprobantes de compra con totales calculados y relaciones básicas.
     *
     * @return array<int,object>
     */
    public static function get_comprobantes(?int $idProveedor = null, ?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $sql = '
            SELECT
                cc.id,
                cc.id_valorizacion_compra,
                vc.numero_correlativo AS valorizacion_correlativo,
                cc.id_tipo_cambio,
                tc.fecha AS tipo_cambio_fecha,
                cc.id_empleado_registro,
                cc.serie,
                cc.numero,
                CONCAT_WS("-", cc.serie, cc.numero) AS codigo_completo,
                cc.fecha_emision,
                cc.evidencias,
                cc.tipo_cambio_venta,
                cc.porcentaje_igv,
                cc.porcentaje_detraccion,
                cc.total_dolares,
                cc.total_soles,
                cc.monto_igv_soles,
                cc.monto_pagado_anticipos,
                cc.monto_detraccion,
                cc.monto_detraccion_soles,
                cc.monto_neto,
                cc.avance_pago_neto,
                cc.avance_pago_detraccion,
                cc.aprobaciones,
                cc.estado,
                cc.created_at,
                p.id AS id_proveedor,
                p.razon_social AS proveedor_nombre,
                p.ruc AS proveedor_ruc,
                c.nombre AS concesion_nombre,
                CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
                COALESCE((SELECT SUM(pg.monto_pagado) FROM pago_comprobante_compra pg WHERE pg.id_comprobante_compra = cc.id AND pg.es_anulado = 0 AND pg.es_para_detraccion = 0), 0) AS total_pagado_neto,
                COALESCE((SELECT SUM(pg.monto_pagado) FROM pago_comprobante_compra pg WHERE pg.id_comprobante_compra = cc.id AND pg.es_anulado = 0 AND pg.es_para_detraccion = 1), 0) AS total_pagado_detraccion
            FROM comprobante_compra cc
            INNER JOIN valorizacion_compra vc ON vc.id = cc.id_valorizacion_compra
            INNER JOIN proveedor p ON p.id = vc.id_proveedor_minero
            INNER JOIN concesion c ON c.id = vc.id_concesion
            INNER JOIN tipo_cambio tc ON tc.id = cc.id_tipo_cambio
            INNER JOIN empleado emp_reg ON emp_reg.id = cc.id_empleado_registro
            WHERE 1 = 1
        ';

        $params = [];

        if ($idProveedor !== null) {
            $sql .= ' AND vc.id_proveedor_minero = :id_proveedor';
            $params['id_proveedor'] = $idProveedor;
        }

        if ($estado !== null) {
            $sql .= ' AND cc.estado = :estado';
            $params['estado'] = $estado;
        }

        if ($fechaInicio !== null) {
            $sql .= ' AND cc.fecha_emision >= :fecha_inicio';
            $params['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null) {
            $sql .= ' AND cc.fecha_emision <= :fecha_fin';
            $params['fecha_fin'] = $fechaFin;
        }

        $sql .= ' ORDER BY cc.id DESC';

        $rows = DB::select($sql, $params);

        $empleadoIds = [];
        foreach ($rows as $r) {
            $aprobaciones = is_string($r->aprobaciones) ? json_decode($r->aprobaciones, true) : $r->aprobaciones;
            if (is_array($aprobaciones)) {
                foreach ($aprobaciones as $ap) {
                    if (! empty($ap['id_empleado'])) {
                        $empleadoIds[(int) $ap['id_empleado']] = true;
                    }
                }
            }
        }

        $empMap = [];
        if (! empty($empleadoIds)) {
            $emps = DB::table('empleado')
                ->whereIn('id', array_keys($empleadoIds))
                ->select('id', DB::raw("CONCAT(nombre, ' ', apellido) AS full_name"))
                ->get();
            foreach ($emps as $e) {
                $empMap[(int) $e->id] = $e->full_name;
            }
        }

        foreach ($rows as $r) {
            self::cast_comprobante_financiero($r);
            $r->total_pagado_neto = (float) $r->total_pagado_neto;
            $r->total_pagado_detraccion = (float) $r->total_pagado_detraccion;
            $r->lotes_valorizados = self::get_lotes_valorizados_by_comprobante((int) $r->id);

            $aprobaciones = is_string($r->aprobaciones) ? json_decode($r->aprobaciones, true) : $r->aprobaciones;
            $aprobaciones = is_array($aprobaciones) ? $aprobaciones : [];
            foreach ($aprobaciones as &$ap) {
                if (! empty($ap['id_empleado']) && isset($empMap[(int) $ap['id_empleado']])) {
                    $ap['empleado_registro_nombre'] = $empMap[(int) $ap['id_empleado']];
                }
            }
            unset($ap);
            $r->aprobaciones = $aprobaciones;

            $evidencias = is_string($r->evidencias) ? json_decode($r->evidencias, true) : $r->evidencias;
            $r->evidencias = is_array($evidencias) ? $evidencias : [];
        }

        return $rows;
    }

    /**
     * Obtener detalle completo de un comprobante + sus pagos + lotes valorizados.
     */
    public static function get_comprobante_by_id(int $id): ?object
    {
        $sql = '
            SELECT
                cc.id,
                cc.id_valorizacion_compra,
                vc.numero_correlativo AS valorizacion_correlativo,
                cc.id_tipo_cambio,
                tc.fecha AS tipo_cambio_fecha,
                tc.valor_compra AS tipo_cambio_valor_compra,
                tc.valor_venta AS tipo_cambio_valor_venta,
                cc.id_empleado_registro,
                cc.serie,
                cc.numero,
                CONCAT_WS("-", cc.serie, cc.numero) AS codigo_completo,
                cc.fecha_emision,
                cc.evidencias,
                cc.tipo_cambio_venta,
                cc.porcentaje_igv,
                cc.porcentaje_detraccion,
                cc.total_dolares,
                cc.total_soles,
                cc.monto_igv_soles,
                cc.monto_pagado_anticipos,
                cc.monto_detraccion,
                cc.monto_detraccion_soles,
                cc.monto_neto,
                cc.avance_pago_neto,
                cc.avance_pago_detraccion,
                cc.aprobaciones,
                cc.estado,
                cc.created_at,
                p.id AS id_proveedor,
                p.razon_social AS proveedor_nombre,
                p.ruc AS proveedor_ruc,
                c.nombre AS concesion_nombre,
                CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre
            FROM comprobante_compra cc
            INNER JOIN valorizacion_compra vc ON vc.id = cc.id_valorizacion_compra
            INNER JOIN proveedor p ON p.id = vc.id_proveedor_minero
            INNER JOIN concesion c ON c.id = vc.id_concesion
            INNER JOIN tipo_cambio tc ON tc.id = cc.id_tipo_cambio
            INNER JOIN empleado emp_reg ON emp_reg.id = cc.id_empleado_registro
            WHERE cc.id = :id
            LIMIT 1
        ';

        $row = DB::selectOne($sql, ['id' => $id]);
        if (! $row) {
            return null;
        }

        self::cast_comprobante_financiero($row);

        $row->lotes_valorizados = self::get_lotes_valorizados_by_comprobante($id);
        $row->pagos = self::get_pagos_by_comprobante($id);

        $aprobaciones = is_string($row->aprobaciones) ? json_decode($row->aprobaciones, true) : $row->aprobaciones;
        $aprobaciones = is_array($aprobaciones) ? $aprobaciones : [];

        $empleadoIds = [];
        foreach ($aprobaciones as $ap) {
            if (! empty($ap['id_empleado'])) {
                $empleadoIds[(int) $ap['id_empleado']] = true;
            }
        }
        if (! empty($empleadoIds)) {
            $emps = DB::table('empleado')
                ->whereIn('id', array_keys($empleadoIds))
                ->select('id', DB::raw("CONCAT(nombre, ' ', apellido) AS full_name"))
                ->get();
            $empMap = [];
            foreach ($emps as $e) {
                $empMap[(int) $e->id] = $e->full_name;
            }
            foreach ($aprobaciones as &$ap) {
                if (! empty($ap['id_empleado']) && isset($empMap[(int) $ap['id_empleado']])) {
                    $ap['empleado_registro_nombre'] = $empMap[(int) $ap['id_empleado']];
                }
            }
            unset($ap);
        }
        $row->aprobaciones = $aprobaciones;

        $evidencias = is_string($row->evidencias) ? json_decode($row->evidencias, true) : $row->evidencias;
        $row->evidencias = is_array($evidencias) ? $evidencias : [];

        return $row;
    }

    /**
     * Castea a float todos los campos DECIMAL del comprobante para evitar
     * el error "toFixed is not a function" en el frontend.
     */
    private static function cast_comprobante_financiero(object $row): void
    {
        $row->id = (int) $row->id;
        $row->id_valorizacion_compra = (int) $row->id_valorizacion_compra;
        $row->id_tipo_cambio = (int) $row->id_tipo_cambio;
        $row->id_empleado_registro = (int) $row->id_empleado_registro;
        $row->id_proveedor = (int) $row->id_proveedor;
        if (isset($row->tipo_cambio_valor_compra)) {
            $row->tipo_cambio_valor_compra = (float) $row->tipo_cambio_valor_compra;
        }
        if (isset($row->tipo_cambio_valor_venta)) {
            $row->tipo_cambio_valor_venta = (float) $row->tipo_cambio_valor_venta;
        }
        $row->tipo_cambio_venta = (float) $row->tipo_cambio_venta;
        $row->porcentaje_igv = (float) $row->porcentaje_igv;
        $row->porcentaje_detraccion = (float) $row->porcentaje_detraccion;
        $row->total_dolares = (float) $row->total_dolares;
        $row->total_soles = (float) $row->total_soles;
        $row->monto_igv_soles = (float) $row->monto_igv_soles;
        $row->monto_pagado_anticipos = (float) $row->monto_pagado_anticipos;
        $row->monto_detraccion = (float) $row->monto_detraccion;
        $row->monto_detraccion_soles = (float) $row->monto_detraccion_soles;
        $row->monto_neto = (float) $row->monto_neto;
        $row->avance_pago_neto = (float) $row->avance_pago_neto;
        $row->avance_pago_detraccion = (float) $row->avance_pago_detraccion;
        if (isset($row->total_pagado_neto)) {
            $row->total_pagado_neto = (float) $row->total_pagado_neto;
        }
        if (isset($row->total_pagado_detraccion)) {
            $row->total_pagado_detraccion = (float) $row->total_pagado_detraccion;
        }
    }

    /**
     * Listar los lotes valorizados del comprobante (JOIN valorizacion_compramineral_detalle).
     *
     * @return array<int,object>
     */
    public static function get_lotes_valorizados_by_comprobante(int $idComprobante): array
    {
        $sql = '
            SELECT
                vcd.id,
                vcd.id_valorizacion_compra,
                vcd.id_lote_guia,
                vcd.elemento_quimico,
                vcd.subtotal,
                vcd.precio_por_tonelada,
                vcd.inter,
                vcd.des_inter,
                vcd.recuperacion,
                vcd.maquila,
                vcd.consumo,
                vcd.factor,
                lm.numero_correlativo AS numero_correlativo,
                lm.correlativo AS lote_correlativo
            FROM comprobante_compra cc
            INNER JOIN valorizacion_compra vc ON vc.id = cc.id_valorizacion_compra
            INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_valorizacion_compra = vc.id
            INNER JOIN lote_guia lg ON lg.id = vcd.id_lote_guia
            INNER JOIN lote_mineral lm ON lm.id = COALESCE(
                lg.id_lote_mineral,
                (SELECT id_lote_mineral FROM particion_lote_mineral WHERE id = lg.id_particion_lote_mineral)
            )
            WHERE cc.id = :id
            ORDER BY vcd.id ASC
        ';

        $rows = DB::select($sql, ['id' => $idComprobante]);

        foreach ($rows as $r) {
            $r->subtotal = (float) $r->subtotal;
            $r->precio_por_tonelada = (float) $r->precio_por_tonelada;
            $r->inter = (float) $r->inter;
            $r->des_inter = (float) $r->des_inter;
            $r->recuperacion = (float) $r->recuperacion;
            $r->maquila = (float) $r->maquila;
            $r->consumo = (float) $r->consumo;
            $r->factor = (float) $r->factor;
        }

        return $rows;
    }

    /**
     * Listar los pagos de un comprobante.
     *
     * @return array<int,object>
     */
    public static function get_pagos_by_comprobante(int $idComprobante): array
    {
        $sql = '
            SELECT
                pg.id,
                pg.id_comprobante_compra,
                pg.id_cuenta_bancaria_empresa,
                pg.id_cuenta_bancaria_proveedor,
                pg.id_empleado_registro,
                pg.id_empleado_anulacion,
                pg.es_para_detraccion,
                pg.medio_pago,
                pg.monto_pagado,
                pg.fecha_hora_pago,
                pg.numero_operacion,
                pg.observacion,
                pg.evidencias,
                pg.evidencias_anulacion,
                pg.fecha_hora_anulacion,
                pg.motivo_anulacion,
                pg.es_anulado,
                pg.created_at,
                CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
                CONCAT(emp_anu.nombre, " ", emp_anu.apellido) AS empleado_anulacion_nombre,
                cb_emp.banco_nombre AS banco_empresa_nombre,
                cb_emp.numero_cuenta AS empresa_numero_cuenta,
                cb_emp.moneda AS empresa_moneda,
                cb_prov.banco_nombre AS banco_proveedor_nombre,
                cb_prov.numero_cuenta AS proveedor_numero_cuenta,
                cb_prov.moneda AS proveedor_moneda
            FROM pago_comprobante_compra pg
            INNER JOIN empleado emp_reg ON emp_reg.id = pg.id_empleado_registro
            LEFT JOIN empleado emp_anu ON emp_anu.id = pg.id_empleado_anulacion
            LEFT JOIN (
                SELECT cn.id, bc.nombre AS banco_nombre, cn.numero_cuenta, cn.moneda
                FROM cuenta_bancaria_empresa cn
                INNER JOIN banco bc ON bc.id = cn.id_banco
            ) cb_emp ON cb_emp.id = pg.id_cuenta_bancaria_empresa
            LEFT JOIN (
                SELECT cb.id, b.nombre AS banco_nombre, cb.numero_cuenta, cb.moneda
                FROM cuenta_bancaria_proveedor cb
                INNER JOIN banco b ON b.id = cb.id_banco
            ) cb_prov ON cb_prov.id = pg.id_cuenta_bancaria_proveedor
            WHERE pg.id_comprobante_compra = :id
            ORDER BY pg.es_anulado ASC, pg.fecha_hora_pago DESC, pg.id DESC
        ';

        $rows = DB::select($sql, ['id' => $idComprobante]);

        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_comprobante_compra = (int) $r->id_comprobante_compra;
            $r->id_cuenta_bancaria_empresa = $r->id_cuenta_bancaria_empresa !== null ? (int) $r->id_cuenta_bancaria_empresa : null;
            $r->id_cuenta_bancaria_proveedor = $r->id_cuenta_bancaria_proveedor !== null ? (int) $r->id_cuenta_bancaria_proveedor : null;
            $r->es_para_detraccion = (bool) $r->es_para_detraccion;
            $r->es_anulado = (bool) $r->es_anulado;
            $r->monto_pagado = (float) $r->monto_pagado;
            $r->evidencias = self::parse_json_array($r->evidencias ?? null);
            $r->evidencias_anulacion = self::parse_json_array($r->evidencias_anulacion ?? null);
        }

        return $rows;
    }

    /**
     * Parsea un valor JSON a array, manejando cadenas simples o doblemente codificadas.
     *
     * @return array<int, mixed>
     */
    private static function parse_json_array(mixed $val): array
    {
        if (is_array($val)) {
            return $val;
        }
        if (! is_string($val) || trim($val) === '') {
            return [];
        }
        $decoded = json_decode($val, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Calcular todos los importes del comprobante a partir de la valorización y el TC.
     *
     * @param  float  $porcentajeIgv  Default 0.18
     * @param  float  $porcentajeDetraccion  Default 0.11
     * @return array<string,float>
     */
    public static function calcular_importes(
        int $idValorizacion,
        float $tipoCambioVenta,
        float $porcentajeIgv = 0.18,
        float $porcentajeDetraccion = 0.11
    ): array {
        $totalDolares = (float) DB::table('valorizacion_compramineral_detalle')
            ->where('id_valorizacion_compra', $idValorizacion)
            ->sum('subtotal');

        $totalSoles = $totalDolares * $tipoCambioVenta;
        $montoIgvSoles = $totalSoles * $porcentajeIgv;

        $montoAnticipos = (float) DB::table('transaccion_anticipo_proveedor')
            ->where('id_valorizacion_compra', $idValorizacion)
            ->where('estado', 'Aprobado')
            ->sum('monto_retirado');

        $baseDetraccion = max($totalDolares - $montoAnticipos, 0.0);
        $montoDetraccion = $baseDetraccion * $porcentajeDetraccion;
        $montoDetraccionSoles = $montoDetraccion * $tipoCambioVenta;

        $montoNeto = $totalDolares - $montoAnticipos - $montoDetraccion;

        return [
            'total_dolares' => round($totalDolares, 2),
            'total_soles' => round($totalSoles, 2),
            'monto_igv_soles' => round($montoIgvSoles, 2),
            'monto_pagado_anticipos' => round($montoAnticipos, 2),
            'monto_detraccion' => round($montoDetraccion, 2),
            'monto_detraccion_soles' => round($montoDetraccionSoles, 2),
            'monto_neto' => round($montoNeto, 2),
        ];
    }

    /**
     * Insertar un comprobante en BD. Devuelve el ID.
     *
     * @param  array<string,mixed>  $aprobaciones
     */
    public static function crear_comprobante(array $campos, array $aprobaciones): int
    {
        return ComprobanteCompra::insertGetId($campos + [
            'aprobaciones' => json_encode($aprobaciones),
            'avance_pago_neto' => 0,
            'avance_pago_detraccion' => 0,
            'created_at' => now(),
            'estado' => EstadoComprobanteCompra::EnEspera->value,
        ]);
    }

    /**
     * Actualizar JSON de aprobaciones y posiblemente el estado del comprobante.
     *
     * @param  array<int,array<string,mixed>>  $aprobaciones
     */
    public static function actualizar_aprobaciones(int $id, array $aprobaciones, ?EstadoComprobanteCompra $nuevoEstado = null): bool
    {
        $payload = ['aprobaciones' => json_encode($aprobaciones)];
        if ($nuevoEstado !== null) {
            $payload['estado'] = $nuevoEstado->value;
        }

        return ComprobanteCompra::where('id', $id)->update($payload) >= 0;
    }

    /**
     * Actualizar estado del comprobante (transición Pagado / EnProceso / Anulado).
     */
    public static function actualizar_estado(int $id, EstadoComprobanteCompra $estado): bool
    {
        return ComprobanteCompra::where('id', $id)->update([
            'estado' => $estado->value,
        ]) >= 0;
    }

    /**
     * Anular un comprobante (solo cambia el estado, la tabla comprobante_compra no tiene
     * campos de auditoría de anulación propios; el motivo se persiste en la cascada de pagos).
     */
    public static function anular_comprobante(int $id, int $idEmpleadoAnulacion, string $motivo, EstadoComprobanteCompra $estado = EstadoComprobanteCompra::Anulado): bool
    {
        return ComprobanteCompra::where('id', $id)->update([
            'estado' => $estado->value,
            'avance_pago_neto' => 0,
            'avance_pago_detraccion' => 0,
        ]) >= 0;
    }

    /**
     * Anular todos los pagos vigentes de un comprobante (cascada).
     *
     * @return int cantidad de pagos anulados
     */
    public static function anular_pagos_del_comprobante(int $idComprobante, int $idEmpleadoAnulacion, string $motivo): int
    {
        return DB::table('pago_comprobante_compra')
            ->where('id_comprobante_compra', $idComprobante)
            ->where('es_anulado', 0)
            ->update([
                'es_anulado' => 1,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivo,
            ]);
    }

    /**
     * Verificar si ya existe un comprobante (no anulado) para la valorización.
     */
    public static function existe_comprobante_para_valorizacion(int $idValorizacion): bool
    {
        return ComprobanteCompra::where('id_valorizacion_compra', $idValorizacion)
            ->where('estado', '!=', EstadoComprobanteCompra::Anulado->value)
            ->exists();
    }

    /**
     * Obtener valorización con sus transacciones de anticipo (helper usado por el Service).
     */
    public static function get_valorizacion(int $id): ?ValorizacionCompra
    {
        return ValorizacionCompra::find($id);
    }

    /**
     * Sumar pagos vigentes (no anulados) por tipo.
     *
     * @return array{neto: float, detraccion: float}
     */
    public static function sumar_pagos_vigentes(int $idComprobante): array
    {
        $sql = '
            SELECT
                COALESCE(SUM(CASE WHEN es_para_detraccion = 0 THEN monto_pagado ELSE 0 END), 0) AS neto,
                COALESCE(SUM(CASE WHEN es_para_detraccion = 1 THEN monto_pagado ELSE 0 END), 0) AS detraccion
            FROM pago_comprobante_compra
            WHERE id_comprobante_compra = :id
              AND es_anulado = 0
        ';

        $row = DB::selectOne($sql, ['id' => $idComprobante]);

        return [
            'neto' => (float) ($row->neto ?? 0),
            'detraccion' => (float) ($row->detraccion ?? 0),
        ];
    }

    /**
     * Construir el JSON inicial de aprobaciones (3 entradas con esta_aprobado=false).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function build_aprobaciones_iniciales(): array
    {
        return [
            ['tipo' => TipoAprobacionComprobante::Contabilidad->value, 'id_empleado' => null, 'created_at' => null, 'esta_aprobado' => false],
            ['tipo' => TipoAprobacionComprobante::Comercial->value, 'id_empleado' => null, 'created_at' => null, 'esta_aprobado' => false],
            ['tipo' => TipoAprobacionComprobante::Documentaria->value, 'id_empleado' => null, 'created_at' => null, 'esta_aprobado' => false],
        ];
    }

    /**
     * Mapear enum medio_pago de string a Enum o null-safe.
     */
    public static function parse_medio_pago(string $medioPago): ?MedioPagoComprobante
    {
        return MedioPagoComprobante::tryFrom($medioPago);
    }
}
