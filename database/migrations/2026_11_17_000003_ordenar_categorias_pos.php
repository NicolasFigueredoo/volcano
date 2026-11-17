<?php

use App\Models\Categoria;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * El POS muestra una pestaña por categoría, en este orden:
     * Burger, Parrilla, Papas fritas, Bebidas (y después el resto).
     * "Adicionales" pasa a llamarse "Papas fritas".
     */
    public function up(): void
    {
        Categoria::whereRaw('LOWER(nombre) = ?', ['adicionales'])->update(['nombre' => 'Papas fritas']);

        $prioridad = ['burger', 'parrilla', 'papa', 'bebida'];

        Categoria::orderBy('orden')->orderBy('id')->get()
            ->sortBy(function (Categoria $c) use ($prioridad) {
                $nombre = mb_strtolower($c->nombre);

                foreach ($prioridad as $i => $clave) {
                    if (str_contains($nombre, $clave)) {
                        return $i;
                    }
                }

                return count($prioridad);
            })
            ->values()
            ->each(fn (Categoria $c, int $i) => $c->update(['orden' => $i + 1]));
    }

    public function down(): void
    {
        Categoria::whereRaw('LOWER(nombre) = ?', ['papas fritas'])->update(['nombre' => 'Adicionales']);
    }
};
