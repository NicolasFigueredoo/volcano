<?php

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fusión burger + parrilla: cada categoría pertenece a un negocio
     * (burger, parrilla o general para lo que comparten los dos: bebidas y
     * delivery),
     * cada línea vendida guarda el negocio que tenía al momento de la venta, y
     * cada gasto fijo es de un negocio o compartido.
     *
     * Además crea la categoría Delivery (compartida) con el producto Envío
     * a $500 y $1000, para cobrar el envío desde el POS.
     */
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->string('negocio', 20)->default('burger')->after('nombre');
        });

        Schema::table('detalle_ventas', function (Blueprint $table) {
            $table->string('negocio', 20)->default('burger')->after('variante_id');
        });

        Schema::table('gastos_fijos', function (Blueprint $table) {
            $table->string('negocio', 20)->default('compartido')->after('nombre');
        });

        // Bebidas y delivery los venden los dos negocios. Las papas
        // (adicionales) quedan en la burger, que es la que las compra.
        Categoria::whereRaw('LOWER(nombre) LIKE ?', ['%bebida%'])
            ->orWhereRaw('LOWER(nombre) = ?', ['delivery'])
            ->update(['negocio' => 'general']);

        // Las ventas viejas toman el negocio actual de su categoría.
        DB::table('detalle_ventas')
            ->join('variantes', 'variantes.id', '=', 'detalle_ventas.variante_id')
            ->join('productos', 'productos.id', '=', 'variantes.producto_id')
            ->join('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->where('categorias.negocio', 'general')
            ->pluck('detalle_ventas.id')
            ->chunk(500)
            ->each(fn ($ids) => DB::table('detalle_ventas')->whereIn('id', $ids)->update(['negocio' => 'general']));

        // Hasta ahora todos los gastos eran de la burger, salvo el alquiler
        // que se comparte mitad y mitad.
        DB::table('gastos_fijos')
            ->whereRaw('LOWER(nombre) NOT LIKE ?', ['%alquiler%'])
            ->update(['negocio' => 'burger']);

        if (! Categoria::whereRaw('LOWER(nombre) = ?', ['delivery'])->exists()) {
            $delivery = Categoria::create([
                'nombre' => 'Delivery',
                'negocio' => 'general',
                'orden' => (int) Categoria::max('orden') + 1,
                'activo' => true,
            ]);

            $envio = Producto::create([
                'categoria_id' => $delivery->id,
                'nombre' => 'Envío',
                'activo' => true,
            ]);

            foreach ([500, 1000] as $i => $precio) {
                $envio->variantes()->create([
                    'nombre' => '$'.number_format($precio, 0, ',', '.'),
                    'precio_venta' => $precio,
                    'costo_calculado' => 0,
                    'activo' => true,
                    'orden' => $i,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categorias', fn (Blueprint $table) => $table->dropColumn('negocio'));
        Schema::table('detalle_ventas', fn (Blueprint $table) => $table->dropColumn('negocio'));
        Schema::table('gastos_fijos', fn (Blueprint $table) => $table->dropColumn('negocio'));
    }
};
