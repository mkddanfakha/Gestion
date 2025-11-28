<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();
        
        if ($user->hasVerifiedEmail()) {
            // Rediriger les vendeurs vers la liste des ventes
            if ($user->hasRole('vendeur')) {
                return redirect()->intended(route('sales.index', absolute: false))->with('success', 'Votre adresse email est déjà vérifiée.');
            }
            return redirect()->intended(route('dashboard', absolute: false))->with('success', 'Votre adresse email est déjà vérifiée.');
        }

        try {
            $request->fulfill();
            
            // Recharger l'utilisateur depuis la base de données pour obtenir la valeur mise à jour
            $user->refresh();
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la vérification de l\'email', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('verification.notice')->withErrors([
                'message' => 'Une erreur est survenue lors de la vérification de votre email : ' . $e->getMessage()
            ]);
        }

        // Rediriger les vendeurs vers la liste des ventes
        if ($user->hasRole('vendeur')) {
            return redirect()->intended(route('sales.index', absolute: false))->with('success', 'Votre adresse email a été vérifiée avec succès.');
        }

        return redirect()->intended(route('dashboard', absolute: false))->with('success', 'Votre adresse email a été vérifiée avec succès.');
    }
}
