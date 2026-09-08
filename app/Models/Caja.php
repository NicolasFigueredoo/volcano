<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caja extends Model
{
    protected $fillable = [
        'fecha_operativa',
        'estado',
        'abierta_por',
        'cerrada_por',
        'abierta_at',
        'cerrada_at',
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
        'fecha_operativa' => 'date',
        'abierta_at' => 'datetime',
        'cerrada_at' => 'datetime',
        'resumen_json' => 'array',
        'total_ventas' => 'decimal:2',
        'total_efectivo' => 'decimal:2',
        'total_transferencia' => 'decimal:2',
        'costo_insumos' => 'decimal:2',
        'ganancia_bruta' => 'decimal:2',
        'gastos_fijos' => 'decimal:2',
        'ganancia_neta' => 'decimal:2',
    ];

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function abiertaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abierta_por');
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function scopeAbierta($query)
    {
        return $query->where('estado', 'abierta');
    }

    public function scopeCerrada($query)
    {
        return $query->where('estado', 'cerrada');
    }

    public static function fechaOperativaActual(): string
    {
        $now = now();

        if ((int) $now->format('H') < 6) {
            return $now->copy()->subDay()->toDateString();
        }

        return $now->toDateString();
    }

    /**
     * Caja donde se registran las ventas. Se puede abrir y cerrar varias veces
     * el mismo dia, e incluso abrir una caja con fecha operativa pasada, asi
     * que se prioriza la abierta de hoy y si no hay, cualquier otra abierta.
     */
    public static function abiertaActual(): ?self
    {
        return self::where('estado', 'abierta')
            ->orderByRaw('case when fecha_operativa = ? then 0 else 1 end', [self::fechaOperativaActual()])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Caja "vigente" de una fecha operativa: la abierta si existe, si no la
     * ultima cerrada de ese dia.
     */
    public static function vigenteDeFecha(string $fecha): ?self
    {
        return self::whereDate('fecha_operativa', $fecha)
            ->orderByRaw("case when estado = 'abierta' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();
    }
}
