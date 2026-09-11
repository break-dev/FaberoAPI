<?php

namespace App\Shared\Enums\_Generic;

enum CondicionIngreso: string
{
    case Comercializacion = 'Comercializacion';
    case Chancado = 'Chancado';
    case Almacen = 'Almacen';
    case OtrosServicios = 'OtrosServicios';
}
