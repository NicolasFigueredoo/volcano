<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\GastoFijo;
use App\Models\Insumo;
use App\Models\Inversion;
use App\Models\Producto;
use App\Models\Variante;
use App\Models\Venta;
use App\Services\InversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    // ── Productos ────────────────────────────────────────────────────────────

    public function productos(): JsonResponse
    {
        return response()->json(
            Producto::with(['categoria', 'variantes'])->orderBy('orden')->get()
        );
    }

    public function crearProducto(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'categoria_id' => 'required|exists:categorias,id',
            'descripcion' => 'nullable|string',
            'activo' => 'boolean',
            'orden' => 'integer',
        ]);

        $producto = Producto::create(
            $request->only('nombre', 'categoria_id', 'descripcion', 'activo', 'orden')
        );

        return response()->json($producto->load('categoria'), 201);
    }

    public function actualizarProducto(Request $request, Producto $producto): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'categoria_id' => 'sometimes|exists:categorias,id',
            'descripcion' => 'nullable|string',
            'activo' => 'boolean',
            'orden' => 'integer',
        ]);

        $producto->update(
            $request->only('nombre', 'categoria_id', 'descripcion', 'activo', 'orden')
        );

        return response()->json($producto->load(['categoria', 'variantes']));
    }

    public function eliminarProducto(Producto $producto): JsonResponse
    {
        $producto->update(['activo' => false]);

        return response()->json(['message' => 'Producto desactivado']);
    }

    // ── Variantes ────────────────────────────────────────────────────────────

    public function crearVariante(Request $request, Producto $producto): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:50',
            'precio_venta' => 'required|numeric|min:0',
            'activo' => 'boolean',
            'orden' => 'integer',
        ]);

        $variante = $producto->variantes()->create(
            $request->only('nombre', 'precio_venta', 'activo', 'orden')
        );

        $variante->recalcularCosto();

        return response()->json($variante, 201);
    }

    public function actualizarVariante(Request $request, Variante $variante): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:50',
            'precio_venta' => 'sometimes|numeric|min:0',
            'activo' => 'boolean',
            'orden' => 'integer',
        ]);

        $variante->update(
            $request->only('nombre', 'precio_venta', 'activo', 'orden')
        );

        return response()->json($variante);
    }

    // ── Insumos ──────────────────────────────────────────────────────────────

    public function insumos(): JsonResponse
    {
        return response()->json(
            Insumo::orderBy('nombre')->get()
        );
    }

    public function crearInsumo(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'unidad' => 'required|string|max:50',
            'costo_unitario' => 'required|numeric|min:0',
            'stock_minimo' => 'required|numeric|min:0',
            'descuenta_stock' => 'boolean',
            'grupo_separacion' => ['nullable', Rule::in(Insumo::GRUPOS_SEPARACION)],
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'unidad_compra' => 'nullable|string|max:50',
            'equivalencia_compra' => 'nullable|numeric|gt:0',
        ]);

        $insumo = Insumo::create(
            $request->only(
                'nombre',
                'unidad',
                'costo_unitario',
                'stock_minimo',
                'descuenta_stock',
                'grupo_separacion',
                'proveedor_id',
                'unidad_compra',
                'equivalencia_compra'
            )
        );

        return response()->json($insumo, 201);
    }

    public function actualizarInsumo(Request $request, Insumo $insumo): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'unidad' => 'sometimes|string|max:50',
            'costo_unitario' => 'sometimes|numeric|min:0',
            'stock_minimo' => 'sometimes|numeric|min:0',
            'activo' => 'boolean',
            'descuenta_stock' => 'boolean',
            'grupo_separacion' => ['nullable', Rule::in(Insumo::GRUPOS_SEPARACION)],
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'unidad_compra' => 'nullable|string|max:50',
            'equivalencia_compra' => 'nullable|numeric|gt:0',
        ]);

        $insumo->update(
            $request->only(
                'nombre',
                'unidad',
                'costo_unitario',
                'stock_minimo',
                'activo',
                'descuenta_stock',
                'grupo_separacion',
                'proveedor_id',
                'unidad_compra',
                'equivalencia_compra'
            )
        );

        if ($request->has('costo_unitario')) {
            $insumo->loadMissing('recetas.variante');

            $insumo->recetas->each(function ($receta) {
                if ($receta->variante) {
                    $receta->variante->recalcularCosto();
                }
            });
        }

        return response()->json($insumo->fresh());
    }

    // ── Categorías ───────────────────────────────────────────────────────────

    public function categorias(): JsonResponse
    {
        return response()->json(
            Categoria::orderBy('orden')->get()
        );
    }

    public function crearCategoria(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'negocio' => 'sometimes|in:'.implode(',', Categoria::NEGOCIOS),
            'orden' => 'integer',
        ]);

        $categoria = Categoria::create(
            $request->only('nombre', 'negocio', 'orden')
        );

        return response()->json($categoria, 201);
    }

    public function actualizarCategoria(Request $request, Categoria $categoria): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'negocio' => 'sometimes|in:'.implode(',', Categoria::NEGOCIOS),
            'orden' => 'sometimes|integer',
            'activo' => 'sometimes|boolean',
        ]);

        $categoria->update(
            $request->only('nombre', 'negocio', 'orden', 'activo')
        );

        return response()->json($categoria);
    }

    // ── Gastos Fijos ─────────────────────────────────────────────────────────

    public function gastosFijos(): JsonResponse
    {
        return response()->json(
            GastoFijo::orderBy('id')->get()
        );
    }

    public function crearGastoFijo(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'negocio' => 'sometimes|in:'.implode(',', GastoFijo::NEGOCIOS),
            'monto_mensual' => 'required|numeric|min:0',
            'dias_apertura_mes' => 'required|integer|min:1|max:31',
        ]);

        $gasto = GastoFijo::create(
            $request->only('nombre', 'negocio', 'monto_mensual', 'dias_apertura_mes')
        );

        return response()->json($gasto, 201);
    }

    public function actualizarGastoFijo(Request $request, GastoFijo $gasto): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'negocio' => 'sometimes|in:'.implode(',', GastoFijo::NEGOCIOS),
            'monto_mensual' => 'sometimes|numeric|min:0',
            'dias_apertura_mes' => 'sometimes|integer|min:1|max:31',
            'activo' => 'boolean',
        ]);

        $gasto->update(
            $request->only('nombre', 'negocio', 'monto_mensual', 'dias_apertura_mes', 'activo')
        );

        return response()->json($gasto);
    }

    // ── Bebidas e inversiones ────────────────────────────────────────────────

    public function bebidas(InversionService $inversiones): JsonResponse
    {
        $categoria = Categoria::bebidas();

        if (! $categoria) {
            return response()->json(['message' => 'No existe la categoría Bebidas'], 404);
        }

        $productos = Producto::where('categoria_id', $categoria->id)
            ->with(['variantes' => fn ($q) => $q->orderBy('orden'), 'variantes.recetas.insumo'])
            ->orderBy('orden')
            ->get();

        $insumoIds = $productos->flatMap->variantes->flatMap->recetas->pluck('insumo_id');

        $insumos = Insumo::where('grupo_separacion', 'bebidas')
            ->orWhereIn('id', $insumoIds)
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'categoria' => $categoria,
            'productos' => $productos,
            'insumos' => $insumos,
            'inversiones' => $categoria->inversiones()->orderByDesc('fecha')->orderByDesc('id')->get(),
            'recupero' => $inversiones->recupero($categoria),
        ]);
    }

    public function crearInversion(Request $request): JsonResponse
    {
        $request->validate([
            'categoria_id' => 'required|exists:categorias,id',
            'monto' => 'required|numeric|gt:0',
            'fecha' => 'required|date',
            'descripcion' => 'nullable|string|max:255',
        ]);

        $inversion = Inversion::create(
            $request->only('categoria_id', 'monto', 'fecha', 'descripcion')
        );

        return response()->json($inversion, 201);
    }

    public function actualizarInversion(Request $request, Inversion $inversion): JsonResponse
    {
        $request->validate([
            'monto' => 'sometimes|numeric|gt:0',
            'fecha' => 'sometimes|date',
            'descripcion' => 'nullable|string|max:255',
        ]);

        $inversion->update(
            $request->only('monto', 'fecha', 'descripcion')
        );

        return response()->json($inversion);
    }

    public function eliminarInversion(Inversion $inversion): JsonResponse
    {
        $inversion->delete();

        return response()->json(['message' => 'Inversión eliminada']);
    }

    // ── Variantes con receta ─────────────────────────────────────────────────

    public function varianteConReceta(Variante $variante): JsonResponse
    {
        $variante->load(['recetas.insumo', 'producto']);
        $variante->recalcularCosto();

        $variante = $variante->fresh(['recetas.insumo', 'producto']);

        return response()->json([
            'variante' => $variante,
            'recetas' => $variante->recetas->map(fn ($r) => [
                'id' => $r->id,
                'insumo_id' => $r->insumo_id,
                'insumo_nombre' => $r->insumo->nombre,
                'insumo_unidad' => $r->insumo->unidad,
                'costo_unitario' => $r->insumo->costo_unitario,
                'cantidad' => $r->cantidad,
                'costo_linea' => $r->cantidad * $r->insumo->costo_unitario,
            ]),
            'costo_total' => $variante->costo_calculado,
            'precio_venta' => $variante->precio_venta,
            'ganancia' => $variante->ganancia,
            'margen' => $variante->margen,
        ]);
    }

    public function guardarReceta(Request $request, Variante $variante): JsonResponse
    {
        $request->validate([
            'recetas' => 'required|array',
            'recetas.*.insumo_id' => 'required|exists:insumos,id',
            'recetas.*.cantidad' => 'required|numeric|min:0.001',
        ]);

        $variante->recetas()->delete();

        foreach ($request->recetas as $r) {
            $variante->recetas()->create([
                'insumo_id' => $r['insumo_id'],
                'cantidad' => $r['cantidad'],
            ]);
        }

        $variante->recalcularCosto();

        $variante = $variante->fresh();

        return response()->json([
            'costo_calculado' => $variante->costo_calculado,
            'margen' => $variante->margen,
        ]);
    }

    // ── Exportar resumen (para pasarle a Claude) ────────────────────────────

    /**
     * Arma un resumen en Markdown con la info critica del negocio: precios,
     * costos, margenes, recetas, insumos, gastos fijos y ventas recientes.
     */
    public function exportarResumen(Request $request): JsonResponse
    {
        $dias = max(1, (int) $request->integer('dias', 30));
        $desde = now()->subDays($dias)->toDateString();
        $m = fn ($n): string => '$'.number_format((float) $n, 0, ',', '.');
        $pct = fn ($gan, $precio): string => $precio > 0 ? round($gan / $precio * 100, 1).'%' : '—';
        $fila = fn (array $celdas): string => '| '.implode(' | ', $celdas).' |';

        $l = [];
        $l[] = '# Resumen del negocio — '.now()->format('d/m/Y H:i');
        $l[] = '';
        $l[] = "Montos en pesos argentinos. Ventas de los últimos {$dias} días (desde {$desde}). Ganancia = precio - costo de insumos (sin gastos fijos).";
        $l[] = '';

        // Carta
        $l[] = '## Carta: precio, costo y margen';
        $l[] = '';
        $l[] = '| Categoría | Producto | Variante | Precio | Costo | Ganancia | Margen | Activo | Receta |';
        $l[] = '|---|---|---|---|---|---|---|---|---|';
        $productos = Producto::with(['categoria', 'variantes' => fn ($q) => $q->orderBy('orden'), 'variantes.recetas.insumo'])
            ->orderBy('categoria_id')
            ->orderBy('orden')
            ->get();
        foreach ($productos as $p) {
            foreach ($p->variantes as $v) {
                $gan = $v->precio_venta - $v->costo_calculado;
                $receta = $v->recetas
                    ->map(fn ($r) => ($r->insumo?->nombre ?? '?').' x'.(float) $r->cantidad)
                    ->implode(', ');
                $l[] = $fila([
                    $p->categoria?->nombre ?? '—',
                    $p->nombre,
                    $v->nombre.($v->es_extra ? ' (agregado)' : ''),
                    $m($v->precio_venta),
                    $m($v->costo_calculado),
                    $m($gan),
                    $pct($gan, $v->precio_venta),
                    $v->activo ? 'sí' : 'no',
                    $receta ?: '—',
                ]);
            }
        }
        $l[] = '';

        // Combos
        $combos = Combo::with('items.variante.producto')->get();
        if ($combos->isNotEmpty()) {
            $l[] = '## Combos';
            $l[] = '';
            $l[] = '| Combo | Items | Precio | Costo | Ganancia | Margen | Activo |';
            $l[] = '|---|---|---|---|---|---|---|';
            foreach ($combos as $c) {
                $costo = $c->items->sum(fn ($i) => $i->variante->costo_calculado * $i->cantidad);
                $l[] = $fila([
                    $c->nombre,
                    $c->items->map(fn ($i) => $i->cantidad.'x '.$i->variante->producto->nombre.' '.$i->variante->nombre)->implode(', '),
                    $m($c->precio_total),
                    $m($costo),
                    $m($c->precio_total - $costo),
                    $pct($c->precio_total - $costo, $c->precio_total),
                    $c->activo ? 'sí' : 'no',
                ]);
            }
            $l[] = '';
        }

        // Insumos
        $l[] = '## Insumos';
        $l[] = '';
        $l[] = '| Insumo | Unidad | Costo unitario | Stock | Stock mínimo |';
        $l[] = '|---|---|---|---|---|';
        foreach (Insumo::orderBy('nombre')->get() as $i) {
            $l[] = $fila([$i->nombre, $i->unidad, $m($i->costo_unitario), (float) $i->stock_actual, (float) $i->stock_minimo]);
        }
        $l[] = '';

        // Gastos fijos
        $gastos = GastoFijo::where('activo', true)->get();
        $l[] = '## Gastos fijos (activos)';
        $l[] = '';
        $l[] = '| Gasto | Mensual | Días de apertura/mes | Por día |';
        $l[] = '|---|---|---|---|';
        foreach ($gastos as $g) {
            $l[] = $fila([$g->nombre, $m($g->monto_mensual), $g->dias_apertura_mes, $m($g->costo_diario)]);
        }
        $l[] = $fila(['**Total**', '**'.$m($gastos->sum('monto_mensual')).'**', '', '**'.$m($gastos->sum('costo_diario')).'**']);
        $l[] = '';

        // Cajas
        $cajas = Caja::whereDate('fecha_operativa', '>=', $desde)->orderBy('fecha_operativa')->get();
        $l[] = '## Cajas (días trabajados)';
        $l[] = '';
        $l[] = '| Fecha | Estado | Pedidos | Ventas | Efectivo | Transferencia | Costo insumos | Ganancia bruta | Gastos fijos | Ganancia neta |';
        $l[] = '|---|---|---|---|---|---|---|---|---|---|';
        foreach ($cajas as $c) {
            $l[] = $fila([
                $c->fecha_operativa->format('d/m/Y'),
                $c->estado,
                (int) $c->cantidad_ventas,
                $m($c->total_ventas),
                $m($c->total_efectivo),
                $m($c->total_transferencia),
                $m($c->costo_insumos),
                $m($c->ganancia_bruta),
                $m($c->gastos_fijos),
                $m($c->ganancia_neta),
            ]);
        }
        $cerradas = $cajas->where('estado', 'cerrada');
        $l[] = $fila([
            '**Total cerradas**', '', '**'.(int) $cerradas->sum('cantidad_ventas').'**',
            '**'.$m($cerradas->sum('total_ventas')).'**', '', '',
            '**'.$m($cerradas->sum('costo_insumos')).'**', '**'.$m($cerradas->sum('ganancia_bruta')).'**',
            '**'.$m($cerradas->sum('gastos_fijos')).'**', '**'.$m($cerradas->sum('ganancia_neta')).'**',
        ]);
        $l[] = '';
        $l[] = '_Los totales de una caja abierta se guardan al cerrarla (pueden figurar en 0)._';
        $l[] = '';

        // Unidades vendidas por item
        $vendidos = DB::table('detalle_ventas as d')
            ->join('ventas as v', 'v.id', '=', 'd.venta_id')
            ->where('v.estado', '!=', 'anulado')
            ->whereDate('v.fecha_operativa', '>=', $desde)
            ->groupBy('d.nombre_snapshot')
            ->selectRaw('d.nombre_snapshot as nombre, SUM(d.cantidad) as cant, SUM(d.subtotal) as facturado, SUM(d.costo_snapshot * d.cantidad) as costo')
            ->orderByDesc('cant')
            ->get();
        $l[] = '## Unidades vendidas por item';
        $l[] = '';
        $l[] = '| Item | Unidades | Facturado | Costo | Ganancia | Margen |';
        $l[] = '|---|---|---|---|---|---|';
        foreach ($vendidos as $r) {
            $gan = $r->facturado - $r->costo;
            $l[] = $fila([$r->nombre, (int) $r->cant, $m($r->facturado), $m($r->costo), $m($gan), $pct($gan, $r->facturado)]);
        }
        $l[] = '';

        // Pedidos a costo / regalo
        $especiales = Venta::with('detalles')
            ->where('estado', '!=', 'anulado')
            ->whereIn('tipo_precio', ['costo', 'regalo'])
            ->whereDate('fecha_operativa', '>=', $desde)
            ->get()
            ->groupBy('tipo_precio');
        if ($especiales->isNotEmpty()) {
            $l[] = '## Pedidos a precio costo / regalo';
            $l[] = '';
            $l[] = '| Tipo | Pedidos | Cobrado | Costo insumos |';
            $l[] = '|---|---|---|---|';
            foreach ($especiales as $tipo => $vs) {
                $costo = $vs->flatMap->detalles->sum(fn ($d) => $d->costo_snapshot * $d->cantidad);
                $l[] = $fila([$tipo, $vs->count(), $m($vs->sum('total')), $m($costo)]);
            }
            $l[] = '';
        }

        return response()->json(['texto' => implode("\n", $l)]);
    }
}
