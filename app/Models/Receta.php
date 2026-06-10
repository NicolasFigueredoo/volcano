<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receta extends Model
{
    protected $fillable = [
        'variante_id',
        'insumo_id',
        'cantidad',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getCostoLineaAttribute(): float
    {
        return $this->cantidad * $this->insumo->costo_unitario;
    }
}
