<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\DetalleVenta;
use App\Models\Venta;
use App\Services\NegocioService;
use App\Services\TandaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas de un solo negocio (burger o parrilla): ventas, costo, gastos fijos
 * y ganancia por jornada, lo más vendido y, en la parrilla, las compras.
 */
class NegocioController extends Controller
{
    /** GET /api/negocio/{negocio}?desde=&hasta= */
    public function show(Request $request, string $negocio): JsonResponse
    {
        $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);

        $desde = $request->get('desde') ? Carbon::parse($request->get('desde'))->toDateString() : now()->startOfMonth()->toDateString();
        $hasta = $request->get('hasta') ? Carbon::parse($request->get('hasta'))->toDateString() : now()->toDateString();

        $cajas = Caja::whereDate('fecha_operativa', '>=', $desde)
            ->whereDate('fecha_operativa', '<=', $hasta)
            ->orderByDesc('fecha_operativa')
            ->orderByDesc('id')
            ->get();

        $jornadas = $cajas
            ->map(function (Caja $caja) use ($negocio) {
                $fila = $this->desgloseDeCaja($caja)[$negocio] ?? null;

                return [
                    'caja_id' => $caja->id,
                    'fecha' => $caja->fecha_operativa?->toDateString(),
                    'estado' => $caja->estado,
                    'ventas' => (float) ($fila['ventas'] ?? 0),
                    'costo_insumos' => (float) ($fila['costo_insumos'] ?? 0),
                    'ganancia_bruta' => (float) ($fila['ganancia_bruta'] ?? 0),
                    'gastos_fijos' => (float) ($fila['gastos_fijos'] ?? 0),
                    'ganancia_neta' => (float) ($fila['ganancia_neta'] ?? 0),
                    'unidades' => (int) ($fila['unidades'] ?? 0),
                ];
            })
            // Días en que este negocio no vendió nada no cuentan como jornada.
            ->filter(fn ($j) => $j['unidades'] > 0)
            ->values();

        $suma = fn (string $k) => round((float) $jornadas->sum($k), 2);

        $productos = DetalleVenta::query()
            ->whereIn('venta_id', Venta::whereIn('caja_id', $cajas->pluck('id'))->where('estado', '!=', 'anulado')->select('id'))
            ->where('negocio', $negocio)
            ->selectRaw('nombre_snapshot as nombre, SUM(cantidad) as cantidad, SUM(subtotal) as monto')
            ->groupBy('nombre_snapshot')
            ->orderByDesc('cantidad')
            ->get()
            ->map(fn ($p) => ['nombre' => $p->nombre, 'cantidad' => (int) $p->cantidad, 'monto' => round((float) $p->monto, 2)]);

        return response()->json([
            'negocio' => $negocio,
            'desde' => $desde,
            'hasta' => $hasta,
            'totales' => [
                'ventas' => $suma('ventas'),
                'costo_insumos' => $suma('costo_insumos'),
                'ganancia_bruta' => $suma('ganancia_bruta'),
                'gastos_fijos' => $suma('gastos_fijos'),
                'ganancia_neta' => $suma('ganancia_neta'),
                'unidades' => (int) $jornadas->sum('unidades'),
                'jornadas' => $jornadas->count(),
            ],
            'jornadas' => $jornadas,
            'productos' => $productos,
            'tandas' => $negocio === 'parrilla' ? [
                'abiertas' => TandaService::abiertas(),
                'historial' => TandaService::historial(),
                'opciones' => TandaService::opciones(),
            ] : null,
        ]);
    }

    /**
     * Mismo criterio que la caja: la abierta (o sin totales) se calcula en
     * vivo; la cerrada usa lo que se guardó al cerrarla.
     *
     * @return array<string, array<string, mixed>>
     */
    private function desgloseDeCaja(Caja $caja): array
    {
        $guardado = $caja->resumen_json['por_negocio'] ?? null;

        if ($caja->estado !== 'abierta' && (float) $caja->total_ventas > 0 && $guardado) {
            return $guardado;
        }

        $ventas = Venta::where('caja_id', $caja->id)->with('detalles')->get();

        return NegocioService::desglose($ventas, $caja->estado === 'abierta' ? null : (float) $caja->gastos_fijos);
    }
}
