<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleVenta extends Model
{
    protected $fillable = [
        'venta_id',
        'variante_id',
        'negocio',
        'nombre_snapshot',
        'precio_snapshot',
        'costo_snapshot',
        'cantidad',
        'subtotal',
    ];

    protected $casts = [
        'precio_snapshot' => 'decimal:2',
        'costo_snapshot' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'cantidad' => 'integer',
    ];

    /**
     * Cada línea guarda el negocio de su categoría al momento de la venta, así
     * mover una categoría de negocio no reescribe la historia.
     */
    protected static function booted(): void
    {
        static::creating(function (DetalleVenta $detalle) {
            if (! $detalle->negocio) {
                $detalle->negocio = Variante::with('producto.categoria')
                    ->find($detalle->variante_id)
                    ?->producto?->categoria?->negocio ?? 'burger';
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getGananciaAttribute(): float
    {
        return ($this->precio_snapshot - $this->costo_snapshot) * $this->cantidad;
    }
}
