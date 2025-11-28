<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
        $this->configureResponses();
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }

    /**
     * Configure authentication responses.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $request->validate([
                Fortify::username() => 'required|string',
                'password' => 'required|string',
            ], [
                'email.required' => 'L\'adresse email est requise.',
                'email.string' => 'L\'adresse email doit être une chaîne de caractères.',
                'email.email' => 'L\'adresse email doit être valide.',
                'password.required' => 'Le mot de passe est requis.',
                'password.string' => 'Le mot de passe doit être une chaîne de caractères.',
            ]);

            $user = \App\Models\User::where(Fortify::username(), $request->{Fortify::username()})->first();

            if ($user && \Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
                // Vérifier si l'utilisateur est actif
                if (!$user->is_active) {
                    throw ValidationException::withMessages([
                        Fortify::username() => 'Votre compte a été désactivé. Veuillez contacter un administrateur.',
                    ]);
                }
                
                return $user;
            }

            throw ValidationException::withMessages([
                Fortify::username() => 'Ces identifiants ne correspondent pas à nos enregistrements.',
            ]);
        });

    }

    /**
     * Configure authentication responses.
     */
    private function configureResponses(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }
}
