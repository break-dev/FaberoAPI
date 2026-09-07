<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlendingDetalle extends Model
{
    protected $table = 'blending_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_blending',
        'id_lote_mineral',
        'id_reblending',
        'peso_actual',
        'peso_tomado',
        'created_at',
    ];

    protected $casts = [
        'id_blending' => 'integer',
        'id_lote_mineral' => 'integer',
        'id_reblending' => 'integer',
        'peso_actual' => 'float',
        'peso_tomado' => 'float',
        'created_at' => 'datetime',
    ];

    public function blending(): BelongsTo
    {
        return $this->belongsTo(Blending::class, 'id_blending');
    }

    public function loteMineral(): BelongsTo
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function reblending(): BelongsTo
    {
        return $this->belongsTo(Blending::class, 'id_reblending');
    }
}
