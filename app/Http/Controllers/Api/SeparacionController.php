<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Insumo;
use App\Services\SeparacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SeparacionController extends Controller
{
    /**
     * Acumulado vigente por grupo + total general + historial de reposiciones.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * Marca un grupo como repuesto: cierra el acumulado vigente creando una
     * reposición y deja el grupo en cero.
     */
    public function reponer(Request $request, string $grupo): JsonResponse
    {
        $data = $request->validate([
            'monto_real' => 'nullable|numeric|min:0',
            'observacion' => 'nullable|string|max:500',
        ]);

        $validador = validator(
            ['grupo' => $grupo],
            ['grupo' => ['required', Rule::in(Insumo::GRUPOS_SEPARACION)]]
        );

        if ($validador->fails()) {
            return response()->json(['message' => 'Grupo inválido.'], 422);
        }

        $reposicion = SeparacionService::reponer(
            $grupo,
            isset($data['monto_real']) ? (float) $data['monto_real'] : null,
            $data['observacion'] ?? null,
            Auth::id()
        );

        return response()->json(array_merge($this->payload(), [
            'reposicion' => [
                'id' => $reposicion->id,
                'grupo' => $reposicion->grupo,
                'monto_acumulado' => (float) $reposicion->monto_acumulado,
                'monto_real' => $reposicion->monto_real !== null ? (float) $reposicion->monto_real : null,
            ],
        ]), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $grupos = SeparacionService::acumulados();

        return [
            'grupos' => $grupos,
            'total_general' => round(array_sum(array_column($grupos, 'monto_acumulado')), 2),
            'historial' => SeparacionService::historial(),
        ];
    }
}
