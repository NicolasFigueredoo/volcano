<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Insumo extends Model
{
    protected $fillable = [
        'nombre',
        'unidad',
        'costo_unitario',
        'stock_actual',
        'stock_minimo',
        'descuenta_stock',
        'activo',
    ];

    protected $casts = [
        'costo_unitario'  => 'decimal:2',
        'stock_actual'    => 'decimal:3',
        'stock_minimo'    => 'decimal:3',
        'activo'          => 'boolean',
        'descuenta_stock' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }

    public function scopeBajoStock($query)
    {
        return $query->whereColumn('stock_actual', '<=', 'stock_minimo');
    }

    public function scopeSinStock($query)
    {
        return $query->where('stock_actual', '<=', 0);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getEstadoStockAttribute(): string
    {
        if ($this->stock_actual <= 0) return 'falta';
        if ($this->stock_actual <= $this->stock_minimo) return 'poco';
        return 'ok';
    }
}