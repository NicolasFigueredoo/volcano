<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Caja;

class Venta extends Model
{
    protected $fillable = [
        'user_id',
        'mesa',
        'estado',
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
        'subtotal'   => 'decimal:2',
        'descuento'  => 'decimal:2',
        'total'      => 'decimal:2',
        'cerrada_at' => 'datetime',
    ];

    const ESTADOS = ['pendiente', 'preparacion', 'pagado', 'entregado'];

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
    public static function proximoNumeroOrden(): int
    {
        $ultimo = self::whereDate('created_at', today())->max('numero_orden') ?? 0;
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

            $subtotal = collect($items)->sum(
                fn($i) => ($variantesMap[$i['variante_id']]->precio_venta - ($i['descuento'] ?? 0)) * $i['cantidad']
            );


            $caja = Caja::abiertaActual();

            if (!$caja) {
                throw new \Exception('No hay una caja abierta para registrar ventas.');
            }

            $descuento = $data['descuento'] ?? 0;
            $total = $subtotal - $descuento;

            $venta = self::create([
                'caja_id' => $caja->id,
                'fecha_operativa' => $caja->fecha_operativa,
                'user_id'      => $userId,
                'mesa'         => $data['mesa'] ?? null,
                'estado'       => 'pendiente',
                'numero_orden' => self::proximoNumeroOrden(),
                'metodo_pago'  => count($pagos) === 1 ? $pagos[0]['metodo'] : 'mixto',
                'subtotal'     => $subtotal,
                'descuento'    => $descuento,
                'total'        => $total,
                'notas'        => $data['notas'] ?? null,
            ]);

            // Registrar pagos
            foreach ($pagos as $pago) {
                if (($pago['monto'] ?? 0) > 0) {
                    $venta->pagos()->create([
                        'metodo' => $pago['metodo'],
                        'monto'  => $pago['monto'],
                    ]);
                }
            }

            // Registrar detalle y descontar stock
            foreach ($items as $item) {
                $variante = $variantesMap[$item['variante_id']];
                $precioFinal = $variante->precio_venta - ($item['descuento'] ?? 0);

                $venta->detalles()->create([
                    'variante_id'     => $variante->id,
                    'nombre_snapshot' => $variante->producto->nombre . ' ' . $variante->nombre,
                    'precio_snapshot' => $precioFinal,
                    'costo_snapshot'  => $variante->costo_calculado,
                    'cantidad'        => $item['cantidad'],
                    'subtotal'        => $precioFinal * $item['cantidad'],
                ]);

                foreach ($variante->recetas as $receta) {
                    $insumo = $receta->insumo;
                    $cantidadTotal = $receta->cantidad * $item['cantidad'];

                    // Solo descontar si el insumo está marcado para descontar stock
                    if (!$insumo->descuenta_stock) continue;

                    $stockAnterior = $insumo->stock_actual;
                    $stockNuevo = max(0, $stockAnterior - $cantidadTotal);

                    $insumo->decrement('stock_actual', $cantidadTotal);

                    MovimientoStock::create([
                        'insumo_id'      => $insumo->id,
                        'user_id'        => $userId,
                        'tipo'           => 'salida',
                        'cantidad'       => $cantidadTotal,
                        'stock_anterior' => $stockAnterior,
                        'stock_nuevo'    => $stockNuevo,
                        'motivo'         => 'venta',
                        'venta_id'       => $venta->id,
                    ]);
                }
            }

            return $venta;
        });
    }
}
