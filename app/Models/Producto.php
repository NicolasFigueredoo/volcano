<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    protected $fillable = [
        'categoria_id',
        'nombre',
        'descripcion',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden'  => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(Variante::class)->orderBy('orden');
    }

    public function variantesActivas(): HasMany
    {
        return $this->hasMany(Variante::class)->where('activo', true)->orderBy('orden');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActivo($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }

    public function scopeConVariantes($query)
    {
        return $query->with(['variantes' => fn($q) => $q->where('activo', true)->orderBy('orden')]);
    }
}
