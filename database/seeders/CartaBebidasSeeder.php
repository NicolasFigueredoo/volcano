<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Variante;
use Illuminate\Database\Seeder;

/**
 * Carta de tragos y latas. Idempotente: se puede correr varias veces (lo usa
 * también la migración 2026_10_30_000002) sin duplicar ni pisar lo que el
 * admin ya cambió.
 *
 * Los líquidos van en litros y el hielo en kg para que costo × cantidad dé
 * exacto con costo_unitario de 2 decimales (ej: Fernet $21.000/L × 0,2 L).
 */
class CartaBebidasSeeder extends Seeder
{
    /** nombre => [unidad, costo_unitario, unidad_compra, equivalencia_compra] */
    private const INSUMOS = [
        'Fernet' => ['L', 21000, 'botella 1L', 1],
        'Federico de Alvear' => ['L', 5600, 'botella 750cc', 0.75],
        'Skyy' => ['L', 12400, 'botella 750cc', 0.75],
        'Gancia' => ['L', 6736.84, 'botella 950cc', 0.95],
        'Coca Cola 2,25L' => ['L', 2222.22, 'botella 2,25L', 2.25],
        'Sprite 2,25L' => ['L', 2222.22, 'botella 2,25L', 2.25],
        'Speed' => ['lata', 2000, null, null],
        'Limón' => ['unidad', 300, null, null],
        'Hielo' => ['kg', 466.67, 'bolsa 15kg', 15],
        'Vaso 1L' => ['unidad', 300, null, null],
        'Brahma lata' => ['lata', 1750, 'pack x6', 6],
        'Schneider latón 710' => ['lata', 2316.67, 'pack x6', 6],
    ];

    /** producto => [variante, precio, [insumo => cantidad]] */
    private const CARTA = [
        'Fernet con Coca' => ['Vaso 1L', 10000, ['Fernet' => 0.2, 'Coca Cola 2,25L' => 0.5, 'Hielo' => 0.3, 'Vaso 1L' => 1]],
        'Federico + Speed' => ['Vaso 1L', 8000, ['Federico de Alvear' => 0.15, 'Speed' => 1, 'Hielo' => 0.3, 'Vaso 1L' => 1]],
        'Skyy + Speed' => ['Vaso 1L', 9000, ['Skyy' => 0.15, 'Speed' => 1, 'Hielo' => 0.3, 'Vaso 1L' => 1]],
        'Gancia con Sprite' => ['Vaso 1L', 7500, ['Gancia' => 0.19, 'Sprite 2,25L' => 0.5, 'Limón' => 0.5, 'Hielo' => 0.3, 'Vaso 1L' => 1]],
        'Brahma' => ['Lata', 2800, ['Brahma lata' => 1]],
        'Schneider' => ['Latón 710ml', 3800, ['Schneider latón 710' => 1]],
        'Speed' => ['Lata', 3500, ['Speed' => 1]],
    ];

    private const INVERSION_INICIAL = 150000;

    public function run(): void
    {
        $categoria = Categoria::bebidas();

        if (! $categoria) {
            return;
        }

        $insumos = [];

        foreach (self::INSUMOS as $nombre => [$unidad, $costo, $unidadCompra, $equivalencia]) {
            $insumos[$nombre] = Insumo::firstOrCreate(['nombre' => $nombre], [
                'unidad' => $unidad,
                'costo_unitario' => $costo,
                'stock_actual' => 0,
                'stock_minimo' => 0,
                'descuenta_stock' => true,
                'grupo_separacion' => 'bebidas',
                'unidad_compra' => $unidadCompra,
                'equivalencia_compra' => $equivalencia,
            ]);
        }

        $orden = (int) Producto::where('categoria_id', $categoria->id)->max('orden');

        foreach (self::CARTA as $nombre => [$varianteNombre, $precio, $receta]) {
            $producto = Producto::firstOrCreate(
                ['categoria_id' => $categoria->id, 'nombre' => $nombre],
                ['activo' => true, 'orden' => ++$orden]
            );

            $variante = Variante::firstOrCreate(
                ['producto_id' => $producto->id, 'nombre' => $varianteNombre],
                ['precio_venta' => $precio, 'costo_calculado' => 0, 'activo' => true]
            );

            if (! $variante->wasRecentlyCreated) {
                continue;
            }

            foreach ($receta as $insumo => $cantidad) {
                Receta::create([
                    'variante_id' => $variante->id,
                    'insumo_id' => $insumos[$insumo]->id,
                    'cantidad' => $cantidad,
                ]);
            }

            $variante->recalcularCosto();
        }

        if (! $categoria->inversiones()->exists()) {
            $categoria->inversiones()->create([
                'monto' => self::INVERSION_INICIAL,
                'fecha' => now()->toDateString(),
                'descripcion' => 'Inversión inicial bebidas',
            ]);
        }
    }
}
