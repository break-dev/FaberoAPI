<?php

namespace App\Modules\RecepcionMineral\Services;

use App\Models\Empresa;
use App\Models\LoteMineral;
use App\Models\RecepcionUnidad;
use App\Models\Vehiculo;
use App\Modules\RecepcionMineral\Data\RecepcionMineralData;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Enums\_Generic\EstadoLeyes;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CorrelativoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class RecepcionMineralService
{
    /**
     * Obtener listado de recepciones filtradas por sucursal
     */
    public static function get_recepciones_mineral(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = RecepcionMineralData::get_recepciones_mineral($filters);

        return ApiResponse::success($data, 'Recepciones obtenidas correctamente');
    }

    /**
     * Iniciar el proceso de pesaje
     */
    public static function iniciar_pesaje(int $id): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $recepcion->estado_pesaje = 'En Proceso';
        $recepcion->fecha_hora_inicio_pesaje = now()->toDateTimeString();
        $recepcion->save();

        $updated = RecepcionMineralData::get_recepcion_by_id_with_lotes($id);

        return ApiResponse::success($updated, 'Proceso de pesaje iniciado correctamente.');
    }

    /**
     * Validar y actualizar un campo del registro de recepción o vehículo de forma dinámica
     */
    public static function validar_campo(int $id, string $field, $value): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $validacionDatos = $recepcion->validacion_datos ?? [
            'condicion_ingreso' => false,
            'tipo_carga' => false,
            'placa' => false,
            'empresa_transporte' => false,
            'tipo_vehiculo' => false,
            'segunda_placa' => false,
            'conductor' => false,
        ];

        // 1. Modificar la tabla correspondiente según el campo
        switch ($field) {
            case 'condicion_ingreso':
                $recepcion->tipo_ingreso = $value;
                break;

            case 'tipo_carga':
                $recepcion->tipo_carga = $value;
                break;

            case 'placa':
                $vehiculo = null;
                if ($recepcion->id_vehiculo) {
                    $vehiculo = Vehiculo::find($recepcion->id_vehiculo);
                }

                // Separar serie y número si viene en formato XXX-YYY
                $serie = null;
                $numero = $value ? strtoupper(trim($value)) : '';
                if ($value && str_contains($value, '-')) {
                    $parts = explode('-', $value, 2);
                    $serie = strtoupper(trim($parts[0]));
                    $numero = strtoupper(trim($parts[1]));
                }

                // Buscar si existe un vehículo con esa placa en la BD
                $vehiculoExistente = Vehiculo::where('placa', $value)->first();

                if ($vehiculoExistente) {
                    // Si ya existe el vehículo, asociamos su ID
                    $recepcion->id_vehiculo = $vehiculoExistente->id;
                    $recepcion->save();

                    // Si el vehículo que tenía asignado anteriormente era ficticio o temporal, lo borramos para no dejar basura
                    if ($vehiculo && $vehiculo->id !== $vehiculoExistente->id) {
                        if ($vehiculo->placa && str_contains($vehiculo->placa, 'FICT')) {
                            $vehiculo->delete();
                        }
                    }
                } else {
                    // Si no existe, creamos o modificamos el vehículo actual
                    if (! $vehiculo) {
                        $vehiculo = Vehiculo::create([
                            'estado' => 'Activo',
                        ]);
                        $recepcion->id_vehiculo = $vehiculo->id;
                    }
                    $vehiculo->placa = $value;
                    $vehiculo->save();

                    $recepcion->save();
                }
                break;

            case 'empresa_transporte':
                $recepcion->id_empresa_transporte = $value ? (int) $value : null;
                break;

            case 'tipo_vehiculo':
                $recepcion->id_tipo_vehiculo = $value ? (int) $value : null;
                if ($recepcion->id_vehiculo) {
                    $vehiculo = Vehiculo::find($recepcion->id_vehiculo);
                    if ($vehiculo) {
                        $vehiculo->id_tipo_vehiculo = $value ? (int) $value : null;
                        $vehiculo->save();
                    }
                }
                break;

            case 'segunda_placa':
                $recepcion->segunda_placa = $value;
                break;

            case 'conductor':
                $recepcion->id_conductor = $value ? (int) $value : null;
                break;

            case 'fecha_hora_ingreso':
                if ($value) {
                    $parsed = date('Y-m-d H:i:s', strtotime($value));
                    if ($parsed) {
                        $recepcion->fecha_hora_ingreso = $parsed;
                    }
                }
                break;
        }

        // 2. Marcar la validación de este campo como completada (true) y propagar a los lotes
        $validacionDatos[$field] = true;
        $recepcion->validacion_datos = $validacionDatos;
        $recepcion->save();

        LoteMineral::where('id_recepcion_unidad', $id)->update([
            'id_vehiculo' => $recepcion->id_vehiculo,
            'id_empresa_transporte' => $recepcion->id_empresa_transporte,
            'id_tipo_vehiculo' => $recepcion->id_tipo_vehiculo,
            'id_conductor' => $recepcion->id_conductor,
        ]);

        $updated = RecepcionMineralData::get_recepcion_by_id_with_lotes($id);

        return ApiResponse::success($updated, 'Campo validado y actualizado correctamente.');
    }

    /**
     * Crear un lote vacío para una recepción de unidad
     */
    public static function crear_lote(int $id, int $idEmpleadoRegistro, string $condicionIngreso, int $idEmpresa): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        $empresa = Empresa::find($idEmpresa);
        if (! $empresa) {
            return ApiResponse::error('No se encontró la empresa seleccionada.');
        }

        $isComercial = $condicionIngreso === CondicionIngreso::Comercializacion->value;
        $prefijo = $isComercial ? 'FB' : 'LOT';
        $filtros = $isComercial
            ? ['id_empresa' => $idEmpresa, 'condicion_ingreso' => CondicionIngreso::Comercializacion->value]
            : ['condicion_ingreso' => ['!=', CondicionIngreso::Comercializacion->value]];

        // Generar correlativo usando CorrelativoHelper
        $correlativoData = CorrelativoHelper::generar(
            tabla: 'lote_mineral',
            prefijo: $prefijo,
            filtros: $filtros,
            longitudCeros: 5,
            reseteo: Periodo::Anual
        );

        // Crear automáticamente el registro en ticket_balanza al generar el lote
        $ticketId = DB::table('ticket_balanza')->insertGetId([
            'numero' => null,
            'created_at' => now(),
        ]);
        DB::table('ticket_balanza')->where('id', $ticketId)->update(['numero' => $ticketId]);

        $lote = LoteMineral::create([
            'id_recepcion_unidad' => $id,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'id_empresa' => $idEmpresa,
            'condicion_ingreso' => $condicionIngreso,
            'correlativo' => $correlativoData['correlativo'],
            'numero_correlativo' => $correlativoData['numero_correlativo'],
            'id_ticket_balanza' => $ticketId,
            'estado_leyes' => EstadoLeyes::Pendiente->value,
            'created_at' => now()->toDateTimeString(),
            'id_vehiculo' => $recepcion->id_vehiculo,
            'id_empresa_transporte' => $recepcion->id_empresa_transporte,
            'id_tipo_vehiculo' => $recepcion->id_tipo_vehiculo,
            'id_conductor' => $recepcion->id_conductor,
        ]);

        $loteDetalle = RecepcionMineralData::get_lote_by_id($lote->id);

        return ApiResponse::success($loteDetalle, 'Lote generado correctamente.');
    }

    /**
     * Eliminar un lote vacío o incompleto
     */
    public static function eliminar_lote(int $loteId): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        if ($lote->id_ticket_balanza) {
            DB::table('ticket_balanza')->where('id', $lote->id_ticket_balanza)->delete();
        }

        $lote->delete();

        return ApiResponse::success(null, 'Lote eliminado correctamente.');
    }

    /**
     * Registrar la información del peso inicial de un lote
     */
    public static function registrar_peso_inicial(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        // Guardar los archivos de evidencias físicas en storage/app/public/lotes
        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('lotes', $archivos);
        }

        $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int) $data['id_proveedor_minero'] : null;
        $lote->id_zona_origen = $data['id_zona_origen'] ? (int) $data['id_zona_origen'] : null;
        $lote->numero_contacto = $data['numero_contacto'];
        $lote->tipo_carga = $data['tipo_carga'];
        $lote->tipo_producto = $data['tipo_producto'];
        $lote->tipo_mineral = $data['tipo_mineral'];
        $lote->peso_inicial = (float) $data['peso_inicial'];
        $lote->observacion_peso_inicial = $data['observacion_peso_inicial'] ?? null;
        $lote->fecha_hora_peso_inicial = now()->toDateTimeString();
        $lote->evidencias = $evidenciasGuardadas;

        // Heredar campos de la recepción de unidad asociada
        $recepcion = DB::table('recepcion_unidad')->where('id', $lote->id_recepcion_unidad)->first();
        if ($recepcion) {
            $lote->id_vehiculo = $recepcion->id_vehiculo;
            $lote->id_empresa_transporte = $recepcion->id_empresa_transporte;
            $lote->id_tipo_vehiculo = $recepcion->id_tipo_vehiculo;
            $lote->id_conductor = $recepcion->id_conductor;
        }

        // Al registrar el peso inicial desde balanza, crear registro en ticket_balanza si no tiene uno
        if (! $lote->id_ticket_balanza) {
            $ticketId = DB::table('ticket_balanza')->insertGetId([
                'numero' => null,
                'created_at' => now(),
            ]);
            DB::table('ticket_balanza')->where('id', $ticketId)->update(['numero' => $ticketId]);
            $lote->id_ticket_balanza = $ticketId;
        }

        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);

        return ApiResponse::success($updatedLote, 'Peso inicial registrado correctamente.');
    }

    /**
     * Obtener los datos completos del ticket de balanza
     */
    public static function get_ticket_balanza(int $loteId): array
    {
        $data = RecepcionMineralData::get_ticket_balanza_info($loteId);
        if (! $data) {
            return ApiResponse::error('No se encontró la información del ticket para el lote especificado.');
        }

        return ApiResponse::success($data, 'Ticket de balanza obtenido correctamente.');
    }

    /**
     * Registrar la información del peso final de un lote
     */
    public static function registrar_peso_final(int $loteId, array $data, array $archivos): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        if ($lote->peso_inicial === null && ! isset($data['peso_inicial'])) {
            return ApiResponse::error('Debe registrar primero el peso inicial del lote.');
        }

        // Guardar los archivos de evidencias físicas y anexarlos a los existentes
        $evidenciasGuardadas = [];
        if (array_key_exists('evidencias_existentes', $data)) {
            $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                ? $data['evidencias_existentes']
                : (json_decode($data['evidencias_existentes'], true) ?? []);
        } else {
            $evidenciasGuardadas = $lote->evidencias ?? [];
        }

        if (! empty($archivos)) {
            $nuevosArchivos = ArchivoHelper::guardarArchivos('lotes', $archivos);
            $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevosArchivos);
        }

        // Actualizar datos del peso inicial si fueron provistos
        if (array_key_exists('id_proveedor_minero', $data)) {
            $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int) $data['id_proveedor_minero'] : null;
        }
        if (array_key_exists('id_zona_origen', $data)) {
            $lote->id_zona_origen = $data['id_zona_origen'] ? (int) $data['id_zona_origen'] : null;
        }
        if (array_key_exists('numero_contacto', $data)) {
            $lote->numero_contacto = $data['numero_contacto'];
        }
        if (array_key_exists('tipo_carga', $data)) {
            $lote->tipo_carga = $data['tipo_carga'];
        }
        if (array_key_exists('tipo_producto', $data)) {
            $lote->tipo_producto = $data['tipo_producto'];
        }
        if (array_key_exists('tipo_mineral', $data)) {
            $lote->tipo_mineral = $data['tipo_mineral'];
        }
        if (array_key_exists('peso_inicial', $data) && $data['peso_inicial'] !== null) {
            $lote->peso_inicial = (float) $data['peso_inicial'];
        }
        if (array_key_exists('observacion_peso_inicial', $data)) {
            $lote->observacion_peso_inicial = $data['observacion_peso_inicial'];
        }
        if (array_key_exists('id_vehiculo', $data) && ! empty($data['id_vehiculo'])) {
            $lote->id_vehiculo = (int) $data['id_vehiculo'];
        }
        if (array_key_exists('id_empresa_transporte', $data) && ! empty($data['id_empresa_transporte'])) {
            $lote->id_empresa_transporte = (int) $data['id_empresa_transporte'];
        }
        if (array_key_exists('id_tipo_vehiculo', $data) && ! empty($data['id_tipo_vehiculo'])) {
            $lote->id_tipo_vehiculo = (int) $data['id_tipo_vehiculo'];
        }
        if (array_key_exists('id_conductor', $data) && ! empty($data['id_conductor'])) {
            $lote->id_conductor = (int) $data['id_conductor'];
        }

        $pesoFinal = (float) $data['peso_final'];
        $pesoInicial = (float) $lote->peso_inicial;

        $lote->peso_final = $pesoFinal;
        $lote->observacion_peso_final = $data['observacion_peso_final'] ?? null;
        $lote->fecha_hora_peso_final = now()->toDateTimeString();
        $lote->peso_neto = $pesoInicial - $pesoFinal; // Peso Inicial - Peso Final
        $lote->evidencias = $evidenciasGuardadas;
        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);

        return ApiResponse::success($updatedLote, 'Peso final registrado correctamente.');
    }

    /**
     * Cerrar el proceso de balanza de una recepción
     */
    public static function cerrar_proceso(int $id): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        // Validaciones:
        // 1. Que todos los checks de validacion_datos sean true
        $validacionDatos = $recepcion->validacion_datos;
        if (empty($validacionDatos)) {
            return ApiResponse::error('No se han validado los datos de vigilancia.');
        }
        foreach ($validacionDatos as $key => $val) {
            if (! $val) {
                return ApiResponse::error("Falta validar el campo: {$key}.");
            }
        }

        // 2. Que tenga al menos un lote y que todos los lotes tengan peso_final registrado
        $lotes = LoteMineral::where('id_recepcion_unidad', $id)->get();
        if ($lotes->isEmpty()) {
            return ApiResponse::error('Debe registrar al menos un lote de mineral para esta unidad.');
        }
        foreach ($lotes as $lote) {
            if ($lote->peso_final === null) {
                return ApiResponse::error("El lote {$lote->correlativo} no tiene registrado su peso final.");
            }
        }

        $recepcion->estado_pesaje = 'Pesado';
        $recepcion->fecha_hora_final_pesaje = now()->toDateTimeString();
        $recepcion->save();

        return ApiResponse::success(null, 'Proceso de balanza cerrado correctamente.');
    }

    /**
     * Registrar una unidad ficticia
     */
    public static function crear_unidad_ficticia(array $data): array
    {
        // 1. Crear un vehículo ficticio en la BD
        $uniqueId = rand(1000, 9999);
        $plateNum = date('ymd').$uniqueId;
        $vehiculo = Vehiculo::create([
            'placa' => 'FICT-'.$plateNum,
            'estado' => 'Activo',
        ]);

        // 2. Resolver la fecha/hora de ingreso (provista o ahora)
        $fechaHoraIngreso = ! empty($data['fecha_hora_ingreso'])
            ? date('Y-m-d H:i:s', strtotime($data['fecha_hora_ingreso']))
            : now()->toDateTimeString();

        // 3. Crear el registro de recepcion_unidad vacío
        $recepcion = RecepcionUnidad::create([
            'id_empleado_recepcion' => $data['id_empleado_registro'],
            'id_vehiculo' => $vehiculo->id,
            'id_empresa_transporte' => null,
            'id_tipo_vehiculo' => null,
            'id_conductor' => null,
            'tipo_ingreso' => 'Ficticio',
            'tipo_carga' => 'Mixto',
            'segunda_placa' => null,
            'fecha_hora_ingreso' => $fechaHoraIngreso,
            'estado' => 'En Planta',
            'estado_pesaje' => 'Sin Pesar',
            'id_sucursal' => $data['id_sucursal'],
            'validacion_datos' => [
                'condicion_ingreso' => false,
                'tipo_carga' => false,
                'placa' => false,
                'empresa_transporte' => false,
                'tipo_vehiculo' => false,
                'segunda_placa' => false,
                'conductor' => false,
            ],
        ]);

        $nuevaRecepcion = RecepcionMineralData::get_recepcion_by_id_with_lotes($recepcion->id);

        return ApiResponse::success($nuevaRecepcion, 'Unidad ficticia creada correctamente.');
    }

    /**
     * Obtener el resumen de balanza filtrado
     */
    public static function get_resumen_balanza(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = RecepcionMineralData::get_resumen_balanza($filters);

        return ApiResponse::success($data, 'Resumen de balanza obtenido correctamente.');
    }

    /**
     * Actualizar toda la información de un lote de mineral (para Resumen de Balanza)
     */
    public static function actualizar_lote(int $loteId, array $data, array $archivos, ?int $idEmpleado = null): array
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return ApiResponse::error('No se encontró el registro de lote.');
        }

        $cambios = [];
        $motivo = $data['motivo'] ?? null;

        // 1. Validar cambio de condición de ingreso y generar nuevo correlativo si aplica
        $oldCondicion = $lote->condicion_ingreso;
        $newCondicion = $data['condicion_ingreso'];

        if ($oldCondicion !== $newCondicion) {
            $isComercial = $newCondicion === CondicionIngreso::Comercializacion->value;
            $prefijo = $isComercial ? 'FB' : 'LOT';
            $filtros = $isComercial
                ? ['id_empresa' => $lote->id_empresa, 'condicion_ingreso' => CondicionIngreso::Comercializacion->value]
                : ['condicion_ingreso' => ['!=', CondicionIngreso::Comercializacion->value]];

            // Generar correlativo usando CorrelativoHelper
            $correlativoData = CorrelativoHelper::generar(
                tabla: 'lote_mineral',
                prefijo: $prefijo,
                filtros: $filtros,
                longitudCeros: 5,
                reseteo: Periodo::Anual
            );

            $cambios[] = [
                'campo_bd' => 'condicion_ingreso',
                'campo' => 'Condición de ingreso',
                'valor_anterior' => $oldCondicion,
                'valor_nuevo' => $newCondicion,
            ];

            $cambios[] = [
                'campo_bd' => 'correlativo',
                'campo' => 'Correlativo del lote',
                'valor_anterior' => $lote->correlativo,
                'valor_nuevo' => $correlativoData['correlativo'],
            ];

            $lote->condicion_ingreso = $newCondicion;
            $lote->correlativo = $correlativoData['correlativo'];
            $lote->numero_correlativo = $correlativoData['numero_correlativo'];
        }

        $ru = \DB::table('recepcion_unidad')->where('id', $lote->id_recepcion_unidad)->first();

        // 2. Comparar el resto de los campos editables
        $camposAuditar = [
            'id_proveedor_minero' => [
                'nombre' => 'Proveedor minero',
                'tipo' => 'int',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $p = \DB::table('proveedor')->where('id', $id)->first();

                    return $p ? $p->razon_social : "ID #$id";
                },
            ],
            'id_zona_origen' => [
                'nombre' => 'Zona de origen',
                'tipo' => 'int',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $z = \DB::table('zona_origen')->where('id', $id)->first();

                    return $z ? $z->nombre : "ID #$id";
                },
            ],
            'numero_contacto' => ['nombre' => 'Número de contacto', 'tipo' => 'string'],
            'tipo_carga' => ['nombre' => 'Tipo de carga', 'tipo' => 'string'],
            'tipo_producto' => ['nombre' => 'Tipo de producto', 'tipo' => 'string'],
            'tipo_mineral' => ['nombre' => 'Tipo de mineral', 'tipo' => 'string'],
            'peso_inicial' => ['nombre' => 'Peso inicial', 'tipo' => 'float'],
            'observacion_peso_inicial' => ['nombre' => 'Observación de peso inicial', 'tipo' => 'string'],
            'peso_final' => ['nombre' => 'Peso final (tara)', 'tipo' => 'float'],
            'observacion_peso_final' => ['nombre' => 'Observación de peso final', 'tipo' => 'string'],
            'id_vehiculo' => [
                'nombre' => 'Vehículo',
                'tipo' => 'int',
                'coalesce_key' => 'id_vehiculo',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $v = \DB::table('vehiculo')->where('id', $id)->first();
                    if ($v) {
                        return $v->placa;
                    }

                    return "ID #$id";
                },
            ],
            'id_empresa_transporte' => [
                'nombre' => 'Empresa de transporte',
                'tipo' => 'int',
                'coalesce_key' => 'id_empresa_transporte',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $et = \DB::table('empresa_transporte')->where('id', $id)->first();

                    return $et ? $et->razon_social : "ID #$id";
                },
            ],
            'id_conductor' => [
                'nombre' => 'Conductor',
                'tipo' => 'int',
                'coalesce_key' => 'id_conductor',
                'resolver' => function ($id) {
                    if (! $id) {
                        return null;
                    }
                    $c = \DB::table('conductor')->where('id', $id)->first();

                    return $c ? trim($c->nombre.' '.$c->apellido) : "ID #$id";
                },
            ],
        ];

        foreach ($camposAuditar as $campoBd => $meta) {
            $valAnt = $lote->$campoBd;
            $valNue = $data[$campoBd];

            if ($valAnt === null && isset($meta['coalesce_key']) && $ru) {
                $coalesceCol = $meta['coalesce_key'];
                $valAnt = $ru->$coalesceCol;
            }

            // Normalizar tipos para la comparación
            if ($meta['tipo'] === 'int') {
                $valAnt = $valAnt !== null ? (int) $valAnt : null;
                $valNue = ($valNue !== null && $valNue !== '') ? (int) $valNue : null;
            } elseif ($meta['tipo'] === 'float') {
                $valAnt = $valAnt !== null ? (float) $valAnt : null;
                $valNue = ($valNue !== null && $valNue !== '') ? (float) $valNue : null;
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

        // Evidencias: manejar existentes y agregar nuevas
        $evidenciasGuardadas = [];
        if (array_key_exists('evidencias_existentes', $data)) {
            $evidenciasGuardadas = is_array($data['evidencias_existentes'])
                ? $data['evidencias_existentes']
                : (json_decode($data['evidencias_existentes'], true) ?? []);
        } else {
            $evidenciasGuardadas = $lote->evidencias ?? [];
        }

        if (! empty($archivos)) {
            $nuevosArchivos = ArchivoHelper::guardarArchivos('lotes', $archivos);
            $evidenciasGuardadas = array_merge($evidenciasGuardadas, $nuevosArchivos);
        }

        // Comparar evidencias
        $vAntEvidencias = $lote->evidencias ?? [];
        if (! is_array($vAntEvidencias)) {
            $vAntEvidencias = json_decode($vAntEvidencias, true) ?? [];
        }
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

        // 3. Registrar auditoría si hubo algún cambio
        if (! empty($cambios)) {
            $logActual = $lote->log_cambios ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            $nuevoLog = [
                'id_empleado' => $idEmpleado,
                'motivo' => $motivo,
                'update_at' => now()->toDateTimeString(),
                'cambios' => $cambios,
            ];
            array_unshift($logActual, $nuevoLog);
            $lote->log_cambios = $logActual;
        }

        // Actualizar datos del lote
        $lote->id_proveedor_minero = $data['id_proveedor_minero'] ? (int) $data['id_proveedor_minero'] : null;
        $lote->id_zona_origen = $data['id_zona_origen'] ? (int) $data['id_zona_origen'] : null;
        $lote->numero_contacto = $data['numero_contacto'];
        $lote->tipo_carga = $data['tipo_carga'];
        $lote->tipo_producto = $data['tipo_producto'];
        $lote->tipo_mineral = $data['tipo_mineral'];
        $lote->peso_inicial = $data['peso_inicial'] !== null ? (float) $data['peso_inicial'] : null;
        $lote->observacion_peso_inicial = $data['observacion_peso_inicial'] ?? null;

        $lote->peso_final = $data['peso_final'] !== null ? (float) $data['peso_final'] : null;
        $lote->observacion_peso_final = $data['observacion_peso_final'] ?? null;

        // Calcular peso neto si ambos pesos existen
        if ($lote->peso_inicial !== null && $lote->peso_final !== null) {
            $lote->peso_neto = $lote->peso_inicial - $lote->peso_final;
        } else {
            $lote->peso_neto = null;
        }

        // Vehículo, conductor y transporte
        $lote->id_vehiculo = $data['id_vehiculo'] ? (int) $data['id_vehiculo'] : null;
        $lote->id_empresa_transporte = $data['id_empresa_transporte'] ? (int) $data['id_empresa_transporte'] : null;
        $lote->id_conductor = $data['id_conductor'] ? (int) $data['id_conductor'] : null;

        // Heredar el id_tipo_vehiculo del vehículo seleccionado
        if ($lote->id_vehiculo) {
            $vehiculo = Vehiculo::find($lote->id_vehiculo);
            if ($vehiculo) {
                $lote->id_tipo_vehiculo = $vehiculo->id_tipo_vehiculo;
            } else {
                $lote->id_tipo_vehiculo = null;
            }
        } else {
            $lote->id_tipo_vehiculo = null;
        }

        $lote->evidencias = $evidenciasGuardadas;
        $lote->save();

        $updatedLote = RecepcionMineralData::get_lote_by_id($loteId);

        return ApiResponse::success($updatedLote, 'Lote actualizado correctamente.');
    }

    /**
     * Obtener los metadatos para los filtros de resumen de balanza
     */
    public static function get_resumen_filtros(int $idSucursal): array
    {
        $data = RecepcionMineralData::get_resumen_filtros($idSucursal);

        return ApiResponse::success($data, 'Metadatos de filtros obtenidos correctamente.');
    }
}
