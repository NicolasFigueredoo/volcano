<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosController extends Controller
{
    // GET /api/pos/menu
    public function menu(): JsonResponse
    {
        $categorias = Categoria::activo()
            ->with(['productos' => function ($q) {
                $q->activo()->with(['variantes' => fn ($q) => $q->activo()]);
            }])
            ->get();

        $combos = Combo::activo()
            ->with(['items.variante.producto'])
            ->get()
            ->map(fn ($c) => [
                'id'           => $c->id,
                'nombre'       => $c->nombre,
                'descripcion'  => $c->descripcion,
                'precio_total' => $c->precio_total,
                'ahorro'       => $c->ahorro,
                'items'        => $c->items,
            ]);

        return response()->json([
            'categorias' => $categorias,
            'combos'     => $combos,
        ]);
    }

    // POST /api/pos/venta
    public function registrarVenta(Request $request): JsonResponse
    {
        $request->validate([
            'mesa'                 => 'nullable|string|max:50',
            'descuento'            => 'nullable|numeric|min:0',
            'notas'                => 'nullable|string',
            'pagos'                => 'required|array|min:1',
            'pagos.*.metodo'       => 'required|in:efectivo,transferencia',
            'pagos.*.monto'        => 'required|numeric|min:0',
            'items'                => 'required|array|min:1',
            'items.*.variante_id'  => 'required|exists:variantes,id',
            'items.*.cantidad'     => 'required|integer|min:1',
            'items.*.descuento'    => 'nullable|numeric|min:0',
        ]);

        $venta = Venta::registrar(
            $request->only('mesa', 'descuento', 'notas'),
            $request->items,
            $request->pagos,
            Auth::id()
        );

        if (!$venta->estado) {
            $venta->update(['estado' => 'pendiente']);
        }

        return response()->json($venta->load('detalles', 'pagos'), 201);
    }

    // GET /api/pos/pedidos-activos
    public function pedidosActivos(): JsonResponse
    {
        $pedidos = Venta::with(['detalles', 'pagos'])
            ->whereDate('created_at', today())
            ->whereIn('estado', ['pendiente', 'preparacion', 'pagado'])
            ->orderBy('numero_orden')
            ->get();

        return response()->json($pedidos);
    }

    // PUT /api/pos/ventas/{venta}/estado
    public function actualizarEstado(Request $request, Venta $venta): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:pendiente,preparacion,pagado,entregado',
        ]);

        $actual = $venta->estado;
        $nuevo = $request->estado;

        $permitidas = [
            'pendiente'   => ['preparacion'],
            'preparacion' => ['pagado'],
            'pagado'      => ['entregado'],
            'entregado'   => [],
        ];

        if (!in_array($nuevo, $permitidas[$actual] ?? [], true)) {
            return response()->json([
                'message' => "No se puede pasar de {$actual} a {$nuevo}.",
            ], 422);
        }

        $venta->update([
            'estado' => $nuevo,
        ]);

        return response()->json(
            $venta->fresh(['detalles', 'pagos'])
        );
    }
}