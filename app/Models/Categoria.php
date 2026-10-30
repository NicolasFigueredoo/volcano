<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $fillable = [
        'nombre',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    public function inversiones(): HasMany
    {
        return $this->hasMany(Inversion::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActivo($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }

    // Misma regla que la migración del rubro bebidas: la categoría cuyo
    // nombre contiene "bebida".
    public static function bebidas(): ?self
    {
        return self::whereRaw('LOWER(nombre) LIKE ?', ['%bebida%'])->orderBy('id')->first();
    }
}
