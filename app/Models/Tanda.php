<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una compra de carne de la parrilla (ej. "2 vacíos, 9,2 kg, $80.000").
 * Mientras está abierta, cada venta que usa ese insumo aparta su parte al
 * sobre; al cerrarla se compara lo apartado con lo pagado.
 */
class Tanda extends Model
{
    protected $fillable = [
        'insumo_id',
        'negocio',
        'cantidad',
        'total',
        'porciones_estimadas',
        'costo_unitario',
        'user_id',
        'observacion',
        'cerrada_at',
        'caja_cierre_id',
        'apartado',
        'cantidad_vendida',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'total' => 'decimal:2',
        'porciones_estimadas' => 'integer',
        'costo_unitario' => 'decimal:2',
        'cerrada_at' => 'datetime',
        'apartado' => 'decimal:2',
        'cantidad_vendida' => 'decimal:3',
    ];

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cajaCierre(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_cierre_id');
    }

    public function ventaInsumos(): HasMany
    {
        return $this->hasMany(VentaInsumo::class);
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereNull('cerrada_at');
    }

    public function scopeCerradas(Builder $query): Builder
    {
        return $query->whereNotNull('cerrada_at');
    }

    public function getAbiertaAttribute(): bool
    {
        return $this->cerrada_at === null;
    }
}
