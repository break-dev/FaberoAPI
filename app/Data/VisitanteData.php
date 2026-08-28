<?php

namespace App\Data;

use App\Models\Visitante;
use Illuminate\Support\Facades\DB;

class VisitanteData
{
    public static function buscar_por_dni(string $dni): ?array
    {
        $sql = '
        SELECT
            v.id AS id_visitante,
            v.nombre,
            v.apellido,
            v.dni,
            v.telefono
        FROM
            visitante v
        WHERE
            v.dni = :dni
        LIMIT 1;
        ';

        $res = DB::selectOne($sql, ['dni' => $dni]);

        return $res ? (array) $res : null;
    }

    public static function crear_visitante(array $data): int
    {
        return Visitante::insertGetId([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'] ?? null,
            'dni' => $data['dni'] ?? null,
            'telefono' => $data['telefono'] ?? null,
        ]);
    }

    public static function get_visitante_by_id(int $id): ?array
    {
        $sql = '
        SELECT
            v.id AS id_visitante,
            v.nombre,
            v.apellido,
            v.dni,
            v.telefono
        FROM
            visitante v
        WHERE
            v.id = :id
        LIMIT 1;
        ';

        $res = DB::selectOne($sql, ['id' => $id]);

        return $res ? (array) $res : null;
    }

    /**
     * Listar visitantes con búsqueda opcional.
     * Devuelve los más recientes primero, limitado a $limit resultados.
     *
     * @return array<int, object>
     */
    public static function listar_visitantes(?string $search = null, int $limit = 50): array
    {
        $sql = '
        SELECT
            v.id AS id_visitante,
            v.nombre,
            v.apellido,
            v.dni,
            v.telefono
        FROM
            visitante v
        WHERE 1=1
        ';

        $params = [];

        if ($search !== null && trim($search) !== '') {
            $like = '%' . trim($search) . '%';
            $sql .= ' AND (
                v.nombre LIKE ?
                OR v.apellido LIKE ?
                OR v.dni LIKE ?
            )';
            $params = [$like, $like, $like];
        }

        $sql .= '
        ORDER BY v.nombre ASC, v.apellido ASC
        LIMIT ' . (int) $limit;

        return DB::select($sql, $params);
    }
}
