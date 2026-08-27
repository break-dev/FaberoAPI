<?php

namespace App\Modules\ValidacionDistribucion\Services;

use App\Models\ParticionLoteMineral;
use App\Models\RecepcionUnidad;
use App\Modules\ValidacionDistribucion\Data\ValidacionDistribucionData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class ValidacionDistribucionService
{
    /**
     * Listar lotes pendientes de particion (indicador).
     */
    public static function get_lotes_pendientes(array $filtros): array
    {
        $data = ValidacionDistribucionData::get_lotes_pendientes($filtros);

        return ApiResponse::success($data, 'Lotes pendientes obtenidos correctamente.');
    }

    /**
     * Validar un lote: trae peso, capacidad, suma de particiones y bandera puede_cerrar.
     */
    public static function get_validar_lote(int $idLote): array
    {
        $lote = ValidacionDistribucionData::get_lote_con_vehiculo($idLote);
        if (! $lote) {
            return ApiResponse::error('No se encontró el lote o no tiene vehículo asociado.', 404);
        }

        $suma = ValidacionDistribucionData::get_suma_peso_neto_particiones($idLote);
        $cantidad = ValidacionDistribucionData::count_particiones($idLote);
        $diferencia = round(abs((float) $lote->lote_peso_neto - $suma), 2);

        return ApiResponse::success([
            'id_lote_mineral' => (int) $lote->id_lote_mineral,
            'lote_correlativo' => $lote->lote_correlativo,
            'lote_peso_neto' => (float) $lote->lote_peso_neto,
            'vehiculo_placa' => $lote->vehiculo_placa,
            'vehiculo_capacidad' => (float) $lote->vehiculo_capacidad,
            'excedente' => (float) $lote->excedente,
            'cantidad_particiones' => $cantidad,
            'suma_peso_neto_particiones' => $suma,
            'diferencia' => $diferencia,
            'puede_cerrar' => $cantidad > 0 && $diferencia <= 0.01,
        ], 'Validación del lote obtenida correctamente.');
    }

    /**
     * Listar particiones de un lote.
     */
    public static function get_particiones(int $idLote): array
    {
        $data = ValidacionDistribucionData::get_particiones($idLote);

        return ApiResponse::success($data, 'Particiones obtenidas correctamente.');
    }

    /**
     * Detalle de una particion.
     */
    public static function get_particion(int $idParticion): array
    {
        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        return ApiResponse::success($particion, 'Partición obtenida correctamente.');
    }

    /**
     * Crear una particion. El backend decide si es la primera (A) o subsiguiente (B+).
     * Acepta payload vacio: los pesos se inicializan en 0 y la primera particion
     * hereda la recepcion / ticket del lote; las siguientes crean una recepcion
     * ficticia y un ticket auto-generado.
     * Despues de crear, dispara auto-balance entre las particiones desbloqueadas
     * del lote para igualar su peso_neto.
     *
     * @param  array{
     *   peso_inicial?: float|null, fecha_hora_peso_inicial?: string|null,
     *   peso_final?: float|null, fecha_hora_peso_final?: string|null,
     *   peso_neto?: float|null,
     *   recepcion?: array{id_vehiculo?: int|null, id_conductor?: int|null, id_sucursal?: int|null, fecha_hora_ingreso?: string|null, segunda_placa?: string|null},
     *   id_empleado_registro?: int|null
     * }  $data
     */
    public static function crear_particion(int $idLote, array $data): array
    {
        try {
            DB::beginTransaction();

            // Lock pessimista sobre el lote para evitar que dos requests
            // concurrentes para el mismo lote "sin particiones" ambos detecten
            // esPrimera=true y generen duplicados.
            $lote = DB::table('lote_mineral')->where('id', $idLote)->lockForUpdate()->first();
            if (! $lote) {
                DB::rollBack();
                return ApiResponse::error('No se encontró el lote.', 404);
            }

            $countExistentes = ValidacionDistribucionData::count_particiones($idLote);
            $esPrimera = $countExistentes === 0;

            // Definicion de cuanto se creara: 2 particiones si es la primera,
            // 1 si no. Cada una con su propia (idRecepcion, idTicket, letra).
            $creaciones = []; // cada item: ['letra' => string, 'idRecepcion' => int|null, 'idTicket' => int|null]
            $estadoPart = EstadoBase::Activo;

            if ($esPrimera) {
                // Particion A: copia de la recepcion original con es_ficticia=true
                // y reusa el ticket del lote padre (mismo id_ticket_balanza).
                // Esto permite que editar la recepcion de la particion NO toque
                // la original, y mantiene el mismo ticket/correlativo visible
                // en la UI para que el operador vincule las dos filas.
                $parentRecepcion = $lote->id_recepcion_unidad !== null
                    ? DB::table('recepcion_unidad')->where('id', $lote->id_recepcion_unidad)->first()
                    : null;

                if ($parentRecepcion) {
                    $copia = $parentRecepcion;
                    unset($copia->id);
                    $copia->es_recepcion_ficticia = 1;
                    $copia->estado = 'Fuera de Planta';
                    $copia->estado_salida = 'Vacío';
                    $copia->estado_pesaje = 'Pesado';
                    $copiaArr = (array) $copia;
                    $idRecepA = DB::table('recepcion_unidad')->insertGetId($copiaArr);
                } else {
                    // Sin recepcion padre: caer al patron de la rama else.
                    $idRecepA = self::crearRecepcionFicticia($lote, $data)->id;
                }

                $idTicketA = $lote->id_ticket_balanza ?? self::crearTicketBalanza();
                $creaciones[] = [
                    'letra' => self::letraPara($countExistentes + 0),
                    'idRecepcion' => $idRecepA,
                    'idTicket' => $idTicketA,
                ];

                // Particion B: recepcion ficticia nueva + ticket nuevo (patron B+).
                $idRecepB = self::crearRecepcionFicticia($lote, $data)->id;
                $ticketB = self::crearTicketBalanza();
                $creaciones[] = [
                    'letra' => self::letraPara($countExistentes + 1),
                    'idRecepcion' => $idRecepB,
                    'idTicket' => $ticketB,
                ];
            } else {
                // Rama B+ original: 1 sola particion con recepcion ficticia nueva.
                $recepcionInput = $data['recepcion'] ?? [];
                $idRecep = self::crearRecepcionFicticia($lote, $data, $recepcionInput)->id;
                $ticket = self::crearTicketBalanza();
                $creaciones[] = [
                    'letra' => self::letraPara($countExistentes),
                    'idRecepcion' => $idRecep,
                    'idTicket' => $ticket,
                ];
            }

            $pesoInicial = (float) ($data['peso_inicial'] ?? 0);
            $pesoFinal = (float) ($data['peso_final'] ?? 0);
            $pesoNeto = isset($data['peso_neto']) && $data['peso_neto'] !== null
                ? (float) $data['peso_neto']
                : round($pesoInicial - $pesoFinal, 2);

            $fechaPesoInicial = ! empty($data['fecha_hora_peso_inicial'])
                ? date('Y-m-d H:i:s', strtotime($data['fecha_hora_peso_inicial']))
                : null;
            $fechaPesoFinal = ! empty($data['fecha_hora_peso_final'])
                ? date('Y-m-d H:i:s', strtotime($data['fecha_hora_peso_final']))
                : null;

            $idsCreadas = [];
            foreach ($creaciones as $c) {
                $idPart = DB::table('particion_lote_mineral')->insertGetId([
                    'id_lote_mineral' => $idLote,
                    'id_ticket_balanza' => $c['idTicket'],
                    'id_recepcion_unidad' => $c['idRecepcion'],
                    'correlativo' => $lote->correlativo.'-'.$c['letra'],
                    'particion' => $c['letra'],
                    'peso_inicial' => $pesoInicial,
                    'fecha_hora_peso_inicial' => $fechaPesoInicial,
                    'peso_final' => $pesoFinal,
                    'fecha_hora_peso_final' => $fechaPesoFinal,
                    'peso_neto' => $pesoNeto,
                    'estado' => $estadoPart->value,
                ]);
                $idsCreadas[] = $idPart;
            }

            if ((int) $lote->tiene_particion === 0) {
                DB::table('lote_mineral')->where('id', $idLote)->update([
                    'tiene_particion' => 1,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al crear la partición: '.$e->getMessage(), 500);
        }

        self::recalcularPesosNoBloqueadas($idLote);

        $particiones = ParticionLoteMineral::whereIn('id', $idsCreadas)
            ->orderBy('id')
            ->get()
            ->all();

        $cantidad = count($particiones);
        $msg = $cantidad > 1
            ? "{$cantidad} particiones creadas correctamente. El backend redistribuyó los pesos entre las particiones no bloqueadas."
            : 'Partición creada correctamente con valores en cero. El backend redistribuyó los pesos entre las particiones no bloqueadas.';

        return ApiResponse::success($particiones, $msg);
    }

    /**
     * Genera la letra para una partición (A, B, C, ..., Z, AA, AB, ...).
     */
    private static function letraPara(int $n): string
    {
        $letra = '';
        $m = $n + 1;
        while ($m > 0) {
            $m--;
            $letra = chr(65 + ($m % 26)).$letra;
            $m = intdiv($m, 26);
        }
        return $letra;
    }

    /**
     * Crea una RecepcionUnidad ficticia nueva y devuelve el modelo creado.
     * Si se pasa $recepcionInput explicito, usa esos campos; si no, deriva del lote.
     *
     * REGLA DE NEGOCIO (corrección bug id_sucursal NULL en particiones B+):
     * Una particion pertenece al mismo lote que su padre, por lo tanto la recepcion
     * ficticia debe heredar SIEMPRE la id_sucursal del padre. El input explicito solo
     * puede SOBREESCRIBIR ese valor, nunca dejarlo en NULL por omision.
     */
    private static function crearRecepcionFicticia(
        object $lote,
        array $data,
        ?array $recepcionInput = null
    ): RecepcionUnidad {
        // Resolucion unica de id_sucursal: input explicito gana; si no, hereda del padre.
        $idSucursalPadre = null;
        if ($lote->id_recepcion_unidad !== null) {
            $padreIdSucursal = DB::table('recepcion_unidad')
                ->where('id', $lote->id_recepcion_unidad)
                ->value('id_sucursal');
            $idSucursalPadre = $padreIdSucursal !== null ? (int) $padreIdSucursal : null;
        }

        if ($recepcionInput === null) {
            $idEmpleadoRegistro = (int) ($data['id_empleado_registro'] ?? auth()->id() ?? 0);
            if ($idEmpleadoRegistro === 0 && $idSucursalPadre !== null) {
                // Fallback consistente: si no hay empleado, tomamos el del padre via el mismo id_recepcion.
                $padreEmpleado = DB::table('recepcion_unidad')
                    ->where('id', $lote->id_recepcion_unidad)
                    ->value('id_empleado_recepcion');
                $idEmpleadoRegistro = (int) ($padreEmpleado ?? 1);
            }
            if ($idEmpleadoRegistro === 0) {
                $idEmpleadoRegistro = 1;
            }
            $fechaIngreso = now()->toDateTimeString();
            $idVehiculo = null;
            $idConductor = null;
            $idSucursal = $idSucursalPadre;
            $segundaPlaca = null;
        } else {
            $idEmpleadoRegistro = (int) ($data['id_empleado_registro'] ?? auth()->id() ?? 0);
            if ($idEmpleadoRegistro === 0) {
                $idEmpleadoRegistro = 1;
            }
            $fechaIngreso = ! empty($recepcionInput['fecha_hora_ingreso'])
                ? date('Y-m-d H:i:s', strtotime($recepcionInput['fecha_hora_ingreso']))
                : now()->toDateTimeString();
            $idVehiculo = ! empty($recepcionInput['id_vehiculo']) ? (int) $recepcionInput['id_vehiculo'] : null;
            $idConductor = ! empty($recepcionInput['id_conductor']) ? (int) $recepcionInput['id_conductor'] : null;
            // FIX: input explicito gana; si NO viene, hereda del padre (nunca NULL).
            $idSucursal = ! empty($recepcionInput['id_sucursal'])
                ? (int) $recepcionInput['id_sucursal']
                : $idSucursalPadre;
            $segundaPlaca = $recepcionInput['segunda_placa'] ?? null;
        }

        return RecepcionUnidad::create([
            'id_empleado_recepcion' => $idEmpleadoRegistro,
            'id_vehiculo' => $idVehiculo,
            'id_empresa_transporte' => null,
            'id_tipo_vehiculo' => null,
            'id_conductor' => $idConductor,
            'tipo_ingreso' => 'Recepción de Mineral',
            'segunda_placa' => $segundaPlaca,
            'fecha_hora_ingreso' => $fechaIngreso,
            'fecha_hora_salida' => date('Y-m-d H:i:s', strtotime($fechaIngreso.' +2 hours')),
            'fecha_hora_inicio_pesaje' => date('Y-m-d H:i:s', strtotime($fechaIngreso.' +30 minutes')),
            'fecha_hora_final_pesaje' => date('Y-m-d H:i:s', strtotime($fechaIngreso.' +40 minutes')),
            'evidencias' => null,
            'observacion' => null,
            'observacion_salida' => null,
            'estado' => 'Fuera de Planta',
            'estado_salida' => 'Vacío',
            'estado_pesaje' => 'Pesado',
            'id_sucursal' => $idSucursal,
            'id_proveedor_minero' => null,
            'id_empleado_autoriza' => null,
            'es_programacion' => 0,
            'fecha_estimada_llegada' => null,
            'guia_remitente' => null,
            'guia_transportista' => null,
            'es_recepcion_ficticia' => true,
        ]);
    }

    /**
     * Genera un TicketBalanza nuevo y devuelve su id.
     */
    private static function crearTicketBalanza(): int
    {
        $ticketData = CorrelativoHelper::generar(
            tabla: 'ticket_balanza',
            prefijo: '',
            filtros: [],
            longitudCeros: 0,
            reseteo: Periodo::Diario,
            formatoFecha: 'dmy',
            incluirPrefijo: false,
        );
        return DB::table('ticket_balanza')->insertGetId([
            'correlativo' => $ticketData['correlativo'],
            'numero_correlativo' => $ticketData['numero_correlativo'],
            'created_at' => now(),
        ]);
    }

    /**
     * Registrar peso inicial de una particion B+ (crea ticket_balanza).
     *
     * @param  array{peso_inicial: float, fecha_hora_peso_inicial: string}  $data
     */
    public static function registrar_peso_inicial(int $idParticion, array $data): array
    {
        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }
        if ($particion->id_ticket_balanza !== null) {
            return ApiResponse::error('La partición ya tiene un ticket de balanza asignado.', 422);
        }

        try {
            DB::beginTransaction();

            $correlativoTicketData = CorrelativoHelper::generar(
                tabla: 'ticket_balanza',
                prefijo: '',
                filtros: [],
                longitudCeros: 0,
                reseteo: Periodo::Diario,
                formatoFecha: 'dmy',
                incluirPrefijo: false,
            );

            $ticketId = DB::table('ticket_balanza')->insertGetId([
                'correlativo' => $correlativoTicketData['correlativo'],
                'numero_correlativo' => $correlativoTicketData['numero_correlativo'],
                'created_at' => now(),
            ]);

            DB::table('particion_lote_mineral')->where('id', $idParticion)->update([
                'id_ticket_balanza' => $ticketId,
                'peso_inicial' => (float) $data['peso_inicial'],
                'fecha_hora_peso_inicial' => date('Y-m-d H:i:s', strtotime($data['fecha_hora_peso_inicial'])),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar peso inicial: '.$e->getMessage(), 500);
        }

        $particion = ParticionLoteMineral::find($idParticion);

        return ApiResponse::success($particion, 'Peso inicial registrado correctamente.');
    }

    /**
     * Registrar peso final de una particion B+.
     *
     * @param  array{peso_final: float, fecha_hora_peso_final: string}  $data
     */
    public static function registrar_peso_final(int $idParticion, array $data): array
    {
        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        $pesoFinal = (float) $data['peso_final'];
        $pesoNeto = round(((float) $particion->peso_inicial) - $pesoFinal, 2);

        DB::table('particion_lote_mineral')->where('id', $idParticion)->update([
            'peso_final' => $pesoFinal,
            'fecha_hora_peso_final' => date('Y-m-d H:i:s', strtotime($data['fecha_hora_peso_final'])),
            'peso_neto' => $pesoNeto,
            'estado' => EstadoBase::Inactivo->value,
        ]);

        $particion = ParticionLoteMineral::find($idParticion);

        return ApiResponse::success($particion, 'Peso final registrado correctamente.');
    }

    /**
     * Actualizar una particion (pesos, fechas, bloqueo, estado, recepción).
     * El backend es "tonto": persiste exactamente lo que el frontend le manda.
     * El frontend se encarga del auto-ajuste within-partition y del re-balance
     * cross-partition. Aqui NO se hace ningun calculo.
     *
     * @param  array{
     *   peso_inicial?: float, peso_final?: float, peso_neto?: float,
     *   fecha_hora_peso_inicial?: string, fecha_hora_peso_final?: string,
     *   es_bloqueado?: bool, estado?: string,
     *   recepcion?: array{id_vehiculo?: int|null, id_conductor?: int|null, id_sucursal?: int|null, id_empresa_transporte?: int|null, id_tipo_vehiculo?: int|null, id_proveedor_minero?: int|null, fecha_hora_ingreso?: string|null}
     * }  $data
     */
    public static function update_particion(int $idParticion, array $data): array
    {
        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }

        $idLote = (int) $particion->id_lote_mineral;
        $estadoAnterior = (string) ($particion->estado?->value ?? '');
        $bloqueadoAnterior = ValidacionDistribucionData::has_column_es_bloqueado()
            ? (bool) $particion->es_bloqueado
            : false;
        $estadoCambio = false;
        $bloqueoCambio = false;

        try {
            DB::beginTransaction();

            $updateFields = [];
            if (array_key_exists('peso_inicial', $data) && $data['peso_inicial'] !== null) {
                $updateFields['peso_inicial'] = round((float) $data['peso_inicial'], 2);
            }
            if (array_key_exists('peso_final', $data) && $data['peso_final'] !== null) {
                $updateFields['peso_final'] = round((float) $data['peso_final'], 2);
            }
            if (array_key_exists('peso_neto', $data) && $data['peso_neto'] !== null) {
                $updateFields['peso_neto'] = round((float) $data['peso_neto'], 2);
            }
            if (array_key_exists('fecha_hora_peso_inicial', $data) && $data['fecha_hora_peso_inicial'] !== null) {
                $updateFields['fecha_hora_peso_inicial'] = date('Y-m-d H:i:s', strtotime($data['fecha_hora_peso_inicial']));
            }
            if (array_key_exists('fecha_hora_peso_final', $data) && $data['fecha_hora_peso_final'] !== null) {
                $updateFields['fecha_hora_peso_final'] = date('Y-m-d H:i:s', strtotime($data['fecha_hora_peso_final']));
            }
            if (array_key_exists('es_bloqueado', $data) && ValidacionDistribucionData::has_column_es_bloqueado()) {
                $nuevoBloqueado = $data['es_bloqueado'] ? true : false;
                if ($nuevoBloqueado !== $bloqueadoAnterior) {
                    $bloqueoCambio = true;
                }
                $updateFields['es_bloqueado'] = $nuevoBloqueado ? 1 : 0;
            }
            if (array_key_exists('estado', $data) && $data['estado'] !== null) {
                if ((string) $data['estado'] !== $estadoAnterior) {
                    $estadoCambio = true;
                }
                $updateFields['estado'] = $data['estado'];
            }

            if (! empty($updateFields)) {
                DB::table('particion_lote_mineral')->where('id', $idParticion)->update($updateFields);
            }

            if (array_key_exists('recepcion', $data) && is_array($data['recepcion']) && $particion->id_recepcion_unidad !== null) {
                $recepUpdate = [];
                $recep = $data['recepcion'];
                if (array_key_exists('id_vehiculo', $recep)) {
                    $recepUpdate['id_vehiculo'] = ! empty($recep['id_vehiculo']) ? (int) $recep['id_vehiculo'] : null;
                }
                if (array_key_exists('id_conductor', $recep)) {
                    $recepUpdate['id_conductor'] = ! empty($recep['id_conductor']) ? (int) $recep['id_conductor'] : null;
                }
                if (array_key_exists('id_sucursal', $recep)) {
                    $recepUpdate['id_sucursal'] = ! empty($recep['id_sucursal']) ? (int) $recep['id_sucursal'] : null;
                }
                if (array_key_exists('id_empresa_transporte', $recep)) {
                    $recepUpdate['id_empresa_transporte'] = ! empty($recep['id_empresa_transporte']) ? (int) $recep['id_empresa_transporte'] : null;
                }
                if (array_key_exists('id_tipo_vehiculo', $recep)) {
                    $recepUpdate['id_tipo_vehiculo'] = ! empty($recep['id_tipo_vehiculo']) ? (int) $recep['id_tipo_vehiculo'] : null;
                }
                if (array_key_exists('id_proveedor_minero', $recep)) {
                    $recepUpdate['id_proveedor_minero'] = ! empty($recep['id_proveedor_minero']) ? (int) $recep['id_proveedor_minero'] : null;
                }
                if (array_key_exists('fecha_hora_ingreso', $recep) && $recep['fecha_hora_ingreso'] !== null) {
                    $recepUpdate['fecha_hora_ingreso'] = date('Y-m-d H:i:s', strtotime($recep['fecha_hora_ingreso']));
                }
                if (array_key_exists('fecha_hora_salida', $recep) && $recep['fecha_hora_salida'] !== null) {
                    $recepUpdate['fecha_hora_salida'] = date('Y-m-d H:i:s', strtotime($recep['fecha_hora_salida']));
                }
                if (! empty($recepUpdate)) {
                    DB::table('recepcion_unidad')->where('id', (int) $particion->id_recepcion_unidad)->update($recepUpdate);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la partición: '.$e->getMessage(), 500);
        }

        if ($estadoCambio || $bloqueoCambio) {
            self::recalcularPesosNoBloqueadas($idLote);
        }

        $particiones = ValidacionDistribucionData::get_particiones($idLote);

        return ApiResponse::success($particiones, 'Partición actualizada correctamente.');
    }

    /**
     * Redistribuye el peso_neto del lote entre las particiones NO bloqueadas
     * y activas. Las bloqueadas conservan su peso actual.
     *
     * Reglas:
     * - locked activas: conservadas.
     * - unlocked activas: se reparte `lote.peso_neto - SUM(locked)` entre todas
     *   equitativamente (la última recibe el ajuste por redondeo).
     * - peso_final NO se modifica; peso_inicial se ajusta para mantener la
     *   invariante `peso_inicial = peso_final + peso_neto`.
     */
    public static function recalcularPesosNoBloqueadas(int $idLote): void
    {
        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        if (! $lote) {
            return;
        }

        $totalLote = round((float) $lote->peso_neto, 2);

        $particiones = DB::table('particion_lote_mineral')
            ->select(['id', 'peso_neto', 'peso_final', 'es_bloqueado'])
            ->where('id_lote_mineral', $idLote)
            ->where('estado', EstadoBase::Activo->value)
            ->get();

        if ($particiones->isEmpty()) {
            return;
        }

        $hasBloqueadoCol = ValidacionDistribucionData::has_column_es_bloqueado();

        $activasLocked = [];
        $activasUnlocked = [];
        foreach ($particiones as $p) {
            $bloqueada = $hasBloqueadoCol ? (bool) $p->es_bloqueado : false;
            if ($bloqueada) {
                $activasLocked[] = $p;
            } else {
                $activasUnlocked[] = $p;
            }
        }

        if (empty($activasUnlocked)) {
            return;
        }

        $sumLocked = 0.0;
        foreach ($activasLocked as $p) {
            $sumLocked += (float) $p->peso_neto;
        }
        $sumLocked = round($sumLocked, 2);

        $targetUnlocked = round(max(0, $totalLote - $sumLocked), 2);
        $count = count($activasUnlocked);
        $baseShare = $count > 0 ? round($targetUnlocked / $count, 2) : 0.0;

        $asignados = [];
        $acumulado = 0.0;
        foreach ($activasUnlocked as $idx => $p) {
            if ($idx === $count - 1) {
                $share = round($targetUnlocked - $acumulado, 2);
            } else {
                $share = $baseShare;
                $acumulado = round($acumulado + $share, 2);
            }
            $asignados[$p->id] = $share;
        }

        foreach ($activasUnlocked as $p) {
            if (! isset($asignados[$p->id])) {
                continue;
            }
            $newNeto = round((float) $asignados[$p->id], 2);
            $pesoFinal = (float) $p->peso_final;
            $newInicial = round($pesoFinal + $newNeto, 2);

            DB::table('particion_lote_mineral')
                ->where('id', $p->id)
                ->update([
                    'peso_neto' => $newNeto,
                    'peso_inicial' => $newInicial,
                ]);
        }
    }

    /**
     * DEPRECATED: el backend ya no hace auto-balance. El frontend se encarga.
     * Esta función queda vacía para evitar errores de import en terceros.
     */
    public static function auto_balance_particiones(int $idLote, int $idPartExcluir = 0): void
    {
        // No-op. El frontend es responsable del auto-balance.
    }

    /**
     * Obtener metadatos completos para el Ticket de Balanza de una partición.
     */
    public static function get_ticket_balanza(int $idParticion): array
    {
        $data = ValidacionDistribucionData::get_ticket_balanza_particion($idParticion);
        if (! $data) {
            return ApiResponse::error('No se encontró la información del ticket para la partición especificada.', 404);
        }

        return ApiResponse::success($data, 'Ticket de balanza obtenido correctamente.');
    }

    /**
     * Obtener metadatos completos para el Ticket de Balanza del lote padre.
     */
    public static function get_ticket_balanza_lote(int $idLote): array
    {
        $data = ValidacionDistribucionData::get_ticket_balanza_lote($idLote);
        if (! $data) {
            return ApiResponse::error('No se encontró la información del ticket para el lote especificado.', 404);
        }

        return ApiResponse::success($data, 'Ticket de balanza del lote obtenido correctamente.');
    }

    /**
     * Evalua la factibilidad de validacion de un lote sin persistir.
     * Reutilizado por validar_lote y validar_particion para evitar N+1.
     */
    public static function get_evaluacion_validacion_lote(int $idLote): array
    {
        $eval = ValidacionDistribucionData::get_evaluacion_validacion([$idLote]);
        if (! isset($eval[$idLote])) {
            return ApiResponse::error('No se encontró el lote o no tiene particiones activas.', 404);
        }

        return ApiResponse::success($eval[$idLote], 'Evaluación de validación obtenida correctamente.');
    }

    /**
     * Valida una particion individual. El backend evalua el lote completo
     * (incluyendo la suma de pesos netos) y solo persiste la marca si todo cumple.
     *
     * @param  array{id_empleado: int}  $params
     */
    public static function validar_particion(int $idParticion, array $params): array
    {
        $idEmpleado = (int) ($params['id_empleado'] ?? 0);

        $particion = ParticionLoteMineral::find($idParticion);
        if (! $particion) {
            return ApiResponse::error('No se encontró la partición.', 404);
        }
        $idLote = (int) $particion->id_lote_mineral;

        $evaluaciones = ValidacionDistribucionData::get_evaluacion_validacion([$idLote]);
        $eval = $evaluaciones[$idLote] ?? null;
        if (! $eval || empty($eval['particiones'][$idParticion])) {
            return ApiResponse::error('La partición no está activa o no pertenece a un lote evaluable.', 422);
        }

        $partEval = $eval['particiones'][$idParticion];
        if (! $partEval['cumple']) {
            return ApiResponse::error('La partición no cumple los requisitos de validación.', 422, [
                'particion' => $partEval,
                'lote_cumple_suma' => $eval['cumple_suma'],
            ]);
        }

        if (! $eval['cumple_suma']) {
            return ApiResponse::error('La suma de pesos netos de las particiones no cuadra con el peso del lote padre.', 422, [
                'diferencia_suma' => $eval['diferencia_suma'],
                'suma_pesos_netos' => $eval['suma_pesos_netos'],
                'peso_neto_lote' => $eval['peso_neto_lote'],
            ]);
        }

        $now = now()->toDateTimeString();

        try {
            DB::beginTransaction();

            DB::table('particion_lote_mineral')->where('id', $idParticion)->update([
                'esta_validado' => 1,
                'id_empleado_valida' => $idEmpleado,
                'fecha_hora_validacion' => $now,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al validar la partición: '.$e->getMessage(), 500);
        }

        $actualizada = ParticionLoteMineral::find($idParticion);

        return ApiResponse::success($actualizada, 'Partición validada correctamente.');
    }

    /**
     * Valida un lote completo: marca todas sus particiones activas + el propio lote.
     *
     * @param  array{id_empleado: int}  $params
     */
    public static function validar_lote(int $idLote, array $params): array
    {
        $idEmpleado = (int) ($params['id_empleado'] ?? 0);

        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        if (! $lote) {
            return ApiResponse::error('No se encontró el lote.', 404);
        }

        $evaluaciones = ValidacionDistribucionData::get_evaluacion_validacion([$idLote]);
        $eval = $evaluaciones[$idLote] ?? null;
        if (! $eval) {
            return ApiResponse::error('No se pudo evaluar el lote.', 422);
        }

        if (! $eval['lote_cumple']) {
            return ApiResponse::error('El lote no cumple los requisitos de validación.', 422, [
                'evaluacion' => $eval,
            ]);
        }

        $now = now()->toDateTimeString();
        $idParticiones = array_map('intval', array_keys($eval['particiones']));

        try {
            DB::beginTransaction();

            if (! empty($idParticiones)) {
                $placeholders = implode(',', array_fill(0, count($idParticiones), '?'));
                DB::statement(
                    "UPDATE particion_lote_mineral
                     SET esta_validado = 1, id_empleado_valida = ?, fecha_hora_validacion = ?
                     WHERE id IN ({$placeholders})",
                    array_merge([$idEmpleado, $now], $idParticiones)
                );
            }

            DB::table('lote_mineral')->where('id', $idLote)->update([
                'esta_validado' => 1,
                'id_empleado_valida' => $idEmpleado,
                'fecha_hora_validacion' => $now,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al validar el lote: '.$e->getMessage(), 500);
        }

        $evaluacionesPost = ValidacionDistribucionData::get_evaluacion_validacion([$idLote]);

        return ApiResponse::success([
            'id_lote_mineral' => $idLote,
            'evaluacion' => $evaluacionesPost[$idLote] ?? $eval,
        ], 'Lote validado correctamente.');
    }

    /**
     * Validacion multiple: procesa cada lote individualmente.
     * Solo persiste los lotes que cumplen; los que no cumplen se devuelven como omitidos.
     *
     * @param  array{id_lotes: array<int, int>, id_empleado: int}  $data
     */
    public static function validar_lotes(array $data): array
    {
        $idEmpleado = (int) ($data['id_empleado'] ?? 0);

        $idLotes = array_values(array_unique(array_filter(
            array_map('intval', $data['id_lotes'] ?? []),
            fn ($v) => $v > 0,
        )));

        if (empty($idLotes)) {
            return ApiResponse::error('No se proporcionaron lotes para validar.', 422);
        }

        $evaluaciones = ValidacionDistribucionData::get_evaluacion_validacion($idLotes);

        $validados = [];
        $omitidos = [];

        $now = now()->toDateTimeString();

        try {
            DB::beginTransaction();

            foreach ($idLotes as $idLote) {
                $eval = $evaluaciones[$idLote] ?? null;
                if (! $eval || ! $eval['lote_cumple']) {
                    $omitidos[] = [
                        'id_lote_mineral' => $idLote,
                        'lote_correlativo' => $eval['lote_correlativo'] ?? null,
                        'razones' => self::razonesDeEvaluacion($eval),
                    ];
                    continue;
                }

                $idParticiones = array_map('intval', array_keys($eval['particiones']));
                if (! empty($idParticiones)) {
                    $placeholders = implode(',', array_fill(0, count($idParticiones), '?'));
                    DB::statement(
                        "UPDATE particion_lote_mineral
                         SET esta_validado = 1, id_empleado_valida = ?, fecha_hora_validacion = ?
                         WHERE id IN ({$placeholders})",
                        array_merge([$idEmpleado, $now], $idParticiones)
                    );
                }

                DB::table('lote_mineral')->where('id', $idLote)->update([
                    'esta_validado' => 1,
                    'id_empleado_valida' => $idEmpleado,
                    'fecha_hora_validacion' => $now,
                ]);

                $validados[] = $idLote;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al validar los lotes: '.$e->getMessage(), 500);
        }

        return ApiResponse::success([
            'validados' => $validados,
            'omitidos' => $omitidos,
        ], 'Proceso de validación múltiple finalizado.');
    }

    /**
     * Convierte una evaluacion en una lista legible de razones (particion + campos faltantes).
     * Usado en validar_lotes para devolver motivos claros de por que un lote fue omitido.
     *
     * @param  array<string, mixed>|null  $eval
     * @return array<int, string>
     */
    private static function razonesDeEvaluacion(?array $eval): array
    {
        if (! $eval) {
            return ['Lote no encontrado o sin particiones activas.'];
        }

        $razones = [];

        if (! $eval['cumple_suma']) {
            $razones[] = sprintf(
                'La suma de pesos netos (%.2f) no cuadra con el peso del lote padre (%.2f). Diferencia: %.2f.',
                $eval['suma_pesos_netos'],
                $eval['peso_neto_lote'],
                $eval['diferencia_suma'],
            );
        }

        foreach ($eval['particiones'] as $p) {
            if ($p['cumple']) {
                continue;
            }
            $razones[] = sprintf(
                'Partición %s: faltan %s.',
                $p['particion'],
                implode(', ', $p['campos_faltantes']),
            );
        }

        if (empty($razones)) {
            $razones[] = 'Lote sin requisitos pendientes identificados.';
        }

        return $razones;
    }
}
