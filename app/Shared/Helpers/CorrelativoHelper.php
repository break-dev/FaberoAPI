<?php

namespace App\Shared\Helpers;

use App\Shared\Enums\_Generic\Periodo;
use Closure;
use Illuminate\Support\Facades\DB;

class CorrelativoHelper
{
    public static function generar(
        string $tabla,
        string $prefijo,
        array $filtros = [],
        int $longitudCeros = 5,
        Periodo $reseteo = Periodo::Anual,
        string $columnaFecha = 'created_at',
        ?Closure $queryModifier = null,
        ?string $alias = null,
        ?string $formatoFecha = null,
        bool $incluirPrefijo = true
    ): array {
        // Configuramos la tabla principal con su alias en el constructor de la consulta
        $tablaQuery = $alias ? "{$tabla} as {$alias}" : $tabla;
        $query = DB::table($tablaQuery);

        // Definimos qué prefijo usar para las columnas
        $prefijoTabla = $alias ?? $tabla;

        // 1. Aplicamos los JOINs si el closure fue proporcionado
        if ($queryModifier) {
            $queryModifier($query);
        }

        $now = now();

        $columnaFechaCompleta = str_contains($columnaFecha, '.') ? $columnaFecha : "{$prefijoTabla}.{$columnaFecha}";

        // 2. Filtro por periodo
        match ($reseteo) {
            Periodo::Diario => $query->whereDate($columnaFechaCompleta, $now->toDateString()),
            Periodo::Semanal => $query->whereBetween($columnaFechaCompleta, [
                $now->startOfWeek()->startOfDay(),
                $now->endOfWeek()->endOfDay(),
            ]),
            Periodo::Mensual => $query
                ->whereYear($columnaFechaCompleta, $now->year)
                ->whereMonth($columnaFechaCompleta, $now->month),
            Periodo::Anual => $query->whereYear($columnaFechaCompleta, $now->year),
            Periodo::Ninguno => null,
        };

        // 3. Otros filtros
        foreach ($filtros as $col => $valor) {

            $columnaFiltro = str_contains($col, '.')
                ? $col
                : "{$prefijoTabla}.{$col}";

            if (! is_array($valor)) {
                $query->where($columnaFiltro, $valor);

                continue;
            }

            [$operador, $dato] = $valor;

            switch (strtolower($operador)) {

                case 'in':
                    $query->whereIn($columnaFiltro, $dato);
                    break;

                case 'not in':
                    $query->whereNotIn($columnaFiltro, $dato);
                    break;

                case 'null':
                    $query->whereNull($columnaFiltro);
                    break;

                case 'not null':
                    $query->whereNotNull($columnaFiltro);
                    break;

                default:
                    $query->where($columnaFiltro, $operador, $dato);
                    break;
            }
        }

        // 4. Obtenemos el máximo usando el prefijo correcto
        $siguienteNumero = ($query->max("{$prefijoTabla}.numero_correlativo") ?? 0) + 1;

        $numeroFormateado = str_pad($siguienteNumero, $longitudCeros, '0', STR_PAD_LEFT);

        $segmentoFecha = match ($reseteo) {
            Periodo::Diario => $formatoFecha !== null ? $now->format($formatoFecha) : $now->format('d').'-'.$now->format('m').'-'.$now->format('y'),
            Periodo::Semanal => $now->weekOfMonth.'-'.$now->format('m').'-'.$now->format('y'),
            Periodo::Mensual => $now->format('m').'-'.$now->format('y'),
            Periodo::Anual => $now->format('y'),
            Periodo::Ninguno => null,
        };

        $correlativo = match (true) {
            $segmentoFecha === null && $incluirPrefijo => "{$numeroFormateado}-{$prefijo}",
            $segmentoFecha === null                    => $numeroFormateado,
            $incluirPrefijo                            => "{$segmentoFecha}-{$prefijo}-{$numeroFormateado}",
            default                                    => "{$segmentoFecha}-{$numeroFormateado}",
        };

        return [
            'correlativo' => $correlativo,
            'numero_correlativo' => $siguienteNumero,
        ];
    }
}
