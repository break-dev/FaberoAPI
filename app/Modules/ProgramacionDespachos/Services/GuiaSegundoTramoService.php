<?php

namespace App\Modules\ProgramacionDespachos\Services;

use App\Modules\ProgramacionDespachos\Data\GuiaSegundoTramoData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class GuiaSegundoTramoService
{
    /**
     * Resolver el id_empleado desde el request (inyectado por JwtAuthMiddleware).
     */
    private static function getIdEmpleadoFromRequest(?Request $request): ?int
    {
        if (! $request) {
            return null;
        }
        $authUser = $request->attributes->get('auth_user');
        if ($authUser && ! empty($authUser->id_empleado)) {
            return (int) $authUser->id_empleado;
        }

        return null;
    }

    /**
     * Construye el JSON `documentos` a partir de archivos subidos + previos.
     *
     * @param  array{guia_remitente: ?UploadedFile, guia_transportista: ?UploadedFile}  $archivos
     * @param  array{guia_remitente?: ?array, guia_transportista?: ?array}  $previos
     * @return array{guia_remitente: ?array, guia_transportista: ?array}
     */
    private static function build_documentos(array $archivos, array $previos, bool $sinGuiaTransportista): array
    {
        $doc = [
            'guia_remitente' => $previos['guia_remitente'] ?? null,
        ];

        if ($sinGuiaTransportista) {
            $doc['guia_transportista'] = null;
        } else {
            $doc['guia_transportista'] = $previos['guia_transportista'] ?? null;
        }

        if ($archivos['guia_remitente'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guia-segundo-tramo', [$archivos['guia_remitente']]);
            $doc['guia_remitente'] = $saved[0] ?? null;
        }

        if (! $sinGuiaTransportista && $archivos['guia_transportista'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guia-segundo-tramo', [$archivos['guia_transportista']]);
            $doc['guia_transportista'] = $saved[0] ?? null;
        }

        return $doc;
    }

    /**
     * Normaliza los nombres_evidencias_* enviados por el frontend.
     * El frontend puede enviarlos como JSON string o como array nativo (Laravel
     * convierte multipart JSON automáticamente cuando el form está bien armado;
     * igualmente toleramos ambos formatos).
     *
     * @return array<int, string>
     */
    private static function parseNombresEvidencias(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        return [];
    }

    /**
     * GET /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo
     *
     * @return array<string, mixed>
     */
    public static function get_guia_by_distribucion(int $idDistribucion): array
    {
        $guia = GuiaSegundoTramoData::get_by_distribucion($idDistribucion);

        return ApiResponse::success($guia, $guia === null
            ? 'La distribución aún no tiene guía de segundo tramo.'
            : 'Guía de segundo tramo obtenida correctamente.');
    }

    /**
     * POST /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo
     *
     * @param  array{
     *     motivo_traslado: string,
     *     fecha_inicio_traslado: ?string,
     *     fecha_emision: ?string,
     *     fecha_en_planta: ?string,
     *     guia_remitente: ?string,
     *     guia_transportista: ?string,
     *     sin_guia_transportista: bool
     * }  $data
     * @param  array{guia_remitente: ?UploadedFile, guia_transportista: ?UploadedFile}  $archivos
     * @return array<string, mixed>
     */
    public static function crear_guia(int $idDistribucion, array $data, array $archivos, ?Request $request = null): array
    {
        // Una distribución solo puede tener una guía de segundo tramo activa.
        $existente = GuiaSegundoTramoData::get_by_distribucion($idDistribucion);
        if ($existente !== null && ($existente['estado'] ?? null) !== EstadoBase::Eliminado->value) {
            return ApiResponse::error(
                'La distribución ya tiene una guía de segundo tramo registrada. Use la opción de editar.',
                409,
            );
        }

        try {
            DB::beginTransaction();

            $sinGuiaTransportista = (bool) ($data['sin_guia_transportista'] ?? false);

            // Si ya existía una guía eliminada, sobreescribimos su fila; si no,
            // insertamos una nueva.
            $documentos = self::build_documentos($archivos, [], $sinGuiaTransportista);

            $idEmpleadoRegistro = self::getIdEmpleadoFromRequest($request);

            $payload = [
                'id_ditribucion' => $idDistribucion,
                'id_empleado_reistro' => $idEmpleadoRegistro,
                'motivo_traslado' => $data['motivo_traslado'],
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'guia_remitente' => $data['guia_remitente'] ?? null,
                'guia_transportista' => $sinGuiaTransportista ? null : ($data['guia_transportista'] ?? null),
                'sin_guia_transportista' => $sinGuiaTransportista,
                'log_cambios' => null,
                'documentos' => json_encode($documentos),
                'created_at' => now()->toDateTimeString(),
                'estado' => EstadoBase::Activo->value,
            ];

            $idGuia = GuiaSegundoTramoData::insertar($payload);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la guía de segundo tramo: '.$e->getMessage());
        }

        $guiaCreada = GuiaSegundoTramoData::get_by_id($idGuia);

        return ApiResponse::success($guiaCreada, 'Guía de segundo tramo registrada correctamente.');
    }

    /**
     * POST /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo/{idGuia}/update
     *
     * @param  array{
     *     motivo_traslado: string,
     *     fecha_inicio_traslado: ?string,
     *     fecha_emision: ?string,
     *     fecha_en_planta: ?string,
     *     guia_remitente: ?string,
     *     guia_transportista: ?string,
     *     sin_guia_transportista: bool,
     *     motivo?: ?string,
     *     nombres_evidencias_nuevas?: mixed,
     *     nombres_evidencias_eliminadas?: mixed
     * }  $data
     * @param  array{guia_remitente: ?UploadedFile, guia_transportista: ?UploadedFile}  $archivos
     * @return array<string, mixed>
     */
    public static function actualizar_guia(
        int $idDistribucion,
        int $idGuia,
        array $data,
        array $archivos,
        ?Request $request = null,
    ): array {
        $guiaPrevio = GuiaSegundoTramoData::get_by_id($idGuia);
        if (! $guiaPrevio) {
            return ApiResponse::error('No se encontró la guía de segundo tramo.', 404);
        }
        if ((int) ($guiaPrevio['id_ditribucion'] ?? 0) !== $idDistribucion) {
            return ApiResponse::error('La guía no pertenece a la distribución indicada.', 422);
        }
        if (($guiaPrevio['estado'] ?? null) !== EstadoBase::Activo->value) {
            return ApiResponse::error('Solo se pueden editar guías activas.', 422);
        }

        try {
            DB::beginTransaction();

            $sinGuiaTransportista = (bool) ($data['sin_guia_transportista'] ?? false);
            $previosDocumentos = is_array($guiaPrevio['documentos'] ?? null) ? $guiaPrevio['documentos'] : [];
            $documentos = self::build_documentos($archivos, $previosDocumentos, $sinGuiaTransportista);

            $nuevosValores = [
                'motivo_traslado' => $data['motivo_traslado'],
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'guia_remitente' => $data['guia_remitente'] ?? null,
                'guia_transportista' => $sinGuiaTransportista ? null : ($data['guia_transportista'] ?? null),
                'sin_guia_transportista' => $sinGuiaTransportista,
                'documentos' => json_encode($documentos),
            ];

            // ---- auditoría ----
            $cambios = [];
            $camposAuditar = [
                'motivo_traslado' => ['nombre' => 'Motivo de traslado', 'tipo' => 'string'],
                'fecha_inicio_traslado' => ['nombre' => 'Fecha inicio traslado', 'tipo' => 'string'],
                'fecha_emision' => ['nombre' => 'Fecha de emisión', 'tipo' => 'string'],
                'fecha_en_planta' => ['nombre' => 'Fecha en planta', 'tipo' => 'string'],
                'guia_remitente' => ['nombre' => 'Guía remitente', 'tipo' => 'string'],
                'guia_transportista' => ['nombre' => 'Guía transportista', 'tipo' => 'string'],
                'sin_guia_transportista' => ['nombre' => 'Sin guía transportista', 'tipo' => 'bool'],
            ];

            foreach ($camposAuditar as $campoBd => $meta) {
                $valAnt = $guiaPrevio[$campoBd] ?? null;
                $valNue = $nuevosValores[$campoBd] ?? null;

                if ($meta['tipo'] === 'bool') {
                    $valAnt = ! empty($valAnt);
                    $valNue = ! empty($valNue);
                } else {
                    $valAnt = $valAnt !== null ? trim((string) $valAnt) : '';
                    $valNue = $valNue !== null ? trim((string) $valNue) : '';
                }

                if ($valAnt !== $valNue) {
                    $cambios[] = [
                        'campo_bd' => $campoBd,
                        'campo' => $meta['nombre'],
                        'valor_anterior' => $valAnt === '' ? '—' : $valAnt,
                        'valor_nuevo' => $valNue === '' ? '—' : $valNue,
                    ];
                }
            }

            // ---- auditoría de documentos ----
            $nombresNuevos = self::parseNombresEvidencias($data['nombres_evidencias_nuevas'] ?? null);
            $nombresEliminados = self::parseNombresEvidencias($data['nombres_evidencias_eliminadas'] ?? null);

            $docFields = [
                'guia_remitente' => 'Documento guía remitente',
                'guia_transportista' => 'Documento guía transportista',
            ];
            foreach ($docFields as $docKey => $docLabel) {
                $previoDoc = is_array($previosDocumentos) ? ($previosDocumentos[$docKey] ?? null) : null;
                $nuevoDoc = is_array($documentos) ? ($documentos[$docKey] ?? null) : null;

                $archivoSubido = $archivos[$docKey] ?? null;
                $previoNombre = is_array($previoDoc) ? ($previoDoc['nombre_original'] ?? null) : null;
                $nuevoNombre = is_array($nuevoDoc) ? ($nuevoDoc['nombre_original'] ?? null) : null;

                if ($archivoSubido !== null) {
                    $label = $nuevoNombre ?? 'archivo';
                    if ($previoNombre !== null && $previoNombre !== $label) {
                        $cambios[] = [
                            'campo_bd' => "documento_{$docKey}",
                            'campo' => $docLabel,
                            'valor_anterior' => $previoNombre,
                            'valor_nuevo' => $label,
                        ];
                    } elseif ($previoNombre === null) {
                        $cambios[] = [
                            'campo_bd' => "documento_{$docKey}",
                            'campo' => $docLabel,
                            'valor_anterior' => '—',
                            'valor_nuevo' => $label,
                        ];
                    }
                } elseif ($previoNombre !== null && $nuevoNombre === null) {
                    $cambios[] = [
                        'campo_bd' => "documento_{$docKey}",
                        'campo' => $docLabel,
                        'valor_anterior' => $previoNombre,
                        'valor_nuevo' => '—',
                    ];
                }
            }

            // Si el frontend reporto nombres nuevos/eliminados pero no subi archivos,
            // registrarlos para trazabilidad (cubre bajas marcadas en UI sin reupload).
            foreach ($nombresNuevos as $nombre) {
                $cambios[] = [
                    'campo_bd' => 'documento_nuevo',
                    'campo' => 'Archivo nuevo',
                    'valor_anterior' => '—',
                    'valor_nuevo' => $nombre,
                ];
            }
            foreach ($nombresEliminados as $nombre) {
                $cambios[] = [
                    'campo_bd' => 'documento_eliminado',
                    'campo' => 'Archivo eliminado',
                    'valor_anterior' => $nombre,
                    'valor_nuevo' => '—',
                ];
            }

            $logActual = is_array($guiaPrevio['log_cambios'] ?? null) ? $guiaPrevio['log_cambios'] : [];
            $idEmpleado = self::getIdEmpleadoFromRequest($request);
            if (! empty($cambios)) {
                $nuevoLog = RES_CambiosLog::crear(
                    $idEmpleado ?? 0,
                    $data['motivo'] ?? null,
                    $cambios,
                );
                array_unshift($logActual, $nuevoLog);
            }

            $nuevosValores['log_cambios'] = json_encode($logActual);

            GuiaSegundoTramoData::update($idGuia, $nuevosValores);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la guía de segundo tramo: '.$e->getMessage());
        }

        $guiaActualizada = GuiaSegundoTramoData::get_by_id($idGuia);

        return ApiResponse::success($guiaActualizada, 'Guía de segundo tramo actualizada correctamente.');
    }

    /**
     * PATCH /api/programacion-despachos/distribuciones/{id}/guia-segundo-tramo/{idGuia}/anular
     *
     * @return array<string, mixed>
     */
    public static function anular_guia(int $idDistribucion, int $idGuia, ?Request $request = null): array
    {
        try {
            DB::beginTransaction();

            $guia = GuiaSegundoTramoData::get_by_id($idGuia);
            if (! $guia) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la guía de segundo tramo.', 404);
            }
            if ((int) ($guia['id_ditribucion'] ?? 0) !== $idDistribucion) {
                DB::rollBack();

                return ApiResponse::error('La guía no pertenece a la distribución indicada.', 422);
            }
            if (($guia['estado'] ?? null) !== EstadoBase::Activo->value) {
                DB::rollBack();

                return ApiResponse::error('La guía ya se encuentra anulada.');
            }

            $logActual = is_array($guia['log_cambios'] ?? null) ? $guia['log_cambios'] : [];
            $idEmpleado = self::getIdEmpleadoFromRequest($request);

            $logAnulacion = RES_CambiosLog::crear($idEmpleado ?? 0, 'Anulación de guía de segundo tramo', [
                [
                    'campo_bd' => 'estado',
                    'campo' => 'Estado',
                    'valor_anterior' => EstadoBase::Activo->value,
                    'valor_nuevo' => EstadoBase::Eliminado->value,
                ],
            ]);
            array_unshift($logActual, $logAnulacion);

            GuiaSegundoTramoData::update($idGuia, [
                'estado' => EstadoBase::Eliminado->value,
                'log_cambios' => json_encode($logActual),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular la guía de segundo tramo: '.$e->getMessage());
        }

        return ApiResponse::success(
            GuiaSegundoTramoData::get_by_id($idGuia),
            'Guía de segundo tramo anulada correctamente.',
        );
    }
}
