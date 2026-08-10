<?php

namespace App\Services;

use App\Data\VehiculosData;
use App\Shared\Responses\ApiResponse;

class VehiculosService
{
    /**
     * Obtener listado global de vehículos (datos simplificados)
     */
    public static function get_vehiculos(?string $placa = null, ?int $id = null, ?bool $esCarreta = null): array
    {
        $data = VehiculosData::get_vehiculos($placa, $id, $esCarreta);

        return ApiResponse::success($data);
    }

    /**
     * Crear un nuevo vehículo de forma simplificada
     */
    public static function crear_vehiculo_simplificado(
        string $placa,
        ?int $idEmpresaTransporte = null,
        ?int $idTipoVehiculo = null
    ): array {
        $existenteId = VehiculosData::buscar_vehiculo_existente($placa);
        if ($existenteId !== null) {
            $vehiculos = VehiculosData::get_vehiculos(id: $existenteId);
            $vehiculo = $vehiculos[0] ?? null;
            if ($vehiculo) {
                $vehiculo->ya_existia = true;
            }

            return ApiResponse::success($vehiculo, 'El vehículo ya se encontraba registrado.');
        }

        $id = VehiculosData::crear_vehiculo_simplificado($placa, $idEmpresaTransporte, $idTipoVehiculo);
        $vehiculos = VehiculosData::get_vehiculos(id: $id);
        $vehiculo = $vehiculos[0] ?? null;
        if ($vehiculo) {
            $vehiculo->ya_existia = false;
        }

        return ApiResponse::success($vehiculo, 'Vehículo registrado correctamente');
    }

    /**
     * Editar un vehículo de forma simplificada
     */
    public static function editar_vehiculo_simplificado(
        int $id,
        int $idEmpresaTransporte,
        int $idTipoVehiculo
    ): array {
        VehiculosData::editar_vehiculo_simplificado($id, $idEmpresaTransporte, $idTipoVehiculo);
        $vehiculos = VehiculosData::get_vehiculos(id: $id);

        return ApiResponse::success($vehiculos[0] ?? null, 'Vehículo actualizado correctamente');
    }
}
