<?php

namespace App\Http\Responses;

use App\Services\ActivityLogger;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\Request;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        // Forcer le rechargement de l'utilisateur depuis la base de données
        // pour s'assurer que le rôle est à jour
        $user = $request->user();
        if ($user) {
            $user->refresh();

            ActivityLogger::logLogin($user, $request);
            
            // Rediriger les vendeurs vers la liste des ventes
            if ($user->hasRole('vendeur')) {
                return redirect()->intended(route('sales.index'));
            }
        }
        
        return redirect()->intended(route('dashboard'));
    }
}

