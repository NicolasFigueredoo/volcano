<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeparacionMovimiento extends Model
{
    protected $table = 'separacion_movimientos';

    protected $fillable = [
        'venta_id',
        'grupo',
        'monto',
        'reposicion_id',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function reposicion(): BelongsTo
    {
        return $this->belongsTo(Reposicion::class);
    }

    /**
     * Movimientos que todavía no fueron incluidos en una reposición: son los
     * que forman el acumulado vigente de cada grupo.
     */
    public function scopePendientes($query)
    {
        return $query->whereNull('reposicion_id');
    }

    public function scopeDeGrupo($query, string $grupo)
    {
        return $query->where('grupo', $grupo);
    }
}
