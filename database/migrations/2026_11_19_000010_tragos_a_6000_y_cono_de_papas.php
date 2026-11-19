<?php

use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * - Federico + Speed y Gancia con Sprite a $6.000.
     * - Cono de papas (tipo feria) a $3.000: 1 porción de 150 g, con cartón,
     *   aceite y garrafa como las bandejas. Se ajusta desde Admin.
     */
    public function up(): void
    {
        Producto::whereIn('nombre', ['Federico + Speed', 'Gancia con Sprite'])
            ->get()
            ->each(fn (Producto $p) => $p->variantes()->update(['precio_venta' => 6000]));

        $categoria = Categoria::where('nombre', 'like', 'Papas%')->first();

        if (! $categoria || Producto::where('nombre', 'Cono de papas')->exists()) {
            return;
        }

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Cono de papas',
            'activo' => true,
            'orden' => -1, // primero en la pestaña: es lo más barato
        ]);

        $variante = $producto->variantes()->create([
            'nombre' => 'Único',
            'precio_venta' => 3000,
            'costo_calculado' => 0,
            'activo' => true,
            'orden' => 0,
        ]);

        foreach (['Papas' => 1, 'Cartón papas' => 1, 'Aceite freidora' => 1, 'Garrafa' => 1] as $nombre => $cantidad) {
            $insumo = Insumo::where('nombre', $nombre)->first();

            if ($insumo) {
                $variante->recetas()->create(['insumo_id' => $insumo->id, 'cantidad' => $cantidad]);
            }
        }

        $variante->recalcularCosto();
    }

    public function down(): void
    {
        //
    }
};
