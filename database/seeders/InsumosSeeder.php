<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InsumosSeeder extends Seeder
{
    public function run(): void
    {
        $insumos = [
            // nombre                   | unidad              | costo_unit | stock_actual | stock_minimo
            ['Carne (vacío)',            'medallón 100g',       1590,          0,              20],
            ['Pan de hamburguesa',       'unidad',               417,         60,              12],
            ['Cheddar',                  'feta',                 169,        147,              50],
            ['Panceta',                  'porción',              321,         10,              10],
            ['Papas',                    'porción 150g',         500,          1,               8],
            ['Cebolla',                  'media unidad',         100,          6,               8],
            ['Tomate',                   'porción',              100,          0,               4],
            ['Lechuga',                  'porción',              100,          0,               4],
            ['Verdeo',                   'porción',              200,          0,               2],
            ['Huevo',                    'unidad',               150,          8,               6],
            ['Bolsa delivery',           'unidad',                55,         50,              20],
            ['Cartón papas',             'unidad',                75,        100,              20],
            ['Aluminio',                 'unidad',               155,        160,              30],
            ['Aceite freidora',          'por venta',            222,          1,               3],
            ['Garrafa',                  'por venta',            278,          0,               1],
            ['Salsa',                    'por burger',           200,          0,               2],
            ['Coca Cola',                'lata',                1200,          0,              12],
            ['Sprite',                   'lata',                 600,          0,              12],
        ];

        foreach ($insumos as [$nombre, $unidad, $costo, $stock_actual, $stock_minimo]) {
            DB::table('insumos')->insert([
                'nombre'         => $nombre,
                'unidad'         => $unidad,
                'costo_unitario' => $costo,
                'stock_actual'   => $stock_actual,
                'stock_minimo'   => $stock_minimo,
                'activo'         => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }
}