<?php

use App\Models\Insumo;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Cada sanguche de chinchulín carga $2.000 de chinchulín. La cantidad sale
     * del precio por kg ($7.000 → ~286 g), así el stock sigue en kg.
     */
    public function up(): void
    {
        $chinchulin = Insumo::where('nombre', 'Chinchulín')->first();

        if (! $chinchulin || (float) $chinchulin->costo_unitario <= 0) {
            return;
        }

        $cantidad = round(2000 / (float) $chinchulin->costo_unitario, 4);

        $chinchulin->recetas()->with('variante')->get()->each(function ($receta) use ($cantidad) {
            $receta->update(['cantidad' => $cantidad]);
            $receta->variante?->recalcularCosto();
        });
    }

    public function down(): void
    {
        //
    }
};
