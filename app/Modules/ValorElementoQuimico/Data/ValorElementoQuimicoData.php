<?php

namespace App\Modules\ValorElementoQuimico\Data;

use App\Models\ValorElementoQuimico;
use Illuminate\Support\Facades\DB;

class ValorElementoQuimicoData
{
    /**
     * Buscar precio INTER exacto por elemento + fecha
     */
    public static function find_by_elemento_y_fecha(string $elemento, string $fecha): ?array
    {
        $row = DB::selectOne(
            'SELECT id, id_empleado_registro, elemento_quimico, inter, fecha, created_at
             FROM valor_elemento_quimico
             WHERE elemento_quimico = :elemento AND fecha = :fecha
             ORDER BY id DESC
             LIMIT 1',
            ['elemento' => $elemento, 'fecha' => $fecha],
        );

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'id_empleado_registro' => $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null,
            'elemento_quimico' => $row->elemento_quimico,
            'inter' => (float) $row->inter,
            'fecha' => $row->fecha,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Insertar un nuevo precio INTER
     */
    public static function crear_precio(
        int $idEmpleadoRegistro,
        string $elementoQuimico,
        float $inter,
        string $fecha,
    ): int {
        return ValorElementoQuimico::insertGetId([
            'id_empleado_registro' => $idEmpleadoRegistro,
            'elemento_quimico' => $elementoQuimico,
            'inter' => $inter,
            'fecha' => $fecha,
            'created_at' => now(),
        ]);
    }
}
