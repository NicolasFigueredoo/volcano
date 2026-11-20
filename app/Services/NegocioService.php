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
            vendieron: self::vendieron($resultado),
            gastosTotal: $gastosTotal,
        );

        foreach ($resultado as $negocio => $fila) {
            $bruta = $fila['ventas'] - $fila['costo_insumos'];
            $gasto = $gastos['totales'][$negocio] ?? 0.0;

            $resultado[$negocio] = [
                'ventas' => round($fila['ventas'], 2),
                'costo_insumos' => round($fila['costo_insumos'], 2),
                'ganancia_bruta' => round($bruta, 2),
                'gastos_fijos' => round($gasto, 2),
                'ganancia_neta' => round($bruta - $gasto, 2),
                'unidades' => $fila['unidades'],
                'gastos_detalle' => $gastos['detalle'][$negocio] ?? [],
            ];
        }

        return $resultado;
    }

    /**
     * Le agrega el detalle de gastos fijos a un desglose guardado de una caja
     * vieja que no lo tenía. Usa los gastos actuales, escalados para que cada
     * negocio sume lo mismo que se guardó.
     *
     * @param  array<string, array<string, mixed>>  $porNegocio
     * @return array<string, array<string, mixed>>
     */
    public static function completarDetalle(array $porNegocio): array
    {
        $gastos = self::repartirGastos(self::vendieron($porNegocio), null);

        foreach ($porNegocio as $negocio => $fila) {
            if (isset($fila['gastos_detalle'])) {
                continue;
            }

            $guardado = (float) ($fila['gastos_fijos'] ?? 0);
            $actual = (float) ($gastos['totales'][$negocio] ?? 0);
            $factor = $actual > 0 ? $guardado / $actual : 0.0;

            $porNegocio[$negocio]['gastos_detalle'] = array_map(
                fn ($g) => [...$g, 'monto' => round($g['monto'] * $factor, 2)],
                $factor > 0 ? ($gastos['detalle'][$negocio] ?? []) : [],
            );
        }

        return $porNegocio;
    }

    /** @return list<string> */
    private static function vendieron(array $filas): array
    {
        return collect(['burger', 'parrilla'])
            ->filter(fn ($n) => ($filas[$n]['unidades'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $vendieron  negocios (burger/parrilla) con ventas en la caja
     * @return array{totales: array<string, float>, detalle: array<string, list<array{nombre: string, monto: float, compartido: bool}>>}
     */
    private static function repartirGastos(array $vendieron, ?float $gastosTotal): array
    {
        $reparto = ['burger' => 0.0, 'parrilla' => 0.0, 'general' => 0.0];
        $detalle = ['burger' => [], 'parrilla' => [], 'general' => []];
        $entre = $vendieron ?: ['burger'];

        $agregar = function (string $negocio, string $nombre, float $monto, bool $compartido) use (&$reparto, &$detalle) {
            $reparto[$negocio] = ($reparto[$negocio] ?? 0) + $monto;
            $detalle[$negocio][] = ['nombre' => $nombre, 'monto' => $monto, 'compartido' => $compartido];
        };

        foreach (GastoFijo::where('activo', true)->orderBy('nombre')->get() as $gasto) {
            $diario = (float) $gasto->costo_diario;

            if ($gasto->negocio === 'compartido') {
                foreach ($entre as $negocio) {
                    $agregar($negocio, $gasto->nombre, $diario / count($entre), true);
                }
            } else {
                $agregar($gasto->negocio, $gasto->nombre, $diario, false);
            }
        }

        if ($gastosTotal !== null) {
            $actual = array_sum($reparto);

            if ($actual > 0) {
                $factor = $gastosTotal / $actual;
                $reparto = array_map(fn ($v) => $v * $factor, $reparto);
                $detalle = array_map(fn ($items) => array_map(fn ($g) => [...$g, 'monto' => $g['monto'] * $factor], $items), $detalle);
            } else {
                $reparto = ['burger' => $gastosTotal, 'parrilla' => 0.0, 'general' => 0.0];
                $detalle = ['burger' => [], 'parrilla' => [], 'general' => []];
            }
        }

        $detalle = array_map(fn ($items) => array_map(fn ($g) => [...$g, 'monto' => round($g['monto'], 2)], $items), $detalle);

        return ['totales' => $reparto, 'detalle' => $detalle];
    }
}
