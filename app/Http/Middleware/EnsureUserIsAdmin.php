<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Inertia\Inertia;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès refusé. Vous devez être connecté.'], 401);
            }

            return redirect()->route('login')
                ->with('error', 'Vous devez être connecté pour accéder à cette page.');
        }
        
        // Recharger l'utilisateur depuis la base de données pour avoir le rôle à jour
        $user->refresh();
        
        // Debug: logger les informations de l'utilisateur
        \Log::info('EnsureUserIsAdmin - User check', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_role' => $user->role,
            'isAdmin' => $user->isAdmin(),
            'route' => $request->route()->getName(),
        ]);
        
        if (!$user->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès refusé. Vous devez être administrateur.'], 403);
            }

            return redirect()->route('dashboard')
                ->with('error', 'Accès refusé. Vous devez être administrateur pour accéder à cette page.');
        }

        return $next($request);
    }
}
