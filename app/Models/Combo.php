<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Combo extends Model
{
    protected $fillable = ['nombre', 'descripcion', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(ComboItem::class);
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }

    // Precio total del combo = suma de (precio_variante - descuento) * cantidad
    public function getPrecioTotalAttribute(): float
    {
        return $this->items->sum(
            fn($item) => ($item->variante->precio_venta - $item->descuento) * $item->cantidad
        );
    }

    // Ahorro respecto a comprar todo suelto
    public function getAhorroAttribute(): float
    {
        return $this->items->sum(fn($item) => $item->descuento * $item->cantidad);
    }
}
