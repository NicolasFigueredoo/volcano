<?php

use Database\Seeders\CartaBebidasSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Carga los tragos/latas, sus insumos y la inversión inicial en la base
     * existente. En una base vacía no hace nada: ahí lo carga el seeder.
     */
    public function up(): void
    {
        (new CartaBebidasSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};
