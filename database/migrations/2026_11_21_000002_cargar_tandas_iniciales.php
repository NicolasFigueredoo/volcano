<?php

use App\Models\Insumo;
use App\Models\Tanda;
use App\Models\User;
use App\Services\TandaService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Primeras compras de parrilla (21/11/2026): lo que se pone a la venta hoy.
     * Vacío: medio de 7,54 kg a $17.300 · Bondiola: 2 de 3 (8,22 kg a $7.800)
     * Chinchulín: 4 kg a $3.250 · Chorizo: los 28 que quedaron.
     */
    public function up(): void
    {
        $compras = [
            ['Vacío (parrilla)', 3.77, 65221, 10, 'Medio vacío (7,54 kg a $17.300)'],
            ['Bondiola', 5.48, 42744, 22, '2 de 3 bondiolas (8,22 kg a $7.800)'],
            ['Chinchulín', 4, 13000, 9, '4 kg de 8,08 kg a $3.250'],
            ['Chorizo', 28, 29300, 28, 'Los 28 que quedaron'],
        ];

        $adminId = User::where('role', 'admin')->orderBy('id')->value('id') ?? User::orderBy('id')->value('id');

        // Base nueva (tests / instalación limpia): no hay compras reales que cargar.
        if (! $adminId) {
            return;
        }

        foreach ($compras as [$nombre, $cantidad, $total, $porciones, $nota]) {
            $insumo = Insumo::where('nombre', $nombre)->first();

            // Si ya tiene una compra cargada a mano, no se duplica.
            if (! $insumo || Tanda::where('insumo_id', $insumo->id)->exists()) {
                continue;
            }

            TandaService::crear([
                'insumo_id' => $insumo->id,
                'cantidad' => $cantidad,
                'total' => $total,
                'porciones_estimadas' => $porciones,
                'observacion' => $nota,
            ], $adminId);
        }
    }

    public function down(): void
    {
        //
    }
};
