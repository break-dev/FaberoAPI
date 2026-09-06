<?php

namespace App\Models;

use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoLeyes;
use Illuminate\Database\Eloquent\Model;

class LoteMineral extends Model
{
    protected $table = 'lote_mineral';

    public $timestamps = false;

    protected $fillable = [
        'id_recepcion_unidad',
        'id_empresa',
        'id_proveedor_minero',
        'id_empleado_registro',
        'id_zona_origen',
        'correlativo',
        'numero_correlativo',
        'con_codigo_manual',
        'numero_contacto',
        'tipo_producto',
        'tipo_mineral',
        'evidencias',
        'peso_inicial',
        'fecha_hora_peso_inicial',
        'peso_final',
        'fecha_hora_peso_final',
        'peso_neto',
        'peso_actual',
        'peso_inicial_oficial',
        'peso_final_oficial',
        'peso_neto_oficial',
        'tiene_particion',
        'estado',
        'condicion_ingreso',
        'log_cambios',
        'created_at',
        'id_empleado_inicio_analisis',
        'id_empleado_confirmacion_analisis',
        'id_ticket_balanza',
        'ley_oro',
        'ley_plata',
        'ley_humedad',
        'ley_recuperacion',
        'estado_leyes',
        'con_valor_comercial',
        'fecha_hora_inicio_analisis',
        'fecha_hora_confirmacion_analisis',
        'esta_valorizado_oro',
        'esta_valorizado_plata',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'peso_inicial' => 'float',
        'peso_final' => 'float',
        'peso_neto' => 'float',
        'peso_actual' => 'float',
        'peso_inicial_oficial' => 'float',
        'peso_final_oficial' => 'float',
        'peso_neto_oficial' => 'float',
        'con_codigo_manual' => 'boolean',
        'tiene_particion' => 'boolean',
        'log_cambios' => 'array',
        'estado' => EstadoBase::class,
        'estado_leyes' => EstadoLeyes::class,
        'ley_oro' => 'float',
        'ley_plata' => 'float',
        'ley_humedad' => 'float',
        'ley_recuperacion' => 'float',
        'con_valor_comercial' => 'boolean',
        'esta_valorizado_oro' => 'boolean',
        'esta_valorizado_plata' => 'boolean',
    ];
}
