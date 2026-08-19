<?php

namespace App\Shared\Enums\_Generic;

enum EstadoBase: string
{
    case Activo = 'Activo';
    case Inactivo = 'Inactivo';
    case Eliminado = 'Eliminado';
}
