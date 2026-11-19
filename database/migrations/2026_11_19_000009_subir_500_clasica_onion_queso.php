<?php

use App\Models\Producto;
use App\Models\Variante;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * +$500 a Clásica, Onion volcano y Queso volcano en simple, doble y
     * triple (el patty extra queda igual).
     */
    public function up(): void
    {
        $productos = Producto::whereIn('nombre', ['Clásica', 'Onion volcano', 'Queso volcano'])->pluck('id');

        Variante::whereIn('producto_id', $productos)
            ->where('es_extra', false)
            ->increment('precio_venta', 500);
    }

    public function down(): void
    {
        //
    }
};
