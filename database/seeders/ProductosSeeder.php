<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductosSeeder extends Seeder
{
    public function run(): void
    {
        // Categorías
        $cats = [
            ['nombre' => 'Burgers',      'orden' => 1],
            ['nombre' => 'Adicionales',  'orden' => 2],
            ['nombre' => 'Bebidas',      'orden' => 3],
        ];

        foreach ($cats as $cat) {
            DB::table('categorias')->insert([...$cat, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $catBurgers     = DB::table('categorias')->where('nombre', 'Burgers')->value('id');
        $catAdicionales = DB::table('categorias')->where('nombre', 'Adicionales')->value('id');
        $catBebidas     = DB::table('categorias')->where('nombre', 'Bebidas')->value('id');

        // Helper para insertar producto + variantes + receta
        // $variantes = [ ['nombre', precio_venta, costo_calculado, [ [insumo, cantidad], ... ]] ]
        $insertProducto = function (string $nombre, int $categoriaId, int $orden, array $variantes) {
            $productoId = DB::table('productos')->insertGetId([
                'nombre'       => $nombre,
                'categoria_id' => $categoriaId,
                'activo'       => true,
                'orden'        => $orden,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            foreach ($variantes as $i => [$varNombre, $precio, $costo, $receta]) {
                $varianteId = DB::table('variantes')->insertGetId([
                    'producto_id'      => $productoId,
                    'nombre'           => $varNombre,
                    'precio_venta'     => $precio,
                    'costo_calculado'  => $costo,
                    'activo'           => true,
                    'orden'            => $i + 1,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                foreach ($receta as [$insumoNombre, $cantidad]) {
                    $insumoId = DB::table('insumos')->where('nombre', $insumoNombre)->value('id');
                    if (!$insumoId) continue;
                    DB::table('recetas')->insert([
                        'variante_id' => $varianteId,
                        'insumo_id'   => $insumoId,
                        'cantidad'    => $cantidad,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        };

        // Insumos fijos presentes en todas las burgers
        $fijos = [
            ['Pan de hamburguesa',  1],
            ['Papas',               1],
            ['Cartón papas',        1],
            ['Bolsa delivery',      1],
            ['Aluminio',            1],
            ['Aceite freidora',     1],
            ['Garrafa',             1],
            ['Salsa',               1],
        ];

        // ── CHEESE ──────────────────────────────────────────────────────────
        $insertProducto('Cheese', $catBurgers, 1, [
            ['Simple', 10000, 3830, array_merge([['Carne (vacío)', 1], ['Cheddar', 2]], $fijos)],
            ['Doble',  11000, 5758, array_merge([['Carne (vacío)', 2], ['Cheddar', 4]], $fijos)],
            ['Triple', 12000, 7686, array_merge([['Carne (vacío)', 3], ['Cheddar', 6]], $fijos)],
        ]);

        // ── BACON ────────────────────────────────────────────────────────────
        $insertProducto('Bacon', $catBurgers, 2, [
            ['Simple', 11000, 3982, array_merge([['Carne (vacío)', 1], ['Cheddar', 1], ['Panceta', 1]], $fijos)],
            ['Doble',  12000, 6062, array_merge([['Carne (vacío)', 2], ['Cheddar', 2], ['Panceta', 2]], $fijos)],
            ['Triple', 13000, 8142, array_merge([['Carne (vacío)', 3], ['Cheddar', 3], ['Panceta', 3]], $fijos)],
        ]);

        // ── CARAMEL ──────────────────────────────────────────────────────────
        $insertProducto('Caramel', $catBurgers, 3, [
            ['Simple', 11000, 3761, array_merge([['Carne (vacío)', 1], ['Cheddar', 1], ['Cebolla', 1]], $fijos)],
            ['Doble',  12000, 5520, array_merge([['Carne (vacío)', 2], ['Cheddar', 2], ['Cebolla', 1]], $fijos)],
            ['Triple', 13000, 7279, array_merge([['Carne (vacío)', 3], ['Cheddar', 3], ['Cebolla', 1]], $fijos)],
        ]);

        // ── VOLCANO ──────────────────────────────────────────────────────────
        $insertProducto('Volcano', $catBurgers, 4, [
            ['Simple', 11000, 4232, array_merge([['Carne (vacío)', 1], ['Cheddar', 1], ['Panceta', 1], ['Cebolla', 1], ['Huevo', 1]], $fijos)],
            ['Doble',  12000, 6312, array_merge([['Carne (vacío)', 2], ['Cheddar', 2], ['Panceta', 2], ['Cebolla', 1], ['Huevo', 1]], $fijos)],
            ['Triple', 14000, 8392, array_merge([['Carne (vacío)', 3], ['Cheddar', 3], ['Panceta', 3], ['Cebolla', 1], ['Huevo', 1]], $fijos)],
        ]);

        // ── CLÁSICA ──────────────────────────────────────────────────────────
        $insertProducto('Clásica', $catBurgers, 5, [
            ['Simple', 10000, 3861, array_merge([['Carne (vacío)', 1], ['Cheddar', 1], ['Tomate', 1], ['Lechuga', 1]], $fijos)],
            ['Doble',  11000, 5620, array_merge([['Carne (vacío)', 2], ['Cheddar', 2], ['Tomate', 1], ['Lechuga', 1]], $fijos)],
            ['Triple', 12000, 7379, array_merge([['Carne (vacío)', 3], ['Cheddar', 3], ['Tomate', 1], ['Lechuga', 1]], $fijos)],
        ]);

        // ── INFERNO (sin precios aún, los ponés vos) ─────────────────────────
        $insertProducto('Inferno', $catBurgers, 6, [
            ['Simple', 11000, 0, array_merge([['Carne (vacío)', 1], ['Cheddar', 1], ['Panceta', 1], ['Cebolla', 1], ['Huevo', 1]], $fijos)],
            ['Doble',  12000, 0, array_merge([['Carne (vacío)', 2], ['Cheddar', 2], ['Panceta', 2], ['Cebolla', 1], ['Huevo', 1]], $fijos)],
            ['Triple', 14000, 0, array_merge([['Carne (vacío)', 3], ['Cheddar', 3], ['Panceta', 3], ['Cebolla', 1], ['Huevo', 1]], $fijos)],
        ]);

        // ── ADICIONALES ──────────────────────────────────────────────────────
        $insertProducto('Bandeja Chica', $catAdicionales, 1, [
            ['Única', 6000, 2124, [['Carne (vacío)', 1], ['Verdeo', 1], ['Aceite freidora', 1], ['Garrafa', 1]]],
        ]);

        $insertProducto('Bandeja Grande', $catAdicionales, 2, [
            ['Única', 9000, 2604, [['Carne (vacío)', 1], ['Verdeo', 1], ['Aceite freidora', 1], ['Garrafa', 1]]],
        ]);

        // ── BEBIDAS ──────────────────────────────────────────────────────────
        $insertProducto('Coca Cola', $catBebidas, 1, [
            ['Lata', 2000, 1200, [['Coca Cola', 1]]],
        ]);

        $insertProducto('Sprite', $catBebidas, 2, [
            ['Lata', 2000, 600, [['Sprite', 1]]],
        ]);
    }
}