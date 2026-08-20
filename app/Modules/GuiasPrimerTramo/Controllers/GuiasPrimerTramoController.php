<?php

namespace App\Modules\GuiasPrimerTramo\Controllers;

use App\Modules\GuiasPrimerTramo\Services\GuiasPrimerTramoService;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GuiasPrimerTramoController extends Controller
{
    /**
     * Listar guías filtradas.
     */
    public function get_guias(Request $request): JsonResponse
    {
        $filters = [
            'id_sucursal' => $request->query('id_sucursal'),
            'id_proveedor' => $request->query('id_proveedor'),
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
            'guia_remitente' => $request->query('guia_remitente'),
        ];

        return response()->json(GuiasPrimerTramoService::get_guias($filters));
    }

    /**
     * Obtener metadatos para los filtros (proveedores según sucursal).
     */
    public function get_filtros_metadata(Request $request): JsonResponse
    {
        $idSucursal = (int) $request->query('id_sucursal');
        if (! $idSucursal) {
            return response()->json(ApiResponse::error('Debe especificar la sucursal.'), 400);
        }

        return response()->json(GuiasPrimerTramoService::get_filtros_metadata($idSucursal));
    }

    /**
     * Obtener una guía específica.
     */
    public function get_guia_by_id(Request $request, int $id): JsonResponse
    {
        return response()->json(GuiasPrimerTramoService::get_guia_by_id($id));
    }

    /**
     * Crea una guía de primer tramo con sus items (lotes o particiones) y documentos.
     */
    public function crear_guia(Request $request): JsonResponse
    {
        if (is_string($request->input('lotes'))) {
            $decoded = json_decode($request->input('lotes'), true);
            if (is_array($decoded)) {
                $request->merge(['lotes' => $decoded]);
            }
        }

        $request->validate([
            'id_sucursal' => 'required|integer|exists:sucursal,id',
            'id_proveedor' => 'required|integer|exists:proveedor,id',
            'id_concesion' => 'required|integer|exists:concesion,id',
            'id_conductor' => 'required|integer|exists:conductor,id',
            'id_vehiculo' => 'required|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'nullable|integer|exists:empresa_transporte,id',
            'id_vehiculo_carreta' => 'nullable|integer|exists:vehiculo,id',
            'id_empresa_transporte_carreta' => 'nullable|integer|exists:empresa_transporte,id',
            'motivo_traslado' => 'required|string|max:100',
            'condicion_ingreso' => ['nullable', 'string', Rule::enum(CondicionIngreso::class)],
            'fecha_inicio_traslado' => 'nullable|date',
            'fecha_emision' => 'nullable|date',
            'fecha_en_planta' => 'nullable|date',
            'guia_remitente' => 'nullable|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'sin_guia_transportista' => 'nullable|boolean',
            'documento_guia_remitente' => 'nullable|file',
            'documento_guia_transportista' => 'nullable|file',
        ]);

        $lotesRaw = $request->input('lotes');
        $lotes = is_string($lotesRaw) ? json_decode($lotesRaw, true) : $lotesRaw;

        if (! is_array($lotes) || count($lotes) === 0) {
            return response()->json(ApiResponse::error('Debe agregar al menos un item a la guía.'), 422);
        }

        foreach ($lotes as $idx => $lote) {
            if (! is_array($lote)) {
                return response()->json(ApiResponse::error("Item en posición {$idx} con formato inválido."), 422);
            }

            $idLote = $lote['id_lote_mineral'] ?? null;
            $idPart = $lote['id_particion_lote_mineral'] ?? null;

            $hasLote = $idLote !== null && $idLote !== '' && is_numeric($idLote);
            $hasPart = $idPart !== null && $idPart !== '' && is_numeric($idPart);

            if ($hasLote && $hasPart) {
                return response()->json(ApiResponse::error("Item {$idx}: solo uno de id_lote_mineral o id_particion_lote_mineral, no ambos."), 422);
            }

            if (! $hasLote && ! $hasPart) {
                return response()->json(ApiResponse::error("Item {$idx}: debe indicar id_lote_mineral o id_particion_lote_mineral."), 422);
            }

            if ($hasLote) {
                $exists = DB::table('lote_mineral')->where('id', (int) $idLote)->exists();
                if (! $exists) {
                    return response()->json(ApiResponse::error("Item {$idx}: id_lote_mineral no existe."), 422);
                }
            }

            if ($hasPart) {
                $exists = DB::table('particion_lote_mineral')->where('id', (int) $idPart)->exists();
                if (! $exists) {
                    return response()->json(ApiResponse::error("Item {$idx}: id_particion_lote_mineral no existe."), 422);
                }
            }
        }

        $data = [
            'id_sucursal' => $request->input('id_sucursal'),
            'id_proveedor' => $request->input('id_proveedor'),
            'id_concesion' => $request->input('id_concesion'),
            'id_conductor' => $request->input('id_conductor'),
            'id_vehiculo' => $request->input('id_vehiculo'),
            'id_empresa_transporte' => $request->input('id_empresa_transporte'),
            'id_vehiculo_carreta' => $request->input('id_vehiculo_carreta'),
            'id_empresa_transporte_carreta' => $request->input('id_empresa_transporte_carreta'),
            'motivo_traslado' => $request->input('motivo_traslado'),
            'condicion_ingreso' => $request->input('condicion_ingreso'),
            'fecha_inicio_traslado' => $request->input('fecha_inicio_traslado'),
            'fecha_emision' => $request->input('fecha_emision'),
            'fecha_en_planta' => $request->input('fecha_en_planta'),
            'guia_remitente' => $request->input('guia_remitente'),
            'guia_transportista' => $request->input('guia_transportista'),
            'sin_guia_transportista' => $request->boolean('sin_guia_transportista'),
        ];

        $archivos = [
            'guia_remitente' => $request->hasFile('documento_guia_remitente') ? $request->file('documento_guia_remitente') : null,
            'guia_transportista' => $request->hasFile('documento_guia_transportista') ? $request->file('documento_guia_transportista') : null,
        ];

        return response()->json(GuiasPrimerTramoService::crear_guia($data, $lotes, $archivos, $request));
    }

    /**
     * Actualiza una guía de primer tramo con sus items y documentos.
     */
    public function actualizar_guia(Request $request, int $id): JsonResponse
    {
        if (is_string($request->input('lotes'))) {
            $decoded = json_decode($request->input('lotes'), true);
            if (is_array($decoded)) {
                $request->merge(['lotes' => $decoded]);
            }
        }

        $request->validate([
            'id_sucursal' => 'required|integer|exists:sucursal,id',
            'id_proveedor' => 'required|integer|exists:proveedor,id',
            'id_concesion' => 'required|integer|exists:concesion,id',
            'id_conductor' => 'required|integer|exists:conductor,id',
            'id_vehiculo' => 'required|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'nullable|integer|exists:empresa_transporte,id',
            'id_vehiculo_carreta' => 'nullable|integer|exists:vehiculo,id',
            'id_empresa_transporte_carreta' => 'nullable|integer|exists:empresa_transporte,id',
            'motivo_traslado' => 'required|string|max:100',
            'condicion_ingreso' => ['nullable', 'string', Rule::enum(CondicionIngreso::class)],
            'fecha_inicio_traslado' => 'nullable|date',
            'fecha_emision' => 'nullable|date',
            'fecha_en_planta' => 'nullable|date',
            'guia_remitente' => 'nullable|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'sin_guia_transportista' => 'nullable|boolean',
            'documento_guia_remitente' => 'nullable|file',
            'documento_guia_transportista' => 'nullable|file',
            'motivo' => 'nullable|string',
        ]);

        $lotesRaw = $request->input('lotes');
        $lotes = is_string($lotesRaw) ? json_decode($lotesRaw, true) : $lotesRaw;

        if (! is_array($lotes) || count($lotes) === 0) {
            return response()->json(ApiResponse::error('Debe agregar al menos un item a la guía.'), 422);
        }

        foreach ($lotes as $idx => $lote) {
            if (! is_array($lote)) {
                return response()->json(ApiResponse::error("Item en posición {$idx} con formato inválido."), 422);
            }

            $idLote = $lote['id_lote_mineral'] ?? null;
            $idPart = $lote['id_particion_lote_mineral'] ?? null;

            $hasLote = $idLote !== null && $idLote !== '' && is_numeric($idLote);
            $hasPart = $idPart !== null && $idPart !== '' && is_numeric($idPart);

            if ($hasLote && $hasPart) {
                return response()->json(ApiResponse::error("Item {$idx}: solo uno de id_lote_mineral o id_particion_lote_mineral, no ambos."), 422);
            }

            if (! $hasLote && ! $hasPart) {
                return response()->json(ApiResponse::error("Item {$idx}: debe indicar id_lote_mineral o id_particion_lote_mineral."), 422);
            }

            if ($hasLote) {
                $exists = DB::table('lote_mineral')->where('id', (int) $idLote)->exists();
                if (! $exists) {
                    return response()->json(ApiResponse::error("Item {$idx}: id_lote_mineral no existe."), 422);
                }
            }

            if ($hasPart) {
                $exists = DB::table('particion_lote_mineral')->where('id', (int) $idPart)->exists();
                if (! $exists) {
                    return response()->json(ApiResponse::error("Item {$idx}: id_particion_lote_mineral no existe."), 422);
                }
            }
        }

        $data = [
            'id_sucursal' => $request->input('id_sucursal'),
            'id_proveedor' => $request->input('id_proveedor'),
            'id_concesion' => $request->input('id_concesion'),
            'id_conductor' => $request->input('id_conductor'),
            'id_vehiculo' => $request->input('id_vehiculo'),
            'id_empresa_transporte' => $request->input('id_empresa_transporte'),
            'id_vehiculo_carreta' => $request->input('id_vehiculo_carreta'),
            'id_empresa_transporte_carreta' => $request->input('id_empresa_transporte_carreta'),
            'motivo_traslado' => $request->input('motivo_traslado'),
            'condicion_ingreso' => $request->input('condicion_ingreso'),
            'fecha_inicio_traslado' => $request->input('fecha_inicio_traslado'),
            'fecha_emision' => $request->input('fecha_emision'),
            'fecha_en_planta' => $request->input('fecha_en_planta'),
            'guia_remitente' => $request->input('guia_remitente'),
            'guia_transportista' => $request->input('guia_transportista'),
            'sin_guia_transportista' => $request->boolean('sin_guia_transportista'),
            'motivo' => $request->input('motivo'),
        ];

        $archivos = [
            'guia_remitente' => $request->hasFile('documento_guia_remitente') ? $request->file('documento_guia_remitente') : null,
            'guia_transportista' => $request->hasFile('documento_guia_transportista') ? $request->file('documento_guia_transportista') : null,
        ];

        return response()->json(GuiasPrimerTramoService::actualizar_guia($id, $data, $lotes, $archivos, $request));
    }

    /**
     * Anular una guía de primer tramo (cambiar su estado a inactivo).
     */
    public function anular_guia(Request $request, int $id): JsonResponse
    {
        return response()->json(GuiasPrimerTramoService::anular_guia($id, $request));
    }
}
