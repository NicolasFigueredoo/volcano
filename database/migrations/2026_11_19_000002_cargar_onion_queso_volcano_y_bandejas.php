<?php

use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Variante;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Burgers nuevas y bandejas de aros / bastones (todo se ajusta después
     * desde Admin):
     *
     * - Onion volcano: la receta de la Bacon + 40 g de aros (2 aros de 20 g).
     * - Queso volcano: la receta de la Bacon + 70 g de muzzarella (2 bastones de 35 g).
     *   Las dos con los precios de la Volcano (13.000 / 14.000 / 15.000 y patty extra 1.500).
     * - Bandejas (categoría "Aros y bastones", negocio burger), con aceite y
     *   garrafa como las de papas:
     *   aros y bastones: x6 $6.000 y x12 $10.000.
     */
    public function up(): void
    {
        $aros = Insumo::firstOrCreate(
            ['nombre' => 'Aros de cebolla'],
            ['unidad' => 'kg', 'costo_unitario' => 13300, 'stock_actual' => 0, 'stock_minimo' => 0, 'descuenta_stock' => true, 'grupo_separacion' => 'varios', 'activo' => true],
        );
        $bastones = Insumo::firstOrCreate(
            ['nombre' => 'Bastones de muzzarella'],
            ['unidad' => 'kg', 'costo_unitario' => 11700, 'stock_actual' => 0, 'stock_minimo' => 0, 'descuenta_stock' => true, 'grupo_separacion' => 'varios', 'activo' => true],
        );

        $bacon = Producto::with('variantes.recetas')->whereRaw('LOWER(nombre) = ?', ['bacon'])->first();
        $volcano = Producto::with('variantes')->whereRaw('LOWER(nombre) = ?', ['volcano'])->first();

        if ($bacon) {
            $this->burger('Onion volcano', $bacon, $volcano, $aros, 0.04);
            $this->burger('Queso volcano', $bacon, $volcano, $bastones, 0.07);
        }

        $this->bandejas($aros, $bastones);
    }

    /**
     * Crea (o completa) una burger copiando las variantes y recetas de la
     * Bacon y sumándole el insumo extra. Las variantes que ya tienen receta
     * no se tocan.
     */
    private function burger(string $nombre, Producto $base, ?Producto $precios, Insumo $extra, float $cantidad): void
    {
        $producto = Producto::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first()
            ?? Producto::create([
                'categoria_id' => $base->categoria_id,
                'nombre' => $nombre,
                'activo' => true,
                'orden' => (int) Producto::where('categoria_id', $base->categoria_id)->max('orden') + 1,
            ]);

        foreach ($base->variantes as $varianteBase) {
            $precio = $precios?->variantes->firstWhere('nombre', $varianteBase->nombre)?->precio_venta
                ?? $varianteBase->precio_venta;

            $variante = $producto->variantes()->where('nombre', $varianteBase->nombre)->first()
                ?? $producto->variantes()->create([
                    'nombre' => $varianteBase->nombre,
                    'precio_venta' => $precio,
                    'costo_calculado' => 0,
                    'activo' => $varianteBase->activo,
                    'es_extra' => $varianteBase->es_extra,
                    'orden' => $varianteBase->orden,
                ]);

            if ($variante->recetas()->exists()) {
                continue;
            }

            $variante->update(['precio_venta' => $precio]);

            foreach ($varianteBase->recetas as $receta) {
                $variante->recetas()->create(['insumo_id' => $receta->insumo_id, 'cantidad' => $receta->cantidad]);
            }

            // El patty extra es solo medallón + cheddar: no lleva aros ni bastones.
            if (! $varianteBase->es_extra) {
                $variante->recetas()->create(['insumo_id' => $extra->id, 'cantidad' => $cantidad]);
            }

            $variante->recalcularCosto();
        }
    }

    private function bandejas(Insumo $aros, Insumo $bastones): void
    {
        if (Categoria::where('nombre', 'Aros y bastones')->exists()) {
            return;
        }

        // Va justo después de "Papas fritas" en el POS.
        $papas = Categoria::where('nombre', 'like', 'Papas%')->first();
        $orden = $papas ? $papas->orden + 1 : (int) Categoria::max('orden') + 1;
        Categoria::where('orden', '>=', $orden)->increment('orden');

        $categoria = Categoria::create([
            'nombre' => 'Aros y bastones',
            'negocio' => 'burger',
            'orden' => $orden,
            'activo' => true,
        ]);

        $aceite = Insumo::where('nombre', 'Aceite freidora')->first();
        $garrafa = Insumo::where('nombre', 'Garrafa')->first();

        $carta = [
            ['Bandeja de aros', $aros, [['6 aros', 6000, 0.12], ['12 aros', 10000, 0.24]]],
            ['Bandeja de bastones', $bastones, [['6 bastones', 6000, 0.21], ['12 bastones', 10000, 0.42]]],
        ];

        foreach ($carta as $i => [$nombre, $insumo, $variantes]) {
            $producto = Producto::create([
                'categoria_id' => $categoria->id,
                'nombre' => $nombre,
                'activo' => true,
                'orden' => $i,
            ]);

            foreach ($variantes as $j => [$variante, $precio, $kg]) {
                $v = $producto->variantes()->create([
                    'nombre' => $variante,
                    'precio_venta' => $precio,
                    'costo_calculado' => 0,
                    'activo' => true,
                    'orden' => $j,
                ]);

                $v->recetas()->create(['insumo_id' => $insumo->id, 'cantidad' => $kg]);
                foreach ([$aceite, $garrafa] as $otro) {
                    if ($otro) {
                        $v->recetas()->create(['insumo_id' => $otro->id, 'cantidad' => 1]);
                    }
                }

                $v->recalcularCosto();
            }
        }
    }

    public function down(): void
    {
        //
    }
};
