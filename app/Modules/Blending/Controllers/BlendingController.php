<?php

namespace App\Modules\Blending\Controllers;

use App\Modules\Blending\Services\BlendingService;
use App\Shared\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BlendingController extends Controller
{
    public function __construct(protected BlendingService $service) {}

    /**
     * Listar lotes y blendings disponibles para mezclas.
     */
    public function disponibles(Request $request): JsonResponse
    {
        try {
            $idProveedor = $request->query('id_proveedor') ? (int) $request->query('id_proveedor') : null;
            $idEmpresa = $request->query('id_empresa') ? (int) $request->query('id_empresa') : null;
            $items = $this->service->get_disponibles($idProveedor, $idEmpresa);

            return response()->json(ApiResponse::success($items, 'Lotes y blendings disponibles consultados correctamente'));
        } catch (Exception $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 400);
        }
    }

    /**
     * Listar todos los blendings registrados.
     */
    public function listar(Request $request): JsonResponse
    {
        try {
            $fechaInicio = $request->query('fecha_inicio');
            $fechaFin = $request->query('fecha_fin');
            $list = $this->service->get_blendings(
                is_string($fechaInicio) ? $fechaInicio : null,
                is_string($fechaFin) ? $fechaFin : null
            );

            return response()->json(ApiResponse::success($list, 'Blendings consultados correctamente'));
        } catch (Exception $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 400);
        }
    }

    /**
     * Obtener un blending por su ID.
     */
    public function obtener(int $id): JsonResponse
    {
        try {
            $blending = $this->service->get_blending($id);
            if (! $blending) {
                return response()->json(ApiResponse::error('Blending no encontrado'), 404);
            }

            return response()->json(ApiResponse::success($blending, 'Blending obtenido correctamente'));
        } catch (Exception $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 400);
        }
    }

    /**
     * Registrar un nuevo blending.
     */
    public function crear(Request $request): JsonResponse
    {
        try {
            $detallesRaw = $request->input('detalles');
            if (is_string($detallesRaw)) {
                $request->merge(['detalles' => json_decode($detallesRaw, true)]);
            }

            $validated = $request->validate([
                'fecha_hora_blending' => 'nullable|string',
                'observacion' => 'nullable|string',
                'evidencias' => 'nullable|array',
                'evidencias.*' => 'file',
                'id_empresa' => 'nullable|integer|exists:empresa,id',
                'detalles' => 'required|array|min:1',
                'detalles.*.id_lote_mineral' => 'nullable|integer',
                'detalles.*.id_reblending' => 'nullable|integer',
                'detalles.*.peso_tomado' => 'required|numeric|gt:0',
            ]);

            /** @var \App\Models\Usuario|null $user */
            $user = auth()->user();
            $idEmpleado = $user ? (int) ($user->id_empleado ?? $user->id) : 1;

            $archivos = [];
            if ($request->hasFile('evidencias')) {
                $archivosRaw = $request->file('evidencias');
                $archivos = is_array($archivosRaw) ? $archivosRaw : [$archivosRaw];
            }

            $blending = $this->service->crear_blending($validated, $idEmpleado, $archivos);

            return response()->json(ApiResponse::success($blending, 'Blending creado exitosamente'), 201);
        } catch (Exception $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 400);
        }
    }

    /**
     * Editar metadata o incrementar pesos/lotes de un blending.
     */
    public function editar(int $id, Request $request): JsonResponse
    {
        try {
            foreach (['adiciones', 'evidencias_existentes', 'nombres_evidencias_nuevas', 'nombres_evidencias_eliminadas'] as $field) {
                $val = $request->input($field);
                if (is_string($val)) {
                    $request->merge([$field => json_decode($val, true)]);
                }
            }

            $validated = $request->validate([
                'fecha_hora_blending' => 'nullable|string',
                'observacion' => 'nullable|string',
                'evidencias' => 'nullable|array',
                'evidencias.*' => 'file',
                'evidencias_existentes' => 'nullable|array',
                'nombres_evidencias_nuevas' => 'nullable|array',
                'nombres_evidencias_nuevas.*' => 'string',
                'nombres_evidencias_eliminadas' => 'nullable|array',
                'nombres_evidencias_eliminadas.*' => 'string',
                'adiciones' => 'nullable|array',
                'adiciones.*.id_detalle' => 'nullable|integer',
                'adiciones.*.id_lote_mineral' => 'nullable|integer',
                'adiciones.*.id_reblending' => 'nullable|integer',
                'adiciones.*.peso_adicional' => 'required_with:adiciones|numeric|gt:0',
            ]);

            /** @var \App\Models\Usuario|null $user */
            $user = auth()->user();
            $idEmpleado = $user ? (int) ($user->id_empleado ?? $user->id) : 1;

            $archivos = [];
            if ($request->hasFile('evidencias')) {
                $archivosRaw = $request->file('evidencias');
                $archivos = is_array($archivosRaw) ? $archivosRaw : [$archivosRaw];
            }

            $blending = $this->service->editar_blending($id, $validated, $idEmpleado, $archivos);

            return response()->json(ApiResponse::success($blending, 'Blending actualizado exitosamente'));
        } catch (Exception $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 400);
        }
    }
}
