<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $resource  La ressource (ex: 'products', 'customers')
     * @param  string  $action    L'action (ex: 'create', 'edit', 'update', 'delete')
     */
    public function handle(Request $request, Closure $next, string $resource, string $action): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Vous devez être connecté.'], 401);
            }

            return redirect()->route('login')
                ->with('error', 'Vous devez être connecté pour accéder à cette page.');
        }

        // Recharger l'utilisateur depuis la base de données pour avoir les permissions à jour
        $user->refresh();

        // Vérifier la permission
        if (!$user->hasPermission($resource, $action)) {
            $message = "Accès refusé. Vous n'avez pas la permission d'effectuer l'action '{$action}' sur la ressource '{$resource}'.";
            
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                ], 403);
            }

            // Utiliser abort pour déclencher le handler d'exceptions qui affichera la page 403 personnalisée
            abort(403, $message);
        }

        return $next($request);
    }
}
