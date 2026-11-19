<?php

use App\Models\Insumo;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Pan de sanguche: se compra por kilo a $1.700 y salen 10 panes de 100 g,
     * así que cada pan cuesta $170 (antes $250). Las entregas se cargan en kg.
     */
    public function up(): void
    {
        $pan = Insumo::where('nombre', 'Pan de sanguche')->first();

        if (! $pan) {
            return;
        }

        $pan->update([
            'costo_unitario' => 170,
            'unidad_compra' => 'kg',
            'equivalencia_compra' => 10,
        ]);

        $pan->recetas()->with('variante')->get()->each(fn ($r) => $r->variante?->recalcularCosto());
    }

    public function down(): void
    {
        //
    }
};
