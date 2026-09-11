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
            'id_departamento' => 'nullable|integer|exists:departamento,id',
            'id_provincia' => 'nullable|integer|exists:provincia,id',
            'id_distrito' => 'nullable|integer|exists:distrito,id',
            'domicilio_fiscal' => 'nullable|string|max:50',
        ], [
            'ruc.required' => 'El RUC es obligatorio',
            'ruc.size' => 'El RUC debe tener 11 dígitos',
            'razon_social.required' => 'La razón social es obligatoria',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $data = [
            'ruc' => $request->input('ruc'),
            'razon_social' => $request->input('razon_social'),
            'id_departamento' => $request->input('id_departamento') ?: null,
            'id_provincia' => $request->input('id_provincia') ?: null,
            'id_distrito' => $request->input('id_distrito') ?: null,
            'domicilio_fiscal' => $request->input('domicilio_fiscal') ?: null,
        ];

        $result = EmpresasService::crear_empresa($data, $request->file('path_logo'));

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

    /**
     * Actualizar los datos de una empresa (sin logo).
     */
    public function actualizar_empresa(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ruc' => 'nullable|string|size:11',
            'razon_social' => 'nullable|string|max:128',
            'id_departamento' => 'nullable|integer|exists:departamento,id',
            'id_provincia' => 'nullable|integer|exists:provincia,id',
            'id_distrito' => 'nullable|integer|exists:distrito,id',
            'domicilio_fiscal' => 'nullable|string|max:50',
        ], [
            'ruc.size' => 'El RUC debe tener 11 dígitos',
            'razon_social.required' => 'La razón social es obligatoria',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $data = [
            'ruc' => $request->input('ruc'),
            'razon_social' => $request->input('razon_social'),
            'id_departamento' => $request->input('id_departamento') ?: null,
            'id_provincia' => $request->input('id_provincia') ?: null,
            'id_distrito' => $request->input('id_distrito') ?: null,
            'domicilio_fiscal' => $request->input('domicilio_fiscal') ?: null,
        ];

        $result = EmpresasService::actualizar_empresa($id, $data);

        return response()->json($result);
    }
}
