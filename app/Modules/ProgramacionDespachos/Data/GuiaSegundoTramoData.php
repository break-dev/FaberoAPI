<?php

namespace App\Modules\ProgramacionDespachos\Data;

use Illuminate\Support\Facades\DB;

class GuiaSegundoTramoData
{
    /**
     * Obtener la guía de segundo tramo de una distribución, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public static function get_by_distribucion(int $idDistribucion): ?array
    {
        $row = DB::selectOne(
            'SELECT * FROM guia_segundo_tramo WHERE id_ditribucion = :id LIMIT 1',
            ['id' => $idDistribucion],
        );

        if (! $row) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * Obtener la guía de segundo tramo por su ID.
     *
     * @return array<string, mixed>|null
     */
    public static function get_by_id(int $id): ?array
    {
        $row = DB::selectOne(
            'SELECT * FROM guia_segundo_tramo WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        if (! $row) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * Insertar una nueva guía de segundo tramo. Devuelve el ID generado.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function insertar(array $payload): int
    {
        return (int) DB::table('guia_segundo_tramo')->insertGetId($payload);
    }

    /**
     * Actualizar los campos escalares + log_cambios de una guía.
     *
     * @param  array<string, mixed>  $updates
     */
    public static function update(int $id, array $updates): bool
    {
        return DB::table('guia_segundo_tramo')->where('id', $id)->update($updates) > 0;
    }

    /**
     * Cambiar el estado de una guía (usado para anular).
     */
    public static function update_estado(int $id, string $estado): bool
    {
        return DB::table('guia_segundo_tramo')
            ->where('id', $id)
            ->update(['estado' => $estado]) > 0;
    }

    /**
     * Normaliza los tipos del row y decodifica JSON.
     */
    private static function hydrate(object $row): array
    {
        $row->id = (int) $row->id;
        $row->id_ditribucion = $row->id_ditribucion !== null ? (int) $row->id_ditribucion : null;
        $row->id_empleado_reistro = $row->id_empleado_reistro !== null
            ? (int) $row->id_empleado_reistro
            : null;

        $row->sin_guia_transportista = (bool) $row->sin_guia_transportista;

        $documentosRaw = $row->documentos ?? null;
        $row->documentos = is_string($documentosRaw) && $documentosRaw !== ''
            ? (json_decode($documentosRaw, true) ?: null)
            : (is_array($documentosRaw) ? $documentosRaw : null);

        $logRaw = $row->log_cambios ?? null;
        $row->log_cambios = is_string($logRaw) && $logRaw !== ''
            ? (json_decode($logRaw, true) ?: [])
            : (is_array($logRaw) ? $logRaw : []);

        return (array) $row;
    }
}
