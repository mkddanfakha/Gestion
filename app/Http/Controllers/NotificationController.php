<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Marquer une notification spécifique comme lue
     */
    public function markAsRead(Request $request)
    {
        $legacyTypes = config('notifications.legacy_api_types', []);

        $request->validate([
            'type' => ['required', 'string', Rule::in($legacyTypes)],
            'id' => 'required|integer',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $this->notificationService->markAsRead($user, $request->type, (int) $request->id);

        return response()->json(['success' => true]);
    }

    /**
     * Marquer toutes les notifications d'un type comme lues
     */
    public function markAllAsRead(Request $request)
    {
        $legacyTypes = [...config('notifications.legacy_api_types', []), 'all'];

        $request->validate([
            'type' => ['required', 'string', Rule::in($legacyTypes)],
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $this->notificationService->markAllAsRead($user, $request->type);

        return response()->json(['success' => true]);
    }

    /**
     * Tester l'envoi d'une notification en temps réel
     */
    public function testNotification(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $notification = [
            'type' => 'test',
            'id' => 999,
            'message' => 'Ceci est une notification de test',
            'timestamp' => now()->toDateTimeString(),
        ];

        event(new \App\Events\NotificationSent($notification, $user->id));

        return response()->json([
            'success' => true,
            'message' => 'Notification de test envoyée',
        ]);
    }
}
