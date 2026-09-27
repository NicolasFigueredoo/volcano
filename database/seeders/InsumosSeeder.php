<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InsumosSeeder extends Seeder
{
    public function run(): void
    {
        $insumos = [
            // nombre                   | unidad              | costo_unit | stock_actual | stock_minimo | grupo_separacion
            ['Carne (vacío)',            'medallón 100g',       1590,          0,              20, 'carne'],
            ['Pan de hamburguesa',       'unidad',               417,         60,              12, 'pan'],
            ['Cheddar',                  'feta',                 169,        147,              50, 'cheddar'],
            ['Panceta',                  'porción',              321,         10,              10, 'panceta'],
            ['Papas',                    'porción 150g',         500,          1,               8, 'papas'],
            ['Cebolla',                  'media unidad',         100,          6,               8, 'varios'],
            ['Tomate',                   'porción',              100,          0,               4, 'varios'],
            ['Lechuga',                  'porción',              100,          0,               4, 'varios'],
            ['Verdeo',                   'porción',              200,          0,               2, 'varios'],
            ['Huevo',                    'unidad',               150,          8,               6, 'varios'],
            ['Bolsa delivery',           'unidad',                55,         50,              20, 'descartables'],
            ['Cartón papas',             'unidad',                75,        100,              20, 'descartables'],
            ['Aluminio',                 'unidad',               155,        160,              30, 'descartables'],
            ['Aceite freidora',          'por venta',            222,          1,               3, 'varios'],
            ['Garrafa',                  'por venta',            278,          0,               1, 'varios'],
            ['Salsa',                    'por burger',           200,          0,               2, 'varios'],
            ['Coca Cola',                'lata',                1200,          0,              12, 'bebidas'],
            ['Sprite',                   'lata',                 600,          0,              12, 'bebidas'],
        ];

        foreach ($insumos as [$nombre, $unidad, $costo, $stock_actual, $stock_minimo, $grupo]) {
            DB::table('insumos')->insert([
                'nombre' => $nombre,
                'unidad' => $unidad,
                'costo_unitario' => $costo,
                'stock_actual' => $stock_actual,
                'stock_minimo' => $stock_minimo,
                'grupo_separacion' => $grupo,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
