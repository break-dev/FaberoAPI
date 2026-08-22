<?php

namespace App\Modules\PlantasDestino\Services;

use App\Modules\PlantasDestino\Data\PlantasDestinoData;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class PlantasDestinoService
{
    public static function get_plantas(): array
    {
        $data = PlantasDestinoData::get_plantas();

        return ApiResponse::success($data, 'Plantas de destino obtenidas correctamente');
    }

    /**
     * Listar plantas activas en formato simplificado (id, ruc, razon_social) para dropdowns.
     */
    public static function get_plantas_despachable(): array
    {
        $sql = '
        SELECT id, ruc, razon_social
        FROM planta_destino
        WHERE estado = :estado
        ORDER BY razon_social ASC
        ';
        $rows = DB::select($sql, ['estado' => 'Activo']);

        return ApiResponse::success($rows, 'Plantas activas obtenidas correctamente');
    }

    public static function get_planta(int $id): array
    {
        $data = PlantasDestinoData::get_planta_by_id($id);
        if (empty((array) $data)) {
            return ApiResponse::error('Planta de destino no encontrada');
        }

        return ApiResponse::success($data, 'Planta de destino obtenida correctamente');
    }

    public static function crear_planta(
        string $ruc,
        string $razon_social,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): array {
        if (PlantasDestinoData::existe_ruc($ruc)) {
            return ApiResponse::error('El RUC ingresado ya está registrado para otra planta destino');
        }

        $id = PlantasDestinoData::crear_planta($ruc, $razon_social, $direccion, $telefono, $correo);
        $nuevaPlanta = PlantasDestinoData::get_planta_by_id($id);

        return ApiResponse::success($nuevaPlanta, 'Planta de destino registrada correctamente');
    }

    public static function editar_planta(
        int $id,
        string $ruc,
        string $razon_social,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): array {
        if (PlantasDestinoData::existe_ruc($ruc, $id)) {
            return ApiResponse::error('El RUC ingresado ya está registrado para otra planta destino');
        }

        PlantasDestinoData::editar_planta($id, $ruc, $razon_social, $direccion, $telefono, $correo);
        $updated = PlantasDestinoData::get_planta_by_id($id);

        return ApiResponse::success($updated, 'Planta de destino editada correctamente');
    }

    public static function cambiar_estado_planta(int $id, string $estado): array
    {
        PlantasDestinoData::cambiar_estado_planta($id, $estado);
        $updated = PlantasDestinoData::get_planta_by_id($id);

        return ApiResponse::success($updated, 'Estado de la planta de destino cambiado correctamente');
    }

    /* --- Asociación de Proveedores --- */

    public static function get_proveedores_asociados(int $idPlanta): array
    {
        $data = PlantasDestinoData::get_proveedores_asociados($idPlanta);

        return ApiResponse::success($data, 'Proveedores asociados obtenidos correctamente');
    }

    public static function asociar_proveedor(int $idPlanta, int $idProveedor): array
    {
        if (PlantasDestinoData::existe_asociacion($idPlanta, $idProveedor)) {
            return ApiResponse::error('Este proveedor ya está asociado a esta planta destino');
        }

        PlantasDestinoData::asociar_proveedor($idPlanta, $idProveedor);

        return ApiResponse::success(null, 'Proveedor asociado correctamente');
    }

    public static function desasociar_proveedor(int $idPlanta, int $idProveedor): array
    {
        PlantasDestinoData::desasociar_proveedor($idPlanta, $idProveedor);

        return ApiResponse::success(null, 'Proveedor desasociado correctamente');
    }
}
