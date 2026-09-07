<?php

namespace App\Modules\GuiasPrimerTramo\Services;

use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoGuiaPrimerTramo;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuiasPrimerTramoService
{
    /**
     * Listar guías filtradas por sucursal.
     */
    public static function get_guias(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = GuiasPrimerTramoData::get_guias($filters);

        return ApiResponse::success($data, 'Guías de primer tramo obtenidas correctamente.');
    }

    /**
     * Obtener metadatos para los filtros.
     */
    public static function get_filtros_metadata(int $idSucursal): array
    {
        $data = GuiasPrimerTramoData::get_filtros_metadata($idSucursal);

        return ApiResponse::success($data, 'Metadatos de filtros obtenidos correctamente.');
    }

    /**
     * Obtener una guía por id.
     */
    public static function get_guia_by_id(int $id): array
    {
        $guia = GuiasPrimerTramoData::get_guia_by_id($id);
        if (! $guia) {
            return ApiResponse::error('No se encontró la guía de primer tramo.');
        }

        return ApiResponse::success($guia, 'Guía de primer tramo obtenida correctamente.');
    }

    /**
     * Construye el JSON `documentos` a partir de los archivos subidos y los previos.
     *
     * @param  array{guia_remitente: ?\Illuminate\Http\UploadedFile, guia_transportista: ?\Illuminate\Http\UploadedFile}  $archivos
     * @param  array  $previos  documentos previos {guia_remitente, guia_transportista}
     * @return array JSON listo para almacenar.
     */
    private static function build_documentos(array $archivos, array $previos, bool $sin_guia_transportista): array
    {
        $doc = [
            'guia_remitente' => $previos['guia_remitente'] ?? null,
        ];

        if ($sin_guia_transportista) {
            $doc['guia_transportista'] = null;
        } else {
            $doc['guia_transportista'] = $previos['guia_transportista'] ?? null;
        }

        if ($archivos['guia_remitente'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guias-primer-tramo', [$archivos['guia_remitente']]);
            $doc['guia_remitente'] = $saved[0] ?? null;
        }

        if (! $sin_guia_transportista && $archivos['guia_transportista'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guias-primer-tramo', [$archivos['guia_transportista']]);
            $doc['guia_transportista'] = $saved[0] ?? null;
        }

        return $doc;
    }

    /**
     * Valida duplicados de una guia de primer tramo contra el resto de guias
     * activas. Devuelve tres flags independientes para que el frontend pueda
     * mostrar advertencias separadas por campo:
     *
     *   - `existe_combinacion`:    la combinacion exacta
     *                              (guia_remitente + transportista/sin_transportista)
     *                              ya esta usada por OTRA guia activa.
     *   - `existe_remitente`:      existe OTRA guia activa con el mismo
     *                              `guia_remitente` (cualquier transportista).
     *   - `existe_transportista`:  existe OTRA guia activa con el mismo
     *                              `guia_transportista` (cualquier remitente).
     *                              No aplica si `sinGuiaTransportista=true`.
     *
     * Solo se comparan los inputs (texto); los archivos NO se validan para
     * evitar falsos positivos por metadata.
     *
     * @param  int|null  $idExcluir  ID de la guia a excluir del chequeo (en updates).
     * @return array{
     *     existe: bool,
     *     existe_combinacion: bool,
     *     existe_remitente: bool,
     *     existe_transportista: bool,
     *     id_guia_combinacion: ?int,
     *     id_guia_remitente: ?int,
     *     id_guia_transportista: ?int,
     *     messages: array<string, string>
     * }|null  Null si guia_remitente viene vacio (no hay nada que validar).
     */
    public static function validar_duplicado_guia_activa(
        string $guiaRemitente,
        ?string $guiaTransportista,
        bool $sinGuiaTransportista,
        ?int $idExcluir = null,
    ): ?array {
        $guiaRemitenteTrim = trim($guiaRemitente);
        if ($guiaRemitenteTrim === '') {
            return null;
        }

        $baseActivo = function ($q) {
            $q->where('estado', EstadoBase::Activo->value);
        };

        $aplicarExcluir = function ($q) use ($idExcluir) {
            if ($idExcluir !== null) {
                $q->where('id', '<>', $idExcluir);
            }
        };

        // --- 1) Combinacion exacta ---
        $queryCombinacion = DB::table('guia_primer_tramo')
            ->where($baseActivo)
            ->where('guia_remitente', $guiaRemitenteTrim);
        if ($sinGuiaTransportista) {
            $queryCombinacion->where(function ($q) {
                $q->whereNull('guia_transportista')
                    ->orWhere('guia_transportista', '');
            });
        } elseif ($guiaTransportista === null || trim($guiaTransportista) === '') {
            $queryCombinacion->where(function ($q) {
                $q->whereNull('guia_transportista')
                    ->orWhere('guia_transportista', '');
            });
        } else {
            $queryCombinacion->where('guia_transportista', $guiaTransportista);
        }
        $aplicarExcluir($queryCombinacion);
        $combinacionHit = $queryCombinacion->first();

        // --- 2) Solo por guia_remitente (otra guia activa con mismo remitente) ---
        $queryRemitente = DB::table('guia_primer_tramo')
            ->where($baseActivo)
            ->where('guia_remitente', $guiaRemitenteTrim);
        $aplicarExcluir($queryRemitente);
        $remitenteHit = $queryRemitente->first();

        // --- 3) Solo por guia_transportista (otra guia activa con mismo transportista) ---
        $transportistaHit = null;
        if (! $sinGuiaTransportista && $guiaTransportista !== null && trim($guiaTransportista) !== '') {
            $queryTransportista = DB::table('guia_primer_tramo')
                ->where($baseActivo)
                ->where('guia_transportista', trim($guiaTransportista));
            $aplicarExcluir($queryTransportista);
            $transportistaHit = $queryTransportista->first();
        }

        $messages = [];

        if ($combinacionHit) {
            $trans = $sinGuiaTransportista || $guiaTransportista === null || trim($guiaTransportista) === ''
                ? 'sin guía transportista'
                : "guía transportista '{$guiaTransportista}'";
            $messages['combinacion'] = "Ya existe una guía activa con la misma guía remitente '{$guiaRemitenteTrim}' y {$trans}.";
        }

        if ($remitenteHit) {
            $messages['remitente'] = "Ya existe otra guía activa con el mismo número de guía remitente '{$guiaRemitenteTrim}'.";
        }

        if ($transportistaHit) {
            $messages['transportista'] = "Ya existe otra guía activa con el mismo número de guía transportista '{$guiaTransportista}'.";
        }

        return [
            'existe' => count($messages) > 0,
            'existe_combinacion' => $combinacionHit !== null,
            'existe_remitente' => $remitenteHit !== null,
            'existe_transportista' => $transportistaHit !== null,
            'id_guia_combinacion' => $combinacionHit ? (int) $combinacionHit->id : null,
            'id_guia_remitente' => $remitenteHit ? (int) $remitenteHit->id : null,
            'id_guia_transportista' => $transportistaHit ? (int) $transportistaHit->id : null,
            'messages' => $messages,
        ];
    }

    /**
     * Endpoint publico de validacion de duplicados. Reutiliza
     * {@see self::validar_duplicado_guia_activa()} para chequeo previo al submit.
     *
     * @param  array  $params  {id_sucursal: int, guia_remitente: string, guia_transportista?: ?string, sin_guia_transportista: bool, id_excluir?: ?int}
     */
    public static function validar_duplicado(array $params): array
    {
        if (empty($params['id_sucursal'])) {
            return ApiResponse::error('Debe especificar la sucursal.');
        }

        $guiaRemitente = trim((string) ($params['guia_remitente'] ?? ''));
        $sinGuiaTransportista = ! empty($params['sin_guia_transportista']);
        $guiaTransportista = $sinGuiaTransportista
            ? null
            : (isset($params['guia_transportista']) && $params['guia_transportista'] !== null && trim((string) $params['guia_transportista']) !== ''
                ? trim((string) $params['guia_transportista'])
                : null);
        $idExcluir = isset($params['id_excluir']) && $params['id_excluir'] !== null
            ? (int) $params['id_excluir']
            : null;

        $resultado = self::validar_duplicado_guia_activa(
            $guiaRemitente,
            $guiaTransportista,
            $sinGuiaTransportista,
            $idExcluir,
        );

        // guia_remitente vacio -> no hay nada que validar, devolver OK sin duplicado.
        $data = $resultado ?? [
            'existe' => false,
            'existe_combinacion' => false,
            'existe_remitente' => false,
            'existe_transportista' => false,
            'id_guia_combinacion' => null,
            'id_guia_remitente' => null,
            'id_guia_transportista' => null,
            'messages' => [],
        ];

        // Si hay cualquier conflicto, incluimos la primera guia existente para
        // que el frontend pueda enlazarla o mostrar contexto si lo necesita.
        $firstExistingId = $data['id_guia_combinacion']
            ?? $data['id_guia_remitente']
            ?? $data['id_guia_transportista']
            ?? null;
        if ($firstExistingId !== null) {
            $guiaExistente = GuiasPrimerTramoData::get_guia_by_id($firstExistingId);
            $data['guia'] = $guiaExistente;
        }

        return ApiResponse::success($data, $data['existe'] ? 'Se detectaron conflictos con guías existentes.' : 'No se encontraron duplicados.');
    }

    /**
     * Aplicar los pesos oficiales reportados por el frontend a cada lote sin
     * particiones incluido en la guia. Si peso_neto_oficial difiere del
     * peso_neto original del lote, sobrescribe peso_actual.
     *
     * Esta funcion NO toca las particiones: las particiones mantienen sus
     * propios pesos originales (los *_oficial solo aplican al LOTE).
     *
     * Para lotes con particiones, los *_oficial se asignan automaticamente
     * desde peso_neto al crearse una guia para una de sus particiones
     * (ver aplicar_oficiales_por_particion()). Aqui se ignoran.
     *
     * @param  array<int, array{id_lote_mineral: int, peso_inicial_oficial: float, peso_final_oficial: float, peso_neto_oficial: float}>  $pesosOficiales
     * @param  int|null  $idEmpleado  Reservado para compatibilidad; ya no se usa dentro.
     */
    private static function aplicar_pesos_oficiales_lotes(array $pesosOficiales, ?int $idEmpleado): void
    {
        foreach ($pesosOficiales as $item) {
            $idLote = (int) ($item['id_lote_mineral'] ?? 0);
            if ($idLote <= 0) {
                continue;
            }

            $loteActual = DB::table('lote_mineral')->where('id', $idLote)->first();
            if (! $loteActual) {
                continue;
            }

            // Para lotes con particiones, *_oficial los asigna el metodo
            // aplicar_oficiales_por_particion() automaticamente. Aqui no se procesan.
            if ((int) ($loteActual->tiene_particion ?? 0) === 1) {
                continue;
            }

            $pesoInicialOficial = round((float) ($item['peso_inicial_oficial'] ?? 0), 2);
            $pesoFinalOficial = round((float) ($item['peso_final_oficial'] ?? 0), 2);
            $pesoNetoOficial = round((float) ($item['peso_neto_oficial'] ?? 0), 2);

            $updateFields = [
                'peso_inicial_oficial' => $pesoInicialOficial,
                'peso_final_oficial' => $pesoFinalOficial,
                'peso_neto_oficial' => $pesoNetoOficial,
            ];

            // Si el peso neto oficial difiere del peso neto original del lote,
            // sobrescribir peso_actual (sin registrar en log_cambios del lote).
            $pesoNetoOriginal = (float) ($loteActual->peso_neto ?? 0);
            if (abs($pesoNetoOficial - $pesoNetoOriginal) > 0.01) {
                $updateFields['peso_actual'] = $pesoNetoOficial;
            }

            DB::table('lote_mineral')->where('id', $idLote)->update($updateFields);
        }
    }

    /**
     * Para lotes con particiones: cuando se crea (o actualiza) una guia para
     * al menos una de sus particiones, copiar peso_neto -> peso_neto_oficial.
     * El oficial representa la suma validada de las particiones.
     *
     * Idempotente: solo asigna si *_oficial esta NULL. No sobrescribe valores
     * ya presentes (auditoria).
     *
     * @param  array<int, int>  $idLotesPadres
     */
    private static function aplicar_oficiales_por_particion(array $idLotesPadres): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $idLotesPadres),
            fn ($v) => $v > 0
        )));
        if (empty($ids)) {
            return;
        }

        foreach ($ids as $idLote) {
            $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
            if (! $lote || ! (int) ($lote->tiene_particion ?? 0)) {
                continue;
            }
            // El cast a (float) cubre null, "0", "0.00", "0.0", 0, 0.0 — todos evalúan
            // a 0.0 y la guarda deja pasar el UPDATE. Solo saltamos si hay un valor
            // real (>0) ya seteado (auditoría / idempotencia).
            if ((float) $lote->peso_neto_oficial > 0) {
                continue;
            }
            DB::table('lote_mineral')->where('id', $idLote)->update([
                'peso_inicial_oficial' => $lote->peso_inicial,
                'peso_final_oficial' => $lote->peso_final,
                'peso_neto_oficial' => $lote->peso_neto,
            ]);
        }
    }

    /**
     * Crear una nueva guía de primer tramo con sus items.
     *
     * @param  array  $data  Cabecera validada.
     * @param  array  $items  Cada item: {id_lote_mineral?: int, id_particion_lote_mineral?: int} (excluyentes).
     * @param  array{guia_remitente: ?\Illuminate\Http\UploadedFile, guia_transportista: ?\Illuminate\Http\UploadedFile}  $archivos
     */
    public static function crear_guia(array $data, array $items, array $archivos, ?Request $request = null): array
    {
        if (empty($items)) {
            return ApiResponse::error('Debe agregar al menos un item a la guía.');
        }

        $sinGuiaTransportista = ! empty($data['sin_guia_transportista']);
        $guiaRemitente = trim((string) ($data['guia_remitente'] ?? ''));
        $guiaTransportista = $sinGuiaTransportista
            ? null
            : (isset($data['guia_transportista']) && $data['guia_transportista'] !== null && trim((string) $data['guia_transportista']) !== ''
                ? trim((string) $data['guia_transportista'])
                : null);

        $resultadoDuplicado = self::validar_duplicado_guia_activa(
            $guiaRemitente,
            $guiaTransportista,
            $sinGuiaTransportista,
        );
        if ($resultadoDuplicado !== null && $resultadoDuplicado['existe']) {
            $msgPrincipal = $resultadoDuplicado['messages']['combinacion']
                ?? $resultadoDuplicado['messages']['remitente']
                ?? $resultadoDuplicado['messages']['transportista']
                ?? 'Ya existe una guía activa con los mismos datos.';

            return ApiResponse::error($msgPrincipal, [
                'codigo' => 'GUIA_DUPLICADA',
                'detalles' => $resultadoDuplicado['messages'],
            ]);
        }

        try {
            DB::beginTransaction();

            $idEmpleadoRegistro = null;
            if ($request) {
                $authUser = $request->attributes->get('auth_user');
                if ($authUser && ! empty($authUser->id_empleado)) {
                    $idEmpleadoRegistro = (int) $authUser->id_empleado;
                }
            }

            $documentos = self::build_documentos($archivos, [], $sinGuiaTransportista);

            $valoresNuevos = [
                'id_sucursal' => (int) $data['id_sucursal'],
                'id_proveedor' => (int) $data['id_proveedor'],
                'id_concesion' => (int) $data['id_concesion'],
                'id_conductor' => (int) $data['id_conductor'],
                'id_vehiculo' => (int) $data['id_vehiculo'],
                'id_empresa_transporte' => isset($data['id_empresa_transporte']) && $data['id_empresa_transporte'] !== null
                    ? (int) $data['id_empresa_transporte']
                    : null,
                'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) && $data['id_vehiculo_carreta'] !== null
                    ? (int) $data['id_vehiculo_carreta']
                    : null,
                'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) && $data['id_empresa_transporte_carreta'] !== null
                    ? (int) $data['id_empresa_transporte_carreta']
                    : null,
                'motivo_traslado' => $data['motivo_traslado'],
                'condicion_ingreso' => $data['condicion_ingreso'] ?? null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'guia_remitente' => $data['guia_remitente'] ?? null,
                'guia_transportista' => $sinGuiaTransportista ? null : ($data['guia_transportista'] ?? null),
                'sin_guia_transportista' => $sinGuiaTransportista,
                'documentos' => json_encode($documentos),
                'id_empleado_registro' => $idEmpleadoRegistro,
                'estado' => EstadoGuiaPrimerTramo::Activo->value,
                'created_at' => now()->toDateTimeString(),
            ];

            $guiaId = DB::table('guia_primer_tramo')->insertGetId($valoresNuevos);

            $now = now()->toDateTimeString();
            $rows = [];
            foreach ($items as $item) {
                $rows[] = [
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                        ? (int) $item['id_lote_mineral']
                        : null,
                    'id_particion_lote_mineral' => isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                        ? (int) $item['id_particion_lote_mineral']
                        : null,
                    'created_at' => $now,
                ];
            }
            DB::table('lote_guia')->insert($rows);

            // Aplicar pesos oficiales a los lotes sin particiones (si los hay).
            $pesosOficiales = $data['pesos_oficiales_lotes'] ?? [];
            if (! empty($pesosOficiales) && is_array($pesosOficiales)) {
                self::aplicar_pesos_oficiales_lotes($pesosOficiales, $idEmpleadoRegistro);
            }

            // Auto-asignar *_oficial para lotes con particiones (si los items
            // apuntan a particiones).
            $lotesPadresConParticion = [];
            foreach ($items as $item) {
                $idPart = $item['id_particion_lote_mineral'] ?? null;
                if ($idPart !== null) {
                    $part = DB::table('particion_lote_mineral')->where('id', (int) $idPart)->first();
                    if ($part) {
                        $lotesPadresConParticion[] = (int) $part->id_lote_mineral;
                    }
                }
            }
            if (! empty($lotesPadresConParticion)) {
                self::aplicar_oficiales_por_particion($lotesPadresConParticion);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la guía: '.$e->getMessage());
        }

        $guiaCreada = GuiasPrimerTramoData::get_guia_by_id($guiaId);

        return ApiResponse::success($guiaCreada, 'Guía de primer tramo registrada correctamente.');
    }

    /**
     * Actualizar una guía de primer tramo con sus items.
     */
    public static function actualizar_guia(int $id, array $data, array $items, array $archivos, ?Request $request = null): array
    {
        if (empty($items)) {
            return ApiResponse::error('Debe agregar al menos un item a la guía.');
        }

        $guiaPrevio = DB::table('guia_primer_tramo')->where('id', $id)->first();
        if (! $guiaPrevio) {
            return ApiResponse::error('No se encontró la guía de primer tramo.');
        }

        $sinGuiaTransportista = ! empty($data['sin_guia_transportista']);
        $guiaRemitente = trim((string) ($data['guia_remitente'] ?? ''));
        $guiaTransportista = $sinGuiaTransportista
            ? null
            : (isset($data['guia_transportista']) && $data['guia_transportista'] !== null && trim((string) $data['guia_transportista']) !== ''
                ? trim((string) $data['guia_transportista'])
                : null);

        $resultadoDuplicado = self::validar_duplicado_guia_activa(
            $guiaRemitente,
            $guiaTransportista,
            $sinGuiaTransportista,
            $id,
        );
        if ($resultadoDuplicado !== null && $resultadoDuplicado['existe']) {
            $msgPrincipal = $resultadoDuplicado['messages']['combinacion']
                ?? $resultadoDuplicado['messages']['remitente']
                ?? $resultadoDuplicado['messages']['transportista']
                ?? 'Ya existe una guía activa con los mismos datos.';

            return ApiResponse::error($msgPrincipal, [
                'codigo' => 'GUIA_DUPLICADA',
                'detalles' => $resultadoDuplicado['messages'],
            ]);
        }

        try {
            DB::beginTransaction();

            $previosDocumentos = isset($guiaPrevio->documentos) ? json_decode($guiaPrevio->documentos, true) ?? [] : [];
            $documentos = self::build_documentos(
                $archivos,
                is_array($previosDocumentos) ? $previosDocumentos : [],
                $sinGuiaTransportista
            );

            $nuevosValoresCab = [
                'id_sucursal' => (int) $data['id_sucursal'],
                'id_proveedor' => (int) $data['id_proveedor'],
                'id_concesion' => (int) $data['id_concesion'],
                'id_conductor' => (int) $data['id_conductor'],
                'id_vehiculo' => (int) $data['id_vehiculo'],
                'id_empresa_transporte' => isset($data['id_empresa_transporte']) && $data['id_empresa_transporte'] !== null
                    ? (int) $data['id_empresa_transporte']
                    : null,
                'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) && $data['id_vehiculo_carreta'] !== null
                    ? (int) $data['id_vehiculo_carreta']
                    : null,
                'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) && $data['id_empresa_transporte_carreta'] !== null
                    ? (int) $data['id_empresa_transporte_carreta']
                    : null,
                'motivo_traslado' => $data['motivo_traslado'],
                'condicion_ingreso' => $data['condicion_ingreso'] ?? null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'guia_remitente' => $data['guia_remitente'] ?? null,
                'guia_transportista' => $sinGuiaTransportista ? null : ($data['guia_transportista'] ?? null),
                'sin_guia_transportista' => $sinGuiaTransportista,
                'documentos' => $documentos,
            ];

            // --- AUDITORÍA DE CAMBIOS ---
            $cambios = [];
            $camposAuditar = [
                'id_sucursal' => [
                    'nombre' => 'Sucursal',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $s = DB::table('sucursal')->where('id', $id)->first();

                        return $s ? $s->nombre : "ID #$id";
                    },
                ],
                'id_proveedor' => [
                    'nombre' => 'Proveedor',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $p = DB::table('proveedor')->where('id', $id)->first();

                        return $p ? $p->razon_social : "ID #$id";
                    },
                ],
                'id_concesion' => [
                    'nombre' => 'Concesión',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $c = DB::table('concesion')->where('id', $id)->first();

                        return $c ? $c->nombre : "ID #$id";
                    },
                ],
                'id_conductor' => [
                    'nombre' => 'Conductor',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $c = DB::table('conductor')->where('id', $id)->first();

                        return $c ? trim($c->nombre.' '.$c->apellido) : "ID #$id";
                    },
                ],
                'id_vehiculo' => [
                    'nombre' => 'Vehículo tractor',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $v = DB::table('vehiculo')->where('id', $id)->first();
                        if ($v) {
                            return $v->placa;
                        }

                        return "ID #$id";
                    },
                ],
                'id_empresa_transporte' => [
                    'nombre' => 'Empresa transporte',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $et = DB::table('empresa_transporte')->where('id', $id)->first();

                        return $et ? $et->razon_social : "ID #$id";
                    },
                ],
                'id_vehiculo_carreta' => [
                    'nombre' => 'Carreta',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $v = DB::table('vehiculo')->where('id', $id)->first();
                        if ($v) {
                            return $v->placa;
                        }

                        return "ID #$id";
                    },
                ],
                'id_empresa_transporte_carreta' => [
                    'nombre' => 'Empresa transp. carreta',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $et = DB::table('empresa_transporte')->where('id', $id)->first();

                        return $et ? $et->razon_social : "ID #$id";
                    },
                ],
                'motivo_traslado' => ['nombre' => 'Motivo de traslado', 'tipo' => 'string'],
                'condicion_ingreso' => ['nombre' => 'Condición de ingreso', 'tipo' => 'string'],
                'fecha_inicio_traslado' => ['nombre' => 'Fecha inicio traslado', 'tipo' => 'string'],
                'fecha_emision' => ['nombre' => 'Fecha de emisión', 'tipo' => 'string'],
                'fecha_en_planta' => ['nombre' => 'Fecha en planta', 'tipo' => 'string'],
                'guia_remitente' => ['nombre' => 'Guía remitente', 'tipo' => 'string'],
                'guia_transportista' => ['nombre' => 'Guía transportista', 'tipo' => 'string'],
                'sin_guia_transportista' => ['nombre' => 'Sin guía transportista', 'tipo' => 'bool'],
            ];

            foreach ($camposAuditar as $campoBd => $meta) {
                $valAnt = $guiaPrevio->$campoBd ?? null;
                $valNue = array_key_exists($campoBd, $data) ? $data[$campoBd] : null;

                if ($meta['tipo'] === 'int') {
                    $valAnt = $valAnt !== null ? (int) $valAnt : null;
                    $valNue = ($valNue !== null && $valNue !== '') ? (int) $valNue : null;
                } elseif ($meta['tipo'] === 'bool') {
                    $valAnt = ! empty($valAnt);
                    $valNue = ! empty($valNue);
                } else {
                    $valAnt = $valAnt !== null ? trim((string) $valAnt) : '';
                    $valNue = $valNue !== null ? trim((string) $valNue) : '';
                }

                if ($valAnt !== $valNue) {
                    $valAntLabel = isset($meta['resolver']) ? $meta['resolver']($valAnt) : $valAnt;
                    $valNueLabel = isset($meta['resolver']) ? $meta['resolver']($valNue) : $valNue;

                    $cambios[] = [
                        'campo_bd' => $campoBd,
                        'campo' => $meta['nombre'],
                        'valor_anterior' => $valAntLabel,
                        'valor_nuevo' => $valNueLabel,
                    ];
                }
            }

            // Comparar items (lotes o particiones) asociados.
            $vAntItems = DB::table('lote_guia')->where('id_guia_primer_tramo', $id)->get();
            $oldItemsKey = [];
            foreach ($vAntItems as $ol) {
                if ($ol->id_particion_lote_mineral !== null) {
                    $key = 'PART:'.$ol->id_particion_lote_mineral;
                    $plm = DB::table('particion_lote_mineral')->where('id', $ol->id_particion_lote_mineral)->first();
                    $label = $plm ? ($plm->correlativo ?? "Partición #{$ol->id_particion_lote_mineral}") : "Partición #{$ol->id_particion_lote_mineral}";
                } else {
                    $key = 'LOTE:'.$ol->id_lote_mineral;
                    $lm = DB::table('lote_mineral')->where('id', $ol->id_lote_mineral)->first();
                    $label = $lm ? ($lm->correlativo ?? "Lote #{$ol->id_lote_mineral}") : "Lote #{$ol->id_lote_mineral}";
                }
                $oldItemsKey[$key] = $label;
            }

            $newItemsKey = [];
            foreach ($items as $nl) {
                $idL = $nl['id_lote_mineral'] ?? null;
                $idP = $nl['id_particion_lote_mineral'] ?? null;
                if ($idP !== null && $idP !== '') {
                    $key = 'PART:'.(int) $idP;
                    $plm = DB::table('particion_lote_mineral')->where('id', (int) $idP)->first();
                    $label = $plm ? ($plm->correlativo ?? "Partición #{$idP}") : "Partición #{$idP}";
                } else {
                    $key = 'LOTE:'.(int) $idL;
                    $lm = DB::table('lote_mineral')->where('id', (int) $idL)->first();
                    $label = $lm ? ($lm->correlativo ?? "Lote #{$idL}") : "Lote #{$idL}";
                }
                $newItemsKey[$key] = $label;
            }

            foreach (array_diff_key($newItemsKey, $oldItemsKey) as $key => $label) {
                $cambios[] = [
                    'campo_bd' => 'item_asociado',
                    'campo' => 'Item asociado',
                    'valor_anterior' => '—',
                    'valor_nuevo' => $label,
                ];
            }

            foreach (array_diff_key($oldItemsKey, $newItemsKey) as $key => $label) {
                $cambios[] = [
                    'campo_bd' => 'item_desasociado',
                    'campo' => 'Item desasociado',
                    'valor_anterior' => $label,
                    'valor_nuevo' => '—',
                ];
            }

            // --- AUDITORÍA DE DOCUMENTOS ---
            // Compara los archivos previos contra el `$documentos` recién
            // construido por build_documentos(). Solo registra cuando hay un
            // cambio real (alta, reemplazo, eliminación). El cambio del bool
            // `sin_guia_transportista` ya cubre la transición de transportista.
            $docFields = [
                'guia_remitente' => 'Documento guía remitente',
                'guia_transportista' => 'Documento guía transportista',
            ];
            foreach ($docFields as $docKey => $docLabel) {
                $previoDoc = is_array($previosDocumentos) ? ($previosDocumentos[$docKey] ?? null) : null;
                $nuevoDoc = $documentos[$docKey] ?? null;

                $archivoSubido = $archivos[$docKey] ?? null;
                $previoTenia = is_array($previoDoc) && ! empty($previoDoc['nombre_original']);
                $nuevoTiene = is_array($nuevoDoc) && ! empty($nuevoDoc['nombre_original']);

                if ($archivoSubido !== null) {
                    // El operador subió un archivo en este submit.
                    $nuevoLabel = $nuevoDoc['nombre_original'] ?? 'archivo';
                    if ($previoTenia) {
                        $previoLabel = $previoDoc['nombre_original'];
                        if ($previoLabel !== $nuevoLabel) {
                            $cambios[] = [
                                'campo_bd' => "documento_{$docKey}",
                                'campo' => $docLabel,
                                'valor_anterior' => $previoLabel,
                                'valor_nuevo' => $nuevoLabel,
                            ];
                        }
                    } else {
                        $cambios[] = [
                            'campo_bd' => "documento_{$docKey}",
                            'campo' => $docLabel,
                            'valor_anterior' => '—',
                            'valor_nuevo' => $nuevoLabel,
                        ];
                    }
                } elseif ($previoTenia && ! $nuevoTiene) {
                    // No se subió archivo nuevo y el previo existía pero ya
                    // no está en el resultado. Cubre el caso de borrado
                    // explícito (cuando el operador elimina desde el picker).
                    $cambios[] = [
                        'campo_bd' => "documento_{$docKey}",
                        'campo' => $docLabel,
                        'valor_anterior' => $previoDoc['nombre_original'],
                        'valor_nuevo' => '—',
                    ];
                }
            }

            // --- AUDITORÍA DE PESOS OFICIALES DE LOTES ---
            // Compara los pesos_*_oficial enviados por el frontend contra
            // los valores actuales en lote_mineral. Solo registra cuando
            // difieren (tolerancia 0.01, igual que aplicar_pesos_oficiales_lotes).
            $pesosOficiales = $data['pesos_oficiales_lotes'] ?? [];
            if (! empty($pesosOficiales) && is_array($pesosOficiales)) {
                foreach ($pesosOficiales as $po) {
                    $idLote = (int) ($po['id_lote_mineral'] ?? 0);
                    if ($idLote <= 0) {
                        continue;
                    }
                    $loteActual = DB::table('lote_mineral')->where('id', $idLote)->first();
                    if (! $loteActual) {
                        continue;
                    }
                    $correlativo = $loteActual->correlativo ?? "Lote #{$idLote}";
                    $prefijo = "{$correlativo} — ";

                    $checks = [
                        'peso_inicial_oficial' => 'Peso inicial oficial',
                        'peso_final_oficial' => 'Peso final oficial',
                        'peso_neto_oficial' => 'Peso neto oficial',
                    ];
                    foreach ($checks as $field => $label) {
                        $valAnt = $loteActual->$field !== null ? (float) $loteActual->$field : 0.0;
                        $valNue = round((float) ($po[$field] ?? 0), 2);
                        if (abs($valAnt - $valNue) > 0.01) {
                            $cambios[] = [
                                'campo_bd' => "lote_mineral.{$field}",
                                'campo' => $prefijo.$label,
                                'valor_anterior' => round($valAnt, 2),
                                'valor_nuevo' => $valNue,
                            ];
                        }
                    }
                }
            }

            // Registrar auditoría si hubo algún cambio
            $logActual = isset($guiaPrevio->log_cambios) ? json_decode($guiaPrevio->log_cambios, true) ?? [] : [];
            $idEmpleado = null;
            if ($request) {
                $authUser = $request->attributes->get('auth_user');
                if ($authUser && ! empty($authUser->id_empleado)) {
                    $idEmpleado = (int) $authUser->id_empleado;
                }
            }
            if (! empty($cambios)) {
                $nuevoLog = RES_CambiosLog::crear($idEmpleado ?? 0, $data['motivo'] ?? null, $cambios);
                array_unshift($logActual, $nuevoLog);
            }

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'id_sucursal' => $nuevosValoresCab['id_sucursal'],
                'id_proveedor' => $nuevosValoresCab['id_proveedor'],
                'id_concesion' => $nuevosValoresCab['id_concesion'],
                'id_conductor' => $nuevosValoresCab['id_conductor'],
                'id_vehiculo' => $nuevosValoresCab['id_vehiculo'],
                'id_empresa_transporte' => $nuevosValoresCab['id_empresa_transporte'],
                'id_vehiculo_carreta' => $nuevosValoresCab['id_vehiculo_carreta'],
                'id_empresa_transporte_carreta' => $nuevosValoresCab['id_empresa_transporte_carreta'],
                'motivo_traslado' => $nuevosValoresCab['motivo_traslado'],
                'condicion_ingreso' => $nuevosValoresCab['condicion_ingreso'],
                'fecha_inicio_traslado' => $nuevosValoresCab['fecha_inicio_traslado'],
                'fecha_emision' => $nuevosValoresCab['fecha_emision'],
                'fecha_en_planta' => $nuevosValoresCab['fecha_en_planta'],
                'guia_remitente' => $nuevosValoresCab['guia_remitente'],
                'guia_transportista' => $nuevosValoresCab['guia_transportista'],
                'sin_guia_transportista' => $nuevosValoresCab['sin_guia_transportista'],
                'documentos' => json_encode($documentos),
                'log_cambios' => json_encode($logActual),
            ]);

            // Aplicar pesos oficiales a los lotes sin particiones (si los hay).
            $pesosOficiales = $data['pesos_oficiales_lotes'] ?? [];
            if (! empty($pesosOficiales) && is_array($pesosOficiales)) {
                self::aplicar_pesos_oficiales_lotes($pesosOficiales, $idEmpleado);
            }

            // Sincronizar items (lote o partición)
            $now = now()->toDateTimeString();
            foreach ($items as $item) {
                $idLoteMineral = isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                    ? (int) $item['id_lote_mineral']
                    : null;
                $idParticion = isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                    ? (int) $item['id_particion_lote_mineral']
                    : null;

                $existente = DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->where(function ($q) use ($idLoteMineral, $idParticion) {
                        if ($idParticion !== null) {
                            $q->where('id_particion_lote_mineral', $idParticion);
                        } else {
                            $q->where('id_lote_mineral', $idLoteMineral)
                                ->whereNull('id_particion_lote_mineral');
                        }
                    })
                    ->first();

                if (! $existente) {
                    DB::table('lote_guia')->insert([
                        'id_guia_primer_tramo' => $id,
                        'id_lote_mineral' => $idLoteMineral,
                        'id_particion_lote_mineral' => $idParticion,
                        'created_at' => $now,
                    ]);
                }
            }

            // Eliminar items que ya no están en la nueva lista
            $pairsActuales = [];
            foreach ($items as $item) {
                $idLoteMineral = isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                    ? (int) $item['id_lote_mineral']
                    : null;
                $idParticion = isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                    ? (int) $item['id_particion_lote_mineral']
                    : null;
                $pairsActuales[] = ['lote' => $idLoteMineral, 'part' => $idParticion];
            }

            $existentes = DB::table('lote_guia')->where('id_guia_primer_tramo', $id)->get();
            foreach ($existentes as $ex) {
                $match = false;
                foreach ($pairsActuales as $p) {
                    if ($p['part'] !== null) {
                        if ((int) $ex->id_particion_lote_mineral === $p['part']) {
                            $match = true;
                            break;
                        }
                    } else {
                        if ((int) $ex->id_lote_mineral === $p['lote'] && $ex->id_particion_lote_mineral === null) {
                            $match = true;
                            break;
                        }
                    }
                }
                if (! $match) {
                    DB::table('lote_guia')->where('id', $ex->id)->delete();
                }
            }

            // Auto-asignar *_oficial para lotes con particiones (si los items
            // apuntan a particiones).
            $lotesPadresConParticion = [];
            foreach ($items as $item) {
                $idPart = $item['id_particion_lote_mineral'] ?? null;
                if ($idPart !== null) {
                    $part = DB::table('particion_lote_mineral')->where('id', (int) $idPart)->first();
                    if ($part) {
                        $lotesPadresConParticion[] = (int) $part->id_lote_mineral;
                    }
                }
            }
            if (! empty($lotesPadresConParticion)) {
                self::aplicar_oficiales_por_particion($lotesPadresConParticion);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la guía: '.$e->getMessage());
        }

        $guiaActualizada = GuiasPrimerTramoData::get_guia_by_id($id);

        return ApiResponse::success($guiaActualizada, 'Guía de primer tramo actualizada correctamente.');
    }

    /**
     * Anular una guía de primer tramo (cambiar estado a Inactivo).
     */
    public static function anular_guia(int $id, ?Request $request = null): array
    {
        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'estado' => EstadoGuiaPrimerTramo::Anulado->value,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular la guía: '.$e->getMessage());
        }

        $guiaAnulada = GuiasPrimerTramoData::get_guia_by_id($id);

        return ApiResponse::success($guiaAnulada, 'Guía de primer tramo anulada correctamente.');
    }
}
