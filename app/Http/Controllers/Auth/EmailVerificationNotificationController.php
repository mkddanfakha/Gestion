<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
{
    if ($request->user()->hasVerifiedEmail()) {
        $user = $request->user();

        // Rediriger les vendeurs vers la liste des ventes
        if ($user && $user->hasRole('vendeur')) {
            return redirect()->intended(
                route('sales.index', absolute: false)
            );
        }

        return redirect()->intended(
            route('dashboard', absolute: false)
        );
    }

    try {
        $user = $request->user();

        // Envoyer la notification de vérification via Laravel Notifications.
        // La configuration du mailer est gérée par Laravel.
        $user->sendEmailVerificationNotification();

        return back()->with(
            'success',
            'Un nouveau lien de vérification a été envoyé à votre adresse email.'
        );
    } catch (\Exception $e) {
        \Log::error('Erreur lors de l\'envoi de l\'email de vérification', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage(),
        ]);

        return back()->withErrors([
            'message' => 'Une erreur est survenue lors de l\'envoi de l\'email : ' . $e->getMessage(),
        ]);
    }
}
}
