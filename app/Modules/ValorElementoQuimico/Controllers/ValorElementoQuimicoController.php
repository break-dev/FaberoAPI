<?php

namespace App\Modules\ValorElementoQuimico\Controllers;

use App\Modules\ValorElementoQuimico\Services\ValorElementoQuimicoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValorElementoQuimicoController
{
    /**
     * GET /valor-elemento-quimico/buscar?elemento=Oro&fecha=YYYY-MM-DD
     */
    public function buscar_precio(Request $request): JsonResponse
    {
        $request->validate([
            'elemento' => 'required|string|in:Oro,Plata',
            'fecha' => 'required|date_format:Y-m-d',
        ]);

        return response()->json(ValorElementoQuimicoService::buscar_precio(
            elemento: (string) $request->input('elemento'),
            fecha: (string) $request->input('fecha'),
        ));
    }

    /**
     * POST /valor-elemento-quimico
     */
    public function registrar_precio(Request $request): JsonResponse
    {
        $request->validate([
            'elemento_quimico' => 'required|string|in:Oro,Plata',
            'inter' => 'required|numeric|min:0',
            'fecha' => 'required|date_format:Y-m-d',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? (int) ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        return response()->json(ValorElementoQuimicoService::registrar_precio(
            idEmpleadoRegistro: $idEmpleado,
            elementoQuimico: (string) $request->input('elemento_quimico'),
            inter: (float) $request->input('inter'),
            fecha: (string) $request->input('fecha'),
        ));
    }
}
