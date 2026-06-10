<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GastosFijosSeeder extends Seeder
{
    public function run(): void
    {
        $gastos = [
            // nombre            | monto_mensual | dias_apertura_mes
            ['Alquiler',          200000,          16],
            ['Empleado 1',         15000,          16], // por día de apertura
            ['Empleado 2',         15000,          16],
            ['Garrafa / Luz',      50000,          16],
        ];

        foreach ($gastos as [$nombre, $monto, $dias]) {
            DB::table('gastos_fijos')->insert([
                'nombre'              => $nombre,
                'monto_mensual'       => $monto,
                'dias_apertura_mes'   => $dias,
                'activo'              => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }
}
