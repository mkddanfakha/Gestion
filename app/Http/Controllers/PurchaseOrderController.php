<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Traits\GeneratesPdf;

class PurchaseOrderController extends Controller
{
    use GeneratesPdf;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'purchase-orders', 'view');
        
        $query = PurchaseOrder::with(['supplier', 'user', 'items.product']);

        // Recherche par numéro de BC
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('supplier', function ($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filtrer par statut
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(15);

        // Ajouter les attributs calculés
        $purchaseOrders->getCollection()->transform(function ($po) {
            $po->setAttribute('status_label', $po->status_label);
            return $po;
        });

        return Inertia::render('PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'purchase-orders', 'create');
        
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $products = Product::with('category')->orderBy('name')->get();

        return Inertia::render('PurchaseOrders/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'purchase-orders', 'create');
        
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'status' => 'required|in:draft,sent,confirmed,partially_received,received,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total_price' => 'required|numeric|min:0',
        ]);

        // Générer automatiquement le numéro de BC
        $validated['po_number'] = PurchaseOrder::generatePONumber();
        $validated['user_id'] = auth()->id();

        $po = PurchaseOrder::create($validated);

        // Créer les items
        foreach ($request->items as $item) {
            $po->items()->create($item);
        }

        // Recalculer le total
        $po->calculateTotal();
        $po->save();

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Bon de commande créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission($request, 'purchase-orders', 'view');
        
        $purchaseOrder->load(['supplier', 'user', 'items.product', 'items.product.category', 'items.product.media', 'deliveryNotes']);
        
        // Ajouter l'URL de la première image pour chaque produit
        $purchaseOrder->items->transform(function ($item) {
            if ($item->product) {
                $firstImage = $item->product->getFirstMedia('images');
                $item->product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
            }
            return $item;
        });
        
        $purchaseOrder->setAttribute('status_label', $purchaseOrder->status_label);

        return Inertia::render('PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission($request, 'purchase-orders', 'edit');
        
        $purchaseOrder->load(['supplier', 'items.product']);
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $products = Product::with('category')->orderBy('name')->get();

        return Inertia::render('PurchaseOrders/Edit', [
            'purchaseOrder' => $purchaseOrder,
            'suppliers' => $suppliers,
            'products' => $products,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission($request, 'purchase-orders', 'update');
        
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'status' => 'required|in:draft,sent,confirmed,partially_received,received,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total_price' => 'required|numeric|min:0',
        ]);

        $purchaseOrder->update($validated);

        // Supprimer les anciens items
        $purchaseOrder->items()->delete();

        // Créer les nouveaux items
        foreach ($request->items as $item) {
            $purchaseOrder->items()->create($item);
        }

        // Recalculer le total
        $purchaseOrder->calculateTotal();
        $purchaseOrder->save();

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Bon de commande modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission($request, 'purchase-orders', 'delete');
        
        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Bon de commande supprimé avec succès.');
    }

    /**
     * Générer et télécharger le bon de commande PDF
     */
    public function downloadPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission($request, 'purchase-orders', 'download');
        
        try {
            $pdf = $this->generatePurchaseOrderPdf($purchaseOrder);
            $filename = 'Bon_de_commande_' . $purchaseOrder->po_number . '.pdf';
            return $this->pdfDownloadResponse($pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération du bon de commande PDF: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération du bon de commande. Veuillez réessayer.');
        }
    }

    /**
     * Afficher le bon de commande PDF dans le navigateur (pour impression)
     */
    public function printPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission($request, 'purchase-orders', 'print');
        
        try {
            $pdf = $this->generatePurchaseOrderPdf($purchaseOrder);
            $filename = 'Bon_de_commande_' . $purchaseOrder->po_number . '.pdf';
            return $this->pdfInlineResponse($pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération du bon de commande PDF: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération du bon de commande. Veuillez réessayer.');
        }
    }

    /**
     * Générer le PDF du bon de commande
     */
    private function generatePurchaseOrderPdf(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'user', 'items.product']);
        return $this->generatePdfFromView('purchase-orders.purchase-order', ['purchaseOrder' => $purchaseOrder]);
    }
}
