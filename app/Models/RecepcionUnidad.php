<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa la recepción de unidades.
 */
class RecepcionUnidad extends Model
{
    protected $table = 'recepcion_unidad';

    public $timestamps = false;

    protected $fillable = [
        'id_distribucion',
        'id_empleado_recepcion',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_tipo_vehiculo',
        'id_conductor',
        'tipo_ingreso',
        'segunda_placa',
        'fecha_hora_ingreso',
        'evidencias',
        'observacion',
        'estado',
        'estado_salida',
        'fecha_hora_salida',
        'observacion_salida',
        'id_sucursal',
        'fecha_hora_inicio_pesaje',
        'fecha_hora_final_pesaje',
        'estado_pesaje',
        'id_proveedor_minero',
        'id_empleado_autoriza',
        'es_programacion',
        'fecha_estimada_llegada',
        'guia_remitente',
        'guia_transportista',
        'es_recepcion_ficticia',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'es_recepcion_ficticia' => 'boolean',
    ];
}
