<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\NotificationRead;
use App\Models\Product;
use App\Models\Sale;

class NotificationService
{
    /**
     * Envoyer une notification en temps réel pour une vente avec échéance aujourd'hui
     */
    public static function notifySaleDueToday(Sale $sale): void
    {
        if (!$sale->user_id) {
            return;
        }

        $notification = [
            'type' => 'sale_due_today',
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'customer' => $sale->customer ? $sale->customer->name : 'Client anonyme',
            'remaining_amount' => $sale->remaining_amount ?? $sale->total_amount,
        ];

        // Vérifier si la notification n'a pas déjà été lue
        $isRead = NotificationRead::where('user_id', $sale->user_id)
            ->where('notification_type', 'sale_due_today')
            ->where('notification_id', $sale->id)
            ->exists();

        if (!$isRead) {
            event(new NotificationSent($notification, $sale->user_id));
        }
    }

    /**
     * Envoyer une notification en temps réel pour un produit en stock faible
     */
    public static function notifyLowStock(Product $product): void
    {
        // Pour les produits en stock faible, on notifie tous les utilisateurs
        // Dans un vrai système, vous pourriez avoir une liste d'utilisateurs à notifier
        $users = \App\Models\User::all();

        foreach ($users as $user) {
            $isRead = NotificationRead::where('user_id', $user->id)
                ->where('notification_type', 'low_stock')
                ->where('notification_id', $product->id)
                ->exists();

            if (!$isRead) {
                $firstImage = $product->getFirstMedia('images');
                $notification = [
                    'type' => 'low_stock',
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock_quantity' => $product->stock_quantity,
                    'unit' => $product->unit,
                    'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
                    'category' => $product->category ? [
                        'name' => $product->category->name,
                    ] : null,
                ];

                event(new NotificationSent($notification, $user->id));
            }
        }
    }

    /**
     * Envoyer une notification en temps réel pour un produit expiré ou proche de l'expiration
     */
    public static function notifyExpiringProduct(Product $product): void
    {
        $isExpired = $product->isExpired();
        $isExpiringSoon = $product->isExpiringSoon();
        
        if (!$isExpired && !$isExpiringSoon) {
            return;
        }

        $users = \App\Models\User::all();

        foreach ($users as $user) {
            $isRead = NotificationRead::where('user_id', $user->id)
                ->where('notification_type', 'expiring_product')
                ->where('notification_id', $product->id)
                ->exists();

            if (!$isRead) {
                $firstImage = $product->getFirstMedia('images');
                $notification = [
                    'type' => 'expiring_product',
                    'id' => $product->id,
                    'name' => $product->name,
                    'expiration_date' => $product->expiration_date->format('Y-m-d'),
                    'days_until_expiration' => $product->days_until_expiration,
                    'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
                ];

                event(new NotificationSent($notification, $user->id));
            }
        }
    }
}



