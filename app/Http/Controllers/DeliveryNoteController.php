<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Dompdf\Dompdf;
use Dompdf\Options;

class DeliveryNoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'delivery-notes', 'view');
        
        $query = DeliveryNote::with(['supplier', 'user', 'purchaseOrder', 'items.product']);

        // Recherche par numéro de BL
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_number', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('supplier', function ($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filtrer par statut
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $deliveryNotes = $query->orderBy('created_at', 'desc')->paginate(15);

        // Ajouter les attributs calculés
        $deliveryNotes->getCollection()->transform(function ($dn) {
            $dn->setAttribute('status_label', $dn->status_label);
            return $dn;
        });

        // Calculer la somme totale des BL validés
        $totalValidatedAmount = DeliveryNote::where('status', 'validated')
            ->sum('total_amount');

        return Inertia::render('DeliveryNotes/Index', [
            'deliveryNotes' => $deliveryNotes,
            'filters' => $request->only(['search', 'status']),
            'statistics' => [
                'total_validated_amount' => $totalValidatedAmount,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'delivery-notes', 'create');
        
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $products = Product::with('category')->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::with('supplier')->orderBy('po_number')->get();
        
        // Optionnel: charger un bon de commande si spécifié
        $purchaseOrder = null;
        if ($request->filled('purchase_order_id')) {
            $purchaseOrder = PurchaseOrder::with(['items.product', 'supplier'])
                ->find($request->purchase_order_id);
        }

        return Inertia::render('DeliveryNotes/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
            'purchaseOrders' => $purchaseOrders,
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'delivery-notes', 'create');
        
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'delivery_date' => 'required|date',
            'status' => 'required|in:pending,validated,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'invoice_number' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total_price' => 'required|numeric|min:0',
        ]);

        // Générer automatiquement le numéro de BL
        $validated['delivery_number'] = DeliveryNote::generateDeliveryNumber();
        $validated['user_id'] = auth()->id();

        $dn = DeliveryNote::create($validated);

        // Créer les items
        foreach ($request->items as $item) {
            $dn->items()->create($item);
        }

        // Recharger la relation items pour s'assurer que tous les items sont disponibles
        $dn->load('items');

        // Si le statut est validé, ajuster le stock
        if ($validated['status'] === 'validated') {
            $dn->validate();
        }

        return redirect()->route('delivery-notes.index')
            ->with('success', 'Bon de livraison créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'view');
        
        // Recharger le BL pour s'assurer que toutes les relations sont à jour
        $deliveryNote = DeliveryNote::with(['supplier', 'user', 'purchaseOrder', 'items.product', 'items.product.category', 'items.product.media'])
            ->findOrFail($deliveryNote->id);
        
        // Ajouter l'URL de la première image pour chaque produit
        $deliveryNote->items->transform(function ($item) {
            if ($item->product) {
                $firstImage = $item->product->getFirstMedia('images');
                $item->product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
            }
            return $item;
        });
        
        $deliveryNote->setAttribute('status_label', $deliveryNote->status_label);

        return Inertia::render('DeliveryNotes/Show', [
            'deliveryNote' => $deliveryNote,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'edit');
        
        // Empêcher l'édition si le BL est validé
        if ($deliveryNote->status === 'validated') {
            return back()->withErrors(['message' => 'Un bon de livraison validé ne peut pas être modifié.']);
        }

        $deliveryNote->load(['supplier', 'purchaseOrder', 'items.product']);
        
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $products = Product::with('category')->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::with('supplier')->orderBy('po_number')->get();

        return Inertia::render('DeliveryNotes/Edit', [
            'deliveryNote' => $deliveryNote,
            'suppliers' => $suppliers,
            'products' => $products,
            'purchaseOrders' => $purchaseOrders,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'update');
        
        // Empêcher la modification si le BL est validé
        if ($deliveryNote->status === 'validated') {
            return back()->withErrors(['message' => 'Un bon de livraison validé ne peut pas être modifié.']);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'delivery_date' => 'required|date',
            'status' => 'required|in:pending,validated,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'invoice_number' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:delivery_note_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total_price' => 'required|numeric|min:0',
        ]);

        \DB::transaction(function () use ($validated, $deliveryNote, $request) {
            // Mettre à jour le bon de livraison
            $deliveryNote->update($validated);

            // Gérer les items
            $existingItemIds = $deliveryNote->items->pluck('id')->toArray();
            $updatedItemIds = [];

            foreach ($request->items as $itemData) {
                if (isset($itemData['id'])) {
                    // Mettre à jour l'item existant
                    $deliveryNote->items()->where('id', $itemData['id'])->update([
                        'product_id' => $itemData['product_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'total_price' => $itemData['total_price'],
                    ]);
                    $updatedItemIds[] = $itemData['id'];
                } else {
                    // Créer un nouvel item
                    $deliveryNote->items()->create([
                        'product_id' => $itemData['product_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'total_price' => $itemData['total_price'],
                    ]);
                }
            }

            // Supprimer les items qui ne sont plus présents
            $itemsToDelete = array_diff($existingItemIds, $updatedItemIds);
            if (!empty($itemsToDelete)) {
                $deliveryNote->items()->whereIn('id', $itemsToDelete)->delete();
            }

            // Recharger la relation items pour s'assurer que tous les items sont disponibles
            $deliveryNote->load('items');

            // Recalculer le total
            $deliveryNote->calculateTotal();
            $deliveryNote->save();
        });

        return redirect()->route('delivery-notes.show', $deliveryNote)
            ->with('success', 'Bon de livraison modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'delete');
        
        // Si le BL est validé, on ne peut pas le supprimer
        if ($deliveryNote->status === 'validated') {
            return back()->withErrors(['message' => 'Un bon de livraison validé ne peut pas être supprimé.']);
        }

        $deliveryNote->delete();

        return redirect()->route('delivery-notes.index')
            ->with('success', 'Bon de livraison supprimé avec succès.');
    }

    /**
     * Valider un bon de livraison
     */
    public function validate(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'validate');
        
        if ($deliveryNote->status !== 'pending') {
            return back()->withErrors(['message' => 'Ce bon de livraison ne peut pas être validé.']);
        }

        $success = $deliveryNote->validate();

        if ($success) {
            return redirect()->route('delivery-notes.show', $deliveryNote)
                ->with('success', 'Bon de livraison validé et stock ajusté avec succès.');
        }

        return back()->withErrors(['message' => 'Erreur lors de la validation du bon de livraison.']);
    }

    /**
     * Upload de la facture/BL fournisseur (image/PDF etc.)
     */
    public function uploadInvoice(Request $request, $deliveryNote)
    {
        // Résoudre le DeliveryNote manuellement pour éviter les problèmes de model binding
        $deliveryNote = DeliveryNote::findOrFail($deliveryNote);
        
        $this->checkPermission($request, 'delivery-notes', 'invoice');
        
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:2048', // 2MB
        ]);

        $file = $request->file('file');

        // Dossier de destination sur le disque media (public/storage)
        $directory = 'delivery-notes/' . $deliveryNote->id;

        // Nom de fichier unique
        $filename = now()->format('Ymd_His') . '_' . str_replace(' ', '_', $file->getClientOriginalName());

        // Stocker sur le disque media pour accès via /storage
        $path = $file->storeAs($directory, $filename, 'media');

        // Supprimer l'ancien fichier si existant
        if ($deliveryNote->invoice_file_path) {
            // Vérifier sur les deux disques pour compatibilité
            if (Storage::disk('media')->exists($deliveryNote->invoice_file_path)) {
                Storage::disk('media')->delete($deliveryNote->invoice_file_path);
            } elseif (Storage::disk('public')->exists($deliveryNote->invoice_file_path)) {
                Storage::disk('public')->delete($deliveryNote->invoice_file_path);
            }
        }

        // Mettre à jour les métadonnées
        $deliveryNote->update([
            'invoice_file_path' => $path,
            'invoice_file_name' => $file->getClientOriginalName(),
            'invoice_file_mime' => $file->getClientMimeType(),
            'invoice_file_size' => $file->getSize(),
        ]);

        return back()->with('success', 'Fichier de facture/BL uploadé avec succès.');
    }

    /**
     * Afficher / télécharger la facture/BL fournisseur
     */
    public function showInvoice(Request $request, $deliveryNote)
    {
        // Résoudre le DeliveryNote manuellement pour éviter les problèmes de model binding
        $deliveryNote = DeliveryNote::findOrFail($deliveryNote);
        
        $this->checkPermission($request, 'delivery-notes', 'invoice');
        
        if (!$deliveryNote->invoice_file_path) {
            abort(404, 'Aucun fichier associé à ce bon de livraison.');
        }

        $path = $deliveryNote->invoice_file_path;
        
        // Vérifier d'abord sur le disque media, puis sur public pour compatibilité
        $absolutePath = null;
        
        if (Storage::disk('media')->exists($path)) {
            $absolutePath = Storage::disk('media')->path($path);
        } elseif (Storage::disk('public')->exists($path)) {
            $absolutePath = Storage::disk('public')->path($path);
        } else {
            abort(404, 'Fichier introuvable sur le serveur.');
        }

        // Vérifier que le fichier existe vraiment
        if (!file_exists($absolutePath)) {
            abort(404, 'Fichier introuvable sur le serveur.');
        }

        // Affichage inline pour PDF/images
        return response()->file($absolutePath, [
            'Content-Type' => $deliveryNote->invoice_file_mime ?? mime_content_type($absolutePath),
            'Content-Disposition' => 'inline; filename="' . addslashes($deliveryNote->invoice_file_name ?? basename($absolutePath)) . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    /**
     * Supprimer le fichier de facture/BL
     */
    public function deleteInvoice(Request $request, $deliveryNote)
    {
        // Résoudre le DeliveryNote manuellement pour éviter les problèmes de model binding
        $deliveryNote = DeliveryNote::findOrFail($deliveryNote);
        
        $this->checkPermission($request, 'delivery-notes', 'invoice');
        
        if ($deliveryNote->invoice_file_path) {
            // Supprimer sur les deux disques pour compatibilité
            if (Storage::disk('media')->exists($deliveryNote->invoice_file_path)) {
                Storage::disk('media')->delete($deliveryNote->invoice_file_path);
            }
            if (Storage::disk('public')->exists($deliveryNote->invoice_file_path)) {
                Storage::disk('public')->delete($deliveryNote->invoice_file_path);
            }
        }

        $deliveryNote->update([
            'invoice_file_path' => null,
            'invoice_file_name' => null,
            'invoice_file_mime' => null,
            'invoice_file_size' => null,
        ]);

        return back()->with('success', 'Fichier de facture/BL supprimé avec succès.');
    }

    /**
     * Générer et télécharger le bon de livraison PDF
     */
    public function downloadDeliveryNote(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'download');
        
        try {
            // Charger les relations nécessaires
            $deliveryNote->load(['supplier', 'user', 'purchaseOrder', 'items.product']);
            
            // Générer le PDF
            $pdf = $this->generateDeliveryNotePdf($deliveryNote);
            
            // Nom du fichier
            $filename = 'Bon_de_livraison_' . $deliveryNote->delivery_number . '.pdf';
            
            // Générer le contenu PDF
            $pdfContent = $pdf->output();
            
            // Télécharger le PDF
            return response()->streamDownload(function () use ($pdfContent) {
                echo $pdfContent;
            }, $filename, [
                'Content-Type' => 'application/pdf',
                'Content-Length' => strlen($pdfContent),
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération du bon de livraison PDF: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération du bon de livraison. Veuillez réessayer.');
        }
    }

    /**
     * Afficher le bon de livraison PDF dans le navigateur (pour impression)
     */
    public function printDeliveryNote(Request $request, DeliveryNote $deliveryNote)
    {
        $this->checkPermission($request, 'delivery-notes', 'print');
        
        try {
            // Charger les relations nécessaires
            $deliveryNote->load(['supplier', 'user', 'purchaseOrder', 'items.product']);
            
            // Générer le PDF
            $pdf = $this->generateDeliveryNotePdf($deliveryNote);
            
            // Nom du fichier
            $filename = 'Bon_de_livraison_' . $deliveryNote->delivery_number . '.pdf';
            
            // Générer le contenu PDF
            $pdfContent = $pdf->output();
            
            // Afficher le PDF dans le navigateur (inline)
            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
                'Content-Length' => strlen($pdfContent),
                'Cache-Control' => 'private, max-age=0, must-revalidate',
                'Pragma' => 'public',
                'Accept-Ranges' => 'bytes',
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la génération du bon de livraison PDF: ' . $e->getMessage());
            abort(500, 'Erreur lors de la génération du bon de livraison. Veuillez réessayer.');
        }
    }

    /**
     * Générer le PDF du bon de livraison
     */
    private function generateDeliveryNotePdf(DeliveryNote $deliveryNote)
    {
        // Options pour DomPDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isPhpEnabled', true);
        $options->set('chroot', base_path());
        
        // Créer une instance de DomPDF
        $dompdf = new Dompdf($options);
        
        // Récupérer les informations de l'entreprise
        $company = Company::getInstance();
        
        // Rendre la vue Blade en HTML
        $html = view('delivery-notes.delivery-note', compact('deliveryNote', 'company'))->render();
        
        // S'assurer que le HTML est en UTF-8
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        
        // Charger le HTML dans DomPDF avec encodage UTF-8
        $dompdf->loadHtml($html, 'UTF-8');
        
        // Définir le format de papier (A4)
        $dompdf->setPaper('A4', 'portrait');
        
        // Rendre le PDF
        $dompdf->render();
        
        return $dompdf;
    }
}
