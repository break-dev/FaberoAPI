<?php

namespace App\Modules\ProgramarRecepcion\Data;

use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Support\Facades\DB;

class ProgramarRecepcionData
{
    /**
     * Obtener programaciones (recepciones con es_programacion = 1).
     * Opcionalmente filtrar por estado de confirmación: las no confirmadas (id_empleado_recepcion IS NULL).
     */
    public static function get_programaciones(array $filtros = []): array
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            ru.tipo_ingreso,
            ru.guia_remitente,
            ru.guia_transportista,
            ru.documentos_programacion,
            ru.fecha_estimada_llegada,
            ru.observacion,
            ru.es_programacion,
            ru.id_empleado_recepcion,
            ru.fecha_hora_ingreso,
            ru.estado,
            ru.created_at
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        WHERE ru.es_programacion = 1
        ';

        $params = [];

        $estado = $filtros['estado_confirmacion'] ?? null;
        if ($estado === 'pendientes' || ($estado === null && ($filtros['solo_pendientes'] ?? false) === true)) {
            $sql .= ' AND ru.fecha_hora_ingreso IS NULL';
        } elseif ($estado === 'confirmadas') {
            $sql .= ' AND ru.fecha_hora_ingreso IS NOT NULL';
        }

        if (! empty($filtros['fecha_inicio'])) {
            $sql .= ' AND DATE(COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at)) >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }
        if (! empty($filtros['fecha_fin'])) {
            $sql .= ' AND DATE(COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at)) <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }

        $sql .= ' ORDER BY COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) DESC';

        $results = DB::select($sql, $params);
        foreach ($results as $item) {
            if (isset($item->documentos_programacion)) {
                $item->documentos_programacion = RecepcionUnidadesData::normalizar_documentos_programacion($item->documentos_programacion);
            }
        }

        return $results;
    }

    /**
     * Detalle completo de una programación + visita asociada + vehículos + visitantes.
     */
    public static function get_programacion_full(int $id): ?array
    {
        $programacion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if ($programacion === null) {
            return null;
        }

        // Buscar visita asociada (si fue confirmada)
        $visita = DB::selectOne('
            SELECT
                rv.id AS id_recepcion_visita,
                rv.id_motivo_ingreso,
                mi.nombre AS motivo_ingreso_nombre,
                rv.fecha_hora_ingreso,
                rv.observacion,
                rv.estado
            FROM recepcion_visita rv
            LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
            WHERE rv.id_recepcion_unidad = :id
            LIMIT 1
        ', ['id' => $id]);

        $visitaId = $visita->id_recepcion_visita ?? null;
        $visitaPayload = $visita ? (array) $visita : null;

        if ($visitaId !== null) {
            // Cargar vehículos acompañantes
            $vehiculos = DB::select('
                SELECT
                    id, id_recepcion_visita, placa, cantidad_personas, url_foto
                FROM visita_vehiculo
                WHERE id_recepcion_visita = :id
                ORDER BY id ASC
            ', ['id' => $visitaId]);

            foreach ($vehiculos as $vv) {
                $vv->url_foto = $vv->url_foto ? json_decode($vv->url_foto, true) : null;
            }

            // Cargar detalles (visitantes) de la visita
            $detalles = DB::select('
                SELECT
                    rvd.id AS id_detalle,
                    rvd.id_visitante,
                    rvd.id_visita_vehiculo,
                    rvd.es_conductor,
                    rvd.estado,
                    v.nombre AS visitante_nombre,
                    v.apellido AS visitante_apellido,
                    v.dni AS visitante_dni,
                    v.telefono AS visitante_telefono,
                    rvd.url_foto_documento
                FROM recepcion_visita_detalle rvd
                INNER JOIN visitante v ON v.id = rvd.id_visitante
                WHERE rvd.id_recepcion_visita = :id
                ORDER BY rvd.es_conductor DESC, rvd.id ASC
            ', ['id' => $visitaId]);

            foreach ($detalles as $d) {
                $d->url_foto_documento = $d->url_foto_documento ? json_decode($d->url_foto_documento, true) : null;
                if (isset($d->es_conductor)) {
                    $d->es_conductor = (int) $d->es_conductor === 1;
                }
            }

            $visitaPayload['vehiculos'] = $vehiculos;
            $visitaPayload['detalles'] = $detalles;
        }

        $programacion['visita'] = $visitaPayload;

        return $programacion;
    }

    /**
     * Crear una programación (recepcion_unidad con es_programacion = 1).
     *
     * @param  array  $data  Datos básicos de la programación. Incluye opcionalmente
     *                       `documentos_programacion` (array con claves guia_remitente
     *                       y guia_transportista, cada una con estructura IArchivo o null).
     */
    public static function crear_programacion(array $data): int
    {
        $insert = [
            'id_empleado_autoriza' => $data['id_empleado_autoriza'],
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_vehiculo' => $data['id_vehiculo'] ?? null,
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'] ?? null,
            'id_conductor' => $data['id_conductor'] ?? null,
            'id_proveedor_minero' => $data['id_proveedor_minero'] ?? null,
            'id_sucursal' => $data['id_sucursal'] ?? null,
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Mineral',
            'guia_remitente' => $data['guia_remitente'] ?? null,
            'guia_transportista' => $data['guia_transportista'] ?? null,
            'fecha_estimada_llegada' => $data['fecha_estimada_llegada'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'es_programacion' => 1,
            'created_at' => now()->toDateTimeString(),
        ];

        $documentos = self::normalizar_documentos_input($data['documentos_programacion'] ?? null);
        if ($documentos !== null) {
            $insert['documentos_programacion'] = json_encode($documentos);
        }

        return DB::table('recepcion_unidad')->insertGetId($insert);
    }

    /**
     * Actualizar programación (solo permitido mientras NO esté confirmada).
     *
     * Acepta `documentos_programacion` (array normalizado) y persiste como JSON.
     */
    public static function actualizar_programacion(int $id, array $data): bool
    {
        if (array_key_exists('documentos_programacion', $data)) {
            $documentos = self::normalizar_documentos_input($data['documentos_programacion']);
            $data['documentos_programacion'] = $documentos !== null
                ? json_encode($documentos)
                : null;
        }

        return DB::table('recepcion_unidad')
            ->where('id', $id)
            ->where('es_programacion', 1)
            ->whereNull('id_empleado_recepcion')
            ->update($data) > 0;
    }

    /**
     * Normalizar la entrada de documentos_programacion antes de persistir.
     * Acepta array con claves guia_remitente / guia_transportista (IArchivo|null)
     * y devuelve la misma forma o null si ambas están vacías.
     */
    private static function normalizar_documentos_input(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $result = ['guia_remitente' => null, 'guia_transportista' => null];
        foreach (['guia_remitente', 'guia_transportista'] as $key) {
            $item = $input[$key] ?? null;
            if (is_array($item) && ! empty($item['url'])) {
                $result[$key] = [
                    'url' => $item['url'],
                    'path_relativo' => $item['path_relativo'] ?? '',
                    'nombre_original' => $item['nombre_original'] ?? null,
                    'extension' => $item['extension'] ?? null,
                ];
            }
        }

        if ($result['guia_remitente'] === null && $result['guia_transportista'] === null) {
            return null;
        }

        return $result;
    }

    /**
     * Confirmar una programación.
     *
     * Acepta opcionalmente `observacion` y archivos de `evidencias` que se persisten en
     * `recepcion_unidad` con su entrada en `log_cambios` (motivo: "Confirmación inicial").
     */
    public static function confirmar_programacion(
        int $id,
        int $idEmpleadoRecepcion,
        array $overrides = [],
        ?string $observacion = null,
        array $archivosEvidencias = [],
    ): bool {
        $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if (! $recepcion) {
            return false;
        }

        return DB::transaction(function () use ($recepcion, $id, $idEmpleadoRecepcion, $overrides, $observacion, $archivosEvidencias) {
            $update = array_merge([
                'id_empleado_recepcion' => $idEmpleadoRecepcion,
                'fecha_hora_ingreso' => now()->toDateTimeString(),
                'estado' => EstadoVisita::EnPlanta->value,
                'estado_pesaje' => EstadoPesaje::SinPesar->value,
            ], $overrides);

            $ok = DB::table('recepcion_unidad')
                ->where('id', $id)
                ->where('es_programacion', 1)
                ->whereNull('id_empleado_recepcion')
                ->update($update) > 0;

            if (! $ok) {
                return false;
            }

            // Aplicar observación/evidencias (con log_cambios) si corresponde.
            $cambios = [];
            $motivo = 'Confirmación inicial';

            $observacionAnterior = $recepcion['observacion'] ?? null;
            if (($observacion ?? '') !== ($observacionAnterior ?? '')) {
                $cambios[] = [
                    'campo_bd' => 'observacion',
                    'campo' => 'Observación',
                    'valor_anterior' => $observacionAnterior !== null && $observacionAnterior !== '' ? $observacionAnterior : '— (vacío)',
                    'valor_nuevo' => $observacion !== null && $observacion !== '' ? $observacion : '— (vacío)',
                ];
                $update['observacion'] = $observacion;
            }

            $evidenciasAnteriores = $recepcion['evidencias'] ?? [];
            if (! is_array($evidenciasAnteriores)) {
                $evidenciasAnteriores = [];
            }
            $evidenciasActuales = $evidenciasAnteriores;
            if (! empty($archivosEvidencias)) {
                $nuevas = ArchivoHelper::guardarArchivos('recepcion-unidad', $archivosEvidencias);
                $evidenciasActuales = array_merge($evidenciasActuales, $nuevas);
            }
            $nombresAnt = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasAnteriores,
            )));
            $nombresNue = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasActuales,
            )));
            sort($nombresAnt);
            sort($nombresNue);
            if ($nombresAnt !== $nombresNue) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencias',
                    'valor_anterior' => ! empty($nombresAnt) ? implode(', ', $nombresAnt) : '— (sin evidencias)',
                    'valor_nuevo' => ! empty($nombresNue) ? implode(', ', $nombresNue) : '— (sin evidencias)',
                ];
                $update['evidencias'] = json_encode($evidenciasActuales);
            }

