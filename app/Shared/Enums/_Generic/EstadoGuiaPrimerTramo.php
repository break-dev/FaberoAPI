<?php

namespace App\Shared\Enums\_Generic;

/**
 * Estados posibles de una `guia_primer_tramo.estado`.
 *
 * Aunque el campo en BD es string libre (sin CHECK constraint), se estandariza
 * con este enum para evitar valores magicos en queries (`'Activo'`, `'Anulado'`,
 * etc.). El servicio de GuiasPrimerTramo debe poblar el campo con
 * `::Activo->value` al crear y `::Anulado->value` al anular.
 */
enum EstadoGuiaPrimerTramo: string
{
    case Activo = 'Activo';
    case Inactivo = 'Inactivo';
    case Anulado = 'Anulado';
}
