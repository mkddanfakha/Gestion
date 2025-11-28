<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Vérifier uniquement si l'utilisateur est authentifié
        if ($request->user()) {
            // Recharger l'utilisateur depuis la base de données pour avoir le statut à jour
            $user = $request->user()->fresh();
            
            // Si l'utilisateur est désactivé, le déconnecter
            if (!$user->is_active) {
                Auth::logout();
                
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Votre compte a été désactivé. Veuillez contacter un administrateur.',
                    ], 403);
                }
                
                return redirect()->route('login')
                    ->with('error', 'Votre compte a été désactivé. Veuillez contacter un administrateur.');
            }
        }

        return $next($request);
    }
}
