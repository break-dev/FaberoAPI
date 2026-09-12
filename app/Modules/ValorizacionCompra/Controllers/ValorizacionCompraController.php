<?php

namespace App\Modules\ValorizacionCompra\Controllers;

use App\Modules\ValorizacionCompra\Services\ValorizacionCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ValorizacionCompraController extends Controller
{
    /**
     * Listar valorizaciones de compra con filtro opcional por proveedor
     */
    public function listar_valorizaciones(Request $request): JsonResponse
    {
        $idProveedor = $request->query('id_proveedor') ? (int) $request->query('id_proveedor') : null;
        $res = ValorizacionCompraService::listar_valorizaciones($idProveedor);

        return response()->json($res);
    }

    /**
     * Obtener una valorización por su ID
     */
    public function obtener_valorizacion(int $id): JsonResponse
    {
        $res = ValorizacionCompraService::obtener_valorizacion($id);
        $status = ($res['success'] ?? false) ? 200 : 404;

        return response()->json($res, $status);
    }

    /**
     * Registrar una nueva valorización en estado Pendiente
     */
    public function crear_valorizacion(Request $request): JsonResponse
    {
        if ($request->has('detalles') && is_string($request->input('detalles'))) {
            $decoded = json_decode($request->input('detalles'), true);
            if (is_array($decoded)) {
                $request->merge(['detalles' => $decoded]);
            }
        }

        if ($request->has('anticipos') && is_string($request->input('anticipos'))) {
            $decoded = json_decode($request->input('anticipos'), true);
            if (is_array($decoded)) {
                $request->merge(['anticipos' => $decoded]);
            }
        }

        $request->validate([
            'id_proveedor_minero' => 'required|integer|exists:proveedor,id',
            'id_concesion' => 'required|integer|exists:concesion,id',
            'id_cuenta_bancaria' => 'nullable|integer|exists:cuenta_bancaria_proveedor,id',
            'id_cuenta_detraccion' => 'nullable|integer|exists:cuenta_bancaria_proveedor,id',
            'tipo_pago' => 'required|string|in:transferencia,anticipo,mixto',
            'detalles' => 'required|array|min:1',
            'detalles.*.id_lote_guia' => 'required|integer|exists:lote_guia,id',
            'detalles.*.elemento_quimico' => 'required|string|in:Oro,Plata',
            'detalles.*.id_condicion_comercial' => 'nullable|integer',
            'detalles.*.id_valor_elemento_quimico' => 'nullable|integer',
            'detalles.*.inter' => 'required|numeric|min:0',
            'detalles.*.des_inter' => 'required|numeric|min:0',
            'detalles.*.recuperacion' => 'required|numeric|min:0|max:100',
            'detalles.*.maquila' => 'required|numeric|min:0',
            'detalles.*.consumo' => 'required|numeric|min:0',
            'detalles.*.factor' => 'nullable|numeric|min:0',
            'anticipos' => 'nullable|array',
            'anticipos.*.id_anticipo_proveedor' => 'required|integer|exists:anticipo_proveedor,id',
            'anticipos.*.monto_retirado' => 'required|numeric|gt:0',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'fecha_hora_valorizacion' => 'nullable|date',
            'monto_penalidad' => 'nullable|numeric|min:0',
            'monto_flete' => 'nullable|numeric|min:0',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'id_proveedor_minero' => (int) $request->input('id_proveedor_minero'),
            'id_concesion' => (int) $request->input('id_concesion'),
            'id_cuenta_bancaria' => $request->input('id_cuenta_bancaria') ? (int) $request->input('id_cuenta_bancaria') : null,
            'id_cuenta_detraccion' => $request->input('id_cuenta_detraccion') ? (int) $request->input('id_cuenta_detraccion') : null,
            'id_empleado_registro' => (int) $idEmpleado,
            'tipo_pago' => $request->input('tipo_pago'),
            'detalles' => $request->input('detalles'),
            'anticipos' => $request->input('anticipos', []),
            'fecha_hora_valorizacion' => $request->input('fecha_hora_valorizacion') ?: null,
            'monto_penalidad' => $request->input('monto_penalidad') !== null ? (float) $request->input('monto_penalidad') : 0,
            'monto_flete' => $request->input('monto_flete') !== null ? (float) $request->input('monto_flete') : 0,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ValorizacionCompraService::crear_valorizacion($payload, $archivos);
        $status = ($res['success'] ?? false) ? 201 : 400;

        return response()->json($res, $status);
    }

    /**
     * Editar una valorización existente en estado Pendiente
     */
    public function editar_valorizacion(Request $request, int $id): JsonResponse
    {
        if ($request->has('detalles') && is_string($request->input('detalles'))) {
            $decoded = json_decode($request->input('detalles'), true);
            if (is_array($decoded)) {
                $request->merge(['detalles' => $decoded]);
            }
        }

        if ($request->has('anticipos') && is_string($request->input('anticipos'))) {
            $decoded = json_decode($request->input('anticipos'), true);
            if (is_array($decoded)) {
                $request->merge(['anticipos' => $decoded]);
            }
        }

        $request->validate([
            'id_concesion' => 'required|integer|exists:concesion,id',
            'id_cuenta_bancaria' => 'nullable|integer|exists:cuenta_bancaria_proveedor,id',
            'id_cuenta_detraccion' => 'nullable|integer|exists:cuenta_bancaria_proveedor,id',
            'tipo_pago' => 'required|string|in:transferencia,anticipo,mixto',
            'detalles' => 'required|array|min:1',
            'detalles.*.id_lote_guia' => 'required|integer|exists:lote_guia,id',
            'detalles.*.elemento_quimico' => 'required|string|in:Oro,Plata',
            'detalles.*.id_condicion_comercial' => 'nullable|integer',
            'detalles.*.id_valor_elemento_quimico' => 'nullable|integer',
            'detalles.*.inter' => 'required|numeric|min:0',
            'detalles.*.des_inter' => 'required|numeric|min:0',
            'detalles.*.recuperacion' => 'required|numeric|min:0|max:100',
            'detalles.*.maquila' => 'required|numeric|min:0',
            'detalles.*.consumo' => 'required|numeric|min:0',
            'detalles.*.factor' => 'nullable|numeric|min:0',
            'anticipos' => 'nullable|array',
            'anticipos.*.id_anticipo_proveedor' => 'required|integer|exists:anticipo_proveedor,id',
            'anticipos.*.monto_retirado' => 'required|numeric|gt:0',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'evidencias_existentes' => 'nullable|string',
            'fecha_hora_valorizacion' => 'nullable|date',
            'monto_penalidad' => 'nullable|numeric|min:0',
            'monto_flete' => 'nullable|numeric|min:0',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $payload = [
            'id_concesion' => (int) $request->input('id_concesion'),
            'id_cuenta_bancaria' => $request->input('id_cuenta_bancaria') ? (int) $request->input('id_cuenta_bancaria') : null,
            'id_cuenta_detraccion' => $request->input('id_cuenta_detraccion') ? (int) $request->input('id_cuenta_detraccion') : null,
            'id_empleado_edicion' => (int) $idEmpleado,
            'tipo_pago' => $request->input('tipo_pago'),
            'detalles' => $request->input('detalles'),
            'anticipos' => $request->input('anticipos', []),
            'evidencias_existentes' => $request->input('evidencias_existentes'),
            'fecha_hora_valorizacion' => $request->input('fecha_hora_valorizacion') ?: null,
            'monto_penalidad' => $request->input('monto_penalidad') !== null ? (float) $request->input('monto_penalidad') : 0,
            'monto_flete' => $request->input('monto_flete') !== null ? (float) $request->input('monto_flete') : 0,
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ValorizacionCompraService::editar_valorizacion($id, $payload, $archivos);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }

    /**
     * Aprobar valorización y efectuar descuentos sobre los anticipos asociados
     */
    public function aprobar_valorizacion(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $res = ValorizacionCompraService::aprobar_valorizacion($id, (int) $idEmpleado);
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }

    /**
     * Anular o eliminar una valorización
     */
    public function anular_valorizacion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo_anulacion' => 'required|string|min:3',
            'tipo_eliminacion' => 'required|string|in:logica,fisica',
            'evidencias_anulacion' => 'nullable|array',
            'evidencias_anulacion.*' => 'file',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        $motivoAnulacion = (string) $request->input('motivo_anulacion');
        $tipoEliminacion = (string) $request->input('tipo_eliminacion', 'logica');

        $archivos = [];
        if ($request->hasFile('evidencias_anulacion')) {
            $archivos = $request->file('evidencias_anulacion');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        $res = ValorizacionCompraService::anular_valorizacion(
            $id,
            (int) $idEmpleado,
            $motivoAnulacion,
            $tipoEliminacion,
            $archivos
        );
        $status = ($res['success'] ?? false) ? 200 : 400;

        return response()->json($res, $status);
    }
}
