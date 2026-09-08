<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reposicion extends Model
{
    protected $table = 'reposiciones';

    protected $fillable = [
        'grupo',
        'monto_acumulado',
        'monto_real',
        'user_id',
        'observacion',
    ];

    protected $casts = [
        'monto_acumulado' => 'decimal:2',
        'monto_real' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(SeparacionMovimiento::class);
    }

    /**
     * Diferencia entre lo que realmente se gastó y lo que el sistema apartó.
     * Null si todavía no se cargó el monto real.
     */
    public function getDiferenciaAttribute(): ?float
    {
        if ($this->monto_real === null) {
            return null;
        }

        return (float) $this->monto_real - (float) $this->monto_acumulado;
    }
}
