<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoteGuia extends Model
{
    protected $table = 'lote_guia';

    public $timestamps = false;

    protected $fillable = [
        'id_guia_primer_tramo',
        'id_lote_mineral',
        'id_particion_lote_mineral',
    ];

    protected $casts = [
        'id_guia_primer_tramo' => 'integer',
        'id_lote_mineral' => 'integer',
        'id_particion_lote_mineral' => 'integer',
    ];

    public function loteMineral(): BelongsTo
    {
        return $this->belongsTo(LoteMineral::class, 'id_lote_mineral');
    }

    public function guiaPrimerTramo(): BelongsTo
    {
        return $this->belongsTo(GuiaPrimerTramo::class, 'id_guia_primer_tramo');
    }
}
