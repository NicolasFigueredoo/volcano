<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorMovimiento extends Model
{
    protected $table = 'proveedor_movimientos';

    const TIPOS = ['saldo_inicial', 'entrega', 'separacion', 'pago', 'ajuste'];

    protected $fillable = [
        'proveedor_id',
        'tipo',
        'fecha',
        'monto',
        'monto_vendido',
        'insumo_id',
        'cantidad',
        'unidad',
        'cantidad_insumo',
        'caja_id',
        'user_id',
        'observacion',
    ];

    protected $casts = [
        // Sin hora ni zona: en JSON sale "2026-09-28", no medianoche UTC.
        'fecha' => 'date:Y-m-d',
        'monto' => 'decimal:2',
        'monto_vendido' => 'decimal:2',
        'cantidad' => 'decimal:3',
        'cantidad_insumo' => 'decimal:3',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
