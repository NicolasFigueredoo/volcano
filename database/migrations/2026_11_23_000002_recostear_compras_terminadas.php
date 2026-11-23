<?php

use App\Models\Tanda;
use App\Services\TandaService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Las compras que ya se terminaron (21-22/11) quedaron con el costo por
     * sanguche estimado. Se pasa al costo real (pagado ÷ vendido) y se
     * recalcula la ganancia de esas cajas.
     */
    public function up(): void
    {
        Tanda::cerradas()->orderBy('id')->get()->each(fn (Tanda $t) => TandaService::recostear($t));
    }

    public function down(): void
    {
        //
    }
};
