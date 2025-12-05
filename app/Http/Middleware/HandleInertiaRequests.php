<?php

namespace App\Http\Middleware;

use App\Models\NotificationRead;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        // Récupérer les notifications (uniquement si l'utilisateur est authentifié)
        $notifications = [];
        if ($request->user()) {
            $userId = $request->user()->id;
            
            // Récupérer les IDs des notifications déjà lues
            $readNotificationIds = NotificationRead::where('user_id', $userId)
                ->get()
                ->groupBy('notification_type')
                ->map(function ($reads, $type) {
                    return $reads->pluck('notification_id')->toArray();
                })
                ->toArray();

            // Ventes avec date d'échéance aujourd'hui
            $salesDueToday = Sale::with(['customer'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', now()->toDateString())
                ->where('payment_status', '!=', 'paid')
                ->orderBy('due_date')
                ->get()
                ->filter(function ($sale) use ($readNotificationIds) {
                    $readIds = $readNotificationIds['sale_due_today'] ?? [];
                    return !in_array($sale->id, $readIds);
                })
                ->map(function ($sale) {
                    return [
                        'id' => $sale->id,
                        'sale_number' => $sale->sale_number,
                        'customer' => $sale->customer ? $sale->customer->name : 'Client anonyme',
                        'remaining_amount' => $sale->remaining_amount ?? $sale->total_amount,
                    ];
                })
                ->values();

            // Produits en stock faible (limité à 10 pour l'affichage, mais on compte le total réel)
            $allLowStockProducts = Product::with('category')
                ->whereRaw('stock_quantity <= min_stock_level')
                ->where('is_active', true)
                ->orderBy('stock_quantity', 'asc') // Les plus critiques en premier
                ->get(); // Récupérer tous les produits en stock faible
            
            // Filtrer ceux qui sont déjà lus
            $readIds = $readNotificationIds['low_stock'] ?? [];
            $filteredLowStockProducts = $allLowStockProducts
                ->filter(function ($product) use ($readIds) {
                    return !in_array($product->id, $readIds);
                });
            
            // Compter le total réel (pour le compteur de notifications)
            $totalLowStockProducts = $filteredLowStockProducts->count();
            
            // Limiter à 10 pour l'affichage dans la notification
            $lowStockProducts = $filteredLowStockProducts
                ->take(10)
                ->map(function ($product) {
                    $firstImage = $product->getFirstMedia('images');
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'stock_quantity' => $product->stock_quantity,
                        'unit' => $product->unit,
                        'category' => $product->category,
                        'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
                    ];
                })
                ->values();

            // Produits expirés ou proches de l'expiration (limité à 10 pour l'affichage, mais on compte le total)
            // On récupère d'abord les produits expirés, puis ceux qui expirent bientôt
            $allProductsWithExpiration = Product::with('category')
                ->whereNotNull('expiration_date')
                ->where('is_active', true)
                ->orderBy('expiration_date', 'asc') // Les plus urgents en premier
                ->get(); // On récupère tous les produits actifs avec date d'expiration
            
            $readIds = $readNotificationIds['expiring_product'] ?? [];
            
            // Filtrer les produits expirés/proches de l'expiration et non lus
            $filteredProducts = $allProductsWithExpiration
                ->filter(function ($product) use ($readIds) {
                    // Vérifier si le produit est expiré ou proche de l'expiration
                    $isExpired = $product->isExpired();
                    $isExpiringSoon = $product->isExpiringSoon();
                    
                    // Si le produit n'est ni expiré ni proche de l'expiration, l'exclure
                    if (!$isExpired && !$isExpiringSoon) {
                        return false;
                    }
                    
                    // Exclure les notifications déjà lues
                    $isRead = in_array($product->id, $readIds);
                    
                    return !$isRead;
                });
            
            // Compter le total réel (pour l'affichage dans "Voir tous")
            $totalExpiringProducts = $filteredProducts->count();
            
            // Limiter à 10 pour l'affichage dans la notification
            $expiringProducts = $filteredProducts
                ->take(10) // Limiter à 10 après le filtre
                ->map(function ($product) {
                    $firstImage = $product->getFirstMedia('images');
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'expiration_date' => $product->expiration_date->format('Y-m-d'),
                        'days_until_expiration' => $product->days_until_expiration,
                        'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
                    ];
                })
                ->values();
            
            $notifications = [
                'salesDueToday' => $salesDueToday,
                'lowStockProducts' => $lowStockProducts,
                'lowStockProductsTotal' => $totalLowStockProducts, // Total réel pour le compteur
                'expiringProducts' => $expiringProducts,
                'expiringProductsTotal' => $totalExpiringProducts, // Total réel pour le compteur
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'csrf_token' => csrf_token(),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
            ],
            'auth' => [
                'user' => $request->user() ? (function() use ($request) {
                    // Toujours recharger l'utilisateur depuis la base de données
                    // pour s'assurer que le rôle est à jour, surtout après la connexion
                    $user = $request->user()->fresh();
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'email_verified_at' => $user->email_verified_at?->toIso8601String(), // Inclure la date de vérification
                        'role' => $user->role ?? 'user', // Toujours définir un rôle par défaut
                        'is_active' => $user->is_active ?? true, // Statut actif/inactif
                        'permissions' => $user->isAdmin() 
                            ? [] // Les admins ont toutes les permissions, pas besoin de les lister
                            : $user->getPermissionsArray(), // Liste des permissions pour les autres utilisateurs
                    ];
                })() : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'notifications' => $notifications,
        ];
    }
}
