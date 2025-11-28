<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            EnsureUserIsActive::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Gérer les erreurs de déchiffrement des cookies
        $exceptions->render(function (\Illuminate\Contracts\Encryption\DecryptException $e, \Illuminate\Http\Request $request) {
            // Si c'est une erreur de déchiffrement, nettoyer les cookies et rediriger
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Session invalide. Veuillez vous reconnecter.'], 401);
            }
            
            // Supprimer les cookies de session corrompus
            $cookie = cookie()->forget('laravel_session');
            
            // Rediriger vers la page de connexion si disponible, sinon vers la page d'accueil
            try {
                return redirect()->route('login')
                    ->withCookie($cookie)
                    ->with('error', 'Votre session a expiré ou est invalide. Veuillez vous reconnecter.');
            } catch (\Exception $routeException) {
                // Si la route login n'existe pas, rediriger vers la page d'accueil
                return redirect('/')
                    ->withCookie($cookie)
                    ->with('error', 'Votre session a expiré. Veuillez vous reconnecter.');
            }
        });
        
        // Gérer les erreurs 403 (Accès refusé) avec une page personnalisée
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() === 403) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Accès refusé. Vous n\'avez pas la permission d\'effectuer cette action.',
                    ], 403);
                }
                
                // Utiliser Inertia pour rendre la page d'erreur 403 personnalisée
                return \Inertia\Inertia::render('errors/403', [
                    'error' => $e->getMessage() ?: 'Accès refusé. Vous n\'avez pas la permission d\'effectuer cette action.',
                ])->toResponse($request)->setStatusCode(403);
            }
            
            return null; // Laisser Laravel gérer les autres erreurs
        });
    })->create();
