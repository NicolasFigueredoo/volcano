<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Insumo;
use App\Models\MovimientoStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    // GET /api/inventario
    public function index(): JsonResponse
    {
        $insumos = Insumo::activo()
            ->orderBy('nombre')
            ->get()
            ->map(fn($i) => array_merge($i->toArray(), [
                'estado_stock' => $i->estado_stock,
            ]));

        return response()->json($insumos);
    }

    // GET /api/inventario/alertas
    // Solo los que están en POCO o FALTA
    public function alertas(): JsonResponse
    {
        $insumos = Insumo::activo()
            ->bajoStock()
            ->orderBy('nombre')
            ->get()
            ->map(fn($i) => array_merge($i->toArray(), [
                'estado_stock' => $i->estado_stock,
            ]));

        return response()->json($insumos);
    }

    // PUT /api/inventario/{insumo}/ajustar
    // Body: { stock_actual, motivo? }
    public function ajustar(Request $request, Insumo $insumo): JsonResponse
    {
        $request->validate([
            'stock_actual' => 'required|numeric|min:0',
            'motivo'       => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($request, $insumo) {
            $stockAnterior = $insumo->stock_actual;
            $stockNuevo    = $request->stock_actual;
            $diferencia    = $stockNuevo - $stockAnterior;

            $insumo->update(['stock_actual' => $stockNuevo]);

            MovimientoStock::create([
                'insumo_id'      => $insumo->id,
                'user_id'        => Auth::id(),
                'tipo'           => 'ajuste',
                'cantidad'       => abs($diferencia),
                'stock_anterior' => $stockAnterior,
                'stock_nuevo'    => $stockNuevo,
                'motivo'         => $request->motivo ?? 'ajuste manual',
            ]);
        });

        return response()->json($insumo->fresh());
    }

    // POST /api/inventario/{insumo}/entrada
    // Body: { cantidad, motivo? }
    public function entrada(Request $request, Insumo $insumo): JsonResponse
    {
        $request->validate([
            'cantidad' => 'required|numeric|min:0.001',
            'motivo'   => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($request, $insumo) {
            $stockAnterior = $insumo->stock_actual;
            $stockNuevo    = $stockAnterior + $request->cantidad;

            $insumo->increment('stock_actual', $request->cantidad);

            MovimientoStock::create([
                'insumo_id'      => $insumo->id,
                'user_id'        => Auth::id(),
                'tipo'           => 'entrada',
                'cantidad'       => $request->cantidad,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo'    => $stockNuevo,
                'motivo'         => $request->motivo ?? 'compra',
            ]);
        });

        return response()->json($insumo->fresh());
    }

    // GET /api/inventario/{insumo}/movimientos
    public function movimientos(Insumo $insumo): JsonResponse
    {
        $movimientos = $insumo->movimientos()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($movimientos);
    }
}
