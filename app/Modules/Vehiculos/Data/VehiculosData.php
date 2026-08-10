<?php

namespace App\Modules\Vehiculos\Data;

use App\Models\Vehiculo;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class VehiculosData
{
    public static function get_vehiculos(?int $id = null)
    {
        $sql = '
        SELECT
            v.id,
            v.id_marca,
            m.nombre AS marca_nombre,
            v.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            et.ruc AS empresa_transporte_ruc,
            v.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            v.placa,
            v.numero_constancia_mtc,
            CAST(v.capacidad AS DECIMAL(10,2)) AS capacidad,
            CAST(v.tara AS DECIMAL(10,2)) AS tara,
            CAST(v.largo AS DECIMAL(5,2)) AS largo,
            CAST(v.ancho AS DECIMAL(5,2)) AS ancho,
            CAST(v.alto AS DECIMAL(5,2)) AS alto,
            v.estado
        FROM
            vehiculo v
        LEFT JOIN marca m ON m.id = v.id_marca
        LEFT JOIN empresa_transporte et ON et.id = v.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = v.id_tipo_vehiculo
        WHERE 1 = 1
          AND (v.placa IS NULL OR v.placa NOT LIKE \'FICT%\')
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND v.id = :id';
            $params['id'] = $id;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY v.placa ASC;';

        return DB::select($sql, $params);
    }

    public static function get_vehiculo_by_id(int $id): array
    {
        return self::get_vehiculos(id: $id);
    }

    public static function crear_vehiculo(
        int $idMarca,
        int $idEmpresaTransporte,
        int $idTipoVehiculo,
        string $placa,
        ?string $numeroConstanciaMtc,
        float $capacidad,
        float $tara,
        ?float $largo,
        ?float $ancho,
        ?float $alto
    ): int {
        $existenteId = self::buscar_vehiculo_existente($placa);
        if ($existenteId !== null) {
            return $existenteId;
        }

        return Vehiculo::insertGetId([
            'id_marca' => $idMarca,
            'id_empresa_transporte' => $idEmpresaTransporte,
            'id_tipo_vehiculo' => $idTipoVehiculo,
            'placa' => $placa,
            'numero_constancia_mtc' => $numeroConstanciaMtc,
            'capacidad' => $capacidad,
            'tara' => $tara,
            'largo' => $largo,
            'ancho' => $ancho,
            'alto' => $alto,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function buscar_vehiculo_existente(string $placa): ?int
    {
        return DB::table('vehiculo')->where('placa', $placa)->value('id');
    }

    public static function editar_vehiculo(
        int $id,
        int $idMarca,
        int $idEmpresaTransporte,
        int $idTipoVehiculo,
        string $placa,
        ?string $numeroConstanciaMtc,
        float $capacidad,
        float $tara,
        ?float $largo,
        ?float $ancho,
        ?float $alto
    ): bool {
        return Vehiculo::where('id', $id)->update([
            'id_marca' => $idMarca,
            'id_empresa_transporte' => $idEmpresaTransporte,
            'id_tipo_vehiculo' => $idTipoVehiculo,
            'placa' => $placa,
            'numero_constancia_mtc' => $numeroConstanciaMtc,
            'capacidad' => $capacidad,
            'tara' => $tara,
            'largo' => $largo,
            'ancho' => $ancho,
            'alto' => $alto,
        ]) >= 0;
    }

    public static function cambiar_estado_vehiculo(int $id, string $estado): bool
    {
        return Vehiculo::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }
}
