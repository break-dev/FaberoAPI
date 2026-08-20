<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuiaPrimerTramo extends Model
{
    protected $table = 'guia_primer_tramo';

    public $timestamps = false;

    protected $fillable = [
        'id_sucursal',
        'id_proveedor',
        'id_concesion',
        'id_conductor',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_vehiculo_carreta',
        'id_empresa_transporte_carreta',
        'motivo_traslado',
        'condicion_ingreso',
        'fecha_inicio_traslado',
        'fecha_emision',
        'fecha_en_planta',
        'guia_remitente',
        'guia_transportista',
        'sin_guia_transportista',
        'documentos',
        'id_empleado_registro',
        'log_cambios',
        'created_at',
    ];

    protected $casts = [
        'documentos' => 'array',
        'fecha_inicio_traslado' => 'datetime',
        'fecha_emision' => 'datetime',
        'fecha_en_planta' => 'datetime',
        'sin_guia_transportista' => 'boolean',
        'id_empleado_registro' => 'integer',
        'log_cambios' => 'array',
    ];
}
