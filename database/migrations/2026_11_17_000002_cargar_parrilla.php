<?php

use App\Models\Categoria;
use App\Models\GastoFijo;
use App\Models\Insumo;
use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gastos fijos por jornada y carta inicial de la parrilla.
     *
     * Costos de arranque (después se cambian desde Admin):
     * - Vacío: 4,4 kg a $81.500 → $18.522,73/kg. Sanguche: 220 g crudo + pan.
     * - Bondiola: 1,84 kg a $19.320 → $10.500/kg. Sanguche: 230 g crudo + pan.
     * - Chorizo: 53 a $55.500 → $1.047,17 c/u. Choripán: 1 chori + pan.
     * - Pan de sanguche: $250.
     * - Por jornada: parrillero $30.000, carbón $3.500, criolla y chimi $4.500.
     *   Quedan desactivados hasta que arranque la parrilla.
     */
    public function up(): void
    {
        Schema::table('gastos_fijos', function (Blueprint $table) {
            $table->string('periodicidad', 20)->default('mensual')->after('negocio');
            $table->decimal('monto_jornada', 10, 2)->nullable()->after('periodicidad');
        });

        if (Categoria::where('negocio', 'parrilla')->exists()) {
            return;
        }

        $insumo = fn (string $nombre, string $unidad, float $costo, string $grupo) => Insumo::firstOrCreate(
            ['nombre' => $nombre],
            [
                'unidad' => $unidad,
                'costo_unitario' => round($costo, 2),
                'stock_actual' => 0,
                'stock_minimo' => 0,
                'descuenta_stock' => true,
                'grupo_separacion' => $grupo,
                'activo' => true,
            ],
        );

        $vacio = $insumo('Vacío (parrilla)', 'kg', 81500 / 4.4, 'vacio');
        $bondiola = $insumo('Bondiola', 'kg', 19320 / 1.84, 'bondiola');
        $chorizo = $insumo('Chorizo', 'unidad', 55500 / 53, 'chorizo');
        $pan = $insumo('Pan de sanguche', 'unidad', 250, 'pan_sanguche');

        $categoria = Categoria::create([
            'nombre' => 'Parrilla',
            'negocio' => 'parrilla',
            'orden' => (int) Categoria::max('orden') + 1,
            'activo' => true,
        ]);

        $carta = [
            ['Vacío', 'Sanguche', 9000, [[$vacio, 0.22], [$pan, 1]]],
            ['Bondiola', 'Sanguche', 7000, [[$bondiola, 0.23], [$pan, 1]]],
            ['Choripán', 'Único', 2500, [[$chorizo, 1], [$pan, 1]]],
        ];

        foreach ($carta as $orden => [$nombre, $variante, $precio, $receta]) {
            $producto = Producto::create([
                'categoria_id' => $categoria->id,
                'nombre' => $nombre,
                'activo' => true,
                'orden' => $orden,
            ]);

            $v = $producto->variantes()->create([
                'nombre' => $variante,
                'precio_venta' => $precio,
                'costo_calculado' => 0,
                'activo' => true,
                'orden' => 0,
            ]);

            foreach ($receta as [$ins, $cantidad]) {
                $v->recetas()->create(['insumo_id' => $ins->id, 'cantidad' => $cantidad]);
            }

            $v->recalcularCosto();
        }

        foreach (['Parrillero' => 30000, 'Carbón' => 3500, 'Criolla y chimi' => 4500] as $nombre => $monto) {
            GastoFijo::create([
                'nombre' => $nombre,
                'negocio' => 'parrilla',
                'periodicidad' => 'jornada',
                'monto_jornada' => $monto,
                'dias_apertura_mes' => 16,
                // Arrancan apagados: se prenden desde Admin el día que abre la
                // parrilla, si no se le cargan a cajas donde solo vendió la burger.
                'activo' => false,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('gastos_fijos', function (Blueprint $table) {
            $table->dropColumn(['periodicidad', 'monto_jornada']);
        });
    }
};
