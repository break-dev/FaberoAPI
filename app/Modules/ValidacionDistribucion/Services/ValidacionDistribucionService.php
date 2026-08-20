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
        $lote = DB::table('lote_mineral')->where('id', $idLote)->first();
        if (! $lote) {
            return ApiResponse::error('No se encontró el lote.', 404);
        }

        $countExistentes = ValidacionDistribucionData::count_particiones($idLote);
        $esPrimera = $countExistentes === 0;
        $letra = ValidacionDistribucionData::get_siguiente_letra_particion($idLote);

        $correlativoPart = $lote->correlativo.'-'.$letra;
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

        try {
            DB::beginTransaction();

            if ($esPrimera) {
                $idRecepcion = $lote->id_recepcion_unidad !== null ? (int) $lote->id_recepcion_unidad : null;
                $idTicket = $lote->id_ticket_balanza !== null ? (int) $lote->id_ticket_balanza : null;
            } else {
                $recepcionInput = $data['recepcion'] ?? [];
                $parentRecepcion = DB::table('recepcion_unidad')->where('id', $lote->id_recepcion_unidad)->first();

                $idEmpleadoRegistro = (int) ($data['id_empleado_registro'] ?? auth()->id() ?? 0);
                if ($idEmpleadoRegistro === 0 && $parentRecepcion) {
                    $idEmpleadoRegistro = (int) ($parentRecepcion->id_empleado_recepcion ?? 1);
                }
                if ($idEmpleadoRegistro === 0) {
                    $idEmpleadoRegistro = 1;
                }

                $fechaIngreso = ! empty($recepcionInput['fecha_hora_ingreso'])
                    ? date('Y-m-d H:i:s', strtotime($recepcionInput['fecha_hora_ingreso']))
                    : now()->toDateTimeString();

                $idVehiculo = ! empty($recepcionInput['id_vehiculo']) ? (int) $recepcionInput['id_vehiculo'] : null;
                $idConductor = ! empty($recepcionInput['id_conductor']) ? (int) $recepcionInput['id_conductor'] : null;
                $idSucursal = ! empty($recepcionInput['id_sucursal'])
                    ? (int) $recepcionInput['id_sucursal']
                    : ($parentRecepcion ? (int) $parentRecepcion->id_sucursal : null);

                $nuevaRecepcion = RecepcionUnidad::create([
                    'id_empleado_recepcion' => $idEmpleadoRegistro,
                    'id_vehiculo' => $idVehiculo,
                    'id_empresa_transporte' => null,
                    'id_tipo_vehiculo' => null,
                    'id_conductor' => $idConductor,
                    'tipo_ingreso' => 'Recepción de Mineral',
                    'segunda_placa' => $recepcionInput['segunda_placa'] ?? null,
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

                $idRecepcion = $nuevaRecepcion->id;

                $ticketData = CorrelativoHelper::generar(
                    tabla: 'ticket_balanza',
                    prefijo: '',
                    filtros: [],
                    longitudCeros: 0,
                    reseteo: Periodo::Diario,
                    formatoFecha: 'dmy',
                    incluirPrefijo: false,
                );
                $idTicket = DB::table('ticket_balanza')->insertGetId([
                    'correlativo' => $ticketData['correlativo'],
                    'numero_correlativo' => $ticketData['numero_correlativo'],
                    'created_at' => now(),
                ]);
            }

            $estadoPart = EstadoBase::Activo;

            $idPart = DB::table('particion_lote_mineral')->insertGetId([
                'id_lote_mineral' => $idLote,
                'id_ticket_balanza' => $idTicket,
                'id_recepcion_unidad' => $idRecepcion,
                'correlativo' => $correlativoPart,
                'particion' => $letra,
                'peso_inicial' => $pesoInicial,
                'fecha_hora_peso_inicial' => $fechaPesoInicial,
                'peso_final' => $pesoFinal,
                'fecha_hora_peso_final' => $fechaPesoFinal,
                'peso_neto' => $pesoNeto,
                'estado' => $estadoPart->value,
            ]);

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

        $particion = ParticionLoteMineral::find($idPart);

        return ApiResponse::success($particion, 'Partición creada correctamente con valores en cero. El frontend se encarga del auto-balance.');
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
                $updateFields['es_bloqueado'] = $data['es_bloqueado'] ? 1 : 0;
            }
            if (array_key_exists('estado', $data) && $data['estado'] !== null) {
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
                if (! empty($recepUpdate)) {
                    DB::table('recepcion_unidad')->where('id', (int) $particion->id_recepcion_unidad)->update($recepUpdate);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la partición: '.$e->getMessage(), 500);
        }

        $particiones = ValidacionDistribucionData::get_particiones($idLote);

        return ApiResponse::success($particiones, 'Partición actualizada correctamente.');
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
}

