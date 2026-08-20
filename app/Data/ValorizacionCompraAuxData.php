<?php

namespace App\Data;

use App\Models\CondicionComercialProveedor;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Enums\_Generic\EstadoAnticipoProveedor;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\ValorizacionCompra\EstadoValorizacionCompra;
use Illuminate\Support\Facades\DB;

class ValorizacionCompraAuxData
{
    /**
     * Obtener listado de proveedores que poseen lotes comercializables con guías y no valorizados
     */
    public static function get_proveedores_con_lotes(): array
    {
        $sql = '
            SELECT DISTINCT
                p.id,
                p.id AS id_proveedor,
                p.tipo_entidad,
                p.dni,
                p.ruc,
                IFNULL(p.ruc, p.dni) AS documento,
                p.razon_social,
                p.direccion,
                p.telefono,
                p.correo,
                p.estado
            FROM proveedor p
            INNER JOIN (
                SELECT lg.id AS id_lote_guia, lm.con_valor_comercial, COALESCE(gpt.id_proveedor, lm.id_proveedor_minero) AS id_proveedor
                FROM lote_guia lg
                INNER JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
                LEFT JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
            ) t ON t.id_proveedor = p.id
            WHERE t.con_valor_comercial = 1
              AND (
                  t.id_lote_guia NOT IN (
                      SELECT vcd.id_lote_guia
                      FROM valorizacion_compramineral_detalle vcd
                      INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                      WHERE vc.estado != :estado_anulado_1
                        AND vcd.elemento_quimico = "Oro"
                  )
                  OR
                  t.id_lote_guia NOT IN (
                      SELECT vcd.id_lote_guia
                      FROM valorizacion_compramineral_detalle vcd
                      INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                      WHERE vc.estado != :estado_anulado_2
                        AND vcd.elemento_quimico = "Plata"
                  )
              )
            ORDER BY p.razon_social ASC;
        ';

        $anuladoVal = EstadoValorizacionCompra::Anulado->value;

        return DB::select($sql, [
            'estado_anulado_1' => $anuladoVal,
            'estado_anulado_2' => $anuladoVal,
        ]);
    }

    /**
     * Obtener concesiones asociadas a un proveedor
     */
    public static function get_concesiones_proveedor(int $idProveedor): array
    {
        $sql = "
            SELECT 
                c.id,
                c.nombre,
                c.codigo_reinfo,
                c.estado,
                c.id_departamento,
                c.id_provincia,
                c.id_distrito,
                dep.nombre AS departamento_nombre,
                prov.nombre AS provincia_nombre,
                dist.nombre AS distrito_nombre,
                CONCAT_WS(' - ', dist.nombre, prov.nombre, dep.nombre) AS procedencia
            FROM concesion_proveedor cp
            INNER JOIN concesion c ON c.id = cp.id_concesion
            LEFT JOIN departamento dep ON dep.id = c.id_departamento
            LEFT JOIN provincia prov ON prov.id = c.id_provincia
            LEFT JOIN distrito dist ON dist.id = c.id_distrito
            WHERE cp.id_proveedor = :id_proveedor
            ORDER BY c.nombre ASC;
        ";

        return DB::select($sql, ['id_proveedor' => $idProveedor]);
    }

    /**
     * Obtener cuentas bancarias de un proveedor
     */
    public static function get_cuentas_bancarias_proveedor(int $idProveedor): array
    {
        $sql = '
            SELECT 
                cb.id,
                cb.id_proveedor,
                cb.id_banco,
                cb.moneda,
                cb.numero_cuenta,
                cb.cci,
                cb.es_para_detraccion,
                cb.estado,
                b.nombre AS banco_nombre
            FROM cuenta_bancaria_proveedor cb
            LEFT JOIN banco b ON b.id = cb.id_banco
            WHERE cb.id_proveedor = :id_proveedor
              AND (cb.estado = :estado_activo OR cb.estado = :estado_activo_upper)
            ORDER BY cb.es_para_detraccion ASC, cb.id ASC;
        ';

        return DB::select($sql, [
            'id_proveedor' => $idProveedor,
            'estado_activo' => EstadoBase::Activo->value,
            'estado_activo_upper' => strtoupper(EstadoBase::Activo->value),
        ]);
    }

