<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GastoFijo extends Model
{
    protected $table = 'gastos_fijos';

    /** Un gasto es de un negocio o se comparte mitad y mitad entre burger y parrilla. */
    public const NEGOCIOS = ['burger', 'parrilla', 'compartido'];

    /**
     * mensual: se paga por mes (alquiler) y se reparte entre los días que se abre.
     * jornada: se paga cada día que se abre (parrillero, carbón).
     */
    public const PERIODICIDADES = ['mensual', 'jornada'];

    protected $fillable = ['nombre', 'negocio', 'proveedor_id', 'periodicidad', 'monto_jornada', 'monto_mensual', 'dias_apertura_mes', 'activo'];

    protected $casts = [
        'monto_mensual' => 'decimal:2',
        'monto_jornada' => 'decimal:2',
        'dias_apertura_mes' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * Un gasto por jornada guarda también su equivalente mensual (monto por
     * jornada x días que se abre), así el costo por día y los resúmenes
     * mensuales siguen saliendo de la misma cuenta para los dos tipos.
     */
    protected static function booted(): void
    {
        static::saving(function (GastoFijo $gasto) {
            if ($gasto->periodicidad === 'jornada') {
                $gasto->monto_mensual = (float) $gasto->monto_jornada * max(1, (int) $gasto->dias_apertura_mes);
            }
        });
    }

    /** Proveedor en el que se separa la plata de este gasto (solo por jornada). */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    // Costo por día de apertura
    public function getCostoDiarioAttribute(): float
    {
        if ($this->periodicidad === 'jornada') {
            return round((float) $this->monto_jornada, 2);
        }

        if ($this->dias_apertura_mes <= 0) {
            return 0;
        }

        return round($this->monto_mensual / $this->dias_apertura_mes, 2);
    }

    // Total de gastos fijos por día sumando todos los activos
    public static function totalDiario(): float
    {
        return self::where('activo', true)->get()->sum('costo_diario');
    }
}
