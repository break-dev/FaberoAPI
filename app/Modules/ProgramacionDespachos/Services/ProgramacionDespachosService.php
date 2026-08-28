<?php

namespace App\Modules\ProgramacionDespachos\Services;

use App\Modules\ProgramacionDespachos\Data\ProgramacionDespachosData;
use App\Shared\Enums\ProgramacionDespachos\EstadoDistribucion;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Responses\ApiResponse;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Support\Facades\DB;

class ProgramacionDespachosService
{
    /**
     * Listar despachos.
     *
     * @return array<string, mixed>
     */
    public static function get_despachos(?int $idPlantaDestino, ?string $fechaInicio, ?string $fechaFin): array
    {
        $filtros = [
            'id_planta_destino' => $idPlantaDestino,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
        ];

        return ApiResponse::success(
            ProgramacionDespachosData::get_despachos($filtros),
            'Despachos consultados correctamente'
        );
    }

    /**
     * Listar items (lotes y blendings) disponibles para despachar.
     *
     * @return array<string, mixed>
     */
    public static function get_items_disponibles(): array
    {
        return ApiResponse::success(
            ProgramacionDespachosData::get_items_disponibles(),
            'Items disponibles para despacho consultados correctamente'
        );
    }

    /**
     * Detalle completo de un despacho.
     *
     * @return array<string, mixed>
     */
    public static function get_despacho(int $id): array
    {
        $full = ProgramacionDespachosData::get_despacho_full($id);
        if ($full === null) {
            return ApiResponse::error('Despacho no encontrado', 404);
        }

        return ApiResponse::success($full, 'Despacho obtenido correctamente');
    }

