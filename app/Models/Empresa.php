<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para la tabla empresa.
 */
class Empresa extends Model
{
    protected $table = 'empresa';

    public $timestamps = false;

    protected $fillable = [
        'ruc',
        'razon_social',
        'nombre_comercial',
        'prefijo',
        'path_logo',
    ];
}
