<?php

namespace App\Modules\ValorizacionCompra\Data;

use App\Models\ValorizacionCompra;

class ValorizacionCompraData
{
    /**
     * Base query para relaciones de ValorizacionCompra
     */
    private static function queryBase()
    {
        return ValorizacionCompra::query()->with([
            'proveedor:id,razon_social,ruc',
            'concesion:id,nombre,codigo_reinfo',
            'cuentaBancaria:id,numero_cuenta,moneda,id_banco',
            'cuentaBancaria.banco:id,nombre',
            'cuentaDetraccion:id,numero_cuenta,moneda,id_banco',
            'cuentaDetraccion.banco:id,nombre',
            'empleadoRegistro:id,nombre,apellido',
            'empleadoAprobacion:id,nombre,apellido',
            'empleadoAnulacion:id,nombre,apellido',
            'detalles',
            'detalles.loteGuia',
            'detalles.loteGuia.loteMineral:id,numero_correlativo,correlativo,ley_humedad,ley_oro,ley_plata,peso_neto',
            'detalles.loteGuia.guiaPrimerTramo:id,guia_remitente,guia_transportista,sin_guia_transportista,fecha_en_planta',
            'transaccionesAnticipo',
            'transaccionesAnticipo.anticipo:id,serie_factura,numero_factura,saldo_inicial,saldo_actual',
        ]);
    }

