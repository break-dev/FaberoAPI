<?php

namespace App\Modules\Empresas;

use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class EmpresasController extends Controller
{
    /**
     * Listar empresas
     */
    public function get_empresas(Request $request): JsonResponse
    {
        $result = EmpresasService::get_empresas();

        return response()->json($result);
    }

    /**
     * Crear una nueva empresa
     */
    public function crear_empresa(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ruc' => 'required|string|size:11',
            'razon_social' => 'required|string|max:128',
            'path_logo' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
        ], [
            'ruc.required' => 'El RUC es obligatorio',
            'ruc.size' => 'El RUC debe tener 11 dígitos',
            'razon_social.required' => 'La razón social es obligatoria',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = EmpresasService::crear_empresa(
            ruc: $request->input('ruc'),
            razon_social: $request->input('razon_social'),
            logo: $request->file('path_logo')
        );

        return response()->json($result);
    }

    /**
     * Actualizar logo de empresa
     */
    public function actualizar_logo(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'path_logo' => 'required|image|mimes:jpg,png,jpeg',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $result = EmpresasService::actualizar_logo($id, $request->file('path_logo'));

        return response()->json($result);
    }
}
