<?php

namespace App\Http\Controllers;

use App\Auth\AuthorizationService;
use App\Models\Permission;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Vérifier si l'utilisateur a une permission spécifique
     * 
     * @param Request $request
     * @param string $resource
     * @param string $action
     * @return void
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function checkPermission(Request $request, string $resource, string $action): void
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                abort(401, 'Vous devez être connecté.');
            }
            abort(401);
        }

        // Recharger l'utilisateur depuis la base de données pour avoir les permissions à jour
        $user->refresh();

        $authorization = app(AuthorizationService::class);

        if (! $authorization->allows($user, Permission::generateName($resource, $action))) {
            $message = "Accès refusé. Vous n'avez pas la permission d'effectuer l'action '{$action}' sur la ressource '{$resource}'.";
            
            if ($request->expectsJson()) {
                abort(403, $message);
            }
            abort(403, $message);
        }
    }

    /**
     * Vérifie que l'utilisateur possède au moins une des permissions listées.
     *
     * @param  list<string>  $actions
     */
    protected function checkAnyPermission(Request $request, string $resource, array $actions): void
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson()) {
                abort(401, 'Vous devez être connecté.');
            }
            abort(401);
        }

        $user->refresh();

        $authorization = app(AuthorizationService::class);
        $permissionNames = array_map(
            static fn (string $action): string => Permission::generateName($resource, $action),
            $actions,
        );

        if (! $authorization->any($user, $permissionNames)) {
            $actionsList = implode(', ', $actions);
            $message = "Accès refusé. Permission requise sur '{$resource}' : {$actionsList}.";

            if ($request->expectsJson()) {
                abort(403, $message);
            }
            abort(403, $message);
        }
    }
}
