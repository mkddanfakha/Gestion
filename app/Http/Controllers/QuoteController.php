<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Sale;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Company;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductStockNotFoundException;
use App\Services\AttachmentService;
use App\Services\SaleStockService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Traits\GeneratesPdf;
use InvalidArgumentException;

class QuoteController extends Controller
{
    use GeneratesPdf;

    public function __construct(
        protected AttachmentService $attachmentService,
        protected SaleStockService $saleStockService,
    ) {}
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'quotes', 'view');
        
        $query = Quote::with(['customer', 'user'])
            ->withCount('quoteItems as items_count');

        // Filtrage par client
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filtrage par statut
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtrage par date
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Recherche par nom de client ou numéro de devis
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('quote_number', 'like', "%{$searchTerm}%")
                  ->orWhereHas('customer', function($customerQuery) use ($searchTerm) {
                      $customerQuery->where('name', 'like', "%{$searchTerm}%");
                  });
            });
        }

        $quotes = $query->orderBy('created_at', 'desc')->paginate(15);
        $customers = Customer::orderBy('name')->get();

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes,
            'customers' => $customers,
            'filters' => $request->only(['customer_id', 'status', 'date_from', 'date_to', 'search']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'quotes', 'create');
        
        $products = Product::with(['category', 'media'])->where('is_active', true)->orderBy('name')->get();

        $products->transform(function ($product) {
            $product->image_url = $product->getThumbImageUrl();
            return $product;
        });

        $customers = Customer::orderBy('name')->get();

        $initialCustomerId = $request->integer('customer_id') ?: null;
        if ($initialCustomerId && ! Customer::whereKey($initialCustomerId)->exists()) {
            $initialCustomerId = null;
        }

        return Inertia::render('Quotes/Create', [
            'products' => $products,
            'customers' => $customers,
            'initialCustomerId' => $initialCustomerId,
            'attachmentConfig' => $this->attachmentConfig(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'quotes', 'create');
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'valid_until' => 'nullable|date|after_or_equal:today',
            'status' => 'nullable|string|in:draft,sent,accepted,rejected,expired',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:' . config('attachments.max_files', 10),
            'attachments.*' => 'file',
        ]);

        unset($validated['attachments']);

        // Vérifier les doublons de produits
        $productIds = array_column($validated['items'], 'product_id');
        $duplicates = array_diff_assoc($productIds, array_unique($productIds));
        
        if (!empty($duplicates)) {
            $duplicateErrors = [];
            foreach ($duplicates as $index => $productId) {
                $product = Product::find($productId);
                $productName = $product ? $product->name : 'Produit supprimé';
                $duplicateErrors["items.{$index}.product_id"] = "Le produit \"{$productName}\" est déjà présent dans ce devis. Veuillez fusionner les quantités.";
            }
            return back()->withErrors($duplicateErrors)->withInput();
        }

        $subtotal = collect($validated['items'])->sum(function($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $taxAmount = $validated['tax_amount'] ?? 0;
        $discountAmount = $validated['discount_amount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discountAmount;

        $quote = Quote::create([
            'quote_number' => Quote::generateQuoteNumber(),
            'customer_id' => $validated['customer_id'] ?? null,
            'user_id' => auth()->id(),
            'notes' => $validated['notes'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
        ]);

        foreach ($validated['items'] as $item) {
            $quote->quoteItems()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        try {
            if ($request->hasFile('attachments')) {
                $this->attachmentService->addMany(
                    $quote,
                    $request->file('attachments'),
                    $request->user()
                );
            }
        } catch (InvalidArgumentException $e) {
            $quote->quoteItems()->delete();
            $quote->delete();

            return back()->withErrors(['attachments' => $e->getMessage()])->withInput();
        }

        return redirect()->route('quotes.index')
            ->with('success', 'Devis créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'quotes', 'view');
        
        // Charger les relations de base
        $quote->load(['customer', 'user', 'attachments.uploadedBy']);
        
        // Charger les articles séparément avec les médias des produits
        $quoteItems = $quote->quoteItems()->with(['product.category', 'product.media'])->get();
        
        // Ajouter l'URL de la première image pour chaque produit
        $quoteItems->transform(function ($item) {
            if ($item->product) {
                $item->product->image_url = $item->product->getThumbImageUrl();
            }
            return $item;
        });
        
        // Calculer le nombre d'articles
        $itemsCount = $quoteItems->count();
        
        // Préparer les données du devis
        $quoteData = $quote->toArray();
        $quoteData['quoteItems'] = $quoteItems->toArray();
        $quoteData['items_count'] = $itemsCount;
        
        return Inertia::render('Quotes/Show', [
            'quote' => $quoteData,
            'canUpdateQuote' => $request->user()?->hasPermission('quotes', 'update') ?? false,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'quotes', 'edit');
        
        $quote->load(['attachments.uploadedBy']);

        // Charger les articles séparément
        $quoteItems = $quote->quoteItems()->with('product.category')->get();
        
        // Charger les produits
        $products = Product::with(['category', 'media'])->where('is_active', true)->orderBy('name')->get();

        $products->transform(function ($product) {
            $product->image_url = $product->getThumbImageUrl();
            return $product;
        });
        
        $customers = Customer::orderBy('name')->get();
        
        // Calculer le nombre d'articles
        $itemsCount = $quoteItems->count();
        
        // Préparer les données du devis
        $quoteData = $quote->toArray();
        $quoteData['quoteItems'] = $quoteItems->toArray();
        $quoteData['items_count'] = $itemsCount;
        
        return Inertia::render('Quotes/Edit', [
            'quote' => $quoteData,
            'products' => $products,
            'customers' => $customers,
            'attachmentConfig' => $this->attachmentConfig(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'quotes', 'update');
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'valid_until' => 'nullable|date',
            'status' => 'required|string|in:draft,sent,accepted,rejected,expired',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:' . config('attachments.max_files', 10),
            'attachments.*' => 'file',
        ]);

        unset($validated['attachments']);

        // Vérifier les doublons de produits
        $productIds = array_column($validated['items'], 'product_id');
        $duplicates = array_diff_assoc($productIds, array_unique($productIds));
        
        if (!empty($duplicates)) {
            $duplicateErrors = [];
            foreach ($duplicates as $index => $productId) {
                $product = Product::find($productId);
                $productName = $product ? $product->name : 'Produit supprimé';
                $duplicateErrors["items.{$index}.product_id"] = "Le produit \"{$productName}\" est déjà présent dans ce devis. Veuillez fusionner les quantités.";
            }
            return back()->withErrors($duplicateErrors)->withInput();
        }

        $subtotal = collect($validated['items'])->sum(function($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $taxAmount = $validated['tax_amount'] ?? 0;
        $discountAmount = $validated['discount_amount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discountAmount;

        $quote->update([
            'customer_id' => $validated['customer_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'status' => $validated['status'],
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
        ]);

        // Supprimer les anciens items
        $quote->quoteItems()->delete();

        // Créer les nouveaux items
        foreach ($validated['items'] as $item) {
            $quote->quoteItems()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        try {
            if ($request->hasFile('attachments')) {
                $this->attachmentService->addMany(
                    $quote,
                    $request->file('attachments'),
                    $request->user()
                );
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['attachments' => $e->getMessage()])->withInput();
        }

        return redirect()->route('quotes.index')
            ->with('success', 'Devis mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'quotes', 'delete');
        
        $quote->delete();

        return redirect()->route('quotes.index')
            ->with('success', 'Devis supprimé avec succès.');
    }

    /**
     * Download quote as PDF
     */
    /**
     * Générer le PDF du devis
     */
    private function generateQuotePdf(Quote $quote): Dompdf
    {
        $quote->load(['customer', 'quoteItems.product']);
        return $this->generatePdfFromView('quotes.quote', ['quote' => $quote]);
    }

    /**
     * Download quote as PDF
     */
    public function downloadQuote(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'quotes', 'download');
        
        try {
            $pdf = $this->generateQuotePdf($quote);
            $filename = 'devis_' . $quote->quote_number . '.pdf';
            return $this->pdfDownloadResponse($pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération du PDF du devis: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération du PDF du devis.');
        }
    }

    /**
     * Print quote as PDF
     */
    public function printQuote(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'quotes', 'print');
        
        try {
            $pdf = $this->generateQuotePdf($quote);
            $filename = 'devis_' . $quote->quote_number . '.pdf';
            return $this->pdfInlineResponse($pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération du PDF du devis: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération du PDF du devis.');
        }
    }

    /**
     * Convertir un devis en vente
     */
    public function convertToSale(Request $request, Quote $quote)
    {
        $this->checkPermission($request, 'sales', 'create');
        
        // Vérifier que le devis peut être converti (pas rejeté ou expiré)
        if ($quote->status === 'rejected' || $quote->status === 'expired') {
            return back()->withErrors(['message' => 'Ce devis ne peut pas être converti en vente. Seuls les devis acceptés, envoyés ou en brouillon peuvent être convertis.']);
        }

        // Charger les items du devis
        $quote->load('quoteItems.product');

        // Vérifier le stock disponible pour chaque produit (ProductStock MAIN)
        $productQuantities = [];
        foreach ($quote->quoteItems as $item) {
            if (! $item->product_id) {
                continue;
            }
            $productQuantities[$item->product_id] = ($productQuantities[$item->product_id] ?? 0) + (int) $item->quantity;
        }

        $stockErrors = [];
        foreach ($this->saleStockService->validateStockAvailability($productQuantities) as $productId => $message) {
            $product = Product::find($productId);
            $productName = $product?->name ?? 'Produit #'.$productId;
            $available = 0;

            try {
                $available = $this->saleStockService->getAvailableStock((int) $productId);
            } catch (ProductStockNotFoundException) {
                $available = 0;
            }

            $requested = $productQuantities[$productId] ?? 0;
            $stockErrors[] = "Stock insuffisant pour {$productName}. Stock disponible: {$available}, Quantité demandée: {$requested}";
        }

        if (! empty($stockErrors)) {
            return back()->withErrors(['stock' => implode(' | ', $stockErrors)]);
        }

        try {
            $sale = DB::transaction(function () use ($quote, $productQuantities) {
                $sale = Sale::create([
                    'sale_number' => Sale::generateSaleNumber(),
                    'customer_id' => $quote->customer_id,
                    'user_id' => auth()->id(),
                    'payment_method' => 'cash',
                    'notes' => $quote->notes ? "Converti depuis le devis {$quote->quote_number}. ".$quote->notes : "Converti depuis le devis {$quote->quote_number}.",
                    'subtotal' => $quote->subtotal,
                    'tax_amount' => $quote->tax_amount,
                    'discount_amount' => $quote->discount_amount,
                    'down_payment_amount' => 0,
                    'remaining_amount' => $quote->total_amount,
                    'total_amount' => $quote->total_amount,
                    'payment_status' => 'pending',
                    'status' => 'completed',
                ]);

                foreach ($quote->quoteItems as $quoteItem) {
                    $sale->saleItems()->create([
                        'product_id' => $quoteItem->product_id,
                        'quantity' => $quoteItem->quantity,
                        'unit_price' => $quoteItem->unit_price,
                        'total_price' => $quoteItem->total_price,
                        'discount_amount' => $quoteItem->discount_amount ?? 0,
                    ]);
                }

                $this->saleStockService->applySaleCreation($sale, $productQuantities);

                if ($quote->status === 'sent') {
                    $quote->update(['status' => 'accepted']);
                }

                return $sale;
            });
        } catch (InsufficientStockException|ProductStockNotFoundException) {
            return back()->withErrors(['stock' => 'Stock insuffisant pour convertir ce devis en vente.']);
        }

        return redirect()->route('sales.show', $sale)
            ->with('success', "Devis converti en vente avec succès. Vente créée: {$sale->sale_number}");
    }

    protected function attachmentConfig(): array
    {
        return [
            'maxFiles' => config('attachments.max_files', 10),
            'maxSizeKb' => config('attachments.max_size', 10240),
            'allowedExtensions' => config('attachments.allowed_extensions', []),
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ];
    }
}
