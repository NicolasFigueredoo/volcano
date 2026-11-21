<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tanda;
use App\Services\TandaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Compras de la parrilla (tandas). Cargarlas, corregirlas y borrarlas es del
 * admin; marcar "se terminó" lo puede hacer también el cajero al cerrar.
 */
class TandaController extends Controller
{
    /** GET /api/tandas */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** POST /api/tandas */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'insumo_id' => 'required|integer|exists:insumos,id',
            'cantidad' => 'required|numeric|min:0.001',
            'total' => 'required|numeric|min:1',
            'porciones_estimadas' => 'required|integer|min:1|max:10000',
            'observacion' => 'nullable|string|max:500',
        ]);

        TandaService::crear($data, Auth::id());

        return response()->json($this->payload(), 201);
    }

    /** PUT /api/tandas/{tanda} */
    public function update(Request $request, Tanda $tanda): JsonResponse
    {
        $data = $request->validate([
            'cantidad' => 'sometimes|numeric|min:0.001',
            'total' => 'sometimes|numeric|min:1',
            'porciones_estimadas' => 'sometimes|integer|min:1|max:10000',
            'observacion' => 'nullable|string|max:500',
        ]);

        TandaService::actualizar($tanda, $data);

        return response()->json($this->payload());
    }

    /** POST /api/tandas/{tanda}/cerrar */
    public function cerrar(Tanda $tanda): JsonResponse
    {
        if (! $tanda->abierta) {
            return response()->json(['message' => 'Esa compra ya estaba terminada.'], 422);
        }

        TandaService::cerrar($tanda);

        return response()->json($this->payload());
    }

    /** POST /api/tandas/{tanda}/reabrir */
    public function reabrir(Tanda $tanda): JsonResponse
    {
        if ($tanda->abierta) {
            return response()->json(['message' => 'Esa compra sigue abierta.'], 422);
        }

        TandaService::reabrir($tanda);

        return response()->json($this->payload());
    }

    /** DELETE /api/tandas/{tanda} */
    public function destroy(Tanda $tanda): JsonResponse
    {
        if (! TandaService::eliminar($tanda)) {
            return response()->json(['message' => 'No se puede borrar: ya tiene ventas. Corregí los números o marcala como terminada.'], 422);
        }

        return response()->json($this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'abiertas' => TandaService::abiertas(),
            'historial' => TandaService::historial(),
            'opciones' => TandaService::opciones(),
        ];
    }
}
