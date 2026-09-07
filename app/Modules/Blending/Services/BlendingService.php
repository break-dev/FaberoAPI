<?php

namespace App\Modules\Blending\Services;

use App\Models\Blending;
use App\Models\BlendingDetalle;
use App\Models\LoteMineral;
use App\Modules\Blending\Data\BlendingData;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Exception;
use Illuminate\Support\Facades\DB;

class BlendingService
{
    /**
     * Resolver el id_empresa de un item del detalle de blending.
     *
     * - Si viene id_lote_mineral: lookup directo en lote_mineral.id_empresa.
     * - Si viene id_reblending: como la tabla blending no tiene id_empresa propio,
     *   se usa el id_empresa del primer componente (lote) del reblending, asumiendo
     *   que fue creado respetando la invariante. Si no hay componentes o no se
     *   puede resolver, retorna null (no se valida esa fila).
     *
     * Retorna null si no se puede resolver (no se considera para validación).
     */
    private static function resolver_empresa_item(?int $idLoteMineral, ?int $idReblending): ?int
    {
        if ($idLoteMineral !== null && $idLoteMineral > 0) {
            $row = DB::table('lote_mineral')->where('id', $idLoteMineral)->first();
            if ($row && isset($row->id_empresa) && $row->id_empresa !== null) {
                return (int) $row->id_empresa;
            }
            return null;
        }

        if ($idReblending !== null && $idReblending > 0) {
            $primerDetalle = DB::table('blending_detalle')
                ->where('id_blending', $idReblending)
                ->whereNotNull('id_lote_mineral')
                ->orderBy('id', 'asc')
                ->first();
            if ($primerDetalle && isset($primerDetalle->id_lote_mineral) && $primerDetalle->id_lote_mineral !== null) {
                $lote = DB::table('lote_mineral')->where('id', $primerDetalle->id_lote_mineral)->first();
                if ($lote && isset($lote->id_empresa) && $lote->id_empresa !== null) {
                    return (int) $lote->id_empresa;
                }
            }
        }

        return null;
    }

    /**
     * Validar que todos los items de detalle (de la mezcla y/o de las adiciones
     * para edición) pertenezcan a la misma empresa. Lanza excepción si hay
     * múltiples empresas distintas. Si no se puede resolver la empresa de un
     * item, se ignora (defensivo).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function validar_misma_empresa(array $items): void
    {
        $empresas = [];
        foreach ($items as $item) {
            $idLote = ! empty($item['id_lote_mineral']) ? (int) $item['id_lote_mineral'] : null;
            $idRebl = ! empty($item['id_reblending']) ? (int) $item['id_reblending'] : null;
            $empresa = self::resolver_empresa_item($idLote, $idRebl);
            if ($empresa !== null) {
                $empresas[$empresa] = true;
            }
        }
        if (count($empresas) > 1) {
            throw new Exception(
                'Todos los lotes y blendings seleccionados deben pertenecer a la misma empresa. '
                . 'Se detectaron '.count($empresas).' empresas distintas en la selección.'
            );
        }
    }

    /**
     * Obtener los lotes y blendings disponibles para mezclas.
     *
     * @return array<int, object>
     */
    public function get_disponibles(?int $idProveedor = null, ?int $idEmpresa = null): array
    {
        return BlendingData::get_disponibles($idProveedor, $idEmpresa);
    }

    /**
     * Listar todos los blendings.
     *
     * @return array<int, object>
     */
    public function get_blendings(?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        return BlendingData::get_blendings($fechaInicio, $fechaFin);
    }

    /**
     * Obtener un blending por ID.
     */
    public function get_blending(int $id): ?object
    {
        return BlendingData::get_blending_by_id($id);
    }

