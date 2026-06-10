<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Combo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComboController extends Controller
{
    // GET /api/combos
    public function index(): JsonResponse
    {
        $combos = Combo::activo()
            ->with(['items.variante.producto'])
            ->get()
            ->map(fn($c) => array_merge($c->toArray(), [
                'precio_total' => $c->precio_total,
                'ahorro'       => $c->ahorro,
            ]));

        return response()->json($combos);
    }

    // POST /api/admin/combos
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nombre'              => 'required|string|max:100',
            'descripcion'         => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.variante_id' => 'required|exists:variantes,id',
            'items.*.descuento'   => 'required|numeric|min:0',
            'items.*.cantidad'    => 'required|integer|min:1',
        ]);

        $combo = Combo::create($request->only('nombre', 'descripcion', 'orden'));

        foreach ($request->items as $item) {
            $combo->items()->create($item);
        }

        return response()->json($combo->load('items.variante.producto'), 201);
    }

    // PUT /api/admin/combos/{combo}
    public function update(Request $request, Combo $combo): JsonResponse
    {
        $request->validate([
            'nombre'      => 'sometimes|string|max:100',
            'descripcion' => 'nullable|string',
            'activo'      => 'boolean',
            'items'       => 'sometimes|array|min:1',
        ]);

        $combo->update($request->only('nombre', 'descripcion', 'activo', 'orden'));

        if ($request->has('items')) {
            $combo->items()->delete();
            foreach ($request->items as $item) {
                $combo->items()->create($item);
            }
        }

        return response()->json($combo->load('items.variante.producto'));
    }

    // DELETE /api/admin/combos/{combo}
    public function destroy(Combo $combo): JsonResponse
    {
        $combo->update(['activo' => false]);
        return response()->json(['message' => 'Combo desactivado']);
    }
}
