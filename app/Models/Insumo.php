<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'grupo_separacion',
        'proveedor_id',
        'unidad_compra',
        'equivalencia_compra',
        'activo',
    ];

    /**
     * Grupos con los que se agrupa la plata a apartar para reponer insumos.
     */
    const GRUPOS_SEPARACION = [
        'carne',
        'pan',
        'papas',
        'cheddar',
        'panceta',
        'descartables',
        'varios',
    ];

    const GRUPOS_LABELS = [
        'carne' => 'Carne',
        'pan' => 'Pan',
        'papas' => 'Papas',
        'cheddar' => 'Cheddar',
        'panceta' => 'Panceta',
        'descartables' => 'Descartables',
        'varios' => 'Varios',
    ];

    protected $casts = [
        'costo_unitario' => 'decimal:2',
        'stock_actual' => 'decimal:3',
        'stock_minimo' => 'decimal:3',
        'activo' => 'boolean',
        'descuenta_stock' => 'boolean',
        'equivalencia_compra' => 'decimal:3',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

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

    public function scopeDeGrupo($query, string $grupo)
    {
        return $query->where('grupo_separacion', $grupo);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getEstadoStockAttribute(): string
    {
        if ($this->stock_actual <= 0) {
            return 'falta';
        }
        if ($this->stock_actual <= $this->stock_minimo) {
            return 'poco';
        }

        return 'ok';
    }
}
