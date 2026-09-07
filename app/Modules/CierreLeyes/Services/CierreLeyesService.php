<?php

namespace App\Modules\CierreLeyes\Services;

use App\Modules\CierreLeyes\Data\CierreLeyesData;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CierreLeyesService
{
    /**
     * Obtener los lotes sugeridos pendientes de análisis.
     */
    public static function get_lotes_sugeridos(): array
    {
        $data = CierreLeyesData::get_lotes_sugeridos();

        return ApiResponse::success($data, 'Lotes sugeridos obtenidos correctamente');
    }

    /**
     * Iniciar el proceso de análisis de leyes para un lote.
     */
    public static function iniciar_lote(int $idLote, int $idEmpleado): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->getRawOriginal('estado_leyes') !== EstadoLeyes::Pendiente->value) {
            return ApiResponse::error('El lote no se encuentra en estado Pendiente');
        }

        DB::beginTransaction();
        try {
            CierreLeyesData::actualizar_estado_inicio_lote($lote, $idEmpleado);

            $uuidFila = Str::uuid()->toString();
            CierreLeyesData::crear_registros_vacios_analisis($idLote, $uuidFila, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Análisis de leyes iniciado correctamente para el lote');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al iniciar el lote: '.$e->getMessage());
        }
    }

    /**
     * Agregar una nueva corrida de análisis a un lote en proceso.
     */
    public static function agregar_analisis(int $idLote, int $idEmpleado): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->getRawOriginal('estado_leyes') !== EstadoLeyes::EnProceso->value) {
            return ApiResponse::error('El lote no se encuentra en proceso de análisis');
        }

        DB::beginTransaction();
        try {
            $uuidFila = Str::uuid()->toString();
            CierreLeyesData::crear_registros_vacios_analisis($idLote, $uuidFila, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Nuevo análisis agregado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al agregar análisis: '.$e->getMessage());
        }
    }

    /**
     * Obtener el listado de lotes en proceso o confirmados para el cierre de leyes.
     */
    public static function get_lotes_cierre(?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null, ?int $id = null): array
    {
        $filtros = [];

        if ($id !== null) {
            $filtros['id_lookup'] = $id;
        }

        if ($estado !== null && $estado !== '' && $estado !== 'Todos') {
            $filtros['estados'] = [$estado];
        }

        if ($fechaInicio !== null && $fechaInicio !== '') {
            $filtros['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null && $fechaFin !== '') {
            $filtros['fecha_fin'] = $fechaFin;
        }

        $idLookup = $filtros['id_lookup'] ?? null;
        unset($filtros['id_lookup']);

        $data = CierreLeyesData::get_lotes_cierre($idLookup, $filtros);

        return ApiResponse::success($data, 'Lotes en proceso/confirmados obtenidos correctamente');
    }

    /**
     * Guardar o actualizar el valor de una ley en el análisis mineral.
     */
    public static function guardar_valor_ley(
        int $idLoteMineral,
        int $idGrupoAnalisisDetalle,
        ?string $tipoOrigen,
        string $uuidFila,
        float $ley,
        bool $estaConfirmada,
        int $idEmpleadoRegistro,
        ?int $id = null
    ): array {
        if ($estaConfirmada && $ley <= 0) {
            return ApiResponse::error('No se puede confirmar un análisis sin un valor mayor a cero.');
        }

        DB::beginTransaction();
        try {
            $detalle = CierreLeyesData::get_detalle_con_analito($idGrupoAnalisisDetalle);
            if (! $detalle) {
                return ApiResponse::error('El detalle del grupo de análisis no existe');
            }

            $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

            if (! $esDesplegable) {
                $affected = CierreLeyesData::actualizar_leyes_no_desplegables(
                    $idLoteMineral,
                    $idGrupoAnalisisDetalle,
                    $ley,
                    $estaConfirmada,
                    $idEmpleadoRegistro
                );

                if ($affected === 0) {
                    CierreLeyesData::crear_analisis_mineral([
                        'id_lote_mineral' => $idLoteMineral,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            } else {
                if ($id !== null) {
                    $registro = CierreLeyesData::get_registro_analisis_by_id($id);
                    if (! $registro) {
                        return ApiResponse::error('Registro de análisis no encontrado para actualizar');
                    }
                    CierreLeyesData::actualizar_registro_analisis($registro, $ley, $estaConfirmada, $idEmpleadoRegistro);
                } else {
                    CierreLeyesData::crear_analisis_mineral([
                        'id_lote_mineral' => $idLoteMineral,
                        'id_grupo_analisis_detalle' => $idGrupoAnalisisDetalle,
                        'tipo_origen' => $tipoOrigen,
                        'uuid_fila' => $uuidFila,
                        'ley' => $ley,
                        'esta_confirmada' => $estaConfirmada ? 1 : 0,
                        'id_empleado_registro' => $idEmpleadoRegistro,
                    ]);
                }
            }

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Valor de ley guardado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al guardar el valor de ley: '.$e->getMessage());
        }
    }

    /**
     * Eliminar un registro individual de ley.
     */
    public static function eliminar_valor(int $id): array
    {
        $registro = CierreLeyesData::get_registro_analisis_by_id($id);
        if (! $registro) {
            return ApiResponse::error('Registro de análisis no encontrado');
        }

        $idLoteMineral = (int) $registro->id_lote_mineral;
        CierreLeyesData::eliminar_registro_analisis($registro);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Registro de análisis eliminado correctamente');
    }

    /**
     * Eliminar todas las celdas asociadas a una corrida de análisis por su uuid_fila.
     */
    public static function eliminar_fila(int $idLoteMineral, string $uuidFila): array
    {
        CierreLeyesData::eliminar_fila_analisis($idLoteMineral, $uuidFila);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Fila de análisis eliminada correctamente');
    }

    /**
     * Validar si un lote cumple las condiciones de cierre.
     * Cada analito activo marcado para valorización debe tener al menos un análisis confirmado y con ley > 0.
     *
     * @return array{ok: bool, motivo?: string}
     */
    private static function validar_cierre(int $idLote): array
    {
        $detallesValorizar = CierreLeyesData::get_detalles_para_valorizacion();

        if (empty($detallesValorizar)) {
            return ['ok' => true];
        }

        $analisisLote = CierreLeyesData::get_filas_analisis_por_lote($idLote);

        foreach ($detallesValorizar as $detalle) {
            $tieneConfirmadoValido = $analisisLote->contains(function ($reg) use ($detalle) {
                return (int) $reg->id_grupo_analisis_detalle === (int) $detalle->detalle_id
                    && (bool) $reg->esta_confirmada
                    && $reg->ley !== null
                    && (float) $reg->ley > 0;
            });

            if (! $tieneConfirmadoValido) {
                return [
                    'ok' => false,
                    'motivo' => "El analito '{$detalle->analito_nombre}' requiere al menos un análisis confirmado con un valor mayor a cero.",
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * Consolidar las leyes representativas del lote calculando promedios para analitos desplegables o valor único para no desplegables.
     *
     * @return array{ley_oro: float, ley_plata: float, ley_humedad: float, ley_recuperacion: float}
     */
    private static function consolidar_leyes(int $idLote): array
    {
        $analisisConfirmados = CierreLeyesData::get_analisis_confirmados_por_lote($idLote);

        $leyesValores = [
            'ley_oro' => 0.0,
            'ley_plata' => 0.0,
            'ley_humedad' => 0.0,
            'ley_recuperacion' => 0.0,
        ];

        $agrupados = $analisisConfirmados->groupBy('id_grupo_analisis_detalle');

        foreach ($agrupados as $idDetalle => $registros) {
            $detalle = CierreLeyesData::get_detalle_con_analito((int) $idDetalle);
            if (! $detalle) {
                continue;
            }

            $esDesplegable = $detalle->analito ? (bool) $detalle->analito->es_desplegable : false;

            if ($esDesplegable) {
                $sumaLeyes = 0.0;
                $cant = 0;
                foreach ($registros as $reg) {
                    $sumaLeyes += (float) $reg->ley;
                    $cant++;
                }
                $valor = $cant > 0 ? $sumaLeyes / $cant : 0.0;
            } else {
                $valor = (float) ($registros[0]->ley ?? 0.0);
            }

            if ((bool) $detalle->para_valorizacion_oro) {
                $leyesValores['ley_oro'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_plata) {
                $leyesValores['ley_plata'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_humedad) {
                $leyesValores['ley_humedad'] = $valor;
            }
            if ((bool) $detalle->para_valorizacion_recuperacion) {
                $leyesValores['ley_recuperacion'] = $valor;
            }
        }

        return $leyesValores;
    }

    /**
     * Confirmar y cerrar el lote de leyes.
     */
    public static function confirmar_lote_leyes(int $idLote, bool $conValorComercial, int $idEmpleado): array
    {
        $lote = CierreLeyesData::get_lote_by_id($idLote);
        if (! $lote) {
            return ApiResponse::error('Lote no encontrado');
        }

        if ($lote->getRawOriginal('estado_leyes') !== EstadoLeyes::EnProceso->value) {
            return ApiResponse::error('El lote no se encuentra en proceso de análisis');
        }

        $validacion = self::validar_cierre($idLote);
        if (! $validacion['ok']) {
            return ApiResponse::error($validacion['motivo']);
        }

        DB::beginTransaction();
        try {
            $leyesValores = self::consolidar_leyes($idLote);
            CierreLeyesData::confirmar_y_cerrar_lote($lote, $leyesValores, $conValorComercial, $idEmpleado);

            DB::commit();

            $updatedLote = CierreLeyesData::get_lotes_cierre($idLote);
            $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

            return ApiResponse::success($loteData, 'Lote de leyes confirmado y cerrado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al confirmar el cierre de leyes: '.$e->getMessage());
        }
    }

    /**
     * Actualizar el tipo de origen de una corrida de análisis.
     */
    public static function actualizar_origen_fila(int $idLoteMineral, string $uuidFila, ?string $tipoOrigen, int $idEmpleado = 1): array
    {
        CierreLeyesData::actualizar_origen_fila($idLoteMineral, $uuidFila, $tipoOrigen, $idEmpleado);

        $updatedLote = CierreLeyesData::get_lotes_cierre($idLoteMineral);
        $loteData = count($updatedLote) > 0 ? $updatedLote[0] : null;

        return ApiResponse::success($loteData, 'Origen de la corrida actualizado correctamente');
    }
}
