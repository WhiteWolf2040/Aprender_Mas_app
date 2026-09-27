<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ParentDashboardMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user
            && $user->role === 'padre'
            && $user->hasActivePlan(),
            403,
            'El Panel del Padre requiere un paquete activo.'
        );

        return $next($request);
    }
}
