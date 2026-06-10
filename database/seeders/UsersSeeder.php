<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Asegurate de tener la columna 'role' en la tabla users.
        // Si usás Breeze/Jetstream sin esa columna, agregala con:
        // $table->enum('role', ['admin', 'cajero'])->default('cajero');

        $users = [
            [
                'name'       => 'Admin',
                'email'      => 'admin@volcanoBurger.com',
                'password'   => Hash::make('admin123'),
                'role'       => 'admin',
            ],
            [
                'name'       => 'Cajero',
                'email'      => 'cajero@volcanoBurger.com',
                'password'   => Hash::make('caja123'),
                'role'       => 'cajero',
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->insertOrIgnore([
                ...$user,
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }
}