    /**
     * Registrar un nuevo despacho y sus detalles (lotes / blendings).
     *
     * @param  array{id_planta_destino: int, detalles: array<int, array{id_lote_mineral?: int|null, id_blending?: int|null, peso_tomado: float|int}>}  $data
     * @return array<string, mixed>
     */
    public static function crear_despacho(array $data, int $idEmpleadoRegistro): array
    {
        $idPlantaDestino = (int) $data['id_planta_destino'];
        $detalles = $data['detalles'];

        if (empty($detalles)) {
            return ApiResponse::error('Debe incluir al menos un item en el despacho.', 400);
        }

        try {
            $correlativo = CorrelativoHelper::generar(
                tabla: 'despacho',
                prefijo: 'DES',
                filtros: [],
                longitudCeros: 5,
                reseteo: Periodo::Anual,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('No se pudo generar el correlativo del despacho: '.$e->getMessage(), 500);
        }

        try {
            DB::transaction(function () use ($idPlantaDestino, $detalles, $idEmpleadoRegistro, $correlativo, &$idDespacho, &$advertencias) {
                $idDespacho = ProgramacionDespachosData::crear_despacho(
                    idEmpleadoRegistro: $idEmpleadoRegistro,
                    idPlantaDestino: $idPlantaDestino,
                    correlativo: $correlativo['correlativo'],
                    numeroCorrelativo: $correlativo['numero_correlativo'],
                );

                $advertencias = [];

                // Validar que no haya lotes/blendings duplicados en el mismo despacho.
                $lotesEnDetalles = [];
                $blendingsEnDetalles = [];
                foreach ($detalles as $det) {
                    if (isset($det['id_lote_mineral']) && $det['id_lote_mineral'] !== null) {
                        $idLoteDup = (int) $det['id_lote_mineral'];
                        if (in_array($idLoteDup, $lotesEnDetalles, true)) {
                            throw new \RuntimeException("El lote ID {$idLoteDup} está duplicado en los items del despacho.");
                        }
                        $lotesEnDetalles[] = $idLoteDup;
                    }
                    if (isset($det['id_blending']) && $det['id_blending'] !== null) {
                        $idBlendingDup = (int) $det['id_blending'];
                        if (in_array($idBlendingDup, $blendingsEnDetalles, true)) {
                            throw new \RuntimeException("El blending ID {$idBlendingDup} está duplicado en los items del despacho.");
                        }
                        $blendingsEnDetalles[] = $idBlendingDup;
                    }
                }

                foreach ($detalles as $det) {
                    $idLote = isset($det['id_lote_mineral']) ? (int) $det['id_lote_mineral'] : null;
                    $idBlending = isset($det['id_blending']) ? (int) $det['id_blending'] : null;
                    $pesoTomado = (float) $det['peso_tomado'];

                    if ((! $idLote && ! $idBlending) || ($idLote && $idBlending)) {
                        throw new \RuntimeException('Cada item debe tener exactamente id_lote_mineral o id_blending, no ambos ni ninguno.');
                    }

                    if ($pesoTomado <= 0) {
                        throw new \RuntimeException('peso_tomado debe ser mayor a 0 en cada item.');
                    }

                    $pesoDisponible = self::get_peso_disponible_item($idLote, $idBlending);
                    if ($pesoDisponible === null) {
                        throw new \RuntimeException('Uno de los items seleccionados ya no está disponible.');
                    }
                    if ($pesoTomado > $pesoDisponible) {
                        throw new \RuntimeException(sprintf(
                            'peso_tomado (%.3f KG) excede el peso disponible (%.3f KG) de uno de los items.',
                            $pesoTomado,
                            $pesoDisponible
                        ));
                    }

                    ProgramacionDespachosData::insertar_despacho_detalle(
                        idDespacho: $idDespacho,
                        idBlending: $idBlending,
                        idLoteMineral: $idLote,
                        pesoTomado: $pesoTomado,
                    );
                }

                // Restar el peso tomado del peso_actual de cada lote/blending usado,
                // dentro de la misma transaccion para que se revierta si algo falla.
                $pesoPorLote = [];
                $pesoPorBlending = [];
                foreach ($detalles as $det) {
                    $idL = isset($det['id_lote_mineral']) ? (int) $det['id_lote_mineral'] : null;
                    $idB = isset($det['id_blending']) ? (int) $det['id_blending'] : null;
                    $pT = (float) $det['peso_tomado'];
                    if ($idL !== null) {
                        $pesoPorLote[$idL] = ($pesoPorLote[$idL] ?? 0) + $pT;
                    } elseif ($idB !== null) {
                        $pesoPorBlending[$idB] = ($pesoPorBlending[$idB] ?? 0) + $pT;
                    }
                }
                foreach ($pesoPorLote as $idL => $pT) {
                    DB::update(
                        'UPDATE lote_mineral SET peso_actual = peso_actual - :peso WHERE id = :id',
                        ['peso' => $pT, 'id' => $idL]
                    );
                }
                foreach ($pesoPorBlending as $idB => $pT) {
                    DB::update(
                        'UPDATE blending SET peso_actual = peso_actual - :peso WHERE id = :id',
                        ['peso' => $pT, 'id' => $idB]
                    );
                }
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $full = ProgramacionDespachosData::get_despacho_full($idDespacho);

        return ApiResponse::success($full, 'Despacho registrado correctamente');
    }

    /**
     * Anular un despacho. Solo permitido si todas sus distribuciones están en En Espera.
     *
     * @return array<string, mixed>
     */
    public static function anular_despacho(int $id, int $idEmpleadoAnulacion): array
    {
        try {
            DB::transaction(function () use ($id, $idEmpleadoAnulacion) {
                $allEspera = ProgramacionDespachosData::all_distribuciones_en_estado(
                    $id,
                    EstadoDistribucion::EnEspera->value
                );
                if (! $allEspera) {
                    throw new \RuntimeException('No se puede anular: hay distribuciones confirmadas o avanzadas.');
                }

                $ok = ProgramacionDespachosData::anular_despacho($id, $idEmpleadoAnulacion);
                if (! $ok) {
                    throw new \RuntimeException('El despacho ya estaba anulado o no existe.');
                }

                ProgramacionDespachosData::restaurar_peso_actual_despacho_detalles($id);
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        return ApiResponse::success(
            ProgramacionDespachosData::get_despacho_full($id),
            'Despacho anulado correctamente'
        );
    }

    /**
     * Crear una distribución para un despacho. Genera automáticamente la recepción_unidad correspondiente.
     *
     * @param  array{
     *     id_sucursal: int,
     *     id_empresa_transporte: int,
     *     id_vehiculo: int,
     *     id_empresa_transporte_carreta?: int|null,
     *     id_vehiculo_carreta?: int|null,
     *     id_tipo_vehiculo: int,
     *     id_conductor: int,
     *     fecha_estimada_llegada?: string|null,
     *     detalles: array<int, array{id_despacho_detalle: int, peso_tomado: float|int}>,
     * }  $data
     * @return array<string, mixed>
     */
    public static function crear_distribucion(int $idDespacho, array $data, int $idEmpleadoRegistro): array
    {
        $detalles = $data['detalles'];
        if (empty($detalles)) {
            return ApiResponse::error('Debe incluir al menos un detalle en la distribución.', 400);
        }

        try {
            DB::transaction(function () use ($idDespacho, $data, $detalles, $idEmpleadoRegistro, &$idDistribucion, &$idRecepcionUnidad, &$advertencias) {
                $idEmpleadoAutoriza = $idEmpleadoRegistro;
                $estadoInicial = EstadoDistribucion::EnEspera->value;

                $logInicial = [
                    RES_CambiosLog::crear($idEmpleadoAutoriza, 'Creación de distribución', [
                        [
                            'campo_bd' => 'estado',
                            'campo' => 'Estado',
                            'valor_anterior' => null,
                            'valor_nuevo' => $estadoInicial,
                        ],
                    ]),
                ];

                $idDistribucion = ProgramacionDespachosData::crear_distribucion(
                    payload: [
                        'id_despacho' => $idDespacho,
                        'id_sucursal' => (int) $data['id_sucursal'],
                        'id_empresa_transporte' => (int) $data['id_empresa_transporte'],
                        'id_vehiculo' => (int) $data['id_vehiculo'],
                        'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) ? (int) $data['id_empresa_transporte_carreta'] : null,
                        'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) ? (int) $data['id_vehiculo_carreta'] : null,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                        'fecha_estimada_llegada' => $data['fecha_estimada_llegada'] ?? null,
                        'estado' => $estadoInicial,
                    ],
                    logCambiosInicial: $logInicial,
                );

                $advertencias = [];

                foreach ($detalles as $det) {
                    $idDespachoDetalle = (int) $det['id_despacho_detalle'];
                    $pesoTomado = (float) $det['peso_tomado'];

                    if ($pesoTomado <= 0) {
                        throw new \RuntimeException('peso_tomado debe ser mayor a 0 en cada detalle.');
                    }

                    // Validar contra el peso_actual del item (despacho_detalle), que es
                    // la fuente de verdad para el saldo disponible de este item específico.
                    $dd = ProgramacionDespachosData::get_despacho_detalle($idDespachoDetalle);
                    if (! $dd || (int) $dd['id_despacho'] !== $idDespacho) {
                        throw new \RuntimeException('Uno de los despacho_detalle no pertenece al despacho.');
                    }
                    $pesoDisponibleItem = (float) $dd['peso_actual'];
                    if ($pesoTomado > $pesoDisponibleItem) {
                        throw new \RuntimeException(sprintf(
                            'peso_tomado (%.3f KG) excede el peso disponible (%.3f KG) de uno de los items.',
                            $pesoTomado,
                            $pesoDisponibleItem
                        ));
                    }

                    $countPrev = ProgramacionDespachosData::count_distribuciones_por_despacho_detalle($idDespachoDetalle);
                    $pesoActualAntes = (float) $dd['peso_actual'];
                    // numero_particion = null SOLO si la primera distribucion del detalle
                    // consume todo el peso pendiente (no hubo particion previa). A partir de
                    // la segunda distribucion, siempre se enumera 2, 3, 4... aunque se
                    // consuma el resto, porque ya estaba particionado.
                    $numeroParticion = ($countPrev === 0 && $pesoTomado >= $pesoActualAntes)
                        ? null
                        : ($countPrev + 1);

                    // NOTA: validación de fecha_estimada_llegada duplicada desactivada
                    // temporalmente. El helper
                    // ProgramacionDespachosData::count_distribuciones_by_despacho_and_fecha()
                    // está disponible y funciona (verificado con tinker: count=0 con tabla
                    // vacía). Si querés re-habilitar el check, descomentar el bloque siguiente
                    // y verificar que el server no tenga cache de opcache que sirva código
                    // viejo.
                    //
                    // $fechaEstimada = $data['fecha_estimada_llegada'] ?? null;
                    // if ($fechaEstimada) {
                    //     $countDup = ProgramacionDespachosData::count_distribuciones_by_despacho_and_fecha(
                    //         idDespacho: $idDespacho,
                    //         fechaEstimada: $fechaEstimada,
                    //     );
                    //     if ($countDup > 0) {
                    //         throw new \RuntimeException(sprintf(
                    //             'Ya existe una distribución con fecha estimada %s para este despacho. Use una fecha distinta.',
                    //             $fechaEstimada
                    //         ));
                    //     }
                    // }

                    ProgramacionDespachosData::insertar_distribucion_detalle(
                        idDistribucion: $idDistribucion,
                        idDespachoDetalle: $idDespachoDetalle,
                        numeroParticion: $numeroParticion,
                        pesoTomado: $pesoTomado,
                    );

                    ProgramacionDespachosData::decrementar_peso_actual_despacho_detalle(
                        idDespachoDetalle: $idDespachoDetalle,
                        delta: $pesoTomado,
                    );
                }

                $segundaPlaca = null;
                if (! empty($data['id_vehiculo_carreta'])) {
                    $segundaPlaca = self::get_placa_vehiculo((int) $data['id_vehiculo_carreta']);
                }

                $idRecepcionUnidad = ProgramacionDespachosData::crear_recepcion_unidad_despacho([
                    'id_distribucion' => $idDistribucion,
                    'id_empleado_autoriza' => $idEmpleadoAutoriza,
                    'id_empresa_transporte' => (int) $data['id_empresa_transporte'],
                    'id_vehiculo' => (int) $data['id_vehiculo'],
                    'id_tipo_vehiculo' => (int) $data['id_tipo_vehiculo'],
                    'id_conductor' => (int) $data['id_conductor'],
                    'id_sucursal' => (int) $data['id_sucursal'],
                    'tipo_ingreso' => 'Despacho de Mineral',
                    'segunda_placa' => $segundaPlaca,
                    'fecha_estimada_llegada' => $data['fecha_estimada_llegada'] ?? null,
                    'estado' => EstadoDistribucion::EnEspera->value,
                    'es_programacion' => 1,
                    'es_recepcion_ficticia' => 0,
                    'created_at' => now()->toDateTimeString(),
                ]);

                $capacidad = ProgramacionDespachosData::get_capacidad_vehiculo((int) $data['id_vehiculo']);
                $pesoTotal = array_sum(array_map(static fn ($d) => (float) $d['peso_tomado'], $detalles));

                if ($capacidad !== null && $pesoTotal > $capacidad) {
                    $advertencias[] = sprintf(
                        'El peso total de la distribución (%.3f TN) supera la capacidad del vehículo (%.3f TN).',
                        $pesoTotal,
                        $capacidad
                    );
                }

                if (! empty($data['fecha_estimada_llegada'])) {
                    $coincidencias = ProgramacionDespachosData::get_distribuciones_con_misma_fecha_estimada(
                        $idDespacho,
                        (string) $data['fecha_estimada_llegada'],
                        $idDistribucion
                    );
                    if (! empty($coincidencias)) {
                        $advertencias[] = sprintf(
                            'Ya existe(n) %d distribución(es) en este despacho con la misma fecha estimada de llegada.',
                            count($coincidencias)
                        );
                    }
                }

                if (! empty($advertencias)) {
                    // Las advertencias no son cambios de campos reales, son solo
                    // información efímera. Se devuelven en la respuesta de la API
                    // y se muestran como toasts al usuario, pero no se persisten
                    // en log_cambios (que está reservado para cambios de estado
                    // y de campos reales de la tabla distribucion).
                }
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $full = ProgramacionDespachosData::get_despacho_full($idDespacho);

        return ApiResponse::success(
            [
                'despacho' => $full,
                'id_distribucion' => $idDistribucion,
                'id_recepcion_unidad' => $idRecepcionUnidad ?? null,
                'advertencias' => $advertencias ?? [],
            ],
            'Distribución registrada correctamente'
        );
    }

    /**
     * Confirmar una distribución (En Espera → En Planta). Marca también la recepción_unidad relacionada.
     *
     * @return array<string, mixed>
     */
    public static function confirmar_distribucion(int $id, int $idEmpleadoRecepcion): array
    {
        try {
            DB::transaction(function () use ($id, $idEmpleadoRecepcion) {
                $dist = ProgramacionDespachosData::get_distribucion($id);
                if (! $dist) {
                    throw new \RuntimeException('Distribución no encontrada.');
                }
                if ($dist['estado'] !== EstadoDistribucion::EnEspera->value) {
                    throw new \RuntimeException('Solo se pueden confirmar distribuciones en estado "En Espera".');
                }

                $nuevoEstado = EstadoDistribucion::EnPlanta->value;
                $logExistente = $dist['log_cambios'] ?? [];
                $logNuevo = RES_CambiosLog::crear($idEmpleadoRecepcion, 'Confirmación de distribución', [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => EstadoDistribucion::EnEspera->value,
                        'valor_nuevo' => $nuevoEstado,
                    ],
                ]);

                ProgramacionDespachosData::update_distribucion($id, [
                    'estado' => $nuevoEstado,
                    'log_cambios' => json_encode(array_merge($logExistente, [$logNuevo])),
                ]);

                $recepcionId = self::get_recepcion_unidad_id_para_distribucion($id);
                if ($recepcionId !== null) {
                    ProgramacionDespachosData::update_recepcion_unidad($recepcionId, [
                        'id_empleado_recepcion' => $idEmpleadoRecepcion,
                        'fecha_hora_ingreso' => now()->toDateTimeString(),
                        'estado' => EstadoDistribucion::EnPlanta->value,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $dist = ProgramacionDespachosData::get_distribucion($id);
        $dist['log_cambios'] = $dist['log_cambios'] ?? null;

        return ApiResponse::success($dist, 'Distribución confirmada correctamente');
    }

    /**
     * Registrar la salida de planta de una distribución (En Planta → Salió de Planta).
     *
     * @return array<string, mixed>
     */
    public static function registrar_salida(int $id, int $idEmpleadoOperador, ?string $observacion): array
    {
        try {
            DB::transaction(function () use ($id, $idEmpleadoOperador, $observacion) {
                $dist = ProgramacionDespachosData::get_distribucion($id);
                if (! $dist) {
                    throw new \RuntimeException('Distribución no encontrada.');
                }
                if ($dist['estado'] !== EstadoDistribucion::EnPlanta->value) {
                    throw new \RuntimeException('Solo se puede registrar salida cuando la distribución está "En Planta".');
                }

                $nuevoEstado = EstadoDistribucion::SalioDePlanta->value;
                $logExistente = $dist['log_cambios'] ?? [];
                $logNuevo = RES_CambiosLog::crear($idEmpleadoOperador, 'Salida de planta', [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => EstadoDistribucion::EnPlanta->value,
                        'valor_nuevo' => $nuevoEstado,
                    ],
                ]);

                ProgramacionDespachosData::update_distribucion($id, [
                    'estado' => $nuevoEstado,
                    'log_cambios' => json_encode(array_merge($logExistente, [$logNuevo])),
                ]);

                $recepcionId = self::get_recepcion_unidad_id_para_distribucion($id);
                if ($recepcionId !== null) {
                    $updates = [
                        'estado' => EstadoDistribucion::SalioDePlanta->value,
                        'estado_salida' => 'Fuera de Planta',
                        'fecha_hora_salida' => now()->toDateTimeString(),
                    ];
                    if ($observacion !== null) {
                        $updates['observacion_salida'] = $observacion;
                    }
                    ProgramacionDespachosData::update_recepcion_unidad($recepcionId, $updates);
                }
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $dist = ProgramacionDespachosData::get_distribucion($id);

        return ApiResponse::success($dist, 'Salida de planta registrada correctamente');
    }

    /**
     * Registrar la llegada al cliente (Salió de Planta → Llegó al Cliente).
     *
     * @return array<string, mixed>
     */
    public static function registrar_llegada(int $id, int $idEmpleadoOperador): array
    {
        try {
            DB::transaction(function () use ($id, $idEmpleadoOperador) {
                $dist = ProgramacionDespachosData::get_distribucion($id);
                if (! $dist) {
                    throw new \RuntimeException('Distribución no encontrada.');
                }
                if ($dist['estado'] !== EstadoDistribucion::SalioDePlanta->value) {
                    throw new \RuntimeException('Solo se puede registrar llegada cuando la distribución está "Salió de Planta".');
                }

                $nuevoEstado = EstadoDistribucion::LlegoAlCliente->value;
                $logExistente = $dist['log_cambios'] ?? [];
                $logNuevo = RES_CambiosLog::crear($idEmpleadoOperador, 'Llegó al cliente', [
                    [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado',
                        'valor_anterior' => EstadoDistribucion::SalioDePlanta->value,
                        'valor_nuevo' => $nuevoEstado,
                    ],
                ]);

                ProgramacionDespachosData::update_distribucion($id, [
                    'estado' => $nuevoEstado,
                    'log_cambios' => json_encode(array_merge($logExistente, [$logNuevo])),
                ]);
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $dist = ProgramacionDespachosData::get_distribucion($id);

        return ApiResponse::success($dist, 'Llegada al cliente registrada correctamente');
    }

    /**
     * Obtener el peso actual disponible de un lote o blending (helper privado).
     *
     * Lee directamente `peso_actual` (en KG), que ya se mantiene decrementado
     * por `crear_despacho` al crear cada `despacho_detalle`. Históricamente esta
     * query restaba además `SUM(dd.peso_tomado)`, lo que provocaba doble
     * descuento y rechazos inválidos al crear un despacho.
     */
    private static function get_peso_disponible_item(?int $idLote, ?int $idBlending): ?float
    {
        if ($idLote !== null) {
            $row = DB::selectOne(
                'SELECT peso_actual AS peso_disponible
                 FROM lote_mineral
                 WHERE id = :id AND esta_validado = 1',
                ['id' => $idLote]
            );
            return $row && $row->peso_disponible !== null ? (float) $row->peso_disponible : null;
        }
        if ($idBlending !== null) {
            $row = DB::selectOne(
                'SELECT peso_actual AS peso_disponible
                 FROM blending
                 WHERE id = :id',
                ['id' => $idBlending]
            );
            return $row && $row->peso_disponible !== null ? (float) $row->peso_disponible : null;
        }

        return null;
    }

    /**
     * Obtener la placa de un vehículo (helper privado).
     */
    private static function get_placa_vehiculo(int $idVehiculo): ?string
    {
        $row = DB::selectOne('SELECT placa FROM vehiculo WHERE id = :id', ['id' => $idVehiculo]);

        return $row?->placa;
    }

    /**
     * Encontrar la recepcion_unidad relacionada a una distribución creada por este módulo.
     * Lookup directo por columna `recepcion_unidad.id_distribucion` (sin heurística de JOINs).
     */
    private static function get_recepcion_unidad_id_para_distribucion(int $idDistribucion): ?int
    {
        $row = DB::table('recepcion_unidad')
            ->where('id_distribucion', $idDistribucion)
            ->value('id');

        return $row !== null ? (int) $row : null;
    }

    /**
     * Registrar el pesaje (tara/bruto/neto) de un detalle de distribución.
     * Soporta guardados parciales: solo tara, solo bruto, o ambos.
     * Genera un ticket_balanza en el primer pesaje y lo persiste.
     * Calcula merma contra peso_tomado del lote (peso húmedo → peso seco)
     * y devuelve advertencias si supera el 1%.
     *
     * @param  array{peso_tara?: float|int|string|null, peso_bruto?: float|int|string|null}  $data
     * @return array<string, mixed>
     */
    public static function pesar_distribucion_detalle(
        int $idDistribucion,
        int $idDetalle,
        array $data,
        int $idEmpleadoOperador
    ): array {
        $pesoTaraInput = array_key_exists('peso_tara', $data) && $data['peso_tara'] !== null
            ? (float) $data['peso_tara']
            : null;
        $pesoBrutoInput = array_key_exists('peso_bruto', $data) && $data['peso_bruto'] !== null
            ? (float) $data['peso_bruto']
            : null;

        if ($pesoTaraInput === null && $pesoBrutoInput === null) {
            return ApiResponse::error('Debe ingresar al menos peso_tara o peso_bruto.', 422);
        }
        if ($pesoTaraInput !== null && $pesoTaraInput <= 0) {
            return ApiResponse::error('El peso tara debe ser mayor a 0.', 422);
        }
        if ($pesoBrutoInput !== null && $pesoBrutoInput <= 0) {
            return ApiResponse::error('El peso bruto debe ser mayor a 0.', 422);
        }

        $advertencias = [];
        $idTicket = null;
        $pesoNeto = null;

        try {
            DB::transaction(function () use ($idDistribucion, $idDetalle, $pesoTaraInput, $pesoBrutoInput, &$idTicket, &$pesoNeto, &$advertencias) {
                $detalle = ProgramacionDespachosData::get_detalle_by_id_with_lote($idDetalle);
                if (! $detalle) {
                    throw new \RuntimeException('Detalle de distribución no encontrado.');
                }

                if ((int) $detalle['id_distribucion'] !== $idDistribucion) {
                    throw new \RuntimeException('El detalle no pertenece a la distribución indicada.');
                }

                // Aplicar regla "el enviado pisa, el no enviado conserva".
                $pesoTaraFinal = $pesoTaraInput !== null
                    ? $pesoTaraInput
                    : ($detalle['peso_tara'] !== null ? (float) $detalle['peso_tara'] : 0.0);
                $pesoBrutoFinal = $pesoBrutoInput !== null
                    ? $pesoBrutoInput
                    : ($detalle['peso_bruto'] !== null ? (float) $detalle['peso_bruto'] : 0.0);

                // Validar tara < bruto solo si ambos presentes y > 0.
                if ($pesoTaraFinal > 0 && $pesoBrutoFinal > 0 && $pesoTaraFinal >= $pesoBrutoFinal) {
                    throw new \RuntimeException('El peso tara debe ser menor que el peso bruto.');
                }

                // Generar ticket lazy: solo si no existe.
                $idTicket = $detalle['id_ticket_balanza'] !== null ? (int) $detalle['id_ticket_balanza'] : null;
                if (! $idTicket) {
                    $ticket = ProgramacionDespachosData::generar_ticket_balanza();
                    $idTicket = $ticket['id'];
                }

                // Calcular peso_neto solo si ambos > 0.
                $pesoNetoLocal = ($pesoTaraFinal > 0 && $pesoBrutoFinal > 0)
                    ? round($pesoBrutoFinal - $pesoTaraFinal, 3)
                    : null;

                ProgramacionDespachosData::update_detalle_pesaje(
                    $idDetalle,
                    $idTicket,
                    $pesoTaraInput,    // null si no se envió en este save
                    $pesoBrutoInput,   // null si no se envió en este save
                    $pesoNetoLocal
                );

                // Calcular merma solo si peso_neto disponible y humedad > 0.
                $pesoNeto = $pesoNetoLocal;
                $humedad = isset($detalle['lote_ley_humedad']) && $detalle['lote_ley_humedad'] !== null
                    ? (float) $detalle['lote_ley_humedad']
                    : 0.0;
                $pesoTomadoHumedo = (float) $detalle['peso_tomado'];

                if ($humedad > 0 && $pesoTomadoHumedo > 0 && $pesoNetoLocal !== null && $pesoNetoLocal > 0) {
                    $factorSeco = 1 - ($humedad / 100);
                    $pesoTomadoSeco = $pesoTomadoHumedo * $factorSeco;
                    $pesoNetoRealSeco = $pesoNetoLocal * $factorSeco;
                    // Diferencia con signo: real - target. Negativo = merma (falta), positivo = excedente (sobra).
                    $diferenciaSeca = $pesoNetoRealSeco - $pesoTomadoSeco;
                    $absDiff = abs($diferenciaSeca);
                    $mermaPct = $pesoTomadoSeco > 0 ? ($absDiff / $pesoTomadoSeco) * 100 : 0.0;

                    if ($mermaPct > 1.0) {
                        if ($diferenciaSeca < 0) {
                            $advertencias[] = sprintf(
                                'Falta peso vs P.Seco distribuido (Merma: %.2f Kg, -%.2f%%)',
                                $absDiff,
                                $mermaPct
                            );
                        } else {
                            $advertencias[] = sprintf(
                                'Sobra peso vs P.Seco distribuido (Excedente: %.2f Kg, +%.2f%%)',
                                $absDiff,
                                $mermaPct
                            );
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        return ApiResponse::success([
            'detalle' => ProgramacionDespachosData::get_detalle_by_id_with_lote($idDetalle),
            'id_ticket_balanza' => $idTicket,
            'peso_neto' => $pesoNeto,
            'advertencias' => $advertencias,
        ], 'Pesaje registrado correctamente');
    }
}