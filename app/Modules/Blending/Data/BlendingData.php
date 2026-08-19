<?php

namespace App\Modules\Blending\Data;

use App\Models\Blending;
use Illuminate\Support\Facades\DB;

class BlendingData
{
    /**
     * Obtener lista de lotes y blendings disponibles para realizar mezclas.
     *
     * @return array<int, object>
     */
    public static function get_disponibles(?int $idProveedor = null, ?int $idEmpresa = null): array
    {
        // 1. Lotes valorizados y pagados con peso_actual > 0
        $sqlLotes = '
            SELECT DISTINCT
                lg.id AS id_lote_guia,
                NULL AS id_reblending,
                "lote" AS tipo_origen,
                lm.correlativo AS codigo,
                lm.correlativo AS correlativo_origen,
                lm.id_empresa,
                emp.razon_social AS empresa_nombre,
                p.id AS id_proveedor,
                p.razon_social AS proveedor_nombre,
                COALESCE(lm.peso_actual, lm.peso_neto) AS tmh_disponible,
                COALESCE(lm.ley_humedad, 0) AS ley_humedad,
                COALESCE(lm.ley_oro, 0) AS ley_oro,
                COALESCE(lm.ley_plata, 0) AS ley_plata
            FROM lote_guia lg
            INNER JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
            LEFT JOIN empresa emp ON emp.id = lm.id_empresa
            INNER JOIN proveedor p ON p.id = lm.id_proveedor_minero
            INNER JOIN valorizacion_compramineral_detalle vcd ON vcd.id_lote_guia = lg.id
            INNER JOIN valorizacion_compra vc ON vc.id = vcd.id_valorizacion_compra
            INNER JOIN comprobante_compra cc ON cc.id_valorizacion_compra = vc.id
            WHERE cc.estado = "Pagado"
              AND COALESCE(lm.peso_actual, lm.peso_neto) > 0
        ';

        $paramsLotes = [];
        if ($idProveedor !== null) {
            $sqlLotes .= ' AND lm.id_proveedor_minero = :id_proveedor';
            $paramsLotes['id_proveedor'] = $idProveedor;
        }

        if ($idEmpresa !== null) {
            $sqlLotes .= ' AND lm.id_empresa = :id_empresa';
            $paramsLotes['id_empresa'] = $idEmpresa;
        }

        $lotes = DB::select($sqlLotes, $paramsLotes);

        // 2. Blendings anteriores con peso_actual > 0
        $sqlBlendings = '
            SELECT
                NULL AS id_lote_guia,
                b.id AS id_reblending,
                "blending" AS tipo_origen,
                b.correlativo AS codigo,
                b.correlativo AS correlativo_origen,
                NULL AS id_empresa,
                NULL AS empresa_nombre,
                NULL AS id_proveedor,
                "Blending" AS proveedor_nombre,
                b.peso_actual AS tmh_disponible,
                COALESCE(b.ley_humedad, 0) AS ley_humedad,
                COALESCE(b.ley_oro, 0) AS ley_oro,
                COALESCE(b.ley_plata, 0) AS ley_plata
            FROM blending b
            WHERE b.peso_actual > 0
        ';

        $blendings = ($idProveedor === null && $idEmpresa === null) ? DB::select($sqlBlendings) : [];

        $items = array_merge($lotes, $blendings);

        foreach ($items as $r) {
            $r->id_lote_guia = $r->id_lote_guia !== null ? (int) $r->id_lote_guia : null;
            $r->id_reblending = $r->id_reblending !== null ? (int) $r->id_reblending : null;
            $r->id_empresa = isset($r->id_empresa) && $r->id_empresa !== null ? (int) $r->id_empresa : null;
            $r->id_proveedor = $r->id_proveedor !== null ? (int) $r->id_proveedor : null;
            $r->tmh_disponible = (float) $r->tmh_disponible;
            $r->ley_humedad = (float) $r->ley_humedad;
            $r->ley_oro = (float) $r->ley_oro;
            $r->ley_plata = (float) $r->ley_plata;
            // TMS = TMH * (1 - H2O / 100)
            $r->tms_disponible = round($r->tmh_disponible * (1 - $r->ley_humedad / 100), 2);
        }

        return $items;
    }

