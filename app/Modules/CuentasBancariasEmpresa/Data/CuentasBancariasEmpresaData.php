<?php

namespace App\Modules\CuentasBancariasEmpresa\Data;

use App\Models\CuentaBancariaEmpresa;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class CuentasBancariasEmpresaData
{
    public static function get_cuentas_bancarias(
        ?int $id_empresa = null,
        ?int $id_cuenta_bancaria = null
    ): array {
        $sql = '
        SELECT
            cn.id AS id_cuenta_bancaria,
            bc.nombre as banco,
            bc.abreviatura as banco_abv,
            cn.id_banco,
            cn.moneda,
            cn.numero_cuenta,
            cn.cci,
            cn.es_para_detraccion,
            cn.estado
        FROM
            cuenta_bancaria_empresa cn
        INNER JOIN banco bc ON bc.id = cn.id_banco
        WHERE 1 = 1
        ';

        $params = [];
        if ($id_empresa !== null) {
            $sql .= ' AND cn.id_empresa = :id_empresa';
            $params['id_empresa'] = $id_empresa;
        }

        if ($id_cuenta_bancaria !== null) {
            $sql .= ' AND cn.id = :id_cuenta_bancaria';
            $params['id_cuenta_bancaria'] = $id_cuenta_bancaria;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY cn.es_para_detraccion DESC, cn.moneda, cn.numero_cuenta;';

        return DB::select($sql, $params);
    }

    public static function get_cuenta_bancaria_by_id(int $id_cuenta_bancaria): array
    {
        return self::get_cuentas_bancarias(id_cuenta_bancaria: $id_cuenta_bancaria);
    }

    public static function crear_cuenta_bancaria(
        int $id_empresa,
        int $id_banco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): int {
        return CuentaBancariaEmpresa::insertGetId([
            'id_empresa' => $id_empresa,
            'id_banco' => $id_banco,
            'moneda' => $moneda,
            'numero_cuenta' => $numeroCuenta,
            'cci' => $cci,
            'es_para_detraccion' => $esParaDetraccion,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_cuenta_bancaria(
        int $id,
        int $idBanco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): bool {
        return CuentaBancariaEmpresa::where('id', $id)->update([
            'id_banco' => $idBanco,
            'moneda' => $moneda,
            'numero_cuenta' => $numeroCuenta,
            'cci' => $cci,
            'es_para_detraccion' => $esParaDetraccion,
        ]) >= 0;
    }

    public static function eliminar_cuenta_bancaria(int $id): bool
    {
        return CuentaBancariaEmpresa::where('id', $id)->delete() > 0;
    }

    public static function existe_cuenta_bancaria(int $id_empresa, int $id_banco, string $numero_cuenta): bool
    {
        return CuentaBancariaEmpresa::where('id_empresa', $id_empresa)
            ->where('id_banco', $id_banco)
            ->where('numero_cuenta', $numero_cuenta)
            ->exists();
    }

    public static function cambiar_estado_cuenta_bancaria(int $id, string $estado): bool
    {
        return CuentaBancariaEmpresa::where('id', $id)->update(['estado' => $estado]) >= 0;
    }

    /**
     * Obtener cuentas bancarias activas filtradas por moneda. Si es_para_detraccion=true
     * restringe a Soles y Banco de la Nación (id_banco del banco con abreviatura 'BN').
     *
     * @return array<int,object>
     */
    public static function get_cuentas_bancarias_por_moneda(string $moneda, bool $esParaDetraccion = false): array
    {
        $sql = '
        SELECT
            cn.id AS id_cuenta_bancaria,
            bc.nombre AS banco,
            bc.abreviatura AS banco_abv,
            cn.id_banco,
            cn.moneda,
            cn.numero_cuenta,
            cn.cci,
            cn.es_para_detraccion,
            cn.estado,
            e.razon_social AS empresa_nombre,
            e.id AS id_empresa
        FROM cuenta_bancaria_empresa cn
        INNER JOIN banco bc ON bc.id = cn.id_banco
        LEFT JOIN empresa e ON e.id = cn.id_empresa
        WHERE cn.estado = :estado
          AND cn.moneda = :moneda
        ';

        $params = [
            'estado' => 'Activo',
            'moneda' => $moneda,
        ];

        if ($esParaDetraccion) {
            $sql .= " AND cn.es_para_detraccion = 1 AND (bc.abreviatura = 'BN' OR bc.es_nacional = 1)";
        }

        $sql .= ' ORDER BY cn.es_para_detraccion DESC, bc.nombre, cn.numero_cuenta';

        return DB::select($sql, $params);
    }
}
