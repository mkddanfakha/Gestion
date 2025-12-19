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
     * Envoie la notification à tous les utilisateurs actifs
     */
    public static function notifySaleDueToday(Sale $sale): void
    {
        $notification = [
            'type' => 'sale_due_today',
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'customer' => $sale->customer ? $sale->customer->name : 'Client anonyme',
            'remaining_amount' => $sale->remaining_amount ?? $sale->total_amount,
        ];

        // Envoyer à tous les utilisateurs actifs
        $users = \App\Models\User::where('is_active', true)->get();
        
        foreach ($users as $user) {
            $isRead = NotificationRead::where('user_id', $user->id)
                ->where('notification_type', 'sale_due_today')
                ->where('notification_id', $sale->id)
                ->exists();

            if (!$isRead) {
                event(new NotificationSent($notification, $user->id));
            }
        }
    }

    /**
     * Envoyer une notification en temps réel pour un produit en stock faible
     * Envoie la notification à tous les utilisateurs actifs
     */
    public static function notifyLowStock(Product $product, ?int $userId = null): void
    {
        // Préparer la notification une seule fois
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

        // Si un userId spécifique est fourni, envoyer uniquement à cet utilisateur
        if ($userId !== null) {
            $isRead = NotificationRead::where('user_id', $userId)
                ->where('notification_type', 'low_stock')
                ->where('notification_id', $product->id)
                ->exists();

            if (!$isRead) {
                event(new NotificationSent($notification, $userId));
            }
            return;
        }

        // Sinon, envoyer à tous les utilisateurs actifs
        $users = \App\Models\User::where('is_active', true)->get();
        
        foreach ($users as $user) {
            $isRead = NotificationRead::where('user_id', $user->id)
                ->where('notification_type', 'low_stock')
                ->where('notification_id', $product->id)
                ->exists();

            if (!$isRead) {
                event(new NotificationSent($notification, $user->id));
            }
        }
    }

    /**
     * Envoyer une notification en temps réel pour un produit expiré ou proche de l'expiration
     * Envoie la notification à tous les utilisateurs actifs
     * 
     * @param Product $product Le produit expiré ou proche de l'expiration
     * @param int|null $userId L'ID de l'utilisateur à notifier (null = tous les utilisateurs)
     */
    public static function notifyExpiringProduct(Product $product, ?int $userId = null): void
    {
        $isExpired = $product->isExpired();
        $isExpiringSoon = $product->isExpiringSoon();
        
        if (!$isExpired && !$isExpiringSoon) {
            return;
        }

        // Préparer la notification une seule fois
        $firstImage = $product->getFirstMedia('images');
        $notification = [
            'type' => 'expiring_product',
            'id' => $product->id,
            'name' => $product->name,
            'expiration_date' => $product->expiration_date->format('Y-m-d'),
            'days_until_expiration' => $product->days_until_expiration,
            'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
        ];

        // Si un userId spécifique est fourni, envoyer uniquement à cet utilisateur
        if ($userId !== null) {
            $isRead = NotificationRead::where('user_id', $userId)
                ->where('notification_type', 'expiring_product')
                ->where('notification_id', $product->id)
                ->exists();

            if (!$isRead) {
                event(new NotificationSent($notification, $userId));
            }
            return;
        }

        // Sinon, envoyer à tous les utilisateurs actifs
        $users = \App\Models\User::where('is_active', true)->get();
        
        foreach ($users as $user) {
            $isRead = NotificationRead::where('user_id', $user->id)
                ->where('notification_type', 'expiring_product')
                ->where('notification_id', $product->id)
                ->exists();

            if (!$isRead) {
                event(new NotificationSent($notification, $user->id));
            }
        }
    }
}



