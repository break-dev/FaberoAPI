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
            e.path_logo,
            e.id_departamento,
            d.nombre AS departamento_nombre,
            e.id_provincia,
            p.nombre AS provincia_nombre,
            e.id_distrito,
            di.nombre AS distrito_nombre,
            e.domicilio_fiscal
        FROM
            empresa e
        LEFT JOIN departamento d ON d.id = e.id_departamento
        LEFT JOIN provincia p ON p.id = e.id_provincia
        LEFT JOIN distrito di ON di.id = e.id_distrito
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
     *
     * @param  array{
     *   ruc: string,
     *   razon_social: string,
     *   path_logo?: string|null,
     *   id_departamento?: int|null,
     *   id_provincia?: int|null,
     *   id_distrito?: int|null,
     *   domicilio_fiscal?: string|null
     * }  $data
     */
    public static function crear_empresa(array $data): int
    {
        return Empresa::insertGetId([
            'ruc' => $data['ruc'],
            'razon_social' => $data['razon_social'],
            'path_logo' => $data['path_logo'] ?? null,
            'id_departamento' => $data['id_departamento'] ?? null,
            'id_provincia' => $data['id_provincia'] ?? null,
            'id_distrito' => $data['id_distrito'] ?? null,
            'domicilio_fiscal' => $data['domicilio_fiscal'] ?? null,
        ]);
    }

    /**
     * Verificar si ya existe una empresa con el mismo RUC.
     * Permite excluir una fila por id (para updates).
     */
    public static function verificar_ruc_duplicado(string $ruc, ?int $excluirId = null): bool
    {
        $query = Empresa::where('ruc', $ruc);
        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->exists();
    }

    /**
     * Actualizar la ruta del logo de una empresa
     */
    public static function actualizar_logo(int $id_empresa, ?string $path_logo): bool
    {
        return (bool) Empresa::where('id', $id_empresa)->update(['path_logo' => $path_logo]);
    }

    /**
     * Actualizar datos de una empresa (excepto logo).
     *
     * @param  array{
     *   razon_social?: string,
     *   id_departamento?: int|null,
     *   id_provincia?: int|null,
     *   id_distrito?: int|null,
     *   domicilio_fiscal?: string|null
     * }  $data
     */
    public static function actualizar_empresa(int $idEmpresa, array $data): bool
    {
        $update = [];
        if (array_key_exists('razon_social', $data)) {
            $update['razon_social'] = $data['razon_social'];
        }
        if (array_key_exists('id_departamento', $data)) {
            $update['id_departamento'] = $data['id_departamento'];
        }
        if (array_key_exists('id_provincia', $data)) {
            $update['id_provincia'] = $data['id_provincia'];
        }
        if (array_key_exists('id_distrito', $data)) {
            $update['id_distrito'] = $data['id_distrito'];
        }
        if (array_key_exists('domicilio_fiscal', $data)) {
            $update['domicilio_fiscal'] = $data['domicilio_fiscal'];
        }

        if (empty($update)) {
            return true;
        }

        return (bool) Empresa::where('id', $idEmpresa)->update($update);
    }
}
