<?php

namespace App\Services;

use App\Models\Categoria;
use Illuminate\Support\Facades\DB;

/**
 * Recupero de lo invertido en un rubro (ej: Bebidas): cuánta ganancia
 * (precio − costo, ambos snapshot de la venta) generaron sus productos desde
 * la primera inversión. Las ventas anuladas no cuentan.
 */
class InversionService
{
    public function recupero(Categoria $categoria): array
    {
        $invertido = (float) $categoria->inversiones()->sum('monto');
        $desde = $categoria->inversiones()->min('fecha');

        $fila = null;

        if ($desde !== null) {
            $fila = DB::table('detalle_ventas')
                ->join('ventas', 'ventas.id', '=', 'detalle_ventas.venta_id')
                ->join('cajas', 'cajas.id', '=', 'ventas.caja_id')
                ->join('variantes', 'variantes.id', '=', 'detalle_ventas.variante_id')
                ->join('productos', 'productos.id', '=', 'variantes.producto_id')
                ->where('productos.categoria_id', $categoria->id)
                ->where('ventas.estado', '!=', 'anulado')
                ->whereDate('cajas.fecha_operativa', '>=', $desde)
                ->selectRaw('
                    COALESCE(SUM((detalle_ventas.precio_snapshot - detalle_ventas.costo_snapshot) * detalle_ventas.cantidad), 0) as ganancia,
                    COALESCE(SUM(detalle_ventas.subtotal), 0) as ventas,
                    COALESCE(SUM(detalle_ventas.cantidad), 0) as unidades
                ')
                ->first();
        }

        $ganancia = round((float) ($fila->ganancia ?? 0), 2);

        return [
            'invertido' => $invertido,
            'desde' => $desde ? substr((string) $desde, 0, 10) : null,
            'ganancia' => $ganancia,
            'ventas' => round((float) ($fila->ventas ?? 0), 2),
            'unidades' => (int) ($fila->unidades ?? 0),
            'falta' => max(0, round($invertido - $ganancia, 2)),
            'pct' => $invertido > 0 ? min(100, round($ganancia / $invertido * 100, 1)) : 0,
        ];
    }
}
