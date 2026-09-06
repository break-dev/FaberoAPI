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
                SELECT lg.id AS id_lote_guia, lm.con_valor_comercial, gpt.id_proveedor AS id_proveedor
                FROM lote_guia lg
                INNER JOIN lote_mineral lm ON lm.id = COALESCE(
                    lg.id_lote_mineral,
                    (SELECT id_lote_mineral FROM particion_lote_mineral WHERE id = lg.id_particion_lote_mineral)
                ) AND (lm.estado IS NULL OR lm.estado <> :estado_lote_no_eliminado)
                LEFT JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                WHERE lm.con_valor_comercial = 1
                  -- El lote padre debe estar validado.
                  AND lm.esta_validado = 1
                  -- Si el lote tiene particiones activas, TODAS deben estar validadas.
                  AND NOT EXISTS (
                      SELECT 1
                      FROM particion_lote_mineral plm_check
                      WHERE plm_check.id_lote_mineral = lm.id
                        AND plm_check.estado = \'Activo\'
                        AND plm_check.esta_validado = 0
                  )
                  -- La guia asociada (si existe) NO debe estar anulada.
                  AND (
                      lg.id_guia_primer_tramo IS NULL
                      OR COALESCE(gpt.estado, \'Activo\') <> \'Anulado\'
                  )
                  -- Si lote_guia es a nivel de particion, esa particion debe estar validada y activa.
                  AND (
                      lg.id_particion_lote_mineral IS NULL
                      OR EXISTS (
                          SELECT 1
                          FROM particion_lote_mineral plm
                          WHERE plm.id = lg.id_particion_lote_mineral
                            AND plm.estado = \'Activo\'
                            AND plm.esta_validado = 1
                      )
                  )
                  -- Excluir el lote completo si ya tiene AMBOS elementos (Oro y Plata) valorizados.
                  AND NOT (lm.esta_valorizado_oro = 1 AND lm.esta_valorizado_plata = 1)
            ) t ON t.id_proveedor = p.id
            WHERE t.con_valor_comercial = 1
            ORDER BY p.razon_social ASC;
        ';

        return DB::select($sql, ['estado_lote_no_eliminado' => EstadoBase::Eliminado->value]);
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

        return DB::select($sql, [
            'id_proveedor' => $idProveedor,
        ]);
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
                id_lote_guia,
                id_lote_mineral,
                numero_correlativo,
                correlativo_lote,
                grr,
                grt,
                fecha_en_planta,
                tmh,
                ley_humedad,
                tms,
                ley_oro,
                ley_plata,
                es_valorizado_oro,
                es_valorizado_plata
            FROM (
                SELECT
                    lg.id AS id_lote_guia,
                    lm.id AS id_lote_mineral,
                    lm.numero_correlativo AS numero_correlativo,
                    lm.correlativo AS correlativo_lote,
                    gpt.guia_remitente AS grr,
                    CASE WHEN gpt.sin_guia_transportista = 1 OR gpt.guia_transportista IS NULL OR gpt.guia_transportista = \'\' THEN NULL ELSE gpt.guia_transportista END AS grt,
                    gpt.fecha_en_planta,
                    COALESCE(lm.peso_neto_oficial, 0) AS tmh,
                    COALESCE(lm.ley_humedad, 0) AS ley_humedad,
                    COALESCE(lm.peso_neto_oficial, 0) * (1 - (COALESCE(lm.ley_humedad, 0) / 100)) AS tms,
                    COALESCE(lm.ley_oro, 0) AS ley_oro,
                    COALESCE(lm.ley_plata, 0) AS ley_plata,
                    lm.esta_valorizado_oro AS es_valorizado_oro,
                    lm.esta_valorizado_plata AS es_valorizado_plata,
                    ROW_NUMBER() OVER (PARTITION BY lm.id ORDER BY lg.id ASC) AS rn
                FROM lote_guia lg
                INNER JOIN lote_mineral lm ON lm.id = COALESCE(
                    lg.id_lote_mineral,
                    (SELECT id_lote_mineral FROM particion_lote_mineral WHERE id = lg.id_particion_lote_mineral)
                ) AND (lm.estado IS NULL OR lm.estado <> :estado_lote_no_eliminado)
                LEFT JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                WHERE COALESCE(gpt.id_proveedor, lm.id_proveedor_minero) = :id_proveedor
                  AND lm.con_valor_comercial = 1
                  AND lm.peso_neto > 0
                  -- El lote padre debe estar validado.
                  AND lm.esta_validado = 1
                  -- Si el lote tiene particiones activas, TODAS deben estar validadas.
                  AND NOT EXISTS (
                      SELECT 1
                      FROM particion_lote_mineral plm_check
                      WHERE plm_check.id_lote_mineral = lm.id
                        AND plm_check.estado = \'Activo\'
                        AND plm_check.esta_validado = 0
                  )
                  -- La guia asociada (si existe) NO debe estar anulada.
                  AND (
                      lg.id_guia_primer_tramo IS NULL
                      OR COALESCE(gpt.estado, \'Activo\') <> \'Anulado\'
                  )
                  -- La fila de lote_guia debe cumplir su propia condicion segun el nivel:
                  -- a nivel de lote: ninguna extra. a nivel de particion: la particion
                  -- debe existir, estar activa y validada.
                  AND (
                      lg.id_particion_lote_mineral IS NULL
                      OR EXISTS (
                          SELECT 1
                          FROM particion_lote_mineral plm
                          WHERE plm.id = lg.id_particion_lote_mineral
                            AND plm.estado = \'Activo\'
                            AND plm.esta_validado = 1
                      )
                  )
                  AND (
                      lm.tiene_particion = 0
                      OR COALESCE(
                          (
                              SELECT SUM(plm.peso_neto)
                              FROM particion_lote_mineral plm
                              WHERE plm.id_lote_mineral = lm.id
                                AND plm.peso_neto > 0
                          ),
                          0
                      ) = lm.peso_neto
                  )
                  -- Excluir el lote completo si ya tiene AMBOS elementos (Oro y Plata) valorizados.
                  AND NOT (lm.esta_valorizado_oro = 1 AND lm.esta_valorizado_plata = 1)
            ) ranked
            WHERE ranked.rn = 1
            ORDER BY ranked.fecha_en_planta ASC, ranked.numero_correlativo ASC;
        ';

        $lotes = DB::select($sql, [
            'id_proveedor' => $idProveedor,
            'estado_lote_no_eliminado' => EstadoBase::Eliminado->value,
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
