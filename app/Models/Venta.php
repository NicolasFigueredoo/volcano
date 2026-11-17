<?php

namespace App\Models;

use App\Observers\VentaObserver;
use App\Services\SeparacionService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[ObservedBy(VentaObserver::class)]
class Venta extends Model
{
    protected $fillable = [
        'user_id',
        'mesa',
        'estado',
        'tipo_precio',
        'numero_orden',
        'metodo_pago',
        'subtotal',
        'descuento',
        'total',
        'notas',
        'cerrada_at',
        'caja_id',
        'fecha_operativa',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'cerrada_at' => 'datetime',
    ];

    const ESTADOS = ['pendiente', 'preparacion', 'pagado', 'entregado'];

    /** cliente = precio normal, costo = solo el costo, regalo = gratis. */
    const TIPOS_PRECIO = ['cliente', 'costo', 'regalo'];

    /**
     * Precio unitario a cobrar de una variante segun el tipo de precio.
     * El descuento por item (combos) solo aplica al precio de cliente.
     */
    public static function precioUnitario(Variante $variante, string $tipoPrecio, float $descuento = 0): float
    {
        return match ($tipoPrecio) {
            'costo' => (float) $variante->costo_calculado,
            'regalo' => 0.0,
            default => (float) $variante->precio_venta - $descuento,
        };
    }

