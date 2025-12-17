<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Company;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Traits\GeneratesPdf;

class SaleController extends Controller
{
    use GeneratesPdf;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'sales', 'view');
        
        $query = Sale::with(['customer', 'user'])
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
            $firstImage = $product->getFirstMedia('images');
            $product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
            return $product;
        });
        
        $customers = Customer::orderBy('name')->get();
        
        return Inertia::render('Sales/Create', [
            'products' => $products,
            'customers' => $customers,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'sales', 'create');
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,check,orange_money,wave',
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

        // Vérifier le stock disponible pour chaque produit
        $stockErrors = [];
        $productQuantities = [];
        
        // Calculer les quantités totales par produit
        foreach ($validated['items'] as $item) {
            $productId = $item['product_id'];
            if (!isset($productQuantities[$productId])) {
                $productQuantities[$productId] = 0;
            }
            $productQuantities[$productId] += $item['quantity'];
        }
        
        // Vérifier le stock pour chaque produit
        foreach ($productQuantities as $productId => $totalQuantity) {
            $product = Product::find($productId);
            if ($product && $product->stock_quantity < $totalQuantity) {
                // Trouver tous les indices où ce produit est utilisé
                foreach ($validated['items'] as $index => $item) {
                    if ($item['product_id'] == $productId) {
                        $stockErrors["items.{$index}.quantity"] = "Stock insuffisant pour {$product->name}. Stock disponible: {$product->stock_quantity}, Quantité demandée: {$totalQuantity}";
                    }
                }
            }
        }

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

        // Si il y a des erreurs de stock, retourner les erreurs
        if (!empty($stockErrors)) {
            return back()->withErrors($stockErrors)->withInput();
        }

        $subtotal = collect($validated['items'])->sum(function($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $taxAmount = $validated['tax_amount'] ?? 0;
        $discountAmount = $validated['discount_amount'] ?? 0;
        $downPaymentAmount = $validated['down_payment_amount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discountAmount;
        $remainingAmount = $totalAmount - $downPaymentAmount;
        
        // Déterminer le statut de paiement automatiquement basé sur les montants
        if ($downPaymentAmount >= $totalAmount && $totalAmount > 0) {
            // Acompte égal ou supérieur au total = payé
            $paymentStatus = 'paid';
            $downPaymentAmount = $totalAmount;
            $remainingAmount = 0;
        } elseif ($downPaymentAmount > 0 && $remainingAmount > 0) {
            // Acompte partiel = paiement partiel
            $paymentStatus = 'partial';
        } elseif ($downPaymentAmount == 0 && $totalAmount > 0) {
            // Aucun acompte = en attente
            $paymentStatus = 'pending';
        } else {
            // Par défaut, payé
            $paymentStatus = 'paid';
        }

        $sale = Sale::create([
            'sale_number' => Sale::generateSaleNumber(),
            'customer_id' => $validated['customer_id'],
            'user_id' => auth()->id(),
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'],
            'due_date' => $validated['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'down_payment_amount' => $downPaymentAmount,
            'remaining_amount' => $remainingAmount,
            'payment_status' => $paymentStatus,
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

            // Mettre à jour le stock
            $product = Product::find($item['product_id']);
            if ($product) {
                $product->decrement('stock_quantity', $item['quantity']);
                $product->refresh();
                
                // Vérifier si le produit est maintenant en stock faible
                if ($product->stock_quantity <= $product->min_stock_level) {
                    NotificationService::notifyLowStock($product);
                }
            }
        }

        // Vérifier si la vente a une date d'échéance aujourd'hui et n'est pas payée
        if ($sale->due_date && $sale->due_date->isToday() && $sale->payment_status !== 'paid') {
            NotificationService::notifySaleDueToday($sale);
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
        
        // Charger les relations de base
        $sale->load(['customer', 'user']);
        
        // Charger les articles séparément avec les médias des produits
        $saleItems = $sale->saleItems()->with(['product.category', 'product.media'])->get();
        
        // Ajouter l'URL de la première image pour chaque produit
        $saleItems->transform(function ($item) {
            if ($item->product) {
                $firstImage = $item->product->getFirstMedia('images');
                $item->product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
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
        
        // Charger les articles séparément
        $saleItems = $sale->saleItems()->with('product.category')->get();
        
        // Charger les produits et ajouter temporairement les quantités de cette vente au stock
        $products = Product::with(['category', 'media'])->where('is_active', true)->orderBy('name')->get();
        
        // Ajouter l'URL de la première image pour chaque produit
        $products->transform(function ($product) {
            $firstImage = $product->getFirstMedia('images');
            $product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
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
        
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,check,orange_money,wave',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'down_payment_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:paid,partial,pending',
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

        // Vérifier le stock disponible en tenant compte de l'ancienne quantité qui sera restaurée
        $stockErrors = [];
        $productQuantities = [];
        
        // Calculer les quantités totales par produit pour les nouveaux items
        foreach ($validated['items'] as $item) {
            $productId = $item['product_id'];
            if (!isset($productQuantities[$productId])) {
                $productQuantities[$productId] = 0;
            }
            $productQuantities[$productId] += $item['quantity'];
        }
        
        // Vérifier le stock pour chaque produit en tenant compte de l'ancienne quantité
        foreach ($productQuantities as $productId => $totalQuantity) {
            $product = Product::find($productId);
            if ($product) {
                // Calculer l'ancienne quantité pour ce produit
                $oldQuantity = 0;
                foreach ($oldItems as $oldItem) {
                    if ($oldItem->product_id == $productId) {
                        $oldQuantity += $oldItem->quantity;
                    }
                }
                
                // Stock disponible = stock actuel + ancienne quantité qui sera restaurée
                $availableStock = $product->stock_quantity + $oldQuantity;
                
                if ($availableStock < $totalQuantity) {
                    // Trouver tous les indices où ce produit est utilisé
                    foreach ($validated['items'] as $index => $item) {
                        if ($item['product_id'] == $productId) {
                            $stockErrors["items.{$index}.quantity"] = "Stock insuffisant pour {$product->name}. Stock disponible: {$availableStock} (incluant {$oldQuantity} de cette vente), Quantité demandée: {$totalQuantity}";
                        }
                    }
                }
            }
        }

        // Si il y a des erreurs de stock, retourner les erreurs
        if (!empty($stockErrors)) {
            return back()->withErrors($stockErrors)->withInput();
        }

        // Restaurer le stock des anciens items (ils avaient été déduits lors de la création)
        foreach ($oldItems as $item) {
            $product = Product::find($item->product_id);
            if ($product) {
                $product->increment('stock_quantity', $item->quantity);
            }
        }

        // Supprimer les anciens items
        $sale->saleItems()->delete();

        $subtotal = collect($validated['items'])->sum(function($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $taxAmount = $validated['tax_amount'] ?? 0;
        $discountAmount = $validated['discount_amount'] ?? 0;
        $downPaymentAmount = $validated['down_payment_amount'] ?? 0;
        $totalAmount = $subtotal + $taxAmount - $discountAmount;
        $remainingAmount = $totalAmount - $downPaymentAmount;
        
        // Déterminer le statut de paiement automatiquement basé sur les montants
        // Si le statut est explicitement 'paid', ajuster les montants pour refléter le paiement complet
        if (isset($validated['payment_status']) && $validated['payment_status'] === 'paid') {
            $downPaymentAmount = $totalAmount;
            $remainingAmount = 0;
            $paymentStatus = 'paid';
        } else {
            // Calculer automatiquement le statut basé sur les montants
            if ($downPaymentAmount >= $totalAmount && $totalAmount > 0) {
                // Acompte égal ou supérieur au total = payé
                $paymentStatus = 'paid';
                $downPaymentAmount = $totalAmount;
                $remainingAmount = 0;
            } elseif ($downPaymentAmount > 0 && $remainingAmount > 0) {
                // Acompte partiel = paiement partiel
                $paymentStatus = 'partial';
            } elseif ($downPaymentAmount == 0 && $totalAmount > 0) {
                // Aucun acompte = en attente
                $paymentStatus = 'pending';
            } else {
                // Par défaut, utiliser le statut fourni ou 'pending'
                $paymentStatus = $validated['payment_status'] ?? 'pending';
            }
        }

        // Mettre à jour la vente
        $sale->update([
            'customer_id' => $validated['customer_id'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'],
            'due_date' => $validated['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'down_payment_amount' => $downPaymentAmount,
            'remaining_amount' => $remainingAmount,
            'payment_status' => $paymentStatus,
            'total_amount' => $totalAmount,
        ]);

        // Créer les nouveaux items et déduire du stock (comme pour une nouvelle vente)
        foreach ($validated['items'] as $item) {
            $sale->saleItems()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
            ]);

            $product = Product::find($item['product_id']);
            if ($product) {
                $product->decrement('stock_quantity', $item['quantity']);
                $product->refresh();
                
                // Vérifier si le produit est maintenant en stock faible
                if ($product->stock_quantity <= $product->min_stock_level) {
                    NotificationService::notifyLowStock($product);
                }
            }
        }

        // Recharger la vente pour avoir les dernières données
        $sale->refresh();

        // Vérifier si la vente a une date d'échéance aujourd'hui et n'est pas payée
        if ($sale->due_date && $sale->due_date->isToday() && $sale->payment_status !== 'paid') {
            NotificationService::notifySaleDueToday($sale);
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
        
        // Restaurer le stock
        foreach ($sale->saleItems as $item) {
            $product = Product::find($item->product_id);
            if ($product) {
                $product->increment('stock_quantity', $item->quantity);
            }
        }

        $sale->delete();

        return redirect()->route('sales.index')
            ->with('success', 'Vente supprimée avec succès.');
    }

    /**
     * Générer et télécharger la facture PDF
     */
    public function downloadInvoice(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'invoice');
        
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
}
