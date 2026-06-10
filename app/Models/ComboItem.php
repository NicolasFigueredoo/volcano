<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboItem extends Model
{
    protected $fillable = ['combo_id', 'variante_id', 'descuento', 'cantidad'];

    protected $casts = [
        'descuento' => 'decimal:2',
        'cantidad'  => 'integer',
    ];

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(Variante::class);
    }

    public function getPrecioFinalAttribute(): float
    {
        return ($this->variante->precio_venta - $this->descuento) * $this->cantidad;
    }
}
