<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de que la plata de insumos de una caja ya se separó. Hay como
 * mucho uno por caja (unique en caja_id), así no se separa dos veces.
 */
class CajaSeparacion extends Model
{
    protected $table = 'caja_separaciones';

    protected $fillable = [
        'caja_id',
        'user_id',
        'total',
        'detalle',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'detalle' => 'array',
    ];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
