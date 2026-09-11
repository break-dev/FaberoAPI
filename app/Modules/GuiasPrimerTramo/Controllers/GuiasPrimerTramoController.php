<?php

namespace App\Modules\GuiasPrimerTramo\Controllers;

use App\Modules\GuiasPrimerTramo\Services\GuiasPrimerTramoService;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Enums\_Generic\EstadoBase;
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
     * Validar si existe una guía activa con la misma combinación
     * (guia_remitente, guia_transportista / sin_guia_transportista).
     * Pensado para chequeo previo al submit desde el frontend.
     */
    public function validar_duplicado(Request $request): JsonResponse
    {
        $request->validate([
            'id_sucursal' => 'required|integer',
            'guia_remitente' => 'required|string|max:20',
            'guia_transportista' => 'nullable|string|max:20',
            'sin_guia_transportista' => 'nullable|boolean',
            'id_excluir' => 'nullable|integer',
        ]);

        $params = [
            'id_sucursal' => (int) $request->input('id_sucursal'),
            'guia_remitente' => (string) $request->input('guia_remitente'),
            'guia_transportista' => $request->input('guia_transportista'),
            'sin_guia_transportista' => $request->boolean('sin_guia_transportista'),
            'id_excluir' => $request->input('id_excluir'),
        ];

        return response()->json(GuiasPrimerTramoService::validar_duplicado($params));
    }

    /**
     * Determina el status HTTP de la respuesta de un service segun codigos
     * semanticos puestos en `errors`. Mantiene el contrato de ApiResponse
     * intacto y evita pasar el codigo HTTP como parametro de error.
     *
     * @param  array{success: bool, message?: string, errors?: mixed}  $result
     */
    private function http_status_from_service_result(array $result): int
    {
        if (($result['success'] ?? false) === true) {
            return 200;
        }

        $errors = $result['errors'] ?? null;
        if (is_array($errors) && isset($errors['codigo']) && in_array($errors['codigo'], ['GUIA_DUPLICADA', 'PROVEEDOR_ITEM_INCONSISTENTE'], true)) {
            return 422;
        }

        return 200;
    }

    /**
     * Valida que cada item (lote o particion) pertenezca al proveedor declarado
     * en la cabecera de la guia. Para PARTICION, hereda el `id_proveedor_minero`
     * del lote padre (la particion no tiene campo propio).
     *
     * Devuelve `null` si todos los items son consistentes, o un payload listo
     * para `ApiResponse::error()` con codigo `PROVEEDOR_ITEM_INCONSISTENTE`
     * cuando alguno difiere.
     *
     * @param  array<int, array{id_lote_mineral?: mixed, id_particion_lote_mineral?: mixed}>  $items
     * @return array{codigo: string, mensaje: string, idx: int}|null
     */
    private function validar_proveedor_items(array $items, int $idProveedorDeclarado): ?array
    {
        foreach ($items as $idx => $item) {
            $idLote = $item['id_lote_mineral'] ?? null;
            $idPart = $item['id_particion_lote_mineral'] ?? null;

            $hasLote = $idLote !== null && $idLote !== '' && is_numeric($idLote);
            $hasPart = $idPart !== null && $idPart !== '' && is_numeric($idPart);

            if ($hasLote) {
                $row = DB::table('lote_mineral')
                    ->select('id_proveedor_minero', 'correlativo')
                    ->where('id', (int) $idLote)
                    ->first();
                if (! $row) {
                    continue;
                }
                $proveedorItem = $row->id_proveedor_minero !== null ? (int) $row->id_proveedor_minero : null;
                $correlativo = $row->correlativo ?: "Lote #{$idLote}";

                if ($proveedorItem !== $idProveedorDeclarado) {
                    $proveedorLabel = $proveedorItem !== null
                        ? $this->resolver_nombre_proveedor($proveedorItem)
                        : 'sin proveedor asignado';

                    return [
                        'codigo' => 'PROVEEDOR_ITEM_INCONSISTENTE',
                        'mensaje' => "Item {$idx} ({$correlativo}) pertenece al proveedor '{$proveedorLabel}', no se puede asociar a una guía del proveedor seleccionado.",
                        'idx' => $idx,
                    ];
                }
            }

            if ($hasPart) {
                $row = DB::table('particion_lote_mineral as plm')
                    ->join('lote_mineral as lm', 'lm.id', '=', 'plm.id_lote_mineral')
                    ->select('lm.id_proveedor_minero', 'plm.correlativo')
                    ->where('plm.id', (int) $idPart)
                    ->first();
                if (! $row) {
                    continue;
                }
                $proveedorItem = $row->id_proveedor_minero !== null ? (int) $row->id_proveedor_minero : null;
                $correlativo = $row->correlativo ?: "Partición #{$idPart}";

                if ($proveedorItem !== $idProveedorDeclarado) {
                    $proveedorLabel = $proveedorItem !== null
                        ? $this->resolver_nombre_proveedor($proveedorItem)
                        : 'sin proveedor asignado';

                    return [
                        'codigo' => 'PROVEEDOR_ITEM_INCONSISTENTE',
                        'mensaje' => "Item {$idx} ({$correlativo}) pertenece al proveedor '{$proveedorLabel}', no se puede asociar a una guía del proveedor seleccionado.",
                        'idx' => $idx,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Resuelve la razon social de un proveedor por id. Devuelve `ID #N` si no
     * se encuentra en BD.
     */
    private function resolver_nombre_proveedor(int $idProveedor): string
    {
        $row = DB::table('proveedor')->select('razon_social')->where('id', $idProveedor)->first();

        return $row && $row->razon_social ? $row->razon_social : "ID #{$idProveedor}";
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

        if (is_string($request->input('pesos_oficiales_lotes'))) {
            $decoded = json_decode($request->input('pesos_oficiales_lotes'), true);
            if (is_array($decoded)) {
                $request->merge(['pesos_oficiales_lotes' => $decoded]);
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
            'pesos_oficiales_lotes' => 'nullable|array',
            'pesos_oficiales_lotes.*.id_lote_mineral' => 'required_with:pesos_oficiales_lotes|integer|exists:lote_mineral,id',
            'pesos_oficiales_lotes.*.peso_inicial_oficial' => 'required_with:pesos_oficiales_lotes|numeric|min:0',
            'pesos_oficiales_lotes.*.peso_final_oficial' => 'required_with:pesos_oficiales_lotes|numeric|min:0',
            'pesos_oficiales_lotes.*.peso_neto_oficial' => 'required_with:pesos_oficiales_lotes|numeric|min:0',
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
                $exists = DB::table('lote_mineral')
                    ->where('id', (int) $idLote)
                    ->where('estado', EstadoBase::Activo->value)
                    ->exists();
                if (! $exists) {
                    return response()->json(ApiResponse::error("Item {$idx}: id_lote_mineral no existe o fue eliminado."), 422);
                }
            }

            if ($hasPart) {
                $exists = DB::table('particion_lote_mineral')
                    ->where('id', (int) $idPart)
                    ->where('estado', EstadoBase::Activo->value)
                    ->exists();
                if (! $exists) {
                    return response()->json(ApiResponse::error("Item {$idx}: id_particion_lote_mineral no existe o fue eliminada."), 422);
                }
            }
        }

        // Validar que todos los items pertenezcan al proveedor declarado en la
        // cabecera. Para PARTICION se valida via JOIN con `lote_mineral` porque
        // la particion hereda el proveedor del padre.
        $idProveedorDeclarado = (int) $request->input('id_proveedor');
        $inconsistencia = $this->validar_proveedor_items($lotes, $idProveedorDeclarado);
        if ($inconsistencia !== null) {
            return response()->json(
                ApiResponse::error($inconsistencia['mensaje'], ['codigo' => $inconsistencia['codigo']]),
                422,
            );
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
            'pesos_oficiales_lotes' => $request->input('pesos_oficiales_lotes'),
        ];

        $archivos = [
            'guia_remitente' => $request->hasFile('documento_guia_remitente') ? $request->file('documento_guia_remitente') : null,
            'guia_transportista' => $request->hasFile('documento_guia_transportista') ? $request->file('documento_guia_transportista') : null,
        ];

        $resultado = GuiasPrimerTramoService::crear_guia($data, $lotes, $archivos, $request);

        return response()->json($resultado, $this->http_status_from_service_result($resultado));
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

        if (is_string($request->input('pesos_oficiales_lotes'))) {
            $decoded = json_decode($request->input('pesos_oficiales_lotes'), true);
            if (is_array($decoded)) {
                $request->merge(['pesos_oficiales_lotes' => $decoded]);
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
            'pesos_oficiales_lotes' => 'nullable|array',
            'pesos_oficiales_lotes.*.id_lote_mineral' => 'required_with:pesos_oficiales_lotes|integer|exists:lote_mineral,id',
            'pesos_oficiales_lotes.*.peso_inicial_oficial' => 'required_with:pesos_oficiales_lotes|numeric|min:0',
            'pesos_oficiales_lotes.*.peso_final_oficial' => 'required_with:pesos_oficiales_lotes|numeric|min:0',
            'pesos_oficiales_lotes.*.peso_neto_oficial' => 'required_with:pesos_oficiales_lotes|numeric|min:0',
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
                // Permitir si el lote ya esta asociado a esta guia (item historico)
                // aunque haya sido eliminado logicamente. Solo validar FK estricto para
                // lotes que se estan agregando nuevos.
                $yaAsociado = DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->where('id_lote_mineral', (int) $idLote)
                    ->whereNull('id_particion_lote_mineral')
                    ->exists();
                if (! $yaAsociado) {
                    $exists = DB::table('lote_mineral')
                        ->where('id', (int) $idLote)
                        ->where('estado', EstadoBase::Activo->value)
                        ->exists();
                    if (! $exists) {
                        return response()->json(ApiResponse::error("Item {$idx}: id_lote_mineral no existe o fue eliminado."), 422);
                    }
                }
            }

            if ($hasPart) {
                $yaAsociado = DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->where('id_particion_lote_mineral', (int) $idPart)
                    ->exists();
                if (! $yaAsociado) {
                    $exists = DB::table('particion_lote_mineral')
                        ->where('id', (int) $idPart)
                        ->where('estado', EstadoBase::Activo->value)
                        ->exists();
                    if (! $exists) {
                        return response()->json(ApiResponse::error("Item {$idx}: id_particion_lote_mineral no existe o fue eliminada."), 422);
                    }
                }
            }
        }

        // Validar que todos los items (historicos y nuevos) pertenezcan al
        // proveedor declarado. Aplica sobre el array completo enviado por el
        // frontend; los items que el operador quiere desvincular llegan
        // simplemente omitidos en `lotes`, asi que no estan en este array.
        $idProveedorDeclarado = (int) $request->input('id_proveedor');
        $inconsistencia = $this->validar_proveedor_items($lotes, $idProveedorDeclarado);
        if ($inconsistencia !== null) {
            return response()->json(
                ApiResponse::error($inconsistencia['mensaje'], ['codigo' => $inconsistencia['codigo']]),
                422,
            );
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
            'pesos_oficiales_lotes' => $request->input('pesos_oficiales_lotes'),
        ];

        $archivos = [
            'guia_remitente' => $request->hasFile('documento_guia_remitente') ? $request->file('documento_guia_remitente') : null,
            'guia_transportista' => $request->hasFile('documento_guia_transportista') ? $request->file('documento_guia_transportista') : null,
        ];

        $resultado = GuiasPrimerTramoService::actualizar_guia($id, $data, $lotes, $archivos, $request);

        return response()->json($resultado, $this->http_status_from_service_result($resultado));
    }

    /**
     * Anular una guía de primer tramo (cambiar su estado a inactivo).
     */
    public function anular_guia(Request $request, int $id): JsonResponse
    {
        return response()->json(GuiasPrimerTramoService::anular_guia($id, $request));
    }
}
