<?php

namespace App\Services;

use App\Data\VisitanteData;
use App\Shared\Responses\ApiResponse;

class VisitanteService
{
    public static function buscar_por_dni(string $dni): array
    {
        $visitante = VisitanteData::buscar_por_dni($dni);
        if (! $visitante) {
            return ApiResponse::error('Visitante no encontrado');
        }

        return ApiResponse::success($visitante, 'Visitante encontrado correctamente');
    }

    public static function crear_visitante(array $data): array
    {
        // Verificar si ya existe por DNI
        $existente = VisitanteData::buscar_por_dni($data['dni']);
        if ($existente) {
            return ApiResponse::error('Ya existe un visitante con el mismo DNI.');
        }

        $id = VisitanteData::crear_visitante($data);
        $nuevo = VisitanteData::get_visitante_by_id($id);

        return ApiResponse::success($nuevo, 'Visitante registrado correctamente');
    }

    /**
     * Listar visitantes con búsqueda opcional.
     *
     * @return array<string, mixed>
     */
    public static function listar_visitantes(?string $search = null): array
    {
        $visitantes = VisitanteData::listar_visitantes($search);

        return ApiResponse::success($visitantes, 'Visitantes consultados correctamente');
    }
}
