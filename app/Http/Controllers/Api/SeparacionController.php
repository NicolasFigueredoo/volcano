<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Insumo;
use App\Models\User;
use App\Services\ProveedorService;
use App\Services\SeparacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SeparacionController extends Controller
{
    /**
     * Vista "Hoy" + estado de proveedores + acumulado por rubro sin
     * proveedor + historial de reposiciones.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * Marca un rubro (sin proveedor) como repuesto: cierra el acumulado
     * vigente creando una reposición y deja el rubro en cero.
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
     * GET /api/separacion/dia?caja_id=
     *
     * Unidades y montos por rubro/insumo de una caja, las líneas sugeridas
     * para separar y si la caja ya fue separada. Sin caja_id usa la caja de
     * hoy (la abierta o la última).
     */
    public function dia(Request $request): JsonResponse
    {
        $request->validate(['caja_id' => 'nullable|integer|exists:cajas,id']);

        $caja = $request->caja_id ? Caja::find($request->caja_id) : SeparacionService::cajaDeHoy();

        if (! $caja) {
            return response()->json(['message' => 'No hay cajas registradas.'], 404);
        }

        if (! $this->puedeOperar(Auth::user(), $caja)) {
            return response()->json(['message' => 'Solo podés ver la separación de la caja actual.'], 403);
        }

        return response()->json($this->payloadDia($caja));
    }

    /**
     * POST /api/separacion/caja/{caja}/confirmar
     *
     * Body: { lineas: [{ proveedor_id?, grupo?, label?, monto }] }
     */
    public function confirmar(Request $request, Caja $caja): JsonResponse
    {
        if (! $this->puedeOperar(Auth::user(), $caja)) {
            return response()->json(['message' => 'Solo podés separar la caja actual.'], 403);
        }

        $data = $request->validate([
            'lineas' => 'required|array|min:1',
            'lineas.*.proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'lineas.*.grupo' => ['nullable', Rule::in(Insumo::GRUPOS_SEPARACION)],
            'lineas.*.label' => 'nullable|string|max:100',
            'lineas.*.monto' => 'required|numeric|min:0',
        ]);

        $separacion = ProveedorService::confirmarSeparacionCaja($caja, $data['lineas'], Auth::id());

        if (! $separacion) {
            return response()->json(['message' => 'Esta caja ya fue separada.'], 422);
        }

        return response()->json($this->payloadDia($caja), 201);
    }

    /**
     * El admin opera cualquier caja; el cajero solo la de hoy (la abierta o,
     * si no hay, la última, que es la que acaba de cerrar).
     */
    private function puedeOperar(User $user, Caja $caja): bool
    {
        return $user->isAdmin() || SeparacionService::cajaDeHoy()?->id === $caja->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadDia(Caja $caja): array
    {
        return array_merge(SeparacionService::resumenCaja($caja), [
            'lineas' => SeparacionService::lineasSeparacion($caja),
            'separacion' => SeparacionService::separacionDeCaja($caja),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $grupos = SeparacionService::acumulados();
        $cajaHoy = SeparacionService::cajaDeHoy();

        return [
            'fecha_corte' => config('separacion.fecha_corte'),
            'hoy' => $cajaHoy ? SeparacionService::resumenCaja($cajaHoy) : null,
            'proveedores' => ProveedorService::listado(soloActivos: true),
            'grupos' => $grupos,
            'total_general' => round(array_sum(array_column($grupos, 'monto_acumulado')), 2),
            'historial' => SeparacionService::historial(),
        ];
    }
}
