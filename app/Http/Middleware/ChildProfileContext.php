<?php

namespace App\Http\Middleware;

use App\Models\ChildProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChildProfileContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $parent = $request->user();
        abort_unless($parent && $parent->role === 'padre', 403, 'Inicia sesión con la cuenta del padre o tutor.');

        $profileId = $request->header('X-Child-Profile-ID');
        if (!$profileId && $request->isMethod('GET') && $request->is('api/activities')) {
            return $next($request);
        }
        abort_unless($profileId, 422, 'Selecciona un perfil infantil para continuar.');

        $child = ChildProfile::where('parent_id', $parent->id)->findOrFail($profileId);
        $request->attributes->set('parent_account', $parent);
        $request->setUserResolver(fn () => $child);

        return $next($request);
    }
}
