<?php

namespace App\Modules\ValidacionDistribucion\Controllers;

use App\Modules\ValidacionDistribucion\Services\ValidacionDistribucionService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidacionDistribucionController
{
    public function get_lotes_pendientes(Request $request): JsonResponse
    {
        $filtros = [
            'id_sucursal' => $request->query('id_sucursal'),
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        return response()->json(ValidacionDistribucionService::get_lotes_pendientes($filtros));
    }

    public function get_validar_lote(int $idLote): JsonResponse
    {
        return response()->json(ValidacionDistribucionService::get_validar_lote($idLote));
    }

    public function get_particiones(int $idLote): JsonResponse
    {
        return response()->json(ValidacionDistribucionService::get_particiones($idLote));
    }

    public function get_particion(int $id): JsonResponse
    {
        return response()->json(ValidacionDistribucionService::get_particion($id));
    }

    public function crear_particion(Request $request, int $idLote): JsonResponse
    {
        $data = $request->validate([
            'peso_inicial' => 'nullable|numeric|min:0',
            'fecha_hora_peso_inicial' => 'nullable|date',
            'peso_final' => 'nullable|numeric|min:0',
            'fecha_hora_peso_final' => 'nullable|date',
            'peso_neto' => 'nullable|numeric|min:0',
            'recepcion' => 'nullable|array',
            'recepcion.id_vehiculo' => 'nullable|integer',
            'recepcion.id_conductor' => 'nullable|integer',
            'recepcion.id_sucursal' => 'nullable|integer',
            'recepcion.fecha_hora_ingreso' => 'nullable|date',
            'recepcion.segunda_placa' => 'nullable|string|max:15',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $data['id_empleado_registro'] = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : null;

        return response()->json(ValidacionDistribucionService::crear_particion($idLote, $data));
    }

    public function registrar_peso_inicial(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'peso_inicial' => 'required|numeric|min:0',
            'fecha_hora_peso_inicial' => 'required|date',
        ]);

        return response()->json(ValidacionDistribucionService::registrar_peso_inicial($id, $data));
    }

    public function registrar_peso_final(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'peso_final' => 'required|numeric|min:0',
            'fecha_hora_peso_final' => 'required|date',
        ]);

        return response()->json(ValidacionDistribucionService::registrar_peso_final($id, $data));
    }

    public function update_particion(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'peso_inicial' => 'nullable|numeric|min:0',
            'peso_final' => 'nullable|numeric|min:0',
            'peso_neto' => 'nullable|numeric|min:0',
            'fecha_hora_peso_inicial' => 'nullable|date',
            'fecha_hora_peso_final' => 'nullable|date',
            'es_bloqueado' => 'nullable|boolean',
            'estado' => 'nullable|string|in:Activo,Inactivo,Eliminado',
            'recepcion' => 'nullable|array',
            'recepcion.id_vehiculo' => 'nullable|integer',
            'recepcion.id_conductor' => 'nullable|integer',
            'recepcion.id_sucursal' => 'nullable|integer',
            'recepcion.id_empresa_transporte' => 'nullable|integer',
            'recepcion.id_tipo_vehiculo' => 'nullable|integer',
            'recepcion.id_proveedor_minero' => 'nullable|integer',
            'recepcion.fecha_hora_ingreso' => 'nullable|date',
        ]);

        return response()->json(ValidacionDistribucionService::update_particion($id, $data));
    }

    public function get_ticket_balanza(int $id): JsonResponse
    {
        return response()->json(ValidacionDistribucionService::get_ticket_balanza($id));
    }
}
