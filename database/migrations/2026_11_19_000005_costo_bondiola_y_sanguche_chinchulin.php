<?php

use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Compras de la parrilla del 19/11/2026:
     * - Bondiola: 8,22 kg a $6.500/kg (antes $10.500/kg).
     * - Chinchulín: 2,3 kg a $5.000/kg. Sanguche: 250 g crudo + pan, a $6.000.
     */
    public function up(): void
    {
        $bondiola = Insumo::where('nombre', 'Bondiola')->first();

        if ($bondiola) {
            $bondiola->update(['costo_unitario' => 6500]);
            $bondiola->recetas()->with('variante')->get()->each(fn ($r) => $r->variante?->recalcularCosto());
        }

        $categoria = Categoria::where('negocio', 'parrilla')->orderBy('orden')->first();

        if (! $categoria || Producto::where('nombre', 'Chinchulín')->exists()) {
            return;
        }

        $chinchulin = Insumo::firstOrCreate(
            ['nombre' => 'Chinchulín'],
            ['unidad' => 'kg', 'costo_unitario' => 5000, 'stock_actual' => 0, 'stock_minimo' => 0, 'descuenta_stock' => true, 'grupo_separacion' => 'varios', 'activo' => true],
        );
        $pan = Insumo::where('nombre', 'Pan de sanguche')->first();

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Chinchulín',
            'activo' => true,
            'orden' => (int) Producto::where('categoria_id', $categoria->id)->max('orden') + 1,
        ]);

        $variante = $producto->variantes()->create([
            'nombre' => 'Sanguche',
            'precio_venta' => 6000,
            'costo_calculado' => 0,
            'activo' => true,
            'orden' => 0,
        ]);

        $variante->recetas()->create(['insumo_id' => $chinchulin->id, 'cantidad' => 0.25]);
        if ($pan) {
            $variante->recetas()->create(['insumo_id' => $pan->id, 'cantidad' => 1]);
        }

        $variante->recalcularCosto();
    }

    public function down(): void
    {
        //
    }
};
