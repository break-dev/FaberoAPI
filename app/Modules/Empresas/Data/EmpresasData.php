<?php

namespace App\Modules\Empresas\Data;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;

class EmpresasData
{
    /**
     * Listar o obtener una empresa
     */
    public static function get_empresas(?int $id_empresa = null)
    {
        $sql = '
        SELECT
            e.id AS id_empresa,
            e.ruc,
            e.razon_social,
            e.path_logo
        FROM
            empresa e
        WHERE
            1 = 1
        ';

        $params = [];
        if ($id_empresa !== null) {
            $sql .= ' AND e.id = :id_empresa';
            $params['id_empresa'] = $id_empresa;

            return DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY e.razon_social ASC';

        return DB::select($sql, $params);
    }

    /**
     * Obtener una empresa por su ID
     */
    public static function get_empresa_by_id(int $id_empresa)
    {
        return self::get_empresas(id_empresa: $id_empresa);
    }

    /**
     * Crear una nueva empresa
     */
    public static function crear_empresa(string $ruc, string $razon_social, ?string $path_logo = null)
    {
        return Empresa::insertGetId([
            'ruc' => $ruc,
            'razon_social' => $razon_social,
            'path_logo' => $path_logo,
        ]);
    }

    /**
     * Verificar si ya existe una empresa con el mismo RUC
     */
    public static function verificar_ruc_duplicado(string $ruc)
    {
        return Empresa::where('ruc', $ruc)->exists();
    }

    /**
     * Actualizar la ruta del logo de una empresa
     */
    public static function actualizar_logo(int $id_empresa, ?string $path_logo): bool
    {
        return (bool) Empresa::where('id', $id_empresa)->update(['path_logo' => $path_logo]);
    }
}
