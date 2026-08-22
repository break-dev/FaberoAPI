<?php

namespace App\Shared\Enums\ProgramacionDespachos;

enum EstadoDistribucion: string
{
    case EnEspera = 'En Espera';
    case EnPlanta = 'En Planta';
    case SalioDePlanta = 'Salió de Planta';
    case LlegoAlCliente = 'Llegó al Cliente';
}