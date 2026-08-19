<?php

namespace App\Controllers;

use App\Services\ConductoresService;
use App\Services\EmpleadosService;
use App\Services\EmpresasService;
use App\Services\EmpresasTransporteService;
use App\Services\MarcasService;
use App\Services\MotivoIngresoService;
use App\Services\ProveedoresService;
use App\Services\SucursalService;
use App\Services\TipoCambioService;
use App\Services\TiposVehiculoService;
use App\Services\UbigeoService;
use App\Services\ValorizacionCompraAuxService;
use App\Services\VehiculosService;
use App\Services\VisitanteService;
use App\Services\ZonasOrigenService;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AuxController extends Controller
{
    public function get_empleados(Request $request): JsonResponse
    {
        $id_empleado = $request->input('id_empleado') ? $request->input('id_empleado') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : EstadoBase::Activo;

        $result = EmpleadosService::get_empleados(
            id_empleado: $id_empleado,
            estado: $estado
        );

        return response()->json($result);
    }

    /**
     * Obtener proveedores habilitados
     */
    public function get_proveedores(Request $request): JsonResponse
    {
        $id_proveedor = $request->input('id_proveedor') ? (int) $request->input('id_proveedor') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : null;
        $tipo_entidad = $request->input('tipo_entidad');

        $result = ProveedoresService::get_proveedores(
            id_proveedor: $id_proveedor,
            estado: $estado,
            tipoEntidad: $tipo_entidad
        );

        return response()->json($result);
    }

    public function get_empresas(Request $request): JsonResponse
    {
        $id_empresa = $request->input('id_empresa') ? (int) $request->input('id_empresa') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : null;

        return response()->json(EmpresasService::get_empresas(
            id_empresa: $id_empresa,
            estado: $estado
        ));
    }

    public function get_marcas(Request $request): JsonResponse
    {
        $id_marca = $request->input('id_marca') ? (int) $request->input('id_marca') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : null;

        return response()->json(MarcasService::get_marcas(id_marca: $id_marca, estado: $estado));
    }

    public function crear_marca(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string',
        ]);

        $result = MarcasService::crear_marca(
            nombre: $request->input('nombre')
        );

        return response()->json($result);
    }

    /**
     * Obtener listado de departamentos
     */
    public function get_departamentos(): JsonResponse
    {
        $result = UbigeoService::get_departamentos();

        return response()->json($result);
    }

    /**
     * Obtener listado de provincias por departamento
     */
    public function get_provincias(Request $request): JsonResponse
    {
        $id_departamento = (int) $request->input('id_departamento');
        $result = UbigeoService::get_provincias($id_departamento);

        return response()->json($result);
    }

    /**
     * Obtener listado de distritos por provincia
     */
    public function get_distritos(Request $request): JsonResponse
    {
        $id_provincia = (int) $request->input('id_provincia');
        $result = UbigeoService::get_distritos($id_provincia);

        return response()->json($result);
    }

    /**
     * Obtener el listado de conductores activos
     */
    public function get_conductores(): JsonResponse
    {
        $result = ConductoresService::get_conductores();

        return response()->json($result);
    }

    /**
     * Crear un nuevo conductor en el sistema
     */
    public function crear_conductor(Request $request): JsonResponse
    {
        $request->validate([
            'dni' => 'required|string|max:8',
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'numero_licencia' => 'required|string|max:20',
        ]);

        $result = ConductoresService::crear_conductor(
            dni: $request->input('dni'),
            nombre: $request->input('nombre'),
            apellido: $request->input('apellido'),
            numeroLicencia: $request->input('numero_licencia'),
            return_object: true
        );

        return response()->json($result);
    }

    /**
     * Función para obtener el listado de tipos de vehículo
     */
    public function get_tipos_vehiculo(Request $request): JsonResponse
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;

        return response()->json(TiposVehiculoService::get_tipos_vehiculo($id));
    }

    /**
     * Crear un nuevo tipo de vehículo en el sistema
     */
    public function crear_tipo_vehiculo(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'tiene_carreta' => 'nullable|boolean',
            'es_carreta' => 'nullable|boolean',
        ]);

        return response()->json(TiposVehiculoService::crear_tipo_vehiculo(
            $request->nombre,
            (bool) $request->input('tiene_carreta', false),
            (bool) $request->input('es_carreta', false)
        ));
    }

    public function editar_tipo_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'tiene_carreta' => 'nullable|boolean',
            'es_carreta' => 'nullable|boolean',
        ]);

        return response()->json(TiposVehiculoService::editar_tipo_vehiculo(
            $id,
            $request->nombre,
            (bool) $request->input('tiene_carreta', false),
            (bool) $request->input('es_carreta', false)
        ));
    }

    public function cambiar_estado_tipo_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(TiposVehiculoService::cambiar_estado_tipo_vehiculo($id, $request->estado));
    }

    /**
     * Obtener listado de empresas de transporte activas (datos simplificados)
     */
    public function get_empresas_transporte(Request $request): JsonResponse
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;

        return response()->json(EmpresasTransporteService::get_empresas_transporte($id));
    }

    /**
     * Obtener listado de vehículos (datos simplificados)
     */
    public function get_vehiculos(Request $request): JsonResponse
    {
        $placa = $request->input('placa');
        $esCarretaParam = $request->input('es_carreta');
        $esCarreta = $esCarretaParam === null ? null : filter_var($esCarretaParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return response()->json(VehiculosService::get_vehiculos($placa, null, $esCarreta));
    }

    /**
     * Crear un nuevo vehículo de forma simplificada
     */
    public function crear_vehiculo(Request $request): JsonResponse
    {
        $request->validate([
            'placa' => 'nullable|string|max:20',
            'id_empresa_transporte' => 'nullable|integer',
            'id_tipo_vehiculo' => 'nullable|integer',
        ]);

        $placa = $request->input('placa') ?? '';

        $empId = $request->input('id_empresa_transporte') ? (int) $request->input('id_empresa_transporte') : null;
        $tipoId = $request->input('id_tipo_vehiculo') ? (int) $request->input('id_tipo_vehiculo') : null;

        $result = VehiculosService::crear_vehiculo_simplificado(
            $placa,
            $empId,
            $tipoId
        );

        return response()->json($result);
    }

    /**
     * Editar un vehículo de forma simplificada (transportista y tipo de vehículo)
     */
    public function editar_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
        ]);

        $result = VehiculosService::editar_vehiculo_simplificado(
            $id,
            (int) $request->input('id_empresa_transporte'),
            (int) $request->input('id_tipo_vehiculo')
        );

        return response()->json($result);
    }

    /**
     * Obtener listado de motivos de ingreso.
     * Acepta `?es_recepcion_unidad=1` para filtrar motivos aplicables a recepciones de unidad.
     */
    public function get_motivos_ingreso(Request $request): JsonResponse
    {
        $filtro = $request->query('es_recepcion_unidad');
        $esRecepcionUnidad = $filtro === null ? null : filter_var($filtro, FILTER_VALIDATE_BOOLEAN);

        return response()->json(MotivoIngresoService::get_motivos_ingreso($esRecepcionUnidad));
    }

    /**
     * Crear un nuevo motivo de ingreso.
     */
    public function crear_motivo_ingreso(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:100',
            'es_recepcion_unidad' => 'nullable|boolean',
        ]);

        return response()->json(MotivoIngresoService::crear_motivo_ingreso($validated));
    }

    /**
     * Buscar visitante por su DNI
     */
    public function buscar_visitante_por_dni(Request $request): JsonResponse
    {
        $request->validate([
            'dni' => 'required|string|max:8',
        ]);

        return response()->json(VisitanteService::buscar_por_dni($request->query('dni')));
    }

    /**
     * Crear un nuevo visitante
     */
    public function crear_visitante(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'dni' => 'required|string|max:8',
            'telefono' => 'nullable|string|max:50',
        ]);

        $data = [
            'nombre' => $request->input('nombre'),
            'apellido' => $request->input('apellido'),
            'dni' => $request->input('dni'),
            'telefono' => $request->input('telefono'),
        ];

        return response()->json(VisitanteService::crear_visitante($data));
    }

    /**
     * Obtener listado de sucursales activas para el select global
     */
    public function get_sucursales(Request $request): JsonResponse
    {
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : EstadoBase::Activo;

        $authUser = $request->attributes->get('auth_user');
        $id_usuario = $authUser ? $authUser->id_usuario : null;

        return response()->json(SucursalService::get_sucursales($estado, $id_usuario));
    }

    /**
     * Obtener listado de zonas de origen activas
     */
    public function get_zonas_origen(): JsonResponse
    {
        return response()->json(ZonasOrigenService::get_zonas_origen());
    }

    /**
     * Registrar una nueva zona de origen
     */
    public function crear_zona_origen(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:150',
        ]);

        $result = ZonasOrigenService::crear_zona_origen(
            $request->input('nombre')
        );

        return response()->json($result);
    }

    /**
     * Obtener lotes de mineral disponibles (Pesado, sin asignar a una guía) para el modal de selección.
     */
    public function get_lotes_mineral_disponibles(Request $request): JsonResponse
    {
        $idSucursal = $request->query('id_sucursal') ? (int) $request->query('id_sucursal') : null;
        $idProveedor = $request->query('id_proveedor') ? (int) $request->query('id_proveedor') : null;

        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.id_proveedor_minero,
            lm.correlativo,
            lm.numero_correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.peso_inicial,
            lm.peso_final,
            lm.peso_neto,
            lm.created_at,
            p.razon_social AS proveedor_nombre,
            v.placa AS vehiculo_placa,
            (
                SELECT COUNT(*)
                FROM lote_guia lg
                INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                WHERE lg.id_lote_mineral = lm.id
                  AND gpt.estado = :estado_guia_activo
            ) AS en_guia
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        WHERE lm.peso_inicial IS NOT NULL
          AND lm.peso_final IS NOT NULL
          AND ru.estado_pesaje = :estado_pesaje
        ';

        $params = [
            'estado_pesaje' => EstadoPesaje::Pesado->value,
            'estado_guia_activo' => EstadoBase::Activo->value,
        ];

        if ($idSucursal !== null) {
            $sql .= ' AND ru.id_sucursal = :id_sucursal';
            $params['id_sucursal'] = $idSucursal;
        }

        if ($idProveedor !== null) {
            $sql .= ' AND lm.id_proveedor_minero = :id_proveedor';
            $params['id_proveedor'] = $idProveedor;
        }

        $sql .= ' ORDER BY lm.correlativo ASC;';

        $rows = DB::select($sql, $params);

        foreach ($rows as $row) {
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->en_guia = (int) $row->en_guia > 0;
        }

        return response()->json([
            'success' => true,
            'message' => 'Lotes de mineral disponibles obtenidos correctamente',
            'data' => $rows,
        ]);
    }

    /**
     * Obtener listado de proveedores con lotes comercializables sin valorizar
     */
    public function get_proveedores_valorizacion(): JsonResponse
    {
        return response()->json(ValorizacionCompraAuxService::get_proveedores_con_lotes());
    }

    /**
     * Obtener concesiones de un proveedor
     */
    public function get_concesiones_proveedor(Request $request): JsonResponse
    {
        $idProveedor = (int) $request->query('id_proveedor');
        if (! $idProveedor) {
            return response()->json([]);
        }

        return response()->json(ValorizacionCompraAuxService::get_concesiones_proveedor($idProveedor));
    }

    /**
     * Obtener cuentas bancarias de un proveedor
     */
    public function get_cuentas_bancarias_proveedor(Request $request): JsonResponse
    {
        $idProveedor = (int) $request->query('id_proveedor');
        if (! $idProveedor) {
            return response()->json([]);
        }

        return response()->json(ValorizacionCompraAuxService::get_cuentas_bancarias_proveedor($idProveedor));
    }

    /**
     * Obtener anticipos aprobados con saldo positivo de un proveedor
     */
    public function get_anticipos_proveedor(Request $request): JsonResponse
    {
        $idProveedor = (int) $request->query('id_proveedor');
        if (! $idProveedor) {
            return response()->json([]);
        }

        return response()->json(ValorizacionCompraAuxService::get_anticipos_proveedor($idProveedor));
    }

    /**
     * Obtener lotes de mineral comercializables disponibles para valorización
     */
    public function get_lotes_disponibles_valorizacion(Request $request): JsonResponse
    {
        $idProveedor = (int) $request->query('id_proveedor');
        if (! $idProveedor) {
            return response()->json([]);
        }

        $idValorizacion = $request->query('id_valorizacion') ? (int) $request->query('id_valorizacion') : null;

        return response()->json(ValorizacionCompraAuxService::get_lotes_disponibles_valorizacion($idProveedor, $idValorizacion));
    }

    /**
     * Listar tipos de cambio. Filtros opcionales: fecha (YYYY-MM-DD) y estado.
     */
    public function get_tipos_cambio(Request $request): JsonResponse
    {
        $fecha = $request->query('fecha');
        $estadoVal = $request->query('estado');
        $estado = $estadoVal ? EstadoBase::from($estadoVal) : null;

        return response()->json(TipoCambioService::get_tipos_cambio($fecha, $estado));
    }

    /**
     * Obtener el tipo de cambio activo de una fecha exacta.
     */
    public function get_tipo_cambio_por_fecha(Request $request): JsonResponse
    {
        $fecha = (string) $request->query('fecha');
        if (! $fecha) {
            return response()->json(ApiResponse::error('Debe especificar el parámetro fecha.'));
        }

        return response()->json(TipoCambioService::get_tipo_cambio_por_fecha($fecha));
    }

    /**
     * Registrar un nuevo tipo de cambio (regla: 1 por fecha).
     */
    public function crear_tipo_cambio(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'valor_compra' => 'required|numeric|min:0.001',
            'valor_venta' => 'required|numeric|min:0.001',
            'fecha' => 'required|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()));
        }

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        return response()->json(TipoCambioService::crear_tipo_cambio(
            (int) $idEmpleado,
            (float) $request->input('valor_compra'),
            (float) $request->input('valor_venta'),
            (string) $request->input('fecha')
        ));
    }

    /**
     * Listar valorizaciones aprobadas de un proveedor (para registrar comprobantes).
     */
    public function get_valorizaciones_aprobadas_por_proveedor(Request $request): JsonResponse
    {
        $idProveedor = (int) $request->query('id_proveedor');
        if (! $idProveedor) {
            return response()->json(ApiResponse::success([], 'Debe especificar id_proveedor.'));
        }

        return response()->json(ValorizacionCompraAuxService::get_valorizaciones_aprobadas_por_proveedor($idProveedor));
    }

    /**
     * Listar cuentas bancarias de la empresa filtradas por moneda y opcionalmente por detracción.
     * Si es_para_detraccion=true solo trae cuentas en Soles del Banco de la Nación.
     */
    public function get_cuentas_bancarias_empresa_por_moneda(Request $request): JsonResponse
    {
        $moneda = (string) $request->query('moneda');
        $esParaDetraccion = filter_var($request->query('es_para_detraccion', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json(\App\Modules\CuentasBancariasEmpresa\Services\CuentasBancariasEmpresaService::get_cuentas_bancarias_por_moneda($moneda, $esParaDetraccion));
    }

    /**
     * Actualizar la capacidad (TN) de un vehiculo.
     */
    public function update_capacidad_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'capacidad' => 'required|numeric|min:0',
        ]);

        $vehiculo = DB::table('vehiculo')->where('id', $id)->first();
        if (! $vehiculo) {
            return response()->json(ApiResponse::error('No se encontró el vehículo.', 404));
        }

        DB::table('vehiculo')->where('id', $id)->update([
            'capacidad' => (float) $request->input('capacidad'),
        ]);

        $actualizado = DB::table('vehiculo')->where('id', $id)->first();

        return response()->json(ApiResponse::success([
            'id' => (int) $actualizado->id,
            'placa' => $actualizado->placa,
            'capacidad' => (float) $actualizado->capacidad,
        ], 'Capacidad del vehículo actualizada correctamente.'));
    }
}
