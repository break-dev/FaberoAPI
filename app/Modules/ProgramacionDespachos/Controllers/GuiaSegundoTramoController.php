<?php

namespace App\Modules\ProgramacionDespachos\Controllers;

use App\Modules\ProgramacionDespachos\Services\GuiaSegundoTramoService;
use App\Shared\Enums\_Generic\MotivoTraslado;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class GuiaSegundoTramoController extends Controller
{
    /**
     * GET /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo
     */
    public function get_guia(int $id): JsonResponse
    {
        return response()->json(GuiaSegundoTramoService::get_guia_by_distribucion($id));
    }

    /**
     * POST /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo
     */
    public function crear_guia(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'motivo_traslado' => ['required', 'string', Rule::enum(MotivoTraslado::class)],
            'fecha_inicio_traslado' => 'nullable|date',
            'fecha_emision' => 'nullable|date',
            'fecha_en_planta' => 'nullable|date',
            'guia_remitente' => 'required|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'sin_guia_transportista' => 'nullable|boolean',
            'documento_guia_remitente' => 'nullable|file',
            'documento_guia_transportista' => 'nullable|file',
        ]);

        $sinGuiaTransportista = $request->boolean('sin_guia_transportista');

        if (! $sinGuiaTransportista) {
            $request->validate([
                'guia_transportista' => 'required|string|max:20',
            ]);
            $validated['guia_transportista'] = $request->input('guia_transportista');
        }

        $archivos = [
            'guia_remitente' => $request->hasFile('documento_guia_remitente')
                ? $request->file('documento_guia_remitente')
                : null,
            'guia_transportista' => $sinGuiaTransportista || ! $request->hasFile('documento_guia_transportista')
                ? null
                : $request->file('documento_guia_transportista'),
        ];

        $resultado = GuiaSegundoTramoService::crear_guia($id, $validated, $archivos, $request);

        $status = ($resultado['success'] ?? false) === true ? 201 : 400;

        return response()->json($resultado, $status);
    }

    /**
     * POST /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo/{idGuia}/update
     */
    public function actualizar_guia(Request $request, int $id, int $idGuia): JsonResponse
    {
        $validated = $request->validate([
            'motivo_traslado' => ['required', 'string', Rule::enum(MotivoTraslado::class)],
            'fecha_inicio_traslado' => 'nullable|date',
            'fecha_emision' => 'nullable|date',
            'fecha_en_planta' => 'nullable|date',
            'guia_remitente' => 'required|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'sin_guia_transportista' => 'nullable|boolean',
            'documento_guia_remitente' => 'nullable|file',
            'documento_guia_transportista' => 'nullable|file',
            'motivo' => 'nullable|string',
            'nombres_evidencias_nuevas' => 'nullable',
            'nombres_evidencias_eliminadas' => 'nullable',
        ]);

        $sinGuiaTransportista = $request->boolean('sin_guia_transportista');

        if (! $sinGuiaTransportista) {
            $request->validate([
                'guia_transportista' => 'required|string|max:20',
            ]);
            $validated['guia_transportista'] = $request->input('guia_transportista');
        }

        $archivos = [
            'guia_remitente' => $request->hasFile('documento_guia_remitente')
                ? $request->file('documento_guia_remitente')
                : null,
            'guia_transportista' => $sinGuiaTransportista || ! $request->hasFile('documento_guia_transportista')
                ? null
                : $request->file('documento_guia_transportista'),
        ];

        $resultado = GuiaSegundoTramoService::actualizar_guia($id, $idGuia, $validated, $archivos, $request);

        $status = ($resultado['success'] ?? false) === true ? 200 : 400;

        return response()->json($resultado, $status);
    }

    /**
     * PATCH /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo/{idGuia}/anular
     */
    public function anular_guia(Request $request, int $id, int $idGuia): JsonResponse
    {
        return response()->json(GuiaSegundoTramoService::anular_guia($id, $idGuia, $request));
    }
}
