<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de precio INTER (oro/plata) por fecha y elemento.
 * Usado por el modulo ValorizacionCompra para valorizar lotes.
 */
class ValorElementoQuimico extends Model
{
    protected $table = 'valor_elemento_quimico';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'elemento_quimico',
        'inter',
        'fecha',
        'created_at',
    ];

    protected $casts = [
        'id_empleado_registro' => 'integer',
        'inter' => 'float',
        'fecha' => 'date',
        'created_at' => 'datetime',
    ];

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }
}
