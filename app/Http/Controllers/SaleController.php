<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Company;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductStockNotFoundException;
use App\Services\ActivityLogger;
use App\Services\SalePaymentService;
use App\Services\SaleStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Traits\GeneratesPdf;

class SaleController extends Controller
{
    use GeneratesPdf;

    public function __construct(
        protected SaleStockService $saleStockService,
    ) {}

    /**
     * Empêcher un vendeur d'accéder aux ventes des autres utilisateurs.
     */
    protected function authorizeSaleAccess(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user?->isVendeur() && $sale->user_id !== $user->id) {
            abort(403, 'Accès refusé. Vous ne pouvez accéder qu\'à vos propres ventes.');
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'sales', 'view');
        
        $query = Sale::with(['customer', 'user'])
            ->visibleTo($request->user())
            ->withCount('saleItems as items_count');

        // Filtrage par date
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Recherche par nom de client ou numéro de vente
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('sale_number', 'like', "%{$searchTerm}%")
                  ->orWhereHas('customer', function($customerQuery) use ($searchTerm) {
                      $customerQuery->where('name', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Filtre pour les ventes avec date d'échéance à une date spécifique et non payées
        if ($request->filled('due_date')) {
            $query->whereNotNull('due_date')
                  ->whereDate('due_date', $request->due_date)
                  ->where('payment_status', '!=', 'paid');
        }

        $sales = $query->orderBy('created_at', 'desc')->paginate(15);

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $request->only(['date_from', 'date_to', 'search', 'due_date']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'sales', 'create');
        
        $products = Product::with(['category', 'media'])->where('is_active', true)->orderBy('name')->get();
        
        // Ajouter l'URL de la première image pour chaque produit
        $products->transform(function ($product) {
            $product->image_url = $product->getThumbImageUrl();
            return $product;
        });
        
        $customers = Customer::orderBy('name')->get();

        $initialCustomerId = $request->integer('customer_id') ?: null;
        if ($initialCustomerId && ! Customer::whereKey($initialCustomerId)->exists()) {
            $initialCustomerId = null;
        }
        
        return Inertia::render('Sales/Create', [
            'products' => $products,
            'customers' => $customers,
            'initialCustomerId' => $initialCustomerId,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'sales', 'create');

        if ($request->input('payment_method') === '') {
            $request->merge(['payment_method' => null]);
        }
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'nullable|string|in:cash,card,bank_transfer,check,orange_money,wave',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'down_payment_amount' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after_or_equal:today',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $expiredErrors = [];
        $expiredMessage = config('notifications.contextual_messages.product_expired');
        $stockMessage = config('notifications.contextual_messages.insufficient_stock');
        $productQuantities = SaleStockService::aggregateQuantitiesByProduct($validated['items']);

        foreach ($validated['items'] as $index => $item) {
            $product = Product::find($item['product_id']);

            if ($product && $product->isExpired()) {
                $expiredErrors["items.{$index}.quantity"] = $expiredMessage;
            }
        }

        $stockErrors = $this->mapStockErrorsToItems(
            $validated['items'],
            $this->saleStockService->validateStockAvailability($productQuantities),
            $stockMessage,
        );

        // Vérifier les doublons de produits
        $productIds = array_column($validated['items'], 'product_id');
        $duplicates = array_diff_assoc($productIds, array_unique($productIds));
        
        if (!empty($duplicates)) {
            $duplicateErrors = [];
            foreach ($duplicates as $index => $productId) {
                $product = Product::find($productId);
                $productName = $product ? $product->name : 'Produit supprimé';
                $duplicateErrors["items.{$index}.product_id"] = "Le produit \"{$productName}\" est déjà présent dans cette vente. Veuillez fusionner les quantités.";
            }
            return back()->withErrors($duplicateErrors)->withInput();
        }

        // Si il y a des erreurs de péremption ou de stock, retourner les erreurs
        if (! empty($expiredErrors) || ! empty($stockErrors)) {
            return back()->withErrors([...$expiredErrors, ...$stockErrors])->withInput();
        }

        $subtotal = collect($validated['items'])->sum(function($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $taxAmount = $validated['tax_amount'] ?? 0;
        $discountAmount = $validated['discount_amount'] ?? 0;
        $downPaymentAmount = $validated['down_payment_amount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discountAmount;

        $paymentState = SalePaymentService::calculatePaymentState($totalAmount, $downPaymentAmount);

        $paymentMethodError = SalePaymentService::paymentMethodValidationMessage(
            $validated['payment_method'] ?? null,
            $paymentState['down_payment_amount'],
        );

        if ($paymentMethodError) {
            return back()->withErrors(['payment_method' => $paymentMethodError])->withInput();
        }

        $paymentMethod = SalePaymentService::resolvePaymentMethod(
            $validated['payment_method'] ?? null,
            $paymentState['down_payment_amount'],
        );

        try {
            $sale = DB::transaction(function () use (
                $validated,
                $subtotal,
                $taxAmount,
                $discountAmount,
                $totalAmount,
                $paymentState,
                $paymentMethod,
                $productQuantities,
            ) {
                $sale = Sale::create([
                    'sale_number' => Sale::generateSaleNumber(),
                    'customer_id' => $validated['customer_id'] ?? null,
                    'user_id' => auth()->id(),
                    'payment_method' => $paymentMethod,
                    'notes' => $validated['notes'] ?? null,
                    'due_date' => $validated['due_date'] ?? null,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'down_payment_amount' => $paymentState['down_payment_amount'],
                    'remaining_amount' => $paymentState['remaining_amount'],
                    'payment_status' => $paymentState['payment_status'],
                    'total_amount' => $totalAmount,
                    'status' => 'completed',
                ]);

                foreach ($validated['items'] as $item) {
                    $sale->saleItems()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['quantity'] * $item['unit_price'],
                    ]);
                }

                $this->saleStockService->applySaleCreation($sale, $productQuantities);

                return $sale;
            });
        } catch (InsufficientStockException|ProductStockNotFoundException) {
            return back()->withErrors(
                $this->mapStockErrorsToItems(
                    $validated['items'],
                    $this->saleStockService->validateStockAvailability($productQuantities),
                    $stockMessage,
                ),
            )->withInput();
        }

        ActivityLogger::logCreate('Facture', $sale);

        if ($sale->down_payment_amount > 0) {
            ActivityLogger::logPayment($sale);
        }

        return redirect()->route('sales.show', $sale->id)
            ->with('success', 'Vente créée avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'view');
        $this->authorizeSaleAccess($request, $sale);
        
        // Charger les relations de base
        $sale->load(['customer', 'user']);
        
        // Charger les articles séparément avec les médias des produits
        $saleItems = $sale->saleItems()->with(['product.category', 'product.media'])->get();
        
        // Ajouter l'URL de la première image pour chaque produit
        $saleItems->transform(function ($item) {
            if ($item->product) {
                $item->product->image_url = $item->product->getThumbImageUrl();
            }
            return $item;
        });
        
        // Calculer le nombre d'articles
        $itemsCount = $saleItems->count();
        
        // Préparer les données de la vente
        $saleData = $sale->toArray();
        $saleData['saleItems'] = $saleItems->toArray();
        $saleData['items_count'] = $itemsCount;
        
        return Inertia::render('Sales/Show', [
            'sale' => $saleData,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'edit');
        $this->authorizeSaleAccess($request, $sale);
        
        // Charger les articles séparément
        $saleItems = $sale->saleItems()->with('product.category')->get();
        
        // Charger les produits et ajouter temporairement les quantités de cette vente au stock
        $products = Product::with(['category', 'media'])->where('is_active', true)->orderBy('name')->get();
        
        // Ajouter l'URL de la première image pour chaque produit
        $products->transform(function ($product) {
            $product->image_url = $product->getThumbImageUrl();
            return $product;
        });
        
        // Ajouter temporairement les quantités de cette vente au stock pour l'affichage
        foreach ($saleItems as $saleItem) {
            $product = $products->find($saleItem->product_id);
            if ($product) {
                $product->stock_quantity += $saleItem->quantity;
            }
        }
        
        $customers = Customer::orderBy('name')->get();
        
        // Calculer le nombre d'articles
        $itemsCount = $saleItems->count();
        
        // Préparer les données de la vente
        $saleData = $sale->toArray();
        $saleData['saleItems'] = $saleItems->toArray();
        $saleData['items_count'] = $itemsCount;
        
        return Inertia::render('Sales/Edit', [
            'sale' => $saleData,
            'products' => $products,
            'customers' => $customers,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'update');
        $this->authorizeSaleAccess($request, $sale);

        if ($request->input('payment_method') === '') {
            $request->merge(['payment_method' => null]);
        }

        $oldPaymentStatus = $sale->payment_status;
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'nullable|string|in:cash,card,bank_transfer,check,orange_money,wave',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'down_payment_amount' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        // Charger les anciens items pour vérifier le stock
        $oldItems = $sale->saleItems()->get();

        // Vérifier les doublons de produits
        $productIds = array_column($validated['items'], 'product_id');
        $duplicates = array_diff_assoc($productIds, array_unique($productIds));
        
        if (!empty($duplicates)) {
            $duplicateErrors = [];
            foreach ($duplicates as $index => $productId) {
                $product = Product::find($productId);
                $productName = $product ? $product->name : 'Produit supprimé';
                $duplicateErrors["items.{$index}.product_id"] = "Le produit \"{$productName}\" est déjà présent dans cette vente. Veuillez fusionner les quantités.";
            }
            return back()->withErrors($duplicateErrors)->withInput();
        }

        $expiredErrors = [];
        $expiredMessage = config('notifications.contextual_messages.product_expired');
        $stockMessage = config('notifications.contextual_messages.insufficient_stock');
        $oldQuantities = SaleStockService::aggregateFromSaleItems($oldItems);
        $newQuantities = SaleStockService::aggregateQuantitiesByProduct($validated['items']);

        foreach ($validated['items'] as $index => $item) {
            $product = Product::find($item['product_id']);

            if ($product && $product->isExpired()) {
                $expiredErrors["items.{$index}.quantity"] = $expiredMessage;
            }
        }

        $stockErrors = $this->mapStockErrorsToItems(
            $validated['items'],
            $this->saleStockService->validateStockAvailability($newQuantities, $oldQuantities),
            $stockMessage,
        );

        if (! empty($expiredErrors) || ! empty($stockErrors)) {
            return back()->withErrors([...$expiredErrors, ...$stockErrors])->withInput();
        }

        $subtotal = collect($validated['items'])->sum(function($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $taxAmount = $validated['tax_amount'] ?? 0;
        $discountAmount = $validated['discount_amount'] ?? 0;
        $downPaymentAmount = $validated['down_payment_amount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discountAmount;

        $paymentState = SalePaymentService::calculatePaymentState(
            $totalAmount,
            $downPaymentAmount,
        );

        $paymentMethodError = SalePaymentService::paymentMethodValidationMessage(
            $validated['payment_method'] ?? null,
            $paymentState['down_payment_amount'],
        );

        if ($paymentMethodError) {
            return back()->withErrors(['payment_method' => $paymentMethodError])->withInput();
        }

        $paymentMethod = SalePaymentService::resolvePaymentMethod(
            $validated['payment_method'] ?? null,
            $paymentState['down_payment_amount'],
        );

        try {
            DB::transaction(function () use (
                $sale,
                $validated,
                $subtotal,
                $taxAmount,
                $discountAmount,
                $totalAmount,
                $paymentState,
                $paymentMethod,
                $oldQuantities,
                $newQuantities,
            ) {
                $this->saleStockService->applySaleUpdateDeltas($sale, $oldQuantities, $newQuantities);

                $sale->update([
                    'customer_id' => $validated['customer_id'] ?? null,
                    'payment_method' => $paymentMethod,
                    'notes' => $validated['notes'] ?? null,
                    'due_date' => $validated['due_date'] ?? null,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'down_payment_amount' => $paymentState['down_payment_amount'],
                    'remaining_amount' => $paymentState['remaining_amount'],
                    'payment_status' => $paymentState['payment_status'],
                    'total_amount' => $totalAmount,
                ]);

                $sale->saleItems()->delete();

                foreach ($validated['items'] as $item) {
                    $sale->saleItems()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['quantity'] * $item['unit_price'],
                    ]);
                }
            });
        } catch (InsufficientStockException|ProductStockNotFoundException) {
            return back()->withErrors(
                $this->mapStockErrorsToItems(
                    $validated['items'],
                    $this->saleStockService->validateStockAvailability($newQuantities, $oldQuantities),
                    $stockMessage,
                ),
            )->withInput();
        }

        $sale->refresh();

        if ($paymentState['payment_status'] === SalePaymentService::STATUS_PAID && $oldPaymentStatus !== SalePaymentService::STATUS_PAID) {
            ActivityLogger::logPayment($sale);
        } else {
            ActivityLogger::logUpdate('Facture', $sale);
        }

        return redirect()->route('sales.show', $sale->id)
            ->with('success', 'Vente mise à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'delete');
        $this->authorizeSaleAccess($request, $sale);
        
        $sale->load('saleItems');
        $productQuantities = SaleStockService::aggregateFromSaleItems($sale->saleItems);

        DB::transaction(function () use ($sale, $productQuantities) {
            $this->saleStockService->applySaleCancellation($sale, $productQuantities);
            $sale->delete();
        });

        ActivityLogger::logCancel('Facture', $sale);

        return redirect()->route('sales.index')
            ->with('success', 'Vente supprimée avec succès.');
    }

    /**
     * Générer et télécharger la facture PDF
     */
    public function downloadInvoice(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'invoice');
        $this->authorizeSaleAccess($request, $sale);
        
        try {
            // Charger les relations nécessaires
            $sale->load(['customer', 'user', 'saleItems.product']);
            
            // Générer le PDF
            $pdf = $this->generateInvoicePdf($sale);
            
            // Nom du fichier
            $filename = 'Facture_' . $sale->sale_number . '.pdf';
            
            // Retourner la réponse PDF pour téléchargement
            return $this->pdfDownloadResponse($pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération de la facture PDF: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération de la facture. Veuillez réessayer.');
        }
    }

    /**
     * Afficher la facture PDF dans le navigateur (pour impression)
     */
    public function printInvoice(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'invoice');
        $this->authorizeSaleAccess($request, $sale);
        
        try {
            // Charger les relations nécessaires
            $sale->load(['customer', 'user', 'saleItems.product']);
            
            // Générer le PDF
            $pdf = $this->generateInvoicePdf($sale);
            
            // Nom du fichier
            $filename = 'Facture_' . $sale->sale_number . '.pdf';
            
            // Retourner la réponse PDF pour affichage inline
            return $this->pdfInlineResponse($pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération de la facture PDF: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération de la facture. Veuillez réessayer.');
        }
    }

    /**
     * Générer le PDF de la facture
     */
    /**
     * Générer le PDF de la facture
     */
    private function generateInvoicePdf(Sale $sale)
    {
        return $this->generatePdfFromView('invoices.sale', ['sale' => $sale]);
    }

    /**
     * @param  list<array{product_id: int|string, quantity: int|string}>  $items
     * @param  array<int, string>  $productErrors
     * @return array<string, string>
     */
    protected function mapStockErrorsToItems(array $items, array $productErrors, string $fallbackMessage): array
    {
        if ($productErrors === []) {
            return [];
        }

        $errors = [];

        foreach ($items as $index => $item) {
            $productId = (int) $item['product_id'];

            if (isset($productErrors[$productId])) {
                $errors["items.{$index}.quantity"] = $productErrors[$productId] ?? $fallbackMessage;
            }
        }

        return $errors;
    }
}
