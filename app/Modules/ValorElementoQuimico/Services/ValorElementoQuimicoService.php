<?php

namespace App\Modules\ValorElementoQuimico\Services;

use App\Modules\ValorElementoQuimico\Data\ValorElementoQuimicoData;
use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ValorElementoQuimicoService
{
    /**
     * Buscar precio INTER exacto por elemento + fecha.
     */
    public static function buscar_precio(string $elemento, string $fecha): array
    {
        $elementoVal = trim($elemento);
        if ($elementoVal === '' || $fecha === '') {
            return ApiResponse::error('Parametros incompletos: elemento y fecha son requeridos.');
        }

        $data = ValorElementoQuimicoData::find_by_elemento_y_fecha($elementoVal, $fecha);
        if (! $data) {
            return ApiResponse::error('No se encontro precio INTER registrado para ese elemento y fecha.');
        }

        return ApiResponse::success($data, 'Precio INTER encontrado.');
    }

    /**
     * Registrar un nuevo precio INTER para (elemento, fecha).
     */
    public static function registrar_precio(
        int $idEmpleadoRegistro,
        string $elementoQuimico,
        float $inter,
        string $fecha,
    ): array {
        if (! in_array($elementoQuimico, [
            ElementoQuimicoValorizacion::Oro->value,
            ElementoQuimicoValorizacion::Plata->value,
        ], true)) {
            return ApiResponse::error('Elemento quimico invalido. Valores permitidos: Oro, Plata.');
        }

        if ($inter < 0) {
            return ApiResponse::error('El valor INTER no puede ser negativo.');
        }

        DB::beginTransaction();
        try {
            $id = ValorElementoQuimicoData::crear_precio(
                $idEmpleadoRegistro,
                $elementoQuimico,
                $inter,
                $fecha,
            );

            DB::commit();

            $data = ValorElementoQuimicoData::find_by_elemento_y_fecha($elementoQuimico, $fecha);

            return ApiResponse::success($data, 'Precio INTER registrado correctamente.');
        } catch (Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar precio INTER: '.$e->getMessage());
        }
    }
}
