<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot de los insumos que consumió cada venta (receta × cantidad, con el
 * costo vigente al momento de la venta). Separación y consumo se calculan
 * desde acá, así un cambio posterior de receta o costo no mueve el histórico.
 */
class VentaInsumo extends Model
{
    protected $table = 'venta_insumos';

    protected $fillable = [
        'venta_id',
        'caja_id',
        'insumo_id',
        'grupo_separacion',
        'cantidad',
        'costo_unitario',
        'subtotal',
        'reposicion_id',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    /**
     * Solo filas de cajas desde la fecha de corte (config/separacion.php).
     */
    public function scopeDesdeCorte(Builder $query): Builder
    {
        return $query->whereIn(
            'venta_insumos.caja_id',
            Caja::query()->select('id')->whereDate('fecha_operativa', '>=', config('separacion.fecha_corte'))
        );
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereNull('venta_insumos.reposicion_id');
    }
}
