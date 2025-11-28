<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     * Seuls les administrateurs peuvent supprimer des comptes.
     * Les utilisateurs ne peuvent pas supprimer leur propre compte.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Empêcher les utilisateurs non-admin de supprimer leur compte
        if (!$user || !$user->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Accès refusé. Seuls les administrateurs peuvent supprimer des comptes. Veuillez contacter un administrateur pour supprimer votre compte.'
                ], 403);
            }

            return redirect()->route('profile.edit')
                ->with('error', 'Accès refusé. Seuls les administrateurs peuvent supprimer des comptes. Veuillez contacter un administrateur pour supprimer votre compte.');
        }

        // Même les administrateurs ne peuvent pas supprimer leur propre compte depuis le profil
        // Ils doivent passer par la section Administration
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte depuis cette page. Veuillez utiliser la section Administration ou contacter un autre administrateur.'
            ], 403);
        }

        return redirect()->route('profile.edit')
            ->with('error', 'Vous ne pouvez pas supprimer votre propre compte depuis cette page. Veuillez utiliser la section Administration ou contacter un autre administrateur.');
    }
}
