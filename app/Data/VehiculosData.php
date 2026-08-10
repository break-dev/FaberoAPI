<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class VehiculosData
{
    /**
     * Obtener listado global de vehículos simplificado, filtrable por placa y/o ID.
     */
    public static function get_vehiculos(?string $placa = null, ?int $id = null, ?bool $esCarreta = null)
    {
        $sql = "
        SELECT
            v.id AS id_vehiculo,
            et.id AS id_empresa_transporte,
            tv.id AS id_tipo_vehiculo,
            et.razon_social,
            tv.nombre AS tipo_vehiculo_nombre,
            tv.es_carreta,
            v.placa,
            v.estado,
            (
                SELECT ru2.id_conductor
                FROM recepcion_unidad ru2
                WHERE ru2.id_vehiculo = v.id
                ORDER BY ru2.id DESC
                LIMIT 1
            ) AS last_id_conductor
        FROM
            vehiculo v
        LEFT JOIN empresa_transporte et ON et.id = v.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = v.id_tipo_vehiculo
        WHERE 1 = 1
          AND (v.placa IS NULL OR v.placa NOT LIKE 'FICT%')
        ";

        $params = [];

        if ($id !== null) {
            $sql .= ' AND v.id = :id';
            $params['id'] = $id;
        }

        if ($placa !== null && $placa !== '') {
            $sql .= ' AND v.placa LIKE :placa';
            $params['placa'] = '%'.$placa.'%';
        }

        if ($esCarreta === true) {
            $sql .= ' AND tv.es_carreta = 1';
        } elseif ($esCarreta === false) {
            $sql .= ' AND (tv.es_carreta = 0 OR tv.es_carreta IS NULL)';
        }

        $sql .= ' ORDER BY v.placa ASC;';

        return DB::select($sql, $params);
    }

    /**
     * Crear un nuevo vehículo de forma simplificada.
     * Si ya existe un vehículo con la misma placa, retorna su id sin crear duplicado.
     */
    public static function crear_vehiculo_simplificado(
        string $placa,
        ?int $idEmpresaTransporte = null,
        ?int $idTipoVehiculo = null
    ): int {
        $placaLimpia = trim(strtoupper($placa));
        $existenteId = self::buscar_vehiculo_existente($placaLimpia);
        if ($existenteId !== null) {
            return $existenteId;
        }

        if (empty($idEmpresaTransporte)) {
            $firstEmp = DB::table('empresa_transporte')->first();
            $idEmpresaTransporte = $firstEmp ? (int) $firstEmp->id : 1;
        }

        if (empty($idTipoVehiculo)) {
            $firstTipo = DB::table('tipo_vehiculo')->first();
            $idTipoVehiculo = $firstTipo ? (int) $firstTipo->id : 1;
        }

        return DB::table('vehiculo')->insertGetId([
            'id_marca' => null,
            'id_empresa_transporte' => $idEmpresaTransporte,
            'id_tipo_vehiculo' => $idTipoVehiculo,
            'placa' => $placaLimpia,
            'numero_constancia_mtc' => null,
            'capacidad' => 0.0,
            'tara' => 0.0,
            'largo' => null,
            'ancho' => null,
            'alto' => null,
            'estado' => 'Activo',
        ]);
    }

    public static function buscar_vehiculo_existente(string $placa): ?int
    {
        $placaLimpia = trim(strtoupper($placa));

        return DB::table('vehiculo')
            ->where('placa', $placaLimpia)
            ->value('id');
    }

    /**
     * Editar un vehículo de forma simplificada (transportista y tipo de vehículo)
     */
    public static function editar_vehiculo_simplificado(
        int $id,
        int $idEmpresaTransporte,
        int $idTipoVehiculo
    ): bool {
        return DB::table('vehiculo')->where('id', $id)->update([
            'id_empresa_transporte' => $idEmpresaTransporte,
            'id_tipo_vehiculo' => $idTipoVehiculo,
        ]) >= 0;
    }
}
