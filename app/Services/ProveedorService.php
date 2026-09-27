<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\CajaSeparacion;
use App\Models\Insumo;
use App\Models\MovimientoStock;
use App\Models\Proveedor;
use App\Models\ProveedorMovimiento;
use App\Models\Receta;
use App\Models\Variante;
use App\Models\VentaInsumo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProveedorService
{
    /**
     * Estado de la cuenta corriente de un proveedor.
     *
     * - deuda: saldo_inicial + entregas + ajustes − pagos
     * - vendido_sin_pagar: consumo desde el corte + parte vendida del saldo
     *   inicial − pagos (mínimo 0)
     * - en_stock_sin_pagar: deuda − vendido_sin_pagar
     * - separado: separaciones − pagos
     * - falta_separar: vendido_sin_pagar − separado
     *
     * @return array<string, mixed>
     */
    public static function estado(Proveedor $proveedor, ?Caja $cajaHoy = null): array
    {
        $sumas = $proveedor->movimientos()
            ->selectRaw('tipo, SUM(monto) as monto, SUM(COALESCE(monto_vendido, 0)) as vendido')
            ->groupBy('tipo')
            ->get()
            ->keyBy('tipo');

        $suma = fn (string $tipo) => (float) ($sumas->get($tipo)->monto ?? 0);

        $pagos = $suma('pago');
        $deuda = $suma('saldo_inicial') + $suma('entrega') + $suma('ajuste') - $pagos;

        $insumoIds = $proveedor->insumos()->pluck('id');

        $consumo = (float) VentaInsumo::desdeCorte()
            ->whereIn('venta_insumos.insumo_id', $insumoIds)
            ->sum('venta_insumos.subtotal');

        $vendidoInicial = (float) ($sumas->get('saldo_inicial')->vendido ?? 0);
        $vendidoSinPagar = max(0, $consumo + $vendidoInicial - $pagos);
        $separado = $suma('separacion') - $pagos;

        $ultimoPago = $proveedor->movimientos()
            ->where('tipo', 'pago')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $desdeUltimoPago = VentaInsumo::desdeCorte()->whereIn('venta_insumos.insumo_id', $insumoIds);

        if ($ultimoPago) {
            $desdeUltimoPago->where('venta_insumos.created_at', '>', $ultimoPago->created_at);
        }

        return [
            'id' => $proveedor->id,
            'nombre' => $proveedor->nombre,
            'telefono' => $proveedor->telefono,
            'notas' => $proveedor->notas,
            'activo' => $proveedor->activo,
            'insumos' => $proveedor->insumos()
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'unidad', 'costo_unitario', 'stock_actual', 'descuenta_stock', 'grupo_separacion', 'unidad_compra', 'equivalencia_compra']),
            'deuda' => round($deuda, 2),
            'vendido_sin_pagar' => round($vendidoSinPagar, 2),
            'en_stock_sin_pagar' => round($deuda - $vendidoSinPagar, 2),
            'separado' => round($separado, 2),
            'falta_separar' => round($vendidoSinPagar - $separado, 2),
            'consumo_desde_corte' => round($consumo, 2),
            'ultimo_pago' => $ultimoPago ? [
                'fecha' => $ultimoPago->created_at?->toIso8601String(),
                'monto' => (float) $ultimoPago->monto,
            ] : null,
            'unidades_hoy' => $cajaHoy
                ? SeparacionService::unidadesPorInsumo(
                    VentaInsumo::where('venta_insumos.caja_id', $cajaHoy->id)->whereIn('venta_insumos.insumo_id', $insumoIds)
                )
                : [],
            'unidades_desde_ultimo_pago' => SeparacionService::unidadesPorInsumo($desdeUltimoPago),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listado(bool $soloActivos = false): array
    {
        $cajaHoy = SeparacionService::cajaDeHoy();

        return Proveedor::query()
            ->when($soloActivos, fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->get()
            ->map(fn (Proveedor $p) => self::estado($p, $cajaHoy))
            ->all();
    }

    /**
     * Registra un movimiento en la cuenta del proveedor. Una entrega además
     * suma stock y, si se pide, actualiza el costo del insumo con el precio
     * de la entrega y recalcula el costo de las variantes que lo usan.
     *
     * @param  array<string, mixed>  $data
     * @return array{movimiento: ProveedorMovimiento, costo_anterior: float|null, costo_nuevo: float|null}
     */
    public static function registrarMovimiento(Proveedor $proveedor, array $data, int $userId): array
    {
        return DB::transaction(function () use ($proveedor, $data, $userId) {
            $costoAnterior = null;
            $costoNuevo = null;
            $cantidadInsumo = null;
            $insumo = null;

            if ($data['tipo'] === 'entrega' && ! empty($data['insumo_id'])) {
                $insumo = Insumo::lockForUpdate()->findOrFail($data['insumo_id']);

                if ((int) $insumo->proveedor_id !== $proveedor->id) {
                    throw new InvalidArgumentException('El insumo no pertenece a este proveedor.');
                }

                $cantidadInsumo = self::convertirAUnidadInsumo($insumo, (float) $data['cantidad'], $data['unidad'] ?? null);
                $costoAnterior = (float) $insumo->costo_unitario;

                if ($insumo->descuenta_stock) {
                    $stockAnterior = (float) $insumo->stock_actual;

                    $insumo->increment('stock_actual', $cantidadInsumo);

                    MovimientoStock::create([
                        'insumo_id' => $insumo->id,
                        'user_id' => $userId,
                        'tipo' => 'entrada',
                        'cantidad' => $cantidadInsumo,
                        'stock_anterior' => $stockAnterior,
                        'stock_nuevo' => $stockAnterior + $cantidadInsumo,
                        'motivo' => 'entrega '.$proveedor->nombre,
                    ]);
                }

                if (! empty($data['actualizar_costo']) && $cantidadInsumo > 0) {
                    $costoNuevo = round((float) $data['monto'] / $cantidadInsumo, 2);

                    $insumo->update(['costo_unitario' => $costoNuevo]);

                    Variante::whereIn('id', Receta::where('insumo_id', $insumo->id)->select('variante_id'))
                        ->get()
                        ->each
                        ->recalcularCosto();
                }
            }

            $movimiento = ProveedorMovimiento::create([
                'proveedor_id' => $proveedor->id,
                'tipo' => $data['tipo'],
                'fecha' => $data['fecha'] ?? now()->toDateString(),
                'monto' => $data['monto'],
                'monto_vendido' => $data['tipo'] === 'saldo_inicial' ? ($data['monto_vendido'] ?? 0) : null,
                'insumo_id' => $insumo?->id,
                'cantidad' => $insumo ? $data['cantidad'] : null,
                'unidad' => $insumo ? ($data['unidad'] ?? $insumo->unidad) : null,
                'cantidad_insumo' => $cantidadInsumo,
                'caja_id' => $data['caja_id'] ?? null,
                'user_id' => $userId,
                'observacion' => $data['observacion'] ?? null,
            ]);

            return [
                'movimiento' => $movimiento,
                'costo_anterior' => $costoAnterior,
                'costo_nuevo' => $costoNuevo,
            ];
        });
    }

    /**
     * Pasa una cantidad a la unidad del insumo: si viene en la unidad de
     * compra (ej: kg) se multiplica por la equivalencia (ej: 10 medallones).
     */
    public static function convertirAUnidadInsumo(Insumo $insumo, float $cantidad, ?string $unidad): float
    {
        $equivalencia = (float) ($insumo->equivalencia_compra ?? 0);

        if ($unidad !== null && $unidad === $insumo->unidad_compra && $equivalencia > 0) {
            return round($cantidad * $equivalencia, 3);
        }

        return round($cantidad, 3);
    }

    /**
     * Confirma la separación de una caja: genera los movimientos 'separacion'
     * de cada proveedor y deja registrada la caja como separada. Devuelve null
     * si la caja ya estaba separada.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public static function confirmarSeparacionCaja(Caja $caja, array $lineas, int $userId): ?CajaSeparacion
    {
        return DB::transaction(function () use ($caja, $lineas, $userId) {
            if (CajaSeparacion::where('caja_id', $caja->id)->lockForUpdate()->exists()) {
                return null;
            }

            $lineas = collect($lineas)
                ->filter(fn ($l) => (float) ($l['monto'] ?? 0) > 0)
                ->values();

            $separacion = CajaSeparacion::create([
                'caja_id' => $caja->id,
                'user_id' => $userId,
                'total' => round((float) $lineas->sum('monto'), 2),
                'detalle' => $lineas->all(),
            ]);

            $fecha = $caja->fecha_operativa?->toDateString() ?? now()->toDateString();

            foreach ($lineas as $linea) {
                if (empty($linea['proveedor_id'])) {
                    continue;
                }

                ProveedorMovimiento::create([
                    'proveedor_id' => $linea['proveedor_id'],
                    'tipo' => 'separacion',
                    'fecha' => $fecha,
                    'monto' => $linea['monto'],
                    'caja_id' => $caja->id,
                    'user_id' => $userId,
                    'observacion' => 'Separación caja '.$fecha,
                ]);
            }

            return $separacion;
        });
    }
}
