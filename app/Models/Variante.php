<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variante extends Model
{
    protected $fillable = [
        'producto_id',
        'nombre',
        'precio_venta',
        'costo_calculado',
        'activo',
        'orden',
    ];

    protected $casts = [
        'precio_venta'    => 'decimal:2',
        'costo_calculado' => 'decimal:2',
        'activo'          => 'boolean',
        'orden'           => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class);
    }

    public function detalleVentas(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActivo($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getMargenAttribute(): float
    {
        if ($this->precio_venta <= 0) return 0;
        return round(($this->precio_venta - $this->costo_calculado) / $this->precio_venta * 100, 1);
    }

    public function getGananciaAttribute(): float
    {
        return $this->precio_venta - $this->costo_calculado;
    }

    // Recalcula costo_calculado sumando receta × costo_unitario de cada insumo
    public function recalcularCosto(): void
    {
        $costo = $this->recetas()
            ->join('insumos', 'insumos.id', '=', 'recetas.insumo_id')
            ->selectRaw('SUM(recetas.cantidad * insumos.costo_unitario) as total')
            ->value('total') ?? 0;

        $this->update(['costo_calculado' => $costo]);
    }
}
