<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $fillable = [
        'nombre',
        'telefono',
        'notas',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function insumos(): HasMany
    {
        return $this->hasMany(Insumo::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(ProveedorMovimiento::class);
    }
}
