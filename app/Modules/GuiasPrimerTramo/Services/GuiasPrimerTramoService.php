<?php

namespace App\Modules\GuiasPrimerTramo\Services;

use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * Crear una nueva guía de primer tramo con sus lotes.
     */
    public static function crear_guia(array $data, array $lotes, array $archivos, ?Request $request = null): array
    {
        if (empty($lotes)) {
            return ApiResponse::error('Debe agregar al menos un lote a la guía.');
        }

        $qrTransportista = (string) Str::uuid();
        $qrRemitente = (string) Str::uuid();

        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('guias-primer-tramo', $archivos);
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
                'qr_token_transportista' => $qrTransportista,
                'qr_token_remitente' => $qrRemitente,
                'motivo_traslado' => $data['motivo_traslado'],
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode($evidenciasGuardadas) : null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
                'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
                'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
                'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
                'sin_guia_transportista' => ! empty($data['sin_guia_transportista']),
                'id_empleado_registro' => $idEmpleadoRegistro,
                'estado' => EstadoBase::Activo->value,
            ];

            $valoresNuevos['created_at'] = now()->toDateTimeString();

            $guiaId = DB::table('guia_primer_tramo')->insertGetId($valoresNuevos);

            foreach ($lotes as $lote) {
                $pesoBruto = (float) ($lote['peso_bruto'] ?? 0);
                $tara = (float) ($lote['tara'] ?? 0);
                $pesoNeto = $pesoBruto - $tara;

                DB::table('lote_guia')->insert([
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => (int) $lote['id_lote_mineral'],
                    'peso_bruto' => $pesoBruto,
                    'tara' => $tara,
                    'peso_neto' => $pesoNeto,
                ]);
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
     * Actualizar una guía de primer tramo con sus lotes y evidencias.
     */
    public static function actualizar_guia(int $id, array $data, array $lotes, array $archivos, ?Request $request = null): array
    {
        if (empty($lotes)) {
            return ApiResponse::error('Debe agregar al menos un lote a la guía.');
        }

        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            $evidenciasGuardadas = [];
            if (array_key_exists('evidencias_existentes', $data)) {
                $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                    ? $data['evidencias_existentes']
                    : (json_decode($data['evidencias_existentes'], true) ?? []);
            } else {
                $evidenciasGuardadas = isset($guia->evidencias) ? json_decode($guia->evidencias, true) ?? [] : [];
            }

            if (! empty($archivos)) {
                $nuevasEvidencias = ArchivoHelper::guardarArchivos('guias-primer-tramo', $archivos);
                $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevasEvidencias);
            }

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
                'evidencias' => $evidenciasGuardadas,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'serie_guia_remitente' => $data['serie_guia_remitente'] ?? null,
                'numero_guia_remitente' => $data['numero_guia_remitente'] ?? null,
                'serie_guia_transportista' => $data['serie_guia_transportista'] ?? null,
                'numero_guia_transportista' => $data['numero_guia_transportista'] ?? null,
                'sin_guia_transportista' => ! empty($data['sin_guia_transportista']),
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
                            return $v->placa ?? ($v->serie_placa ? trim($v->serie_placa.'-'.$v->numero_placa) : $v->numero_placa);
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
                            return $v->placa ?? ($v->serie_placa ? trim($v->serie_placa.'-'.$v->numero_placa) : $v->numero_placa);
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
                'fecha_inicio_traslado' => ['nombre' => 'Fecha inicio traslado', 'tipo' => 'string'],
                'fecha_emision' => ['nombre' => 'Fecha de emisión', 'tipo' => 'string'],
                'fecha_en_planta' => ['nombre' => 'Fecha en planta', 'tipo' => 'string'],
                'serie_guia_remitente' => ['nombre' => 'Serie GR', 'tipo' => 'string'],
                'numero_guia_remitente' => ['nombre' => 'Número GR', 'tipo' => 'string'],
                'serie_guia_transportista' => ['nombre' => 'Serie GT', 'tipo' => 'string'],
                'numero_guia_transportista' => ['nombre' => 'Número GT', 'tipo' => 'string'],
                'sin_guia_transportista' => ['nombre' => 'Sin guía transportista', 'tipo' => 'bool'],
            ];

            foreach ($camposAuditar as $campoBd => $meta) {
                $valAnt = $guia->$campoBd;
                $valNue = array_key_exists($campoBd, $data) ? $data[$campoBd] : null;

                // Normalizar tipos para la comparación
                if ($meta['tipo'] === 'int') {
                    $valAnt = $valAnt !== null ? (int) $valAnt : null;
                    $valNue = ($valNue !== null && $valNue !== '') ? (int) $valNue : null;
                } elseif ($meta['tipo'] === 'float') {
                    $valAnt = $valAnt !== null ? (float) $valAnt : null;
                    $valNue = ($valNue !== null && $valNue !== '') ? (float) $valNue : null;
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

            // Comparar evidencias
            $vAntEvidencias = isset($guia->evidencias) ? json_decode($guia->evidencias, true) ?? [] : [];
            $nombresAnt = [];
            foreach ($vAntEvidencias as $e) {
                if (isset($e['nombre_original'])) {
                    $nombresAnt[] = $e['nombre_original'];
                }
            }
            $nombresNue = [];
            foreach ($evidenciasGuardadas as $e) {
                if (isset($e['nombre_original'])) {
                    $nombresNue[] = $e['nombre_original'];
                }
            }

            sort($nombresAnt);
            sort($nombresNue);

            if ($nombresAnt !== $nombresNue) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencias',
                    'valor_anterior' => ! empty($nombresAnt) ? implode(', ', $nombresAnt) : '— (sin evidencias)',
                    'valor_nuevo' => ! empty($nombresNue) ? implode(', ', $nombresNue) : '— (sin evidencias)',
                ];
            }

            // Comparar lotes asociados
            $vAntLotes = DB::table('lote_guia')->where('id_guia_primer_tramo', $id)->get();
            $oldLotesData = [];
            foreach ($vAntLotes as $ol) {
                $loteMineral = DB::table('lote_mineral')->where('id', $ol->id_lote_mineral)->first();
                $correlativo = $loteMineral ? $loteMineral->correlativo : "Lote #$ol->id_lote_mineral";
                $oldLotesData[$ol->id_lote_mineral] = [
                    'correlativo' => $correlativo,
                    'peso_bruto' => (float) $ol->peso_bruto,
                    'tara' => (float) $ol->tara,
                ];
            }

            $newLotesData = [];
            foreach ($lotes as $nl) {
                $idLoteMineral = (int) $nl['id_lote_mineral'];
                $loteMineral = DB::table('lote_mineral')->where('id', $idLoteMineral)->first();
                $correlativo = $loteMineral ? $loteMineral->correlativo : "Lote #$idLoteMineral";
                $newLotesData[$idLoteMineral] = [
                    'correlativo' => $correlativo,
                    'peso_bruto' => (float) ($nl['peso_bruto'] ?? 0),
                    'tara' => (float) ($nl['tara'] ?? 0),
                ];
            }

            // Comparar cambios en lotes asociados
            foreach ($oldLotesData as $idLote => $oldInfo) {
                if (isset($newLotesData[$idLote])) {
                    $newInfo = $newLotesData[$idLote];
                    $correlativo = $oldInfo['correlativo'];

                    if ($oldInfo['peso_bruto'] !== $newInfo['peso_bruto']) {
                        $cambios[] = [
                            'campo_bd' => 'peso_bruto_lote',
                            'campo' => "Peso Bruto - {$correlativo}",
                            'valor_anterior' => "{$oldInfo['peso_bruto']} kg",
                            'valor_nuevo' => "{$newInfo['peso_bruto']} kg",
                        ];
                    }

                    if ($oldInfo['tara'] !== $newInfo['tara']) {
                        $cambios[] = [
                            'campo_bd' => 'tara_lote',
                            'campo' => "Tara - {$correlativo}",
                            'valor_anterior' => "{$oldInfo['tara']} kg",
                            'valor_nuevo' => "{$newInfo['tara']} kg",
                        ];
                    }
                }
            }

            foreach (array_diff_key($newLotesData, $oldLotesData) as $idLote => $info) {
                $cambios[] = [
                    'campo_bd' => 'lote_asociado',
                    'campo' => 'Lote asociado',
                    'valor_anterior' => '—',
                    'valor_nuevo' => "{$info['correlativo']} (P. Bruto {$info['peso_bruto']}kg, Tara {$info['tara']}kg)",
                ];
            }

            foreach (array_diff_key($oldLotesData, $newLotesData) as $idLote => $info) {
                $cambios[] = [
                    'campo_bd' => 'lote_desasociado',
                    'campo' => 'Lote desasociado',
                    'valor_anterior' => "{$info['correlativo']} (P. Bruto {$info['peso_bruto']}kg, Tara {$info['tara']}kg)",
                    'valor_nuevo' => '—',
                ];
            }

            // Registrar auditoría si hubo algún cambio
            $logActual = isset($guia->log_cambios) ? json_decode($guia->log_cambios, true) ?? [] : [];
            if (! empty($cambios)) {
                $idEmpleado = null;
                if ($request) {
                    $authUser = $request->attributes->get('auth_user');
                    if ($authUser && ! empty($authUser->id_empleado)) {
                        $idEmpleado = (int) $authUser->id_empleado;
                    }
                }

                $nuevoLog = [
                    'id_empleado' => $idEmpleado,
                    'motivo' => $data['motivo'] ?? null,
                    'update_at' => now()->toDateTimeString(),
                    'cambios' => $cambios,
                ];
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
                'evidencias' => ! empty($evidenciasGuardadas) ? json_encode(array_values($evidenciasGuardadas)) : null,
                'fecha_inicio_traslado' => $nuevosValoresCab['fecha_inicio_traslado'],
                'fecha_emision' => $nuevosValoresCab['fecha_emision'],
                'fecha_en_planta' => $nuevosValoresCab['fecha_en_planta'],
                'serie_guia_remitente' => $nuevosValoresCab['serie_guia_remitente'],
                'numero_guia_remitente' => $nuevosValoresCab['numero_guia_remitente'],
                'serie_guia_transportista' => $nuevosValoresCab['serie_guia_transportista'],
                'numero_guia_transportista' => $nuevosValoresCab['numero_guia_transportista'],
                'sin_guia_transportista' => $nuevosValoresCab['sin_guia_transportista'],
                'log_cambios' => json_encode($logActual),
            ]);

            // Sincronizar lotes
            $nuevosLotesIds = [];
            foreach ($lotes as $lote) {
                $idLoteMineral = (int) $lote['id_lote_mineral'];
                $pesoBruto = (float) ($lote['peso_bruto'] ?? 0);
                $tara = (float) ($lote['tara'] ?? 0);
                $pesoNeto = $pesoBruto - $tara;

                $nuevosLotesIds[] = $idLoteMineral;

                $loteGuia = DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->where('id_lote_mineral', $idLoteMineral)
                    ->first();

                if ($loteGuia) {
                    DB::table('lote_guia')
                        ->where('id', $loteGuia->id)
                        ->update([
                            'peso_bruto' => $pesoBruto,
                            'tara' => $tara,
                            'peso_neto' => $pesoNeto,
                        ]);
                } else {
                    DB::table('lote_guia')->insert([
                        'id_guia_primer_tramo' => $id,
                        'id_lote_mineral' => $idLoteMineral,
                        'peso_bruto' => $pesoBruto,
                        'tara' => $tara,
                        'peso_neto' => $pesoNeto,
                    ]);
                }
            }

            if (! empty($nuevosLotesIds)) {
                DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->whereNotIn('id_lote_mineral', $nuevosLotesIds)
                    ->delete();
            } else {
                DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->delete();
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
                'estado' => EstadoBase::Inactivo->value,
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
