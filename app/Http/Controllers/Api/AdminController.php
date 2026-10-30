<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\GastoFijo;
use App\Models\Insumo;
use App\Models\Inversion;
use App\Models\Producto;
use App\Models\Variante;
use App\Services\InversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'orden' => 'integer',
        ]);

        $categoria = Categoria::create(
            $request->only('nombre', 'orden')
        );

        return response()->json($categoria, 201);
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
            'monto_mensual' => 'required|numeric|min:0',
            'dias_apertura_mes' => 'required|integer|min:1|max:31',
        ]);

        $gasto = GastoFijo::create(
            $request->only('nombre', 'monto_mensual', 'dias_apertura_mes')
        );

        return response()->json($gasto, 201);
    }

    public function actualizarGastoFijo(Request $request, GastoFijo $gasto): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'monto_mensual' => 'sometimes|numeric|min:0',
            'dias_apertura_mes' => 'sometimes|integer|min:1|max:31',
            'activo' => 'boolean',
        ]);

        $gasto->update(
            $request->only('nombre', 'monto_mensual', 'dias_apertura_mes', 'activo')
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
}
