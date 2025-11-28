<?php

namespace App\Http\Controllers;

use App\Models\NotificationRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * Marquer une notification spécifique comme lue
     */
    public function markAsRead(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:sale_due_today,low_stock,expiring_product',
            'id' => 'required|integer',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        NotificationRead::updateOrCreate(
            [
                'user_id' => $user->id,
                'notification_type' => $request->type,
                'notification_id' => $request->id,
            ],
            [
                'read_at' => now(),
            ]
        );

        return response()->json(['success' => true]);
    }

    /**
     * Marquer toutes les notifications d'un type comme lues
     */
    public function markAllAsRead(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:sale_due_today,low_stock,expiring_product,all',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        if ($request->type === 'all') {
            // Marquer toutes les notifications comme lues
            $types = ['sale_due_today', 'low_stock', 'expiring_product'];
            foreach ($types as $type) {
                $this->markAllOfTypeAsRead($user->id, $type);
            }
        } else {
            $this->markAllOfTypeAsRead($user->id, $request->type);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Marquer toutes les notifications d'un type spécifique comme lues
     */
    private function markAllOfTypeAsRead(int $userId, string $type)
    {
        // Récupérer les IDs des notifications actuelles selon le type
        $notificationIds = $this->getCurrentNotificationIds($type);

        if (empty($notificationIds)) {
            return;
        }

        // Insérer en masse les notifications lues
        $inserts = [];
        foreach ($notificationIds as $id) {
            $inserts[] = [
                'user_id' => $userId,
                'notification_type' => $type,
                'notification_id' => $id,
                'read_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($inserts)) {
            DB::table('notification_reads')->insertOrIgnore($inserts);
        }
    }

    /**
     * Récupérer les IDs des notifications actuelles selon le type
     */
    private function getCurrentNotificationIds(string $type): array
    {
        switch ($type) {
            case 'sale_due_today':
                return \App\Models\Sale::whereNotNull('due_date')
                    ->whereDate('due_date', now()->toDateString())
                    ->where('payment_status', '!=', 'paid')
                    ->pluck('id')
                    ->toArray();

            case 'low_stock':
                return \App\Models\Product::whereRaw('stock_quantity <= min_stock_level')
                    ->where('is_active', true)
                    ->pluck('id')
                    ->toArray();

            case 'expiring_product':
                return \App\Models\Product::whereNotNull('expiration_date')
                    ->where('is_active', true)
                    ->get()
                    ->filter(function ($product) {
                        return $product->isExpired() || $product->isExpiringSoon();
                    })
                    ->pluck('id')
                    ->toArray();

            default:
                return [];
        }
    }

    /**
     * Tester l'envoi d'une notification en temps réel
     */
    public function testNotification(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
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
