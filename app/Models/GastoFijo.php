<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GastoFijo extends Model
{
    protected $table = 'gastos_fijos';

    protected $fillable = ['nombre', 'monto_mensual', 'dias_apertura_mes', 'activo'];

    protected $casts = [
        'monto_mensual'     => 'decimal:2',
        'dias_apertura_mes' => 'integer',
        'activo'            => 'boolean',
    ];

    // Costo por día de apertura
    public function getCostoDiarioAttribute(): float
    {
        if ($this->dias_apertura_mes <= 0) return 0;
        return round($this->monto_mensual / $this->dias_apertura_mes, 2);
    }

    // Total de gastos fijos por día sumando todos los activos
    public static function totalDiario(): float
    {
        return self::where('activo', true)->get()->sum('costo_diario');
    }
}