    /**
     * Crear un nuevo blending con sus detalles y descontar pesos de origen.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, mixed>  $archivos
     */
    public function crear_blending(array $data, int $idEmpleadoRegistro, array $archivos = []): object
    {
        return DB::transaction(function () use ($data, $idEmpleadoRegistro, $archivos) {
            $detalles = $data['detalles'] ?? [];
            if (empty($detalles) || ! is_array($detalles)) {
                throw new Exception('Debe incluir al menos un lote o blending para la mezcla.');
            }

            self::validar_misma_empresa($detalles);

            $evidenciasGuardadas = [];
            if (! empty($archivos)) {
                $evidenciasGuardadas = ArchivoHelper::guardarArchivos('blending', $archivos);
            }

            $correlativoInfo = CorrelativoHelper::generar(
                tabla: 'blending',
                prefijo: 'FBL',
                filtros: [],
                longitudCeros: 5,
                reseteo: Periodo::Anual,
                columnaFecha: 'created_at'
            );

            $totalTMH = 0.0;
            $totalTMS = 0.0;
            $sumAuTMS = 0.0;
            $sumAgTMS = 0.0;
            $humedades = [];
            $detallesProcesados = [];

            foreach ($detalles as $item) {
                $idLoteMineral = ! empty($item['id_lote_mineral']) ? (int) $item['id_lote_mineral'] : null;
                $idReblending = ! empty($item['id_reblending']) ? (int) $item['id_reblending'] : null;
                $pesoTomado = (float) ($item['peso_tomado'] ?? 0);

                if ($pesoTomado <= 0) {
                    throw new Exception('El peso a tomar debe ser mayor a 0.');
                }

                if ($idLoteMineral === null && $idReblending === null) {
                    throw new Exception('Cada ítem debe especificar un lote o blending de origen.');
                }

                $pesoActualOrigen = 0.0;
                $leyOro = 0.0;
                $leyPlata = 0.0;
                $leyHumedad = 0.0;

                if ($idLoteMineral !== null) {
                    $loteMineral = LoteMineral::where('id', $idLoteMineral)->lockForUpdate()->first();
                    if (! $loteMineral) {
                        throw new Exception("El lote con ID {$idLoteMineral} no fue encontrado.");
                    }

                    $pesoActualOrigen = (float) ($loteMineral->peso_actual ?? $loteMineral->peso_neto);
                    if ($pesoTomado > $pesoActualOrigen + 0.0001) {
                        throw new Exception("El peso a tomar ({$pesoTomado} kg) supera el peso disponible del lote ({$pesoActualOrigen} kg).");
                    }

                    $leyOro = (float) $loteMineral->ley_oro;
                    $leyPlata = (float) $loteMineral->ley_plata;
                    $leyHumedad = (float) $loteMineral->ley_humedad;

                    $loteMineral->peso_actual = round(max(0, $pesoActualOrigen - $pesoTomado), 2);
                    $loteMineral->save();
                } else {
                    $origBlending = Blending::where('id', $idReblending)->lockForUpdate()->first();
                    if (! $origBlending) {
                        throw new Exception("El blending con ID {$idReblending} no fue encontrado.");
                    }

                    $pesoActualOrigen = (float) $origBlending->peso_actual;
                    if ($pesoTomado > $pesoActualOrigen + 0.0001) {
                        throw new Exception("El peso a tomar ({$pesoTomado} kg) supera el peso disponible del blending ({$pesoActualOrigen} kg).");
                    }

                    $leyOro = (float) $origBlending->ley_oro;
                    $leyPlata = (float) $origBlending->ley_plata;
                    $leyHumedad = (float) $origBlending->ley_humedad;

                    $origBlending->peso_actual = round(max(0, $pesoActualOrigen - $pesoTomado), 2);
                    $origBlending->save();
                }

                $tmsTomado = round($pesoTomado * (1 - $leyHumedad / 100), 4);

                $totalTMH += $pesoTomado;
                $totalTMS += $tmsTomado;
                $sumAuTMS += ($tmsTomado * $leyOro);
                $sumAgTMS += ($tmsTomado * $leyPlata);
                $humedades[] = $leyHumedad;

                $detallesProcesados[] = [
                    'id_lote_mineral' => $idLoteMineral,
                    'id_reblending' => $idReblending,
                    'peso_actual' => $pesoActualOrigen,
                    'peso_tomado' => $pesoTomado,
                    'created_at' => now(),
                ];
            }

            $promedioHumedad = ! empty($humedades) ? (array_sum($humedades) / count($humedades)) : 0.0;
            $leyOroFinal = ($totalTMS > 0) ? ($sumAuTMS / $totalTMS) : 0.0;
            $leyPlataFinal = ($totalTMS > 0) ? ($sumAgTMS / $totalTMS) : 0.0;

            // Resolver id_empresa desde el primer detalle. Como
            // `validar_misma_empresa` ya garantizo que todos los detalles comparten
            // empresa, tomar el primero es suficiente.
            $primerDetalle = $detalles[0] ?? [];
            $idEmpresaFinal = self::resolver_empresa_item(
                ! empty($primerDetalle['id_lote_mineral']) ? (int) $primerDetalle['id_lote_mineral'] : null,
                ! empty($primerDetalle['id_reblending']) ? (int) $primerDetalle['id_reblending'] : null,
            );

            $idBlending = Blending::insertGetId([
                'id_empleado_registro' => $idEmpleadoRegistro,
                'id_empresa' => $idEmpresaFinal,
                'correlativo' => $correlativoInfo['correlativo'],
                'numero_correlativo' => $correlativoInfo['numero_correlativo'],
                'fecha_hora_blending' => $data['fecha_hora_blending'] ?? now(),
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode($evidenciasGuardadas) : null,
                'observacion' => $data['observacion'] ?? null,
                'peso_neto' => round($totalTMH, 2),
                'peso_actual' => round($totalTMH, 2),
                'ley_oro' => round($leyOroFinal, 3),
                'ley_plata' => round($leyPlataFinal, 3),
                'ley_humedad' => round($promedioHumedad, 3),
                'log_cambios' => null,
                'created_at' => now(),
            ]);

            foreach ($detallesProcesados as &$dp) {
                $dp['id_blending'] = $idBlending;
            }
            unset($dp);

            BlendingDetalle::insert($detallesProcesados);

            return BlendingData::get_blending_by_id($idBlending);
        });
    }

