<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CierreCaja extends Model
{
    protected $table = 'cierres_caja';

    protected $fillable = [
        'user_id',
        'fecha',
        'total_ventas',
        'total_efectivo',
        'total_transferencia',
        'costo_insumos',
        'ganancia_bruta',
        'gastos_fijos',
        'ganancia_neta',
        'cantidad_ventas',
        'resumen_json',
    ];

    protected $casts = [
        'fecha'               => 'date',
        'total_ventas'        => 'decimal:2',
        'total_efectivo'      => 'decimal:2',
        'total_transferencia' => 'decimal:2',
        'costo_insumos'       => 'decimal:2',
        'ganancia_bruta'      => 'decimal:2',
        'gastos_fijos'        => 'decimal:2',
        'ganancia_neta'       => 'decimal:2',
        'resumen_json'        => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
