<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoVenta extends Model
{
    protected $table = 'pagos_venta';

    protected $fillable = ['venta_id', 'metodo', 'monto'];

    protected $casts = ['monto' => 'decimal:2'];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
