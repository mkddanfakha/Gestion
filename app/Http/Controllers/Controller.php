<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
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

        if (!$user->hasPermission($resource, $action)) {
            $message = "Accès refusé. Vous n'avez pas la permission d'effectuer l'action '{$action}' sur la ressource '{$resource}'.";
            
            if ($request->expectsJson()) {
                abort(403, $message);
            }
            abort(403, $message);
        }
    }
}
