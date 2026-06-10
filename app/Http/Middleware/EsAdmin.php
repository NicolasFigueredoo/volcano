<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin') {
            // Si es API, devuelve JSON
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No autorizado',
                ], 403);
            }

            // Si es página Inertia/Web, redirecciona al POS
            return redirect()
                ->route('pos')
                ->with('error', 'No tenés permisos para acceder a esa sección.');
        }

        return $next($request);
    }
}