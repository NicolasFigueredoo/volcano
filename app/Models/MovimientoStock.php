<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoStock extends Model
{

    protected $table = 'movimientos_stock';

    
    protected $fillable = [
        'insumo_id',
        'user_id',
        'tipo',
        'cantidad',
        'stock_anterior',
        'stock_nuevo',
        'motivo',
        'venta_id',
    ];

    protected $casts = [
        'cantidad'       => 'decimal:3',
        'stock_anterior' => 'decimal:3',
        'stock_nuevo'    => 'decimal:3',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeEntradas($query)
    {
        return $query->where('tipo', 'entrada');
    }

    public function scopeSalidas($query)
    {
        return $query->where('tipo', 'salida');
    }
}