    /**
     * Obtener anticipos con saldo disponible de un proveedor
     */
    public static function get_anticipos_proveedor(int $idProveedor): array
    {
        $sql = '
            SELECT 
                a.id,
                a.id_proveedor_minero,
                a.serie_factura,
                a.numero_factura,
                CONCAT(a.serie_factura, "-", a.numero_factura) AS factura,
                a.saldo_inicial,
                a.saldo_actual,
                a.estado,
                a.created_at
            FROM anticipo_proveedor a
            WHERE a.id_proveedor_minero = :id_proveedor
              AND a.estado != :estado_anulado
              AND a.saldo_actual > 0
            ORDER BY a.created_at ASC, a.id ASC;
        ';

        $rows = DB::select($sql, [
            'id_proveedor' => $idProveedor,
            'estado_anulado' => EstadoAnticipoProveedor::Anulado->value,
        ]);

        foreach ($rows as $r) {
            $r->saldo_inicial = (float) $r->saldo_inicial;
            $r->saldo_actual = (float) $r->saldo_actual;
        }

        return $rows;
    }

    /**
     * Obtener lotes disponibles con sus análisis y condiciones comerciales por ley
     */
    public static function get_lotes_disponibles_valorizacion(int $idProveedor, ?int $idValorizacionEdicion = null): array
    {
        $sql = '
            SELECT 
                lg.id AS id_lote_guia,
                lm.id AS id_lote_mineral,
                lm.numero_correlativo AS codigo_gel,
                lm.correlativo AS correlativo_lote,
                gpt.guia_remitente,
                gpt.guia_remitente AS grr,
                gpt.guia_transportista,
                CASE WHEN gpt.sin_guia_transportista = 1 OR gpt.guia_transportista IS NULL OR gpt.guia_transportista = \'\' THEN NULL ELSE gpt.guia_transportista END AS grt,
                gpt.fecha_en_planta,
                lm.peso_neto AS tmh,
                COALESCE(lm.ley_humedad, 0) AS ley_humedad,
                (lm.peso_neto * (1 - (COALESCE(lm.ley_humedad, 0) / 100))) AS tms,
                COALESCE(lm.ley_oro, 0) AS ley_oro,
                COALESCE(lm.ley_plata, 0) AS ley_plata,
                EXISTS (
                    SELECT 1 
                    FROM valorizacion_compramineral_detalle vcd
                    INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                    WHERE vcd.id_lote_guia = lg.id
                      AND vc.estado != :estado_anulado_1
                      AND vcd.elemento_quimico = "Oro"
                      AND (:id_val_edicion_1 IS NULL OR vc.id != :id_val_edicion_2)
                ) AS es_valorizado_oro,
                EXISTS (
                    SELECT 1 
                    FROM valorizacion_compramineral_detalle vcd
                    INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                    WHERE vcd.id_lote_guia = lg.id
                      AND vc.estado != :estado_anulado_3
                      AND vcd.elemento_quimico = "Plata"
                      AND (:id_val_edicion_3 IS NULL OR vc.id != :id_val_edicion_4)
                ) AS es_valorizado_plata
            FROM lote_guia lg
            INNER JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
            LEFT JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
            WHERE COALESCE(gpt.id_proveedor, lm.id_proveedor_minero) = :id_proveedor
              AND lm.con_valor_comercial = 1
              AND (
                  lg.id NOT IN (
                      SELECT vcd.id_lote_guia
                      FROM valorizacion_compramineral_detalle vcd
                      INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                      WHERE vc.estado != :estado_anulado_5
                        AND vcd.elemento_quimico = "Oro"
                        AND (:id_val_edicion_5 IS NULL OR vc.id != :id_val_edicion_6)
                  )
                  OR
                  lg.id NOT IN (
                      SELECT vcd.id_lote_guia
                      FROM valorizacion_compramineral_detalle vcd
                      INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
                      WHERE vc.estado != :estado_anulado_7
                        AND vcd.elemento_quimico = "Plata"
                        AND (:id_val_edicion_7 IS NULL OR vc.id != :id_val_edicion_8)
                  )
              )
            ORDER BY gpt.fecha_en_planta ASC, lm.numero_correlativo ASC;
        ';

        $anuladoVal = EstadoValorizacionCompra::Anulado->value;
        $lotes = DB::select($sql, [
            'id_proveedor' => $idProveedor,
            'estado_anulado_1' => $anuladoVal,
            'id_val_edicion_1' => $idValorizacionEdicion,
            'id_val_edicion_2' => $idValorizacionEdicion,
            'estado_anulado_3' => $anuladoVal,
            'id_val_edicion_3' => $idValorizacionEdicion,
            'id_val_edicion_4' => $idValorizacionEdicion,
            'estado_anulado_5' => $anuladoVal,
            'id_val_edicion_5' => $idValorizacionEdicion,
            'id_val_edicion_6' => $idValorizacionEdicion,
            'estado_anulado_7' => $anuladoVal,
            'id_val_edicion_7' => $idValorizacionEdicion,
            'id_val_edicion_8' => $idValorizacionEdicion,
        ]);

        // Cargar condiciones comerciales del proveedor (Oro y Plata)
        $condiciones = CondicionComercialProveedor::query()
            ->where('id_proveedor_minero', $idProveedor)
            ->where('estado', EstadoBase::Activo->value)
            ->get();

        foreach ($lotes as $lote) {
            $lote->tmh = (float) $lote->tmh;
            $lote->ley_humedad = (float) $lote->ley_humedad;
            $lote->tms = (float) $lote->tms;
            $lote->ley_oro = (float) $lote->ley_oro;
            $lote->ley_plata = (float) $lote->ley_plata;
            $lote->es_valorizado_oro = (bool) $lote->es_valorizado_oro;
            $lote->es_valorizado_plata = (bool) $lote->es_valorizado_plata;

            // Buscar condición comercial para Oro
            $condOro = $condiciones->first(function ($c) use ($lote) {
                if ($c->elemento_quimico !== ElementoQuimicoValorizacion::Oro) {
                    return false;
                }
                $inicio = (float) $c->ley_inicio;
                $fin = (float) $c->ley_fin;

                return $lote->ley_oro >= $inicio && $lote->ley_oro <= $fin;
            });

            $lote->condicion_oro = $condOro ? [
                'id_condicion_comercial' => $condOro->id,
                'recuperacion' => (float) $condOro->recuperacion,
                'maquila' => (float) $condOro->maquila,
                'consumo' => (float) $condOro->consumo,
            ] : null;

            // Buscar condición comercial para Plata
            $condPlata = $condiciones->first(function ($c) use ($lote) {
                if ($c->elemento_quimico !== ElementoQuimicoValorizacion::Plata) {
                    return false;
                }
                $inicio = (float) $c->ley_inicio;
                $fin = (float) $c->ley_fin;

                return $lote->ley_plata >= $inicio && $lote->ley_plata <= $fin;
            });

            $lote->condicion_plata = $condPlata ? [
                'id_condicion_comercial' => $condPlata->id,
                'recuperacion' => (float) $condPlata->recuperacion,
                'maquila' => (float) $condPlata->maquila,
                'consumo' => (float) $condPlata->consumo,
            ] : null;
        }

        return $lotes;
    }