    /**
     * Editar un blending (actualizar metadata e incorporar adición incremental de lotes/pesos).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, mixed>  $archivos
     */
    public function editar_blending(int $idBlending, array $data, int $idEmpleado, array $archivos = []): object
    {
        return DB::transaction(function () use ($idBlending, $data, $idEmpleado, $archivos) {
            $blending = Blending::where('id', $idBlending)->lockForUpdate()->first();
            if (! $blending) {
                throw new Exception('El blending no existe.');
            }

            // Validar que las nuevas adiciones pertenezcan a la misma empresa
            // que los detalles ya existentes. Si no hay adiciones, no se valida.
            $adiciones = $data['adiciones'] ?? [];
            if (! empty($adiciones) && is_array($adiciones)) {
                $detallesExistentes = DB::table('blending_detalle')
                    ->where('id_blending', $idBlending)
                    ->get(['id_lote_mineral', 'id_reblending'])
                    ->map(fn ($d) => (array) $d)
                    ->all();
                self::validar_misma_empresa([...$detallesExistentes, ...$adiciones]);

                // Si el blending todavía no tiene id_empresa (legacy NULL), derivarlo
                // de las nuevas adiciones (la validacion garantizo consistencia).
                if ($blending->id_empresa === null) {
                    foreach ($adiciones as $adic) {
                        $empresa = self::resolver_empresa_item(
                            ! empty($adic['id_lote_mineral']) ? (int) $adic['id_lote_mineral'] : null,
                            ! empty($adic['id_reblending']) ? (int) $adic['id_reblending'] : null,
                        );
                        if ($empresa !== null) {
                            $blending->id_empresa = $empresa;
                            break;
                        }
                    }
                }
            } elseif ($blending->id_empresa === null) {
                // Sin adiciones pero con id_empresa NULL: backfill desde detalles existentes.
                $primerDetalle = DB::table('blending_detalle')
                    ->where('id_blending', $idBlending)
                    ->whereNotNull('id_lote_mineral')
                    ->orderBy('id', 'asc')
                    ->first(['id_lote_mineral']);
                if ($primerDetalle && $primerDetalle->id_lote_mineral !== null) {
                    $empresa = self::resolver_empresa_item((int) $primerDetalle->id_lote_mineral, null);
                    if ($empresa !== null) {
                        $blending->id_empresa = $empresa;
                    }
                }
            }

            $logCambios = is_string($blending->log_cambios)
                ? json_decode($blending->log_cambios, true)
                : ($blending->log_cambios ?? []);
            if (! is_array($logCambios)) {
                $logCambios = [];
            }

            $cambiosRealizados = [];

            // 1. Actualizar fecha/hora, evidencias u observaciones si vienen
            if (isset($data['fecha_hora_blending']) && ! empty($data['fecha_hora_blending'])) {
                $fechaAnteriorStr = $blending->fecha_hora_blending ? \Illuminate\Support\Carbon::parse($blending->fecha_hora_blending)->format('Y-m-d H:i:s') : '';
                $fechaNuevaStr = \Illuminate\Support\Carbon::parse($data['fecha_hora_blending'])->format('Y-m-d H:i:s');

                if ($fechaAnteriorStr !== $fechaNuevaStr) {
                    $cambiosRealizados['fecha_hora_blending'] = [
                        'anterior' => $fechaAnteriorStr,
                        'nuevo' => $fechaNuevaStr,
                    ];
                    $blending->fecha_hora_blending = $data['fecha_hora_blending'];
                }
            }

            if (isset($data['observacion']) && $data['observacion'] !== $blending->observacion) {
                $cambiosRealizados['observacion'] = [
                    'anterior' => $blending->observacion,
                    'nuevo' => $data['observacion'],
                ];
                $blending->observacion = $data['observacion'];
            }

            $evidenciasGuardadas = [];
            if (array_key_exists('evidencias_existentes', $data)) {
                $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                    ? $data['evidencias_existentes']
                    : (json_decode($data['evidencias_existentes'], true) ?? []);
            } else {
                $evidenciasGuardadas = isset($blending->evidencias)
                    ? (is_array($blending->evidencias) ? $blending->evidencias : json_decode($blending->evidencias, true) ?? [])
                    : [];
            }

            $nombresNuevos = $data['nombres_evidencias_nuevas'] ?? [];
            if (! empty($archivos)) {
                $nuevasEvidencias = ArchivoHelper::guardarArchivos('blending', $archivos);
                $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevasEvidencias);
                if (empty($nombresNuevos)) {
                    $nombresNuevos = array_column($nuevasEvidencias, 'nombre_original');
                }
            }

            $blending->evidencias = $evidenciasGuardadas;

            $nombresEliminados = $data['nombres_evidencias_eliminadas'] ?? [];

            if (! empty($nombresNuevos) || ! empty($nombresEliminados)) {
                $anteriorLabel = ! empty($nombresEliminados) ? ('Eliminado(s): '.implode(', ', $nombresEliminados)) : 'Sin cambios';
                $nuevoLabel = ! empty($nombresNuevos) ? ('Agregado(s): '.implode(', ', $nombresNuevos)) : 'Sin cambios';
                $cambiosRealizados['evidencias'] = [
                    'anterior' => $anteriorLabel,
                    'nuevo' => $nuevoLabel,
                ];
            }

            // 2. Procesar adiciones/incrementos si existen
            $adiciones = $data['adiciones'] ?? [];
            if (! empty($adiciones) && is_array($adiciones)) {
                foreach ($adiciones as $item) {
                    $idDetalle = ! empty($item['id_detalle']) ? (int) $item['id_detalle'] : null;
                    $idLoteMineral = ! empty($item['id_lote_mineral']) ? (int) $item['id_lote_mineral'] : null;
                    $idReblending = ! empty($item['id_reblending']) ? (int) $item['id_reblending'] : null;
                    $pesoAdicional = (float) ($item['peso_adicional'] ?? 0);

                    if ($pesoAdicional <= 0.0001) {
                        continue;
                    }

                    if ($idDetalle !== null) {
                        // Incrementar el peso de un detalle ya existente
                        $detalle = BlendingDetalle::where('id', $idDetalle)->where('id_blending', $idBlending)->lockForUpdate()->first();
                        if (! $detalle) {
                            throw new Exception("El detalle de blending {$idDetalle} no fue encontrado.");
                        }

                        if ($detalle->id_lote_mineral !== null) {
                            $lm = LoteMineral::where('id', $detalle->id_lote_mineral)->lockForUpdate()->first();
                            if (! $lm) {
                                throw new Exception("El lote {$detalle->id_lote_mineral} no fue encontrado.");
                            }
                            $disp = (float) ($lm->peso_actual ?? $lm->peso_neto);
                            if ($pesoAdicional > $disp + 0.0001) {
                                throw new Exception("El peso adicional superó el disponible del lote ({$disp} kg).");
                            }
                            $lm->peso_actual = max($disp - $pesoAdicional, 0.0);
                            $lm->save();
                        } else {
                            $reb = Blending::where('id', $detalle->id_reblending)->lockForUpdate()->first();
                            if (! $reb) {
                                throw new Exception("El blending {$detalle->id_reblending} no fue encontrado.");
                            }
                            $disp = (float) $reb->peso_actual;
                            if ($pesoAdicional > $disp + 0.0001) {
                                throw new Exception("El peso adicional superó el disponible del blending ({$disp} kg).");
                            }
                            Blending::where('id', $detalle->id_reblending)->update(['peso_actual' => max($disp - $pesoAdicional, 0.0)]);
                        }

                        $detalle->peso_tomado = $detalle->peso_tomado + $pesoAdicional;
                        $detalle->save();
                    } else {
                        // Agregar un nuevo detalle al blending
                        if ($idLoteMineral === null && $idReblending === null) {
                            throw new Exception('Debe especificar un lote o blending para la adición.');
                        }

                        $pesoActualOrigen = 0.0;
                        if ($idLoteMineral !== null) {
                            $lm = LoteMineral::where('id', $idLoteMineral)->lockForUpdate()->first();
                            if (! $lm) {
                                throw new Exception("El lote {$idLoteMineral} no fue encontrado.");
                            }
                            $pesoActualOrigen = (float) ($lm->peso_actual ?? $lm->peso_neto);
                            if ($pesoAdicional > $pesoActualOrigen + 0.0001) {
                                throw new Exception("El peso adicional supera el disponible del lote ({$pesoActualOrigen} kg).");
                            }
                            $lm->peso_actual = max($pesoActualOrigen - $pesoAdicional, 0.0);
                            $lm->save();
                        } else {
                            $reb = Blending::where('id', $idReblending)->lockForUpdate()->first();
                            if (! $reb) {
                                throw new Exception("El blending {$idReblending} no fue encontrado.");
                            }
                            $pesoActualOrigen = (float) $reb->peso_actual;
                            if ($pesoAdicional > $pesoActualOrigen + 0.0001) {
                                throw new Exception("El peso adicional supera el disponible del blending ({$pesoActualOrigen} kg).");
                            }
                            Blending::where('id', $idReblending)->update(['peso_actual' => max($pesoActualOrigen - $pesoAdicional, 0.0)]);
                        }

                        BlendingDetalle::create([
                            'id_blending' => $idBlending,
                            'id_lote_mineral' => $idLoteMineral,
                            'id_reblending' => $idReblending,
                            'peso_actual' => $pesoActualOrigen,
                            'peso_tomado' => $pesoAdicional,
                            'created_at' => now(),
                        ]);
                    }
                }

                // Recalcular el total y leyes del blending actualizado SOLO si hubo adiciones
                $todosDetalles = BlendingData::get_detalles_by_blending_id($idBlending);

                $totalTMH = 0.0;
                $totalTMS = 0.0;
                $sumAuTMS = 0.0;
                $sumAgTMS = 0.0;
                $humedades = [];

                foreach ($todosDetalles as $d) {
                    $tms = $d->peso_tomado * (1 - $d->ley_humedad / 100);
                    $totalTMH += $d->peso_tomado;
                    $totalTMS += $tms;
                    $sumAuTMS += ($tms * $d->ley_oro);
                    $sumAgTMS += ($tms * $d->ley_plata);
                    $humedades[] = $d->ley_humedad;
                }

                $promedioHumedad = ! empty($humedades) ? (array_sum($humedades) / count($humedades)) : 0.0;
                $leyOroFinal = ($totalTMS > 0) ? ($sumAuTMS / $totalTMS) : 0.0;
                $leyPlataFinal = ($totalTMS > 0) ? ($sumAgTMS / $totalTMS) : 0.0;

                $diferenciaPeso = $totalTMH - (float) $blending->peso_neto;
                $nuevoPesoNeto = round($totalTMH, 2);
                $nuevoPesoActual = round((float) $blending->peso_actual + $diferenciaPeso, 2);

                if (abs($diferenciaPeso) > 0.001) {
                    $cambiosRealizados['peso_neto'] = [
                        'anterior' => round((float) $blending->peso_neto, 2),
                        'nuevo' => $nuevoPesoNeto,
                    ];
                    $cambiosRealizados['peso_actual'] = [
                        'anterior' => round((float) $blending->peso_actual, 2),
                        'nuevo' => $nuevoPesoActual,
                    ];
                    $blending->peso_neto = $nuevoPesoNeto;
                    $blending->peso_actual = $nuevoPesoActual;
                }

                if (abs($leyOroFinal - (float) $blending->ley_oro) > 0.0001) {
                    $cambiosRealizados['ley_oro'] = [
                        'anterior' => round((float) $blending->ley_oro, 3),
                        'nuevo' => round($leyOroFinal, 3),
                    ];
                    $blending->ley_oro = round($leyOroFinal, 3);
                }

                if (abs($leyPlataFinal - (float) $blending->ley_plata) > 0.0001) {
                    $cambiosRealizados['ley_plata'] = [
                        'anterior' => round((float) $blending->ley_plata, 3),
                        'nuevo' => round($leyPlataFinal, 3),
                    ];
                    $blending->ley_plata = round($leyPlataFinal, 3);
                }

                if (abs($promedioHumedad - (float) $blending->ley_humedad) > 0.0001) {
                    $cambiosRealizados['ley_humedad'] = [
                        'anterior' => round((float) $blending->ley_humedad, 3),
                        'nuevo' => round($promedioHumedad, 3),
                    ];
                    $blending->ley_humedad = round($promedioHumedad, 3);
                }
            }

            $cambios = [];
            if (isset($cambiosRealizados['fecha_hora_blending'])) {
                $cambios[] = [
                    'campo_bd' => 'fecha_hora_blending',
                    'campo' => 'Fecha y Hora',
                    'valor_anterior' => $cambiosRealizados['fecha_hora_blending']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['fecha_hora_blending']['nuevo'],
                ];
            }
            if (isset($cambiosRealizados['observacion'])) {
                $cambios[] = [
                    'campo_bd' => 'observacion',
                    'campo' => 'Observación',
                    'valor_anterior' => $cambiosRealizados['observacion']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['observacion']['nuevo'],
                ];
            }
            if (isset($cambiosRealizados['evidencias'])) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencias Adjuntas',
                    'valor_anterior' => $cambiosRealizados['evidencias']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['evidencias']['nuevo'],
                ];
            }
            if (isset($cambiosRealizados['peso_neto'])) {
                $cambios[] = [
                    'campo_bd' => 'peso_neto',
                    'campo' => 'Peso Neto (kg)',
                    'valor_anterior' => $cambiosRealizados['peso_neto']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['peso_neto']['nuevo'],
                ];
                $cambios[] = [
                    'campo_bd' => 'peso_actual',
                    'campo' => 'Peso Actual (kg)',
                    'valor_anterior' => $cambiosRealizados['peso_actual']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['peso_actual']['nuevo'],
                ];
            }
            if (isset($cambiosRealizados['ley_oro'])) {
                $cambios[] = [
                    'campo_bd' => 'ley_oro',
                    'campo' => 'Ley Au (Oro)',
                    'valor_anterior' => $cambiosRealizados['ley_oro']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['ley_oro']['nuevo'],
                ];
            }
            if (isset($cambiosRealizados['ley_plata'])) {
                $cambios[] = [
                    'campo_bd' => 'ley_plata',
                    'campo' => 'Ley Ag (Plata)',
                    'valor_anterior' => $cambiosRealizados['ley_plata']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['ley_plata']['nuevo'],
                ];
            }
            if (isset($cambiosRealizados['ley_humedad'])) {
                $cambios[] = [
                    'campo_bd' => 'ley_humedad',
                    'campo' => 'Humedad (%)',
                    'valor_anterior' => $cambiosRealizados['ley_humedad']['anterior'],
                    'valor_nuevo' => $cambiosRealizados['ley_humedad']['nuevo'],
                ];
            }

            if (! empty($cambios)) {
                $logCambios[] = RES_CambiosLog::crear($idEmpleado, 'Edición / Incremento de Blending', $cambios);
                $blending->log_cambios = $logCambios;
            }

            $blending->save();

            return BlendingData::get_blending_by_id($idBlending);
        });
    }
}
