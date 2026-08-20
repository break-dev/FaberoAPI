<?php

namespace App\Modules\GuiasPrimerTramo\Services;

use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Helpers\ArchivoHelper;
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
     * @param  bool  $sin_guia_transportista
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
                'estado' => EstadoBase::Activo->value,
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

        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            $sinGuiaTransportista = ! empty($data['sin_guia_transportista']);

            $previosDocumentos = isset($guia->documentos) ? json_decode($guia->documentos, true) ?? [] : [];
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
                $valAnt = $guia->$campoBd ?? null;
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
                    $key = 'PART:' . $ol->id_particion_lote_mineral;
                    $plm = DB::table('particion_lote_mineral')->where('id', $ol->id_particion_lote_mineral)->first();
                    $label = $plm ? ($plm->correlativo ?? "Partición #{$ol->id_particion_lote_mineral}") : "Partición #{$ol->id_particion_lote_mineral}";
                } else {
                    $key = 'LOTE:' . $ol->id_lote_mineral;
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
                    $key = 'PART:' . (int) $idP;
                    $plm = DB::table('particion_lote_mineral')->where('id', (int) $idP)->first();
                    $label = $plm ? ($plm->correlativo ?? "Partición #{$idP}") : "Partición #{$idP}";
                } else {
                    $key = 'LOTE:' . (int) $idL;
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
