<?php

use App\Models\Insumo;
use App\Models\VentaInsumo;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * El chinchulín tiene su propio sobre ("Chinchulín") en vez de mezclarse
     * con "Varios", que queda para descartables, bolsas, limón, aceite, etc.
     */
    public function up(): void
    {
        $chinchulin = Insumo::where('nombre', 'Chinchulín')->first();

        if (! $chinchulin) {
            return;
        }

        $chinchulin->update(['grupo_separacion' => 'chinchulin']);

        // Lo todavía no repuesto pasa al rubro nuevo (lo histórico queda igual).
        VentaInsumo::where('insumo_id', $chinchulin->id)
            ->whereNull('reposicion_id')
            ->update(['grupo_separacion' => 'chinchulin']);
    }

    public function down(): void
    {
        //
    }
};
