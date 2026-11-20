<?php

use App\Models\Insumo;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Compra del 20/11/2026: 4 kg de chinchulín a $28.000 → $7.000/kg (antes $5.000). */
    public function up(): void
    {
        $chinchulin = Insumo::where('nombre', 'Chinchulín')->first();

        if (! $chinchulin) {
            return;
        }

        $chinchulin->update(['costo_unitario' => 7000]);
        $chinchulin->recetas()->with('variante')->get()->each(fn ($r) => $r->variante?->recalcularCosto());
    }

    public function down(): void
    {
        //
    }
};
