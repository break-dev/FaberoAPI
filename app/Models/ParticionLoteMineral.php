<?php

namespace App\Models;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Database\Eloquent\Model;

class ParticionLoteMineral extends Model
{
    protected $table = 'particion_lote_mineral';

    public $timestamps = false;

    protected $fillable = [
        'id_lote_mineral',
        'id_ticket_balanza',
        'id_recepcion_unidad',
        'correlativo',
        'particion',
        'peso_inicial',
        'fecha_hora_peso_inicial',
        'peso_final',
        'fecha_hora_peso_final',
        'peso_neto',
        'estado',
        'es_bloqueado',
    ];

    protected $casts = [
        'peso_inicial' => 'float',
        'peso_final' => 'float',
        'peso_neto' => 'float',
        'estado' => EstadoBase::class,
        'es_bloqueado' => 'boolean',
        'esta_validado' => 'boolean',
    ];

    public function loteMineral()
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function ticketBalanza()
    {
        return $this->belongsTo(TicketBalanza::class, 'id_ticket_balanza');
    }

    public function recepcionUnidad()
    {
        return $this->belongsTo(RecepcionUnidad::class, 'id_recepcion_unidad');
    }
}