    /** Metodo de pago resumido a guardar en la venta. */
    private static function metodoPagoResumen(array $pagos): string
    {
        $pagos = array_values(array_filter($pagos, fn ($p) => ($p['monto'] ?? 0) > 0));

        if (count($pagos) === 0) {
            return 'efectivo';
        }

        return count($pagos) === 1 ? $pagos[0]['metodo'] : 'mixto';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class);
    }

    public function insumos(): HasMany
    {
        return $this->hasMany(VentaInsumo::class);
    }

    public function scopeDeHoy($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeActivos($query)
    {
        return $query->whereIn('estado', ['pendiente', 'preparacion', 'pagado']);
    }

    public function getEfectivoAttribute(): float
    {
        return $this->pagos->where('metodo', 'efectivo')->sum('monto');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function getTransferenciaAttribute(): float
    {
        return $this->pagos->where('metodo', 'transferencia')->sum('monto');
    }

    // Genera número de orden legible (1-99 por día)
    public static function proximoNumeroOrden(string $fechaOperativa): int
    {
        $ultimo = self::whereDate('fecha_operativa', $fechaOperativa)->max('numero_orden') ?? 0;

        return ($ultimo % 99) + 1;
    }

    public function scopeDeCaja($query, Caja $caja)
    {
        return $query->where('caja_id', $caja->id);
    }

    public function scopeDeFechaOperativa($query, string $fecha)
    {
        return $query->where('fecha_operativa', $fecha);
    }

    public static function registrar(array $data, array $items, array $pagos, int $userId): self
    {

        return \DB::transaction(function () use ($data, $items, $pagos, $userId) {
            $variantesMap = Variante::with('recetas.insumo', 'producto')
                ->whereIn('id', collect($items)->pluck('variante_id'))
                ->get()
                ->keyBy('id');

            $tipoPrecio = in_array($data['tipo_precio'] ?? null, self::TIPOS_PRECIO, true)
                ? $data['tipo_precio']
                : 'cliente';

            $subtotal = collect($items)->sum(
                fn ($i) => self::precioUnitario($variantesMap[$i['variante_id']], $tipoPrecio, (float) ($i['descuento'] ?? 0)) * $i['cantidad']
            );

            $caja = Caja::abiertaActual();

            if (! $caja) {
                throw new \Exception('No hay una caja abierta para registrar ventas.');
            }

            // El descuento general solo tiene sentido a precio de cliente.
            $descuento = $tipoPrecio === 'cliente' ? ($data['descuento'] ?? 0) : 0;
            $total = $subtotal - $descuento;

            $venta = self::create([
                'caja_id' => $caja->id,
                'fecha_operativa' => $caja->fecha_operativa,
                'user_id' => $userId,
                'mesa' => $data['mesa'] ?? null,
                'estado' => 'pendiente',
                'numero_orden' => self::proximoNumeroOrden(Carbon::parse($caja->fecha_operativa)->toDateString()),
                'tipo_precio' => $tipoPrecio,
                'metodo_pago' => self::metodoPagoResumen($pagos),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'total' => $total,
                'notas' => $data['notas'] ?? null,
            ]);

            // Registrar pagos
            foreach ($pagos as $pago) {
                if (($pago['monto'] ?? 0) > 0) {
                    $venta->pagos()->create([
                        'metodo' => $pago['metodo'],
                        'monto' => $pago['monto'],
                    ]);
                }
            }

            // Registrar detalle y descontar stock
            foreach ($items as $item) {
                $variante = $variantesMap[$item['variante_id']];
                $precioFinal = self::precioUnitario($variante, $tipoPrecio, (float) ($item['descuento'] ?? 0));

                $venta->detalles()->create([
                    'variante_id' => $variante->id,
                    'nombre_snapshot' => $variante->producto->nombre.' '.$variante->nombre,
                    'precio_snapshot' => $precioFinal,
                    'costo_snapshot' => $variante->costo_calculado,
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $precioFinal * $item['cantidad'],
                ]);

                foreach ($variante->recetas as $receta) {
                    $insumo = $receta->insumo;
                    $cantidadTotal = $receta->cantidad * $item['cantidad'];

                    // Solo descontar si el insumo está marcado para descontar stock
                    if (! $insumo->descuenta_stock) {
                        continue;
                    }

                    $stockAnterior = $insumo->stock_actual;
                    $stockNuevo = max(0, $stockAnterior - $cantidadTotal);

                    $insumo->decrement('stock_actual', $cantidadTotal);

                    MovimientoStock::create([
                        'insumo_id' => $insumo->id,
                        'user_id' => $userId,
                        'tipo' => 'salida',
                        'cantidad' => $cantidadTotal,
                        'stock_anterior' => $stockAnterior,
                        'stock_nuevo' => $stockNuevo,
                        'motivo' => 'venta',
                        'venta_id' => $venta->id,
                    ]);
                }
            }

            // La venta puede nacer ya pagada, antes de tener detalles: se
            // sincroniza aca la plata a apartar para reponer insumos.
            SeparacionService::sincronizar($venta);

            return $venta;
        });
    }

    /**
     * Crea un pedido dentro de una caja puntual (abierta o cerrada), para
     * carga retroactiva por parte del administrador.
     */
    public static function registrarEnCaja(Caja $caja, array $data, array $items, array $pagos, int $userId): self
    {
        return \DB::transaction(function () use ($caja, $data, $items, $pagos, $userId) {
            $variantesMap = Variante::with('recetas.insumo', 'producto')
                ->whereIn('id', collect($items)->pluck('variante_id'))
                ->get()
                ->keyBy('id');

            $tipoPrecio = in_array($data['tipo_precio'] ?? null, self::TIPOS_PRECIO, true)
                ? $data['tipo_precio']
                : 'cliente';

            $subtotal = collect($items)->sum(
                fn ($i) => self::precioUnitario($variantesMap[$i['variante_id']], $tipoPrecio, (float) ($i['descuento'] ?? 0)) * $i['cantidad']
            );

            // El descuento general solo tiene sentido a precio de cliente.
            $descuento = $tipoPrecio === 'cliente' ? ($data['descuento'] ?? 0) : 0;
            $total = $subtotal - $descuento;

            $ultimoOrden = self::where('caja_id', $caja->id)->max('numero_orden') ?? 0;

            $venta = self::create([
                'caja_id' => $caja->id,
                'fecha_operativa' => $caja->fecha_operativa,
                'user_id' => $userId,
                'mesa' => $data['mesa'] ?? null,
                'estado' => $data['estado'] ?? 'pagado',
                'numero_orden' => $ultimoOrden + 1,
                'tipo_precio' => $tipoPrecio,
                'metodo_pago' => self::metodoPagoResumen($pagos),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'total' => $total,
                'notas' => $data['notas'] ?? null,
            ]);

            foreach ($pagos as $pago) {
                if (($pago['monto'] ?? 0) > 0) {
                    $venta->pagos()->create([
                        'metodo' => $pago['metodo'],
                        'monto' => $pago['monto'],
                    ]);
                }
            }

            foreach ($items as $item) {
                $variante = $variantesMap[$item['variante_id']];
                $precioFinal = self::precioUnitario($variante, $tipoPrecio, (float) ($item['descuento'] ?? 0));

                $venta->detalles()->create([
                    'variante_id' => $variante->id,
                    'nombre_snapshot' => $variante->producto->nombre.($variante->nombre ? ' — '.$variante->nombre : ''),
                    'precio_snapshot' => $precioFinal,
                    'costo_snapshot' => $variante->costo_calculado,
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $precioFinal * $item['cantidad'],
                ]);

                foreach ($variante->recetas as $receta) {
                    $insumo = $receta->insumo;
                    $cantidadTotal = $receta->cantidad * $item['cantidad'];

                    if (! $insumo->descuenta_stock) {
                        continue;
                    }

                    $stockAnterior = $insumo->stock_actual;
                    $stockNuevo = max(0, $stockAnterior - $cantidadTotal);

                    $insumo->decrement('stock_actual', $cantidadTotal);

                    MovimientoStock::create([
                        'insumo_id' => $insumo->id,
                        'user_id' => $userId,
                        'tipo' => 'salida',
                        'cantidad' => $cantidadTotal,
                        'stock_anterior' => $stockAnterior,
                        'stock_nuevo' => $stockNuevo,
                        'motivo' => 'venta',
                        'venta_id' => $venta->id,
                    ]);
                }
            }

            // La venta puede nacer ya pagada, antes de tener detalles: se
            // sincroniza aca la plata a apartar para reponer insumos.
            SeparacionService::sincronizar($venta);

            return $venta;
        });
    }
}