            if (! empty($cambios)) {
                $logActual = $recepcion['log_cambios'] ?? [];
                if (! is_array($logActual)) {
                    $logActual = json_decode($logActual, true) ?? [];
                }
                $nuevoLog = RES_CambiosLog::crear($idEmpleadoRecepcion, $motivo, $cambios);
                array_unshift($logActual, $nuevoLog);
                $update['log_cambios'] = json_encode($logActual);
            }

            if (count($update) > 4) {
                DB::table('recepcion_unidad')->where('id', $id)->update($update);
            }

            return true;
        });
    }

    /**
     * Actualizar la observación y/o evidencias de una recepción ya confirmada.
     *
     * Compara antes/después, registra entrada en `log_cambios` con el motivo provisto
     * y devuelve la cantidad de entradas agregadas al log (0 si nada cambió).
     */
    public static function actualizar_observacion_evidencias(
        int $id,
        ?string $observacion,
        ?string $observacionSalida,
        array $evidenciasExistentes,
        array $archivosNuevos,
        int $idEmpleado,
        ?string $motivo = null,
    ): int {
        return DB::transaction(function () use ($id, $observacion, $observacionSalida, $evidenciasExistentes, $archivosNuevos, $idEmpleado, $motivo) {
            $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
            if (! $recepcion) {
                return 0;
            }

            $cambios = [];

            $observacionAnterior = $recepcion['observacion'] ?? null;
            if (($observacion ?? '') !== ($observacionAnterior ?? '')) {
                $cambios[] = [
                    'campo_bd' => 'observacion',
                    'campo' => 'Observación',
                    'valor_anterior' => $observacionAnterior !== null && $observacionAnterior !== '' ? $observacionAnterior : '— (vacío)',
                    'valor_nuevo' => $observacion !== null && $observacion !== '' ? $observacion : '— (vacío)',
                ];
            }

            $observacionSalidaAnterior = $recepcion['observacion_salida'] ?? null;
            if (($observacionSalida ?? '') !== ($observacionSalidaAnterior ?? '')) {
                $cambios[] = [
                    'campo_bd' => 'observacion_salida',
                    'campo' => 'Observación Salida',
                    'valor_anterior' => $observacionSalidaAnterior !== null && $observacionSalidaAnterior !== '' ? $observacionSalidaAnterior : '— (vacío)',
                    'valor_nuevo' => $observacionSalida !== null && $observacionSalida !== '' ? $observacionSalida : '— (vacío)',
                ];
            }

            $evidenciasActuales = $evidenciasExistentes;
            if (! empty($archivosNuevos)) {
                $subidos = ArchivoHelper::guardarArchivos('recepcion-unidad', $archivosNuevos);
                $evidenciasActuales = array_merge($evidenciasActuales, $subidos);
            }

            $evidenciasAnteriores = $recepcion['evidencias'] ?? [];
            if (! is_array($evidenciasAnteriores)) {
                $evidenciasAnteriores = [];
            }
            $nombresAnt = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasAnteriores,
            )));
            $nombresNue = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasActuales,
            )));
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

            if (empty($cambios)) {
                return 0;
            }

            $update = [];
            if (array_key_exists('observacion', array_column($cambios, 'campo_bd') ? array_flip(array_column($cambios, 'campo_bd')) : [])) {
                $update['observacion'] = $observacion;
            }
            if (in_array('observacion_salida', array_column($cambios, 'campo_bd'), true)) {
                $update['observacion_salida'] = $observacionSalida;
            }
            $cambioEvidencias = false;
            foreach ($cambios as $c) {
                if (($c['campo_bd'] ?? null) === 'evidencias') {
                    $cambioEvidencias = true;
                    break;
                }
            }
            if ($cambioEvidencias) {
                $update['evidencias'] = json_encode($evidenciasActuales);
            }

            $logActual = $recepcion['log_cambios'] ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            $nuevoLog = RES_CambiosLog::crear($idEmpleado, $motivo, $cambios);
            array_unshift($logActual, $nuevoLog);
            $update['log_cambios'] = json_encode($logActual);

            DB::table('recepcion_unidad')->where('id', $id)->update($update);

            return count($cambios);
        });
    }
}