    /**
     * Listar todos los blendings registrados.
     *
     * @return array<int, object>
     */
    public static function get_blendings(?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $sql = '
            SELECT
                b.id,
                b.id_empleado_registro,
                b.correlativo,
                b.numero_correlativo,
                b.fecha_hora_blending,
                b.evidencias,
                b.observacion,
                b.peso_neto,
                b.peso_actual,
                b.ley_oro,
                b.ley_plata,
                b.ley_humedad,
                b.log_cambios,
                b.created_at,
                CONCAT(p.nombre, " ", p.apellido) AS empleado_registro_nombre
            FROM blending b
            LEFT JOIN empleado p ON p.id = b.id_empleado_registro
            WHERE 1=1
        ';

        $params = [];
        if ($fechaInicio) {
            $sql .= ' AND DATE(b.fecha_hora_blending) >= :fecha_inicio';
            $params['fecha_inicio'] = $fechaInicio;
        }
        if ($fechaFin) {
            $sql .= ' AND DATE(b.fecha_hora_blending) <= :fecha_fin';
            $params['fecha_fin'] = $fechaFin;
        }

        $sql .= ' ORDER BY b.id DESC';

        $rows = DB::select($sql, $params);
        $result = [];

        foreach ($rows as $r) {
            $detallesSql = '
                SELECT
                    bd.id,
                    bd.id_blending,
                    bd.id_lote_guia,
                    bd.id_reblending,
                    bd.peso_actual,
                    bd.peso_tomado,
                    bd.created_at,
                    COALESCE(lm.correlativo, b2.correlativo, "") AS codigo,
                    COALESCE(lm.correlativo, b2.correlativo, "") AS correlativo_origen,
                    COALESCE(p.razon_social, "Blending") AS proveedor_nombre,
                    COALESCE(lm.ley_humedad, b2.ley_humedad, 0) AS ley_humedad,
                    COALESCE(lm.ley_oro, b2.ley_oro, 0) AS ley_oro,
                    COALESCE(lm.ley_plata, b2.ley_plata, 0) AS ley_plata
                FROM blending_detalle bd
                LEFT JOIN lote_guia lg ON lg.id = bd.id_lote_guia
                LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
                LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
                LEFT JOIN blending b2 ON b2.id = bd.id_reblending
                WHERE bd.id_blending = :id_blending
                ORDER BY bd.id ASC
            ';

            $detalles = DB::select($detallesSql, ['id_blending' => $r->id]);

            foreach ($detalles as $d) {
                $d->id = (int) $d->id;
                $d->id_blending = (int) $d->id_blending;
                $d->id_lote_guia = $d->id_lote_guia !== null ? (int) $d->id_lote_guia : null;
                $d->id_reblending = $d->id_reblending !== null ? (int) $d->id_reblending : null;
                $d->peso_actual = (float) $d->peso_actual;
                $d->peso_tomado = (float) $d->peso_tomado;
                $d->ley_humedad = (float) $d->ley_humedad;
                $d->ley_oro = (float) $d->ley_oro;
                $d->ley_plata = (float) $d->ley_plata;
                $d->tms_tomado = round($d->peso_tomado * (1 - $d->ley_humedad / 100), 2);
            }

            $evidencias = $r->evidencias;
            while (is_string($evidencias)) {
                $decoded = json_decode($evidencias, true);
                if (json_last_error() !== JSON_ERROR_NONE) break;
                $evidencias = $decoded;
            }

            $evidenciasFormateadas = [];
            if (is_array($evidencias)) {
                foreach ($evidencias as $item) {
                    if (is_array($item) && isset($item['url'])) {
                        $evidenciasFormateadas[] = [
                            'url' => (string) $item['url'],
                            'path_relativo' => (string) ($item['path_relativo'] ?? $item['url']),
                            'nombre_original' => isset($item['nombre_original']) ? (string) $item['nombre_original'] : pathinfo((string) $item['url'], PATHINFO_FILENAME),
                            'extension' => isset($item['extension']) ? (string) $item['extension'] : pathinfo((string) $item['url'], PATHINFO_EXTENSION),
                        ];
                    } elseif (is_string($item) && ! empty($item)) {
                        $evidenciasFormateadas[] = [
                            'url' => $item,
                            'path_relativo' => $item,
                            'nombre_original' => pathinfo($item, PATHINFO_FILENAME) ?: 'Evidencia',
                            'extension' => pathinfo($item, PATHINFO_EXTENSION) ?: 'jpg',
                        ];
                    }
                }
            }

            $logCambios = $r->log_cambios;
            while (is_string($logCambios)) {
                $decoded = json_decode($logCambios, true);
                if (json_last_error() !== JSON_ERROR_NONE) break;
                $logCambios = $decoded;
            }

            $result[] = [
                'id' => (int) $r->id,
                'id_empleado_registro' => (int) $r->id_empleado_registro,
                'empleado_registro_nombre' => $r->empleado_registro_nombre ?? 'Sistema',
                'correlativo' => $r->correlativo,
                'numero_correlativo' => $r->numero_correlativo,
                'fecha_hora_blending' => $r->fecha_hora_blending,
                'evidencias' => $evidenciasFormateadas,
                'observacion' => $r->observacion,
                'peso_neto' => (float) $r->peso_neto,
                'peso_actual' => (float) $r->peso_actual,
                'ley_oro' => (float) $r->ley_oro,
                'ley_plata' => (float) $r->ley_plata,
                'ley_humedad' => (float) $r->ley_humedad,
                'log_cambios' => is_array($logCambios) ? $logCambios : [],
                'created_at' => $r->created_at,
                'detalles' => $detalles,
            ];
        }

        return $result;
    }

    /**
     * Obtener un blending individual por su ID.
     */
    public static function get_blending_by_id(int $id): ?object
    {
        $items = self::get_blendings();
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) {
                return (object) $item;
            }
        }

        return null;
    }

    /**
     * Obtener lista de detalles de un blending por su ID.
     *
     * @return array<int, object>
     */
    public static function get_detalles_by_blending_id(int $idBlending): array
    {
        $blending = self::get_blending_by_id($idBlending);
        if (! $blending || ! isset($blending->detalles) || ! is_array($blending->detalles)) {
            return [];
        }

        return array_map(fn ($d) => is_object($d) ? $d : (object) $d, $blending->detalles);
    }
}
