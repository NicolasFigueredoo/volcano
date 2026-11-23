<?php

use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Tanda;
use App\Models\User;
use App\Services\TandaService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Parrilla, 22/11/2026:
     * - Bandeja de pollo: 1 pata + 1 muslo (3 kg dan 6 bandejas → 0,5 kg c/u)
     *   + bandeja $500. Pata y muslo: 3 kg a $12.000 → $2.000 por bandeja.
     *   · Con arroz $7.000: arroz $500 → costo $3.000, deja $4.000.
     *   · Con papas $7.500: papas + aceite + garrafa (~$1.000) → costo
     *     ~$3.500, deja ~$4.000.
     * - Choripán XL a $4.000: chorizo XL ($1.500 c/u) + pan de sanguche.
     */
    public function up(): void
    {
        $choripan = Producto::where('nombre', 'Choripán')->first();
        $categoria = $choripan?->categoria_id;

        if (! $categoria) {
            return;
        }

        $insumo = fn (string $nombre, string $unidad, float $costo, string $grupo, bool $descuenta) => Insumo::firstOrCreate(
            ['nombre' => $nombre],
            [
                'unidad' => $unidad,
                'costo_unitario' => $costo,
                'stock_actual' => 0,
                'stock_minimo' => 0,
                'descuenta_stock' => $descuenta,
                'grupo_separacion' => $grupo,
                'activo' => true,
            ],
        );

        // ── Bandeja de pollo con arroz ──────────────────────────────────────
        if (! Producto::where('nombre', 'Bandeja de pollo')->exists()) {
            $pollo = $insumo('Pata y muslo', 'kg', 4000, 'pollo', true);
            $arroz = $insumo('Porción de arroz', 'unidad', 500, 'varios', false);
            $bandeja = $insumo('Bandeja descartable', 'unidad', 500, 'varios', false);

            // Papas: lo mismo que lleva el cono (porción 150 g + aceite + garrafa).
            $papas = collect(['Papas', 'Aceite freidora', 'Garrafa'])
                ->map(fn ($n) => Insumo::where('nombre', $n)->first())
                ->filter();

            $producto = Producto::create([
                'categoria_id' => $categoria,
                'nombre' => 'Bandeja de pollo',
                'activo' => true,
                'orden' => (int) Producto::where('categoria_id', $categoria)->max('orden') + 1,
            ]);

            $opciones = [
                ['Con arroz', 7000, [[$arroz, 1]]],
                ['Con papas', 7500, $papas->map(fn ($i) => [$i, 1])->all()],
            ];

            foreach ($opciones as $orden => [$nombre, $precio, $guarnicion]) {
                $variante = $producto->variantes()->create([
                    'nombre' => $nombre,
                    'precio_venta' => $precio,
                    'costo_calculado' => 0,
                    'activo' => true,
                    'orden' => $orden,
                ]);

                $variante->recetas()->create(['insumo_id' => $pollo->id, 'cantidad' => 0.5]);
                foreach ($guarnicion as [$ins, $cantidad]) {
                    $variante->recetas()->create(['insumo_id' => $ins->id, 'cantidad' => $cantidad]);
                }
                $variante->recetas()->create(['insumo_id' => $bandeja->id, 'cantidad' => 1]);
                $variante->recalcularCosto();
            }

            // La compra de hoy: 3 kg a $12.000, 6 bandejas.
            $adminId = User::where('role', 'admin')->orderBy('id')->value('id');

            if ($adminId && ! Tanda::where('insumo_id', $pollo->id)->exists()) {
                TandaService::crear([
                    'insumo_id' => $pollo->id,
                    'cantidad' => 3,
                    'total' => 12000,
                    'porciones_estimadas' => 6,
                    'observacion' => 'Pata y muslo 3 kg a $12.000',
                ], $adminId);
            }
        }

        // ── Choripán XL ─────────────────────────────────────────────────────
        if (! $choripan->variantes()->where('nombre', 'XL')->exists()) {
            $comun = $choripan->variantes()->with('recetas')->orderBy('orden')->first();

            // "Único" deja de tener sentido al haber dos tamaños.
            if ($comun && $comun->nombre === 'Único') {
                $comun->update(['nombre' => 'Común']);
            }

            $xl = $choripan->variantes()->create([
                'nombre' => 'XL',
                'precio_venta' => 4000,
                'costo_calculado' => 0,
                'activo' => true,
                'orden' => (int) $choripan->variantes()->max('orden') + 1,
            ]);

            // Chorizo más grande ($1.500 c/u) + el mismo pan del común.
            $chorizoXl = $insumo('Chorizo XL', 'unidad', 1500, 'chorizo', true);
            $xl->recetas()->create(['insumo_id' => $chorizoXl->id, 'cantidad' => 1]);

            $pan = Insumo::where('nombre', 'Pan de sanguche')->first();
            if ($pan) {
                $xl->recetas()->create(['insumo_id' => $pan->id, 'cantidad' => 1]);
            }

            $xl->recalcularCosto();
        }
    }

    public function down(): void
    {
        //
    }
};
