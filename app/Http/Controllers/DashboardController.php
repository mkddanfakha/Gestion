<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Customer;
use App\Models\Category;
use App\Models\Expense;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $this->checkPermission($request, 'dashboard', 'view');
        
        // Statistiques générales
        // Calculer les revenus : ventes payées + acomptes des ventes non payées
        // Pour les ventes payées : utiliser total_amount (car acompte = total dans ce cas)
        // Pour les ventes non payées : utiliser down_payment_amount (acomptes partiels)
        $paidSalesAmount = Sale::where('payment_status', 'paid')->sum('total_amount') ?? 0;
        $downPaymentsAmount = Sale::where('payment_status', '!=', 'paid')->sum('down_payment_amount') ?? 0;
        $totalRevenue = $paidSalesAmount + $downPaymentsAmount;
        
        // Calculer les dépenses : BL validés + dépenses
        $validatedDeliveryNotesAmount = DeliveryNote::where('status', 'validated')->sum('total_amount') ?? 0;
        $totalExpensesAmount = Expense::sum('amount') ?? 0;
        $totalCosts = $validatedDeliveryNotesAmount + $totalExpensesAmount;
        
        // Calculer le bénéfice net
        $netProfit = $totalRevenue - $totalCosts;
        
        $stats = [
            'total_products' => Product::count(),
            'total_customers' => Customer::count(),
            'total_sales' => Sale::count(),
            'total_categories' => Category::count(),
            'total_expenses' => Expense::count(),
            'low_stock_products' => Product::whereRaw('stock_quantity <= min_stock_level')->count(),
            'total_revenue' => $totalRevenue,
            'paid_sales_amount' => $paidSalesAmount,
            'down_payments_amount' => $downPaymentsAmount,
            'validated_delivery_notes_amount' => $validatedDeliveryNotesAmount,
            'total_expenses_amount' => $totalExpensesAmount,
            'total_costs' => $totalCosts,
            'net_profit' => $netProfit,
        ];

        // Produits en rupture de stock
        $allLowStockProducts = Product::with('category')
            ->whereRaw('stock_quantity <= min_stock_level')
            ->where('is_active', true)
            ->orderBy('stock_quantity', 'asc')
            ->get(); // Récupérer tous les produits en stock faible
        
        // Compter le total réel
        $totalLowStockProducts = $allLowStockProducts->count();
        
        // Limiter à 3 pour l'affichage dans le dashboard
        $lowStockProducts = $allLowStockProducts
            ->take(3)
            ->map(function ($product) {
                $firstImage = $product->getFirstMedia('images');
                $product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
                return $product;
            });

        // Produits expirés ou proches de l'expiration
        $allExpiringProducts = Product::with('category')
            ->whereNotNull('expiration_date')
            ->where('is_active', true)
            ->get()
            ->filter(function ($product) {
                return $product->isExpired() || $product->isExpiringSoon();
            });
        
        // Compter le total réel
        $totalExpiringProducts = $allExpiringProducts->count();
        
        // Limiter à 10 pour l'affichage
        $expiringProducts = $allExpiringProducts
            ->take(10)
            ->map(function ($product) {
                $firstImage = $product->getFirstMedia('images');
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'expiration_date' => $product->expiration_date->format('Y-m-d'),
                    'days_until_expiration' => $product->days_until_expiration,
                    'category' => $product->category,
                    'image_url' => $firstImage ? $firstImage->getUrl('thumb') : null,
                ];
            })
            ->values();

        // Ventes récentes (3 dernières)
        $recentSales = Sale::with(['customer', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        // Dépenses récentes (3 dernières)
        $recentExpenses = Expense::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        // Ventes par mois (derniers 6 mois)
        $monthlySales = Sale::selectRaw('
            DATE_FORMAT(created_at, "%Y-%m") as month, 
            SUM(
                CASE 
                    WHEN payment_status = "paid" THEN total_amount
                    ELSE down_payment_amount
                END
            ) as total
        ')
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Si aucune donnée de vente, créer des données de test pour démonstration
        if ($monthlySales->isEmpty()) {
            $monthlySales = collect();
            for ($i = 5; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $monthlySales->push([
                    'month' => $date->format('Y-m'),
                    'total' => rand(50000, 200000) // Montants aléatoires entre 50k et 200k Fcfa
                ]);
            }
        }

        // Top 5 des produits les plus vendus (pour la carte)
        $topProducts = Product::with(['category', 'media'])
            ->withCount('saleItems')
            ->orderBy('sale_items_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($product) {
                $firstImage = $product->getFirstMedia('images');
                $product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
                return $product;
            });

        // Top 10 des produits les plus vendus pour le graphique (avec quantités)
        $topProductsForChart = Product::with(['category'])
            ->withCount('saleItems')
            ->orderBy('sale_items_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                // Calculer la quantité totale vendue
                $totalQuantity = $product->saleItems()->sum('quantity');
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sales_count' => $product->sale_items_count,
                    'total_quantity' => $totalQuantity,
                ];
            })
            ->values();

        // Ventes avec date d'échéance aujourd'hui
        $salesDueToday = Sale::with(['customer', 'user'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', now()->toDateString())
            ->where('payment_status', '!=', 'paid')
            ->orderBy('due_date')
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'customer' => $sale->customer ? $sale->customer->name : 'Client anonyme',
                    'total_amount' => $sale->total_amount,
                    'remaining_amount' => $sale->remaining_amount ?? $sale->total_amount,
                    'due_date' => $sale->due_date->format('Y-m-d'),
                    'payment_status' => $sale->payment_status,
                ];
            });

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'lowStockProducts' => $lowStockProducts,
            'lowStockProductsTotal' => $totalLowStockProducts, // Total réel pour l'affichage
            'expiringProducts' => $expiringProducts,
            'expiringProductsTotal' => $totalExpiringProducts, // Total réel pour l'affichage
            'recentSales' => $recentSales,
            'recentExpenses' => $recentExpenses,
            'topProductsForChart' => $topProductsForChart,
            'topProducts' => $topProducts,
            'salesDueToday' => $salesDueToday,
        ]);
    }
}