    /**
     * Obtener valorizaciones aprobadas de un proveedor (sin comprobante de compra aún)
     * para usarlas en el formulario de registro de comprobantes.
     *
     * NOTA: Cada placeholder aparece una sola vez con nombre único para evitar
     * el error HY093 de PDO cuando un named param se reutiliza en subqueries.
     *
     * @return array<int,object>
     */
    public static function get_valorizaciones_aprobadas_por_proveedor(int $idProveedor): array
    {
        $estadoAprobado = EstadoValorizacionCompra::Aprobado->value;
        $estadoAnulado = EstadoValorizacionCompra::Anulado->value;

        $sql = '
            SELECT
                vc.id,
                vc.id_proveedor_minero,
                vc.numero_correlativo,
                vc.tipo_pago,
                vc.fecha_hora_aprobacion,
                vc.estado,
                vc.created_at,
                p.razon_social AS proveedor_nombre,
                c.nombre AS concesion_nombre,
                COALESCE((SELECT SUM(vcd.subtotal) FROM valorizacion_compramineral_detalle vcd WHERE vcd.id_valorizacion_compra = vc.id), 0) AS total_dolares,
                COALESCE((SELECT SUM(tap.monto_retirado) FROM transaccion_anticipo_proveedor tap WHERE tap.id_valorizacion_compra = vc.id AND tap.estado = :tap_estado_aprobado), 0) AS monto_anticipos
            FROM valorizacion_compra vc
            INNER JOIN proveedor p ON p.id = vc.id_proveedor_minero
            INNER JOIN concesion c ON c.id = vc.id_concesion
            WHERE vc.id_proveedor_minero = :id_proveedor
              AND vc.estado = :vc_estado_aprobado
              AND vc.id NOT IN (
                  SELECT cc.id_valorizacion_compra
                  FROM comprobante_compra cc
                  WHERE cc.estado != :cc_estado_anulado
              )
            ORDER BY vc.fecha_hora_aprobacion DESC, vc.id DESC
        ';

        return self::cast_valorizaciones_aprobadas(DB::select($sql, [
            'id_proveedor' => $idProveedor,
            'tap_estado_aprobado' => $estadoAprobado,
            'vc_estado_aprobado' => $estadoAprobado,
            'cc_estado_anulado' => $estadoAnulado,
        ]));
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<int,object>
     */
    private static function cast_valorizaciones_aprobadas(array $rows): array
    {
        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_proveedor_minero = (int) $r->id_proveedor_minero;
            $r->total_dolares = (float) $r->total_dolares;
            $r->monto_anticipos = (float) $r->monto_anticipos;
        }

        return $rows;
    }
}
