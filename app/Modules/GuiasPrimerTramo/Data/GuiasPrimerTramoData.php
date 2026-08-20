<?php

namespace App\Modules\GuiasPrimerTramo\Data;

use Illuminate\Support\Facades\DB;

class GuiasPrimerTramoData
{
    /**
     * Obtener listado de guías de primer tramo filtradas por sucursal.
     */
    public static function get_guias(array $filters): array
    {
        $sql = '
        SELECT
            gpt.id,
            gpt.id_sucursal,
            suc.nombre AS sucursal_nombre,
            gpt.id_proveedor,
            p.razon_social AS proveedor_razon_social,
            IFNULL(p.ruc, p.dni) AS proveedor_documento,
            gpt.id_concesion,
            c.nombre AS concesion_nombre,
            gpt.id_conductor,
            CONCAT(cd.nombre, " ", cd.apellido) AS conductor_nombre,
            cd.dni AS conductor_dni,
            cd.numero_licencia AS conductor_licencia,
            gpt.id_vehiculo,
            v.placa AS vehiculo_placa,
            gpt.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            gpt.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            gpt.id_empresa_transporte_carreta,
            etc.razon_social AS empresa_transporte_carreta_razon_social,
            gpt.motivo_traslado,
            gpt.condicion_ingreso,
            gpt.fecha_inicio_traslado,
            gpt.fecha_emision,
            gpt.fecha_en_planta,
            gpt.guia_remitente,
            gpt.guia_transportista,
            gpt.sin_guia_transportista,
            gpt.documentos,
            gpt.id_empleado_registro,
            gpt.log_cambios,
            gpt.estado,
            gpt.created_at
        FROM guia_primer_tramo gpt
        INNER JOIN sucursal suc ON suc.id = gpt.id_sucursal
        INNER JOIN proveedor p ON p.id = gpt.id_proveedor
        LEFT JOIN concesion c ON c.id = gpt.id_concesion
        INNER JOIN conductor cd ON cd.id = gpt.id_conductor
        INNER JOIN vehiculo v ON v.id = gpt.id_vehiculo
        LEFT JOIN empresa_transporte et ON et.id = gpt.id_empresa_transporte
        LEFT JOIN vehiculo vc ON vc.id = gpt.id_vehiculo_carreta
        LEFT JOIN empresa_transporte etc ON etc.id = gpt.id_empresa_transporte_carreta
        WHERE gpt.id_sucursal = :id_sucursal
        ';

        $params = ['id_sucursal' => (int) $filters['id_sucursal']];

        if (! empty($filters['id_proveedor'])) {
            $sql .= ' AND gpt.id_proveedor = :id_proveedor';
            $params['id_proveedor'] = (int) $filters['id_proveedor'];
        }

        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND DATE(gpt.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'];
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND DATE(gpt.created_at) <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'];
        }

        if (! empty($filters['guia_remitente'])) {
            $sql .= ' AND gpt.guia_remitente LIKE :guia_remitente';
            $params['guia_remitente'] = '%'.$filters['guia_remitente'].'%';
        }

        $sql .= ' ORDER BY gpt.created_at DESC;';

        $rows = DB::select($sql, $params);

        foreach ($rows as $row) {
            $row->documentos = isset($row->documentos) ? json_decode($row->documentos, true) ?? [] : [];
            $row->log_cambios = isset($row->log_cambios) ? json_decode($row->log_cambios, true) ?? [] : [];
            $row->sin_guia_transportista = (bool) $row->sin_guia_transportista;
            $row->id_empleado_registro = $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null;
            $row->lotes = self::get_lotes_guia((int) $row->id);
        }

        return $rows;
    }

    /**
     * Obtener una guía por id con todos sus lotes.
     */
    public static function get_guia_by_id(int $id): ?array
    {
        $sql = '
        SELECT
            gpt.*,
            suc.nombre AS sucursal_nombre,
            p.razon_social AS proveedor_razon_social,
            IFNULL(p.ruc, p.dni) AS proveedor_documento,
            c.nombre AS concesion_nombre,
            CONCAT(cd.nombre, " ", cd.apellido) AS conductor_nombre,
            cd.dni AS conductor_dni,
            cd.numero_licencia AS conductor_licencia,
            v.placa AS vehiculo_placa,
            et.razon_social AS empresa_transporte_razon_social,
            vc.placa AS vehiculo_carreta_placa,
            etc.razon_social AS empresa_transporte_carreta_razon_social
        FROM guia_primer_tramo gpt
        INNER JOIN sucursal suc ON suc.id = gpt.id_sucursal
        INNER JOIN proveedor p ON p.id = gpt.id_proveedor
        LEFT JOIN concesion c ON c.id = gpt.id_concesion
        INNER JOIN conductor cd ON cd.id = gpt.id_conductor
        INNER JOIN vehiculo v ON v.id = gpt.id_vehiculo
        LEFT JOIN empresa_transporte et ON et.id = gpt.id_empresa_transporte
        LEFT JOIN vehiculo vc ON vc.id = gpt.id_vehiculo_carreta
        LEFT JOIN empresa_transporte etc ON etc.id = gpt.id_empresa_transporte_carreta
        WHERE gpt.id = :id
        LIMIT 1
        ';

        $row = DB::selectOne($sql, ['id' => $id]);

        if (! $row) {
            return null;
        }

        $row->documentos = isset($row->documentos) ? json_decode($row->documentos, true) ?? [] : [];
        $row->log_cambios = isset($row->log_cambios) ? json_decode($row->log_cambios, true) ?? [] : [];
        $row->sin_guia_transportista = (bool) $row->sin_guia_transportista;
        $row->id_empleado_registro = $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null;
        $row->lotes = self::get_lotes_guia($id);

        return (array) $row;
    }

    /**
     * Obtener los items (lote o partición) asociados a una guía con shape unificado.
     */
    public static function get_lotes_guia(int $idGuia): array
    {
        $sql = '
        SELECT
            lg.id,
            lg.id_guia_primer_tramo,
            lg.id_lote_mineral,
            lg.id_particion_lote_mineral,
            lg.created_at,
            CASE
                WHEN lg.id_particion_lote_mineral IS NOT NULL THEN "PARTICION"
                ELSE "LOTE"
            END AS tipo_item,
            COALESCE(plm.correlativo, lm.correlativo) AS correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            COALESCE(plm.peso_inicial, lm.peso_inicial) AS peso_inicial,
            COALESCE(plm.peso_final, lm.peso_final) AS peso_final,
            COALESCE(plm.peso_neto, lm.peso_neto) AS peso_neto
        FROM lote_guia lg
        LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
        LEFT JOIN particion_lote_mineral plm ON plm.id = lg.id_particion_lote_mineral
        WHERE lg.id_guia_primer_tramo = :id_guia
        ORDER BY lg.id ASC
        ';

        $rows = DB::select($sql, ['id_guia' => $idGuia]);

        foreach ($rows as $row) {
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
        }

        return $rows;
    }

    /**
     * Obtener metadatos para los filtros de la página (proveedores, rango fechas disponibles).
     */
    public static function get_filtros_metadata(int $idSucursal): array
    {
        $proveedores = DB::select('
            SELECT DISTINCT p.id, p.razon_social
            FROM guia_primer_tramo gpt
            INNER JOIN proveedor p ON p.id = gpt.id_proveedor
            WHERE gpt.id_sucursal = :id_sucursal
            ORDER BY p.razon_social ASC
        ', ['id_sucursal' => $idSucursal]);

        return [
            'proveedores' => $proveedores,
        ];
    }
}
