<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\GastoFijo;
use Illuminate\Support\Collection;

/**
 * Reparte las ventas de una caja entre los negocios (burger, parrilla,
 * general) para ver cuánto deja cada uno.
 *
 * - Ventas y costo salen de cada línea vendida, según el negocio que tenía al
 *   momento de la venta. El descuento general del pedido se reparte en
 *   proporción a lo que pidió de cada negocio.
 * - Los gastos fijos de un negocio se los lleva ese negocio. Los compartidos
 *   (el alquiler) se dividen en partes iguales entre burger y parrilla, pero
 *   solo entre los que vendieron ese día: si la parrilla no trabajó, la burger
 *   paga todo.
 * - General (bebidas) no carga gastos fijos.
 *
 * La suma de los negocios siempre da el total de la caja.
 */
class NegocioService
{
    /**
     * @param  Collection  $ventas  ventas con sus detalles cargados
     * @param  float|null  $gastosTotal  gastos fijos guardados de una caja vieja;
     *                                   si viene, se reparte ese monto con las
     *                                   mismas proporciones en vez del actual
     * @return array<string, array<string, float|int>>
     */
    public static function desglose(Collection $ventas, ?float $gastosTotal = null): array
    {
        $resultado = collect(Categoria::NEGOCIOS)->mapWithKeys(fn (string $n) => [$n => [
            'ventas' => 0.0,
            'costo_insumos' => 0.0,
            'unidades' => 0,
        ]])->all();

        foreach ($ventas->where('estado', '!=', 'anulado') as $venta) {
            $bruto = (float) $venta->detalles->sum('subtotal');
            $factor = $bruto > 0 ? (float) $venta->total / $bruto : 0.0;

            foreach ($venta->detalles as $detalle) {
                $negocio = isset($resultado[$detalle->negocio]) ? $detalle->negocio : 'burger';

                $resultado[$negocio]['ventas'] += (float) $detalle->subtotal * $factor;
                $resultado[$negocio]['costo_insumos'] += (float) $detalle->costo_snapshot * $detalle->cantidad;
                $resultado[$negocio]['unidades'] += (int) $detalle->cantidad;
            }
        }

        $gastos = self::repartirGastos(
            vendieron: collect(['burger', 'parrilla'])->filter(fn ($n) => $resultado[$n]['unidades'] > 0)->values()->all(),
            gastosTotal: $gastosTotal,
        );

        foreach ($resultado as $negocio => $fila) {
            $bruta = $fila['ventas'] - $fila['costo_insumos'];
            $gasto = $gastos[$negocio] ?? 0.0;

            $resultado[$negocio] = [
                'ventas' => round($fila['ventas'], 2),
                'costo_insumos' => round($fila['costo_insumos'], 2),
                'ganancia_bruta' => round($bruta, 2),
                'gastos_fijos' => round($gasto, 2),
                'ganancia_neta' => round($bruta - $gasto, 2),
                'unidades' => $fila['unidades'],
            ];
        }

        return $resultado;
    }

    /**
     * @param  list<string>  $vendieron  negocios (burger/parrilla) con ventas en la caja
     * @return array<string, float>
     */
    private static function repartirGastos(array $vendieron, ?float $gastosTotal): array
    {
        $reparto = ['burger' => 0.0, 'parrilla' => 0.0, 'general' => 0.0];
        $entre = $vendieron ?: ['burger'];

        foreach (GastoFijo::where('activo', true)->get() as $gasto) {
            $diario = (float) $gasto->costo_diario;

            if ($gasto->negocio === 'compartido') {
                foreach ($entre as $negocio) {
                    $reparto[$negocio] += $diario / count($entre);
                }
            } else {
                $reparto[$gasto->negocio] = ($reparto[$gasto->negocio] ?? 0) + $diario;
            }
        }

        if ($gastosTotal !== null) {
            $actual = array_sum($reparto);

            $reparto = $actual > 0
                ? array_map(fn ($v) => $v * $gastosTotal / $actual, $reparto)
                : ['burger' => $gastosTotal, 'parrilla' => 0.0, 'general' => 0.0];
        }

        return $reparto;
    }
}