    /**
     * Formatear un modelo ValorizacionCompra a arreglo de respuesta
     */
    public static function format_valorizacion(ValorizacionCompra $item): array
    {
        $totalSubtotal = $item->detalles->sum('subtotal');
        $totalAnticipos = $item->transaccionesAnticipo->sum('monto_retirado');
        $montoTransferencia = max(0, $totalSubtotal - $totalAnticipos);

        $anio = $item->created_at ? $item->created_at->format('y') : date('y');
        $correlativoCalculado = $item->correlativo ?? "{$anio}-VAL-".str_pad((string) $item->numero_correlativo, 5, '0', STR_PAD_LEFT);

        return [
            'id' => $item->id,
            'numero_correlativo' => $item->numero_correlativo,
            'correlativo' => $correlativoCalculado,
            'id_proveedor_minero' => $item->id_proveedor_minero,
            'proveedor_nombre' => $item->proveedor ? $item->proveedor->razon_social : null,
            'proveedor_ruc' => $item->proveedor ? $item->proveedor->ruc : null,
            'id_concesion' => $item->id_concesion,
            'concesion_nombre' => $item->concesion ? $item->concesion->nombre : null,
            'id_cuenta_bancaria' => $item->id_cuenta_bancaria,
            'cuenta_bancaria_info' => $item->cuentaBancaria ? ($item->cuentaBancaria->banco ? $item->cuentaBancaria->banco->nombre : '').' - '.$item->cuentaBancaria->numero_cuenta : null,
            'id_cuenta_detraccion' => $item->id_cuenta_detraccion,
            'cuenta_detraccion_info' => $item->cuentaDetraccion ? $item->cuentaDetraccion->numero_cuenta : null,
            'tipo_pago' => $item->tipo_pago ? $item->tipo_pago->value : null,
            'estado' => $item->estado ? $item->estado->value : null,
            'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
            'fecha_hora_aprobacion' => $item->fecha_hora_aprobacion ? $item->fecha_hora_aprobacion->format('Y-m-d H:i:s') : null,
            'fecha_hora_anulacion' => $item->fecha_hora_anulacion ? ($item->fecha_hora_anulacion instanceof \DateTimeInterface ? $item->fecha_hora_anulacion->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', (int) $item->fecha_hora_anulacion)) : null,
            'id_empleado_anulacion' => $item->id_empleado_anulacion,
            'empleado_registro' => $item->empleadoRegistro ? trim($item->empleadoRegistro->nombre.' '.$item->empleadoRegistro->apellido) : null,
            'empleado_aprobacion' => $item->empleadoAprobacion ? trim($item->empleadoAprobacion->nombre.' '.$item->empleadoAprobacion->apellido) : null,
            'empleado_anulacion' => $item->empleadoAnulacion ? trim($item->empleadoAnulacion->nombre.' '.$item->empleadoAnulacion->apellido) : null,
            'motivo_anulacion' => $item->motivo_anulacion,
            'evidencias_anulacion' => isset($item->evidencias_anulacion) ? (is_array($item->evidencias_anulacion) ? $item->evidencias_anulacion : (json_decode((string) $item->evidencias_anulacion, true) ?? [])) : [],
            'total_subtotal' => round($totalSubtotal, 2),
            'total_anticipos' => round($totalAnticipos, 2),
            'monto_transferencia' => round($montoTransferencia, 2),
            'evidencias' => isset($item->evidencias) ? (is_array($item->evidencias) ? $item->evidencias : (json_decode((string) $item->evidencias, true) ?? [])) : [],
            'log_cambios' => $item->log_cambios ?? [],
            'detalles' => $item->detalles->map(function ($d) {
                $lg = $d->loteGuia;
                $lm = $lg ? $lg->loteMineral : null;
                $gpt = $lg ? $lg->guiaPrimerTramo : null;

                $tmh = $lg && $lg->peso_neto !== null ? (float) $lg->peso_neto : ($lm ? (float) $lm->peso_neto : 0);
                $leyHumedad = $lm ? (float) $lm->ley_humedad : 0;
                $tms = round($tmh * (1 - ($leyHumedad / 100)), 4);

                return [
                    'id' => $d->id,
                    'id_valorizacion_compra' => $d->id_valorizacion_compra,
                    'id_lote_guia' => $d->id_lote_guia,
                    'id_condicion_comercial' => $d->id_condicion_comercial,
                    'elemento_quimico' => $d->elemento_quimico ? $d->elemento_quimico->value : null,
                    'codigo_gel' => $lm ? $lm->numero_correlativo : null,
                    'lote_correlativo' => $lm ? $lm->correlativo : null,
                    'grr' => $gpt ? $gpt->guia_remitente : null,
                    'grt' => $gpt && ! $gpt->sin_guia_transportista ? $gpt->guia_transportista : null,
                    'fecha_ingreso' => $gpt ? ($gpt->fecha_en_planta ? $gpt->fecha_en_planta->format('Y-m-d') : null) : null,
                    'tmh' => $tmh,
                    'ley_humedad' => $leyHumedad,
                    'tms' => $tms,
                    'ley' => $lm ? (float) ($d->elemento_quimico && $d->elemento_quimico->value === 'Oro' ? $lm->ley_oro : $lm->ley_plata) : 0,
                    'inter' => (float) $d->inter,
                    'des_inter' => (float) $d->des_inter,
                    'recuperacion' => (float) $d->recuperacion,
                    'maquila' => (float) $d->maquila,
                    'consumo' => (float) $d->consumo,
                    'factor' => (float) $d->factor,
                    'precio_por_tonelada' => (float) $d->precio_por_tonelada,
                    'subtotal' => (float) $d->subtotal,
                    'log_cambios' => $d->log_cambios ?? [],
                ];
            })->toArray(),
            'transacciones_anticipo' => $item->transaccionesAnticipo->map(function ($t) {
                $ant = $t->anticipo;

                return [
                    'id' => $t->id,
                    'id_anticipo_proveedor' => $t->id_anticipo_proveedor,
                    'id_valorizacion_compra' => $t->id_valorizacion_compra,
                    'factura' => $ant ? ($ant->serie_factura.'-'.$ant->numero_factura) : null,
                    'monto_retirado' => (float) $t->monto_retirado,
                    'saldo_actual' => (float) $t->saldo_actual,
                    'estado' => $t->estado ? $t->estado->value : null,
                    'created_at' => $t->created_at ? $t->created_at->format('Y-m-d H:i:s') : null,
                    'log_cambios' => $t->log_cambios ?? [],
                ];
            })->toArray(),
        ];
    }

    /**
     * Obtener listado de valorizaciones
     */
    public static function get_valorizaciones(?int $idProveedor = null): array
    {
        $query = self::queryBase()->orderBy('id', 'desc');

        if ($idProveedor !== null && $idProveedor > 0) {
            $query->where('id_proveedor_minero', $idProveedor);
        }

        return $query->get()->map(fn (ValorizacionCompra $item) => self::format_valorizacion($item))->toArray();
    }

    /**
     * Obtener valorización individual atómicamente por ID
     */
    public static function get_valorizacion_by_id(int $id): ?array
    {
        $item = self::queryBase()->where('id', $id)->first();
        if (! $item) {
            return null;
        }

        return self::format_valorizacion($item);
    }

    /**
     * Buscar modelo ValorizacionCompra por ID
     */
    public static function find_model(int $id): ?ValorizacionCompra
    {
        return ValorizacionCompra::find($id);
    }

    /**
     * Obtener nombre o etiqueta de concesión
     */
    public static function get_nombre_concesion(int $idConcesion): string
    {
        $c = \Illuminate\Support\Facades\DB::table('concesion')->where('id', $idConcesion)->first();

        return $c ? $c->nombre : "ID #{$idConcesion}";
    }

    /**
     * Obtener etiqueta descriptiva de cuenta bancaria o de detracción
     */
    public static function get_etiqueta_cuenta(?int $idCuenta): string
    {
        if (! $idCuenta) {
            return '—';
        }
        $c = \Illuminate\Support\Facades\DB::table('cuenta_bancaria_proveedor as cb')
            ->leftJoin('banco as b', 'b.id', '=', 'cb.id_banco')
            ->where('cb.id', $idCuenta)
            ->select('cb.numero_cuenta', 'b.nombre as banco_nombre', 'b.abreviatura as banco_abrev')
            ->first();
        if (! $c) {
            return "ID #{$idCuenta}";
        }
        $bancoStr = $c->banco_abrev ?: ($c->banco_nombre ?: '');

        return ! empty($bancoStr) ? "{$bancoStr} - {$c->numero_cuenta}" : $c->numero_cuenta;
    }

    /**
     * Buscar lote guía con su relación de lote mineral
     */
    public static function find_lote_guia_con_mineral(int $idLoteGuia): ?\App\Models\LoteGuia
    {
        return \App\Models\LoteGuia::with('loteMineral')->find($idLoteGuia);
    }

    /**
     * Buscar anticipo de proveedor por ID
     */
    public static function find_anticipo_proveedor(int $idAnticipo): ?\App\Models\AnticipoProveedor
    {
        return \App\Models\AnticipoProveedor::find($idAnticipo);
    }

    /**
     * Obtener detalles de una valorización por ID
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ValorizacionCompraDetalle>
     */
    public static function get_detalles_by_valorizacion(int $idValorizacion)
    {
        return \App\Models\ValorizacionCompraDetalle::where('id_valorizacion_compra', $idValorizacion)->get();
    }

    /**
     * Obtener transacciones de anticipo de una valorización por ID
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\TransaccionAnticipoProveedor>
     */
    public static function get_transacciones_by_valorizacion(int $idValorizacion)
    {
        return \App\Models\TransaccionAnticipoProveedor::where('id_valorizacion_compra', $idValorizacion)->get();
    }

    /**
     * Eliminar detalles de una valorización por ID
     */
    public static function delete_detalles_by_valorizacion(int $idValorizacion): int
    {
        return \App\Models\ValorizacionCompraDetalle::where('id_valorizacion_compra', $idValorizacion)->delete();
    }

    /**
     * Eliminar transacciones de anticipo de una valorización por ID
     */
    public static function delete_transacciones_by_valorizacion(int $idValorizacion): int
    {
        return \App\Models\TransaccionAnticipoProveedor::where('id_valorizacion_compra', $idValorizacion)->delete();
    }

    /**
     * Anular una transacción de anticipo atómicamente con registro de auditoría
     */
    public static function anular_transaccion_anticipo(
        \App\Models\TransaccionAnticipoProveedor $transaccion,
        int $idEmpleadoAnulacion,
        string $codigoValorizacion
    ): void {
        $estadoAnteriorTrans = $transaccion->estado->value ?? '—';
        $logTrans = $transaccion->log_cambios ?? [];
        if (! is_array($logTrans)) {
            $logTrans = json_decode((string) $logTrans, true) ?? [];
        }
        $logTrans[] = [
            'id_empleado' => $idEmpleadoAnulacion,
            'fecha_hora' => now()->toDateTimeString(),
            'update_at' => now()->toIso8601String(),
            'accion' => 'Anulación de Transacción de Anticipo',
            'motivo' => "Transacción anulada por anulación de Valorización {$codigoValorizacion}",
            'cambios' => [
                [
                    'campo_bd' => 'estado',
                    'campo' => 'Estado Transacción',
                    'valor_anterior' => $estadoAnteriorTrans,
                    'valor_nuevo' => \App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo::Anulado->value,
                ],
            ],
        ];

        $transaccion->update([
            'estado' => \App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo::Anulado->value,
            'log_cambios' => $logTrans,
        ]);
    }

    /**
     * Eliminar un modelo ValorizacionCompra
     */
    public static function delete_model(ValorizacionCompra $valorizacion): ?bool
    {
        return $valorizacion->delete();
    }
}
