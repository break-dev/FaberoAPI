<?php

namespace App\Models;

use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValorizacionCompraDetalle extends Model
{
    protected $table = 'valorizacion_compramineral_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_valorizacion_compra',
        'id_lote_guia',
        'id_condicion_comercial',
        'id_valor_elemento_quimico',
        'elemento_quimico',
        'inter',
        'des_inter',
        'recuperacion',
        'maquila',
        'consumo',
        'factor',
        'precio_por_tonelada',
        'subtotal',
        'log_cambios',
    ];

    protected $casts = [
        'id_valorizacion_compra' => 'integer',
        'id_lote_guia' => 'integer',
        'id_condicion_comercial' => 'integer',
        'id_valor_elemento_quimico' => 'integer',
        'inter' => 'float',
        'des_inter' => 'float',
        'recuperacion' => 'float',
        'maquila' => 'float',
        'consumo' => 'float',
        'factor' => 'float',
        'precio_por_tonelada' => 'float',
        'subtotal' => 'float',
        'log_cambios' => 'array',
        'elemento_quimico' => ElementoQuimicoValorizacion::class,
    ];

    public function valorizacion(): BelongsTo
    {
        return $this->belongsTo(ValorizacionCompra::class, 'id_valorizacion_compra');
    }

    public function loteGuia(): BelongsTo
    {
        return $this->belongsTo(LoteGuia::class, 'id_lote_guia');
    }

    public function condicionComercial(): BelongsTo
    {
        return $this->belongsTo(CondicionComercialProveedor::class, 'id_condicion_comercial');
    }
}
