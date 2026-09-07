<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blending extends Model
{
    protected $table = 'blending';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'id_empresa',
        'correlativo',
        'numero_correlativo',
        'fecha_hora_blending',
        'evidencias',
        'observacion',
        'peso_neto',
        'peso_actual',
        'ley_oro',
        'ley_plata',
        'ley_humedad',
        'log_cambios',
        'created_at',
    ];

    protected $casts = [
        'id_empleado_registro' => 'integer',
        'id_empresa' => 'integer',
        'fecha_hora_blending' => 'datetime',
        'evidencias' => 'array',
        'peso_neto' => 'float',
        'peso_actual' => 'float',
        'ley_oro' => 'float',
        'ley_plata' => 'float',
        'ley_humedad' => 'float',
        'log_cambios' => 'array',
        'created_at' => 'datetime',
    ];

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(BlendingDetalle::class, 'id_blending');
    }
}
