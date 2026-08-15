<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EmpresasData
{
    /**
     * Obtener listado simple de empresas
     */
    public static function get_empresas(
        ?int $id_empresa = null,
        ?EstadoBase $estado = EstadoBase::Activo,
    ): array {
        $sql = '
        SELECT
            emp.id AS id_empresa,
            emp.ruc,
            emp.razon_social,
            emp.path_logo,
            (
                SELECT
                    COUNT(*)
                FROM
                    cuenta_bancaria_empresa cb
                WHERE
                    cb.id_empresa = emp.id AND
                    cb.estado = "Activo"
            ) as cantidad_cuentas_bancarias
        FROM
            empresa emp
        WHERE 1=1
        ';

        $params = [];

        if ($id_empresa !== null) {
            $sql .= ' AND emp.id = :id_empresa';
            $params['id_empresa'] = $id_empresa;

            return DB::selectOne($sql, $params);
        }

        if ($estado !== null) {
            $sql .= ' AND emp.estado = :estado';
            $params['estado'] = $estado->value;
        }

        $sql .= ' ORDER BY razon_social ASC';

        return DB::select($sql, $params);
    }
}
