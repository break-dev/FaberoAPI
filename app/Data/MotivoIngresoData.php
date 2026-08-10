<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class MotivoIngresoData
{
    public static function get_motivos_ingreso(?bool $esRecepcionUnidad = null): array
    {
        $sql = '
        SELECT
            mi.id AS id_motivo_ingreso,
            mi.nombre,
            mi.es_recepcion_unidad
        FROM
            motivo_ingreso mi
        ORDER BY mi.nombre ASC;
        ';

        return DB::select($sql);
    }

    public static function crear_motivo_ingreso(array $data): array
    {
        $id = DB::table('motivo_ingreso')->insertGetId([
            'nombre' => $data['nombre'],
            'es_recepcion_unidad' => ! empty($data['es_recepcion_unidad']) ? 1 : 0,
        ]);

        return (array) DB::table('motivo_ingreso')
            ->select('id AS id_motivo_ingreso', 'nombre', 'es_recepcion_unidad')
            ->where('id', $id)
            ->first();
    }
}
