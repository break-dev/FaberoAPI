<?php

namespace App\Modules\RecepcionVisitas\Services;

use App\Models\RecepcionVisita;
use App\Models\Visitante;
use App\Modules\RecepcionVisitas\Data\RecepcionVisitasData;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class RecepcionVisitasService
{
    /**
     * Obtener listado de recepciones de visitas.
     */
    public static function get_recepciones(array $filters): array
    {
        $data = RecepcionVisitasData::get_recepciones($filters);

        return ApiResponse::success($data, 'Recepciones de visitas obtenidas correctamente');
    }

    /**
     * Registrar una recepción de visita y sus detalles de visitantes.
     * Crea cabeceras independientes en `recepcion_visita` para visitantes peatonales
     * y para cada vehículo acompañante registrado.
     */
    public static function crear_recepcion(array $data, array $visitantes, array $archivos, array $vehiculos = [], array $archivosVehiculos = [], array $evidencias = []): array
    {
        try {
            DB::beginTransaction();

            $evidenciasJson = null;
            if (! empty($evidencias)) {
                $uploaded = ArchivoHelper::guardarArchivos('visitas/evidencias', $evidencias);
                if (! empty($uploaded)) {
                    $urls = array_map(fn ($file) => $file['url'], $uploaded);
                    $evidenciasJson = json_encode($urls);
                }
            }

            // 1. Crear UN SOLO registro de cabecera en recepcion_visita
            $dataCabecera = $data;
            if ($evidenciasJson) {
                $dataCabecera['evidencias_ingreso'] = $evidenciasJson;
            }

            $idVisita = RecepcionVisitasData::crear_recepcion($dataCabecera);

            // 2. Registrar vehículos acompañantes en visita_vehiculo vinculados a esta visita única
            $tempIdToRealIdMap = [];
            if (! empty($vehiculos)) {
                foreach ($vehiculos as $vehIdx => $veh) {
                    $placa = $veh['placa'] ?? '';
                    $cant = (int) ($veh['cantidad_personas'] ?? 1);
                    $tempId = (string) ($veh['id'] ?? $veh['temp_id'] ?? '');

                    $urlFotoVeh = null;
                    if (isset($archivosVehiculos[$vehIdx]) && ! empty($archivosVehiculos[$vehIdx])) {
                        $guardadosVeh = ArchivoHelper::guardarArchivos('visitas', $archivosVehiculos[$vehIdx]);
                        if (! empty($guardadosVeh)) {
                            $urls = array_map(fn ($f) => $f['url'], $guardadosVeh);
                            $urlFotoVeh = json_encode($urls);
                        }
                    }

                    $realVehId = DB::table('visita_vehiculo')->insertGetId([
                        'id_recepcion_visita' => $idVisita,
                        'placa' => $placa,
                        'cantidad_personas' => $cant,
                        'url_foto' => $urlFotoVeh,
                        'created_at' => now()->toDateTimeString(),
                    ]);

                    if ($tempId !== '') {
                        $tempIdToRealIdMap[$tempId] = $realVehId;
                    }
                }
            }

            // 3. Registrar todos los visitantes en recepcion_visita_detalle asociados a la misma visita única
            foreach ($visitantes as $origIndex => $v) {
                $nombreVal = trim($v['nombre'] ?? '');
                $dniVal = trim($v['dni'] ?? '');

                if ($nombreVal === '' && $dniVal === '' && empty($v['id_visitante'])) {
                    continue;
                }
                if ($nombreVal === '') {
                    $nombreVal = 'VISITANTE';
                }

                $idVisitante = null;
                if (! empty($v['id_visitante'])) {
                    $idVisitante = (int) $v['id_visitante'];
                    Visitante::whereKey($idVisitante)->update([
                        'nombre' => $nombreVal,
                        'apellido' => $v['apellido'] ?? '',
                        'telefono' => $v['telefono'] ?? null,
                    ]);
                } else {
                    $idVisitante = self::obtenerOCrearVisitante(
                        $nombreVal,
                        $v['apellido'] ?? '',
                        $dniVal ?: null,
                        $v['telefono'] ?? null
                    );
                }

                $urlFoto = null;
                if (isset($archivos[$origIndex])) {
                    $uploaded = ArchivoHelper::guardarArchivos('visitas', $archivos[$origIndex]);
                    if (! empty($uploaded)) {
                        $urls = array_map(fn ($file) => $file['url'], $uploaded);
                        $urlFoto = json_encode($urls);
                    }
                }

                $vehTempId = (string) ($v['id_visita_vehiculo'] ?? '');
                $realVehId = $tempIdToRealIdMap[$vehTempId] ?? null;

                $rawEsConductor = $v['es_conductor'] ?? null;
                $esConductor = ($rawEsConductor === true || $rawEsConductor === 1 || $rawEsConductor === '1' || $rawEsConductor === 'true') ? 1 : 0;

                \App\Models\RecepcionVisitaDetalle::create([
                    'id_recepcion_visita' => $idVisita,
                    'id_visitante' => $idVisitante,
                    'id_visita_vehiculo' => $realVehId,
                    'es_conductor' => $esConductor,
                    'url_foto_documento' => $urlFoto,
                    'estado' => EstadoVisita::EnPlanta->value,
                ]);
            }

            DB::commit();

            $nuevaRecepcion = RecepcionVisitasData::get_recepcion_by_id($idVisita);

            return ApiResponse::success($nuevaRecepcion, 'Recepción de visita registrada correctamente');

        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la recepción de visita: '.$e->getMessage());
        }
    }

    /**
     * Registrar la salida de una visita.
     */
    public static function registrar_salida(int $idDetalle, ?string $observacionSalida, array $evidencias = []): array
    {
        $detalle = \App\Models\RecepcionVisitaDetalle::find($idDetalle);
        if (! $detalle) {
            return ApiResponse::error('No se encontró el detalle de la recepción de visita.');
        }

        $nowStr = now()->toDateTimeString();

        $urlEvidencias = null;
        if (! empty($evidencias)) {
            $uploaded = ArchivoHelper::guardarArchivos('visitas/evidencias_salida', $evidencias);
            if (! empty($uploaded)) {
                $urls = array_map(fn ($f) => $f['url'], $uploaded);
                $urlEvidencias = json_encode($urls);
            }
        }

        $detalle->observacion_salida = $observacionSalida;
        $detalle->fecha_hora_salida = $nowStr;
        if ($urlEvidencias) {
            $detalle->evidencias_salida = $urlEvidencias;
        }
        $detalle->estado = EstadoVisita::FueraDePlanta->value;
        $detalle->save();

        if ($detalle->id_recepcion_visita) {
            $visitaHeader = RecepcionVisita::find($detalle->id_recepcion_visita);
            if ($visitaHeader) {
                $pendientes = \App\Models\RecepcionVisitaDetalle::where('id_recepcion_visita', $detalle->id_recepcion_visita)
                    ->where('estado', '!=', EstadoVisita::FueraDePlanta->value)
                    ->count();

                if ($pendientes === 0) {
                    $visitaHeader->fecha_hora_salida = $nowStr;
                    $visitaHeader->observacion_salida = $observacionSalida;
                    if ($urlEvidencias) {
                        $visitaHeader->evidencias_salida = $urlEvidencias;
                    }
                    $visitaHeader->estado = EstadoVisita::FueraDePlanta->value;
                    $visitaHeader->save();
                }
            }
        }

        $updated = RecepcionVisitasData::get_recepcion_by_id($detalle->id_recepcion_visita);

        return ApiResponse::success($updated, 'Salida de visita registrada correctamente');
    }

    /**
     * Registrar la salida general de toda la recepción de visita (header + todos sus detalles pendientes).
     * Las evidencias de salida se guardan únicamente en la tabla `recepcion_visita.evidencias_salida`.
     */
    public static function registrar_salida_general(int $idRecepcionVisita, ?string $observacionSalida, array $evidencias = []): array
    {
        $visitaHeader = RecepcionVisita::find($idRecepcionVisita);
        if (! $visitaHeader) {
            return ApiResponse::error('No se encontró la recepción de visita.');
        }

        $nowStr = now()->toDateTimeString();

        $urlEvidencias = null;
        if (! empty($evidencias)) {
            $uploaded = ArchivoHelper::guardarArchivos('visitas/evidencias_salida', $evidencias);
            if (! empty($uploaded)) {
                $urls = array_map(fn ($f) => $f['url'], $uploaded);
                $urlEvidencias = json_encode($urls);
            }
        }

        // 1. Actualizar la cabecera recepcion_visita
        $visitaHeader->observacion_salida = $observacionSalida;
        $visitaHeader->fecha_hora_salida = $nowStr;
        if ($urlEvidencias) {
            $visitaHeader->evidencias_salida = $urlEvidencias;
        }
        $visitaHeader->estado = EstadoVisita::FueraDePlanta->value;
        $visitaHeader->save();

        // 2. Marcar salida a todos los detalles de visitantes de esta recepción que estén pendientes
        $detalles = \App\Models\RecepcionVisitaDetalle::where('id_recepcion_visita', $idRecepcionVisita)
            ->where('estado', '!=', EstadoVisita::FueraDePlanta->value)
            ->get();

        foreach ($detalles as $det) {
            $det->estado = EstadoVisita::FueraDePlanta->value;
            $det->fecha_hora_salida = $nowStr;
            if (empty($det->observacion_salida) && ! empty($observacionSalida)) {
                $det->observacion_salida = $observacionSalida;
            }
            $det->save();
        }

        $updated = RecepcionVisitasData::get_recepcion_by_id($idRecepcionVisita);

        return ApiResponse::success($updated, 'Salida general de recepción registrada correctamente');
    }

    /**
     * Crear la visita (cabecera + detalle) asociada a una programación de unidad.
     * Separa las cabeceras en `recepcion_visita` para los visitantes de la unidad principal
     * y para cada vehículo acompañante externo.
     */
    public static function crear_recepcion_para_programacion(
        int $idEmpleadoRegistro,
        int $idRecepcionUnidad,
        int $idMotivoIngreso,
        ?string $observacion,
        array $visitantes,
        array $archivosPorIndice = [],
        array $vehiculos = [],
        array $archivosVehiculos = [],
        array $evidencias = []
    ): array {
        try {
            return DB::transaction(function () use ($idEmpleadoRegistro, $idRecepcionUnidad, $idMotivoIngreso, $observacion, $visitantes, $archivosPorIndice, $vehiculos, $archivosVehiculos, $evidencias) {
                $recepcionUnidad = DB::table('recepcion_unidad')->where('id', $idRecepcionUnidad)->first();
                $idEmpleadoAutoriza = $recepcionUnidad->id_empleado_autoriza ?? null;

                if (! empty($evidencias)) {
                    $uploadedEvidencias = ArchivoHelper::guardarArchivos('evidencias', $evidencias);
                    if (! empty($uploadedEvidencias)) {
                        $urlsEvidencias = array_map(fn ($f) => $f['url'], $uploadedEvidencias);
                        DB::table('recepcion_unidad')->where('id', $idRecepcionUnidad)->update([
                            'evidencias' => json_encode($urlsEvidencias),
                        ]);
                    }
                }

                $visitantesValidos = array_filter($visitantes, function ($v) {
                    $nombre = trim($v['nombre'] ?? '');
                    $dni = trim($v['dni'] ?? '');
                    return $nombre !== '' || $dni !== '' || ! empty($v['id_visitante']);
                });

                if (empty($visitantesValidos) && empty($vehiculos)) {
                    return [
                        'id' => null,
                        'mensaje' => 'No se creó recepción de visita por no haber visitantes ni vehículos registrados.',
                    ];
                }

                $motivoTarget = $idMotivoIngreso;
                if (empty($motivoTarget)) {
                    $motivoObj = DB::table('motivo_ingreso')->where('es_recepcion_unidad', 1)->orWhere('es_recepcion_unidad', true)->first();
                    $motivoTarget = $motivoObj ? (int) $motivoObj->id : 1;
                }

                $placaUnidad = DB::table('recepcion_unidad')
                    ->leftJoin('vehiculo', 'vehiculo.id', '=', 'recepcion_unidad.id_vehiculo')
                    ->where('recepcion_unidad.id', $idRecepcionUnidad)
                    ->value('vehiculo.placa');

                $hasPlaca = ! empty($placaUnidad);
                $idRecepcionVisita = RecepcionVisitasData::crear_recepcion([
                    'id_empleado_registro' => $idEmpleadoRegistro,
                    'id_empleado_autoriza' => $idEmpleadoAutoriza,
                    'id_motivo_ingreso' => $motivoTarget,
                    'id_recepcion_unidad' => $idRecepcionUnidad,
                    'observacion' => $observacion,
                    'con_vehiculo' => $hasPlaca || ! empty($vehiculos),
                    'placa' => $hasPlaca ? $placaUnidad : null,
                    'estado' => EstadoVisita::EnPlanta->value,
                ]);

                $realVehIdUnidad = null;
                if ($hasPlaca) {
                    $realVehIdUnidad = DB::table('visita_vehiculo')->insertGetId([
                        'id_recepcion_visita' => $idRecepcionVisita,
                        'placa' => $placaUnidad,
                        'cantidad_personas' => 1,
                        'created_at' => now()->toDateTimeString(),
                    ]);
                }

                $tempIdToRealIdMap = [];
                if (! empty($vehiculos)) {
                    foreach ($vehiculos as $vehIdx => $veh) {
                        $placa = $veh['placa'] ?? '';
                        $cant = (int) ($veh['cantidad_personas'] ?? 1);
                        $tempId = (string) ($veh['id'] ?? $veh['temp_id'] ?? '');

                        $urlFotoVeh = null;
                        if (isset($archivosVehiculos[$vehIdx]) && ! empty($archivosVehiculos[$vehIdx])) {
                            $guardadosVeh = ArchivoHelper::guardarArchivos('visitas', $archivosVehiculos[$vehIdx]);
                            if (! empty($guardadosVeh)) {
                                $urls = array_map(fn ($f) => $f['url'], $guardadosVeh);
                                $urlFotoVeh = json_encode($urls);
                            }
                        }

                        $realVehId = DB::table('visita_vehiculo')->insertGetId([
                            'id_recepcion_visita' => $idRecepcionVisita,
                            'placa' => $placa,
                            'cantidad_personas' => $cant,
                            'url_foto' => $urlFotoVeh,
                            'created_at' => now()->toDateTimeString(),
                        ]);

                        if ($tempId !== '') {
                            $tempIdToRealIdMap[$tempId] = $realVehId;
                        }
                    }
                }

                foreach ($visitantes as $index => $v) {
                    $dni = $v['dni'] ?? null;
                    $nombre = $v['nombre'] ?? 'VISITANTE';
                    $apellido = $v['apellido'] ?? '';
                    $telefono = $v['telefono'] ?? null;

                    $idVisitante = self::obtenerOCrearVisitante($nombre, $apellido, $dni, $telefono);

                    $urlFotoDoc = null;
                    if (isset($archivosPorIndice[$index]) && ! empty($archivosPorIndice[$index])) {
                        $guardados = ArchivoHelper::guardarArchivos('visitas', $archivosPorIndice[$index]);
                        if (! empty($guardados)) {
                            $urls = array_map(fn ($f) => $f['url'], $guardados);
                            $urlFotoDoc = json_encode($urls);
                        }
                    }

                    $vehTempId = (string) ($v['id_visita_vehiculo'] ?? '');
                    $realVehId = $tempIdToRealIdMap[$vehTempId] ?? $realVehIdUnidad;

                    $rawEsConductor = $v['es_conductor'] ?? null;
                    $esConductor = ($rawEsConductor === true || $rawEsConductor === 1 || $rawEsConductor === '1' || $rawEsConductor === 'true') ? 1 : 0;

                    \App\Models\RecepcionVisitaDetalle::create([
                        'id_recepcion_visita' => $idRecepcionVisita,
                        'id_visitante' => $idVisitante,
                        'id_visita_vehiculo' => $realVehId,
                        'es_conductor' => $esConductor,
                        'url_foto_documento' => $urlFotoDoc,
                        'estado' => EstadoVisita::EnPlanta->value,
                    ]);
                }

                $nueva = RecepcionVisitasData::get_recepcion_by_id($idRecepcionVisita);

                return ApiResponse::success($nueva, 'Visita de programación registrada correctamente');
            });
        } catch (\Throwable $e) {
            return ApiResponse::error('Error al registrar la visita de la programación: '.$e->getMessage());
        }
    }

    /**
     * Helper: busca visitante por DNI o crea uno nuevo.
     */
    private static function obtenerOCrearVisitante(string $nombre, string $apellido, ?string $dni, ?string $telefono): int
    {
        if (! empty($dni)) {
            $existing = \App\Data\VisitanteData::buscar_por_dni($dni);
            if ($existing) {
                $id = (int) ($existing['id_visitante'] ?? $existing['id'] ?? 0);
                if ($id > 0) {
                    Visitante::whereKey($id)->update([
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'telefono' => $telefono,
                    ]);

                    return $id;
                }
            }
        }

        return \App\Data\VisitanteData::crear_visitante([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'dni' => $dni,
            'telefono' => $telefono,
        ]);
    }
}
