<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $this->checkPermission($request, 'products', 'view');
        
        $query = Product::with(['category', 'media']);

        // Filtrage par catégorie
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Recherche par nom ou SKU
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Filtrage par statut de stock
        if ($request->filled('stock_status')) {
            if ($request->stock_status === 'low') {
                $query->whereRaw('stock_quantity <= min_stock_level');
            } elseif ($request->stock_status === 'out') {
                $query->where('stock_quantity', 0);
            }
        }

        // Filtrage par produits expirés ou proches de l'expiration
        if ($request->filled('expiration_alert')) {
            $products = $query->whereNotNull('expiration_date')
                ->get()
                ->filter(function ($product) {
                    return $product->isExpired() || $product->isExpiringSoon();
                })
                ->map(function ($product) {
                    $firstImage = $product->getFirstMedia('images');
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'sku' => $product->sku,
                        'price' => $product->price,
                        'stock_quantity' => $product->stock_quantity,
                        'min_stock_level' => $product->min_stock_level,
                        'unit' => $product->unit,
                        'expiration_date' => $product->expiration_date ? $product->expiration_date->format('Y-m-d') : null,
                        'days_until_expiration' => $product->days_until_expiration,
                        'category' => $product->category,
                        'image_url' => Product::getMediaUrl($firstImage, 'thumb'),
                    ];
                });
            
            // Convertir en pagination manuelle
            $currentPage = $request->get('page', 1);
            $perPage = 15;
            $total = $products->count();
            $items = $products->slice(($currentPage - 1) * $perPage, $perPage)->values();
            
            $products = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $products = $query->orderBy('name')->paginate(15);
            // Ajouter days_until_expiration et image_url à chaque produit
            $products->getCollection()->transform(function ($product) {
                $product->days_until_expiration = $product->days_until_expiration;
                if ($product->expiration_date) {
                    $product->expiration_date = $product->expiration_date->format('Y-m-d');
                }
                $firstImage = $product->getFirstMedia('images');
                $product->image_url = Product::getMediaUrl($firstImage, 'thumb');
                return $product;
            });
        }
        $categories = Category::orderBy('name')->get();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'categories' => $categories,
            'filters' => $request->only(['category_id', 'search', 'stock_status', 'expiration_alert']),
        ]);
    }

    public function create(Request $request)
    {
        $this->checkPermission($request, 'products', 'create');
        
        $categories = Category::orderBy('name')->get();
        return Inertia::render('Products/Create', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $this->checkPermission($request, 'products', 'create');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'nullable|string|max:6|unique:products',
            'barcode' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'min_stock_level' => 'required|integer|min:0',
            'unit' => 'required|string|max:50',
            'location' => 'nullable|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
            'expiration_date' => 'nullable|date',
            'alert_threshold_value' => 'nullable|integer|min:1',
            'alert_threshold_unit' => 'nullable|in:days,weeks,months',
        ]);

        // Générer automatiquement le SKU si non fourni
        if (empty($validated['sku'])) {
            $validated['sku'] = Product::generateSku($validated['name'], $validated['category_id']);
        }

        $product = Product::create($validated);

        // Gérer l'upload d'images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $product->addMedia($image)
                    ->toMediaCollection('images', 'media');
            }
        }

        // Vérifier si le produit est en stock faible
        if ($product->stock_quantity <= $product->min_stock_level) {
            NotificationService::notifyLowStock($product);
        }

        // Vérifier si le produit expire bientôt ou est expiré
        if ($product->expiration_date && ($product->isExpired() || $product->isExpiringSoon())) {
            NotificationService::notifyExpiringProduct($product);
        }

        return redirect()->route('products.index')
            ->with('success', 'Produit créé avec succès.');
    }

    public function show(Request $request, Product $product)
    {
        $this->checkPermission($request, 'products', 'view');
        
        // Recharger le produit pour s'assurer qu'il est à jour
        $product->refresh();
        
        // Charger toutes les relations nécessaires
        $product->load([
            'category', 
            'saleItems.sale:id,total_amount,down_payment_amount,payment_status,customer_id',
            'saleItems.sale.customer',
            'media'
        ]);
        
        // Ajouter la marge bénéficiaire calculée
        $product->profit_margin = $product->profit_margin;
        
        // Ajouter les informations d'expiration
        if ($product->expiration_date) {
            $product->days_until_expiration = $product->days_until_expiration;
            $product->expiration_date = $product->expiration_date->format('Y-m-d');
        }
        
        // Calculer le chiffre d'affaires basé sur le statut de paiement
        $totalSales = 0;
        foreach ($product->saleItems as $saleItem) {
            $sale = $saleItem->sale;
            if ($sale && $sale->total_amount > 0) {
                // Calculer la proportion de cette vente que représente ce produit
                $productProportion = $saleItem->total_price / $sale->total_amount;
                
                // Déterminer le montant à utiliser selon le statut de paiement
                $saleAmount = ($sale->payment_status === 'paid') 
                    ? $sale->total_amount 
                    : $sale->down_payment_amount;
                
                // Appliquer cette proportion au montant de la vente
                $totalSales += $saleAmount * $productProportion;
            }
        }
        
        // Préparer les médias - s'assurer que la relation est bien chargée
        $product->load('media');
        $mediaItems = $product->getMedia('images');
        
        $media = $mediaItems->map(function ($mediaItem) {
            $url = Product::getMediaUrl($mediaItem);
            return [
                'id' => $mediaItem->id,
                'url' => $url,
                'name' => $mediaItem->name,
            ];
        })->filter(function ($item) {
            // Filtrer les médias qui n'ont pas d'URL valide
            return !empty($item['url']);
        })->values()->toArray();
        
        
        // Préparer les données pour le frontend
        $productData = $product->toArray();
        $productData['sale_items_count'] = $product->saleItems->count();
        $productData['total_sales'] = $totalSales;
        $productData['media'] = $media;
        
        return Inertia::render('Products/Show', [
            'product' => $productData,
        ]);
    }

    public function edit(Request $request, Product $product)
    {
        $this->checkPermission($request, 'products', 'edit');
        
        $categories = Category::orderBy('name')->get();
        
        // Charger les images - s'assurer que la relation est bien chargée
        $product->refresh();
        $product->load('media');
        $mediaItems = $product->getMedia('images');
        
        $images = $mediaItems->map(function ($media) {
            $url = Product::getMediaUrl($media);
            return [
                'id' => $media->id,
                'url' => $url,
                'name' => $media->name,
            ];
        })->filter(function ($item) {
            // Filtrer les médias qui n'ont pas d'URL valide
            return !empty($item['url']);
        })->values();
        
        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => $categories,
            'uploadUrl' => route('products.upload-image'),
            'images' => $images,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $this->checkPermission($request, 'products', 'update');
        
        $user = $request->user();
        $isVendeur = $user && $user->hasRole('vendeur');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'required|string|max:6|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => $isVendeur ? 'prohibited' : 'required|integer|min:0',
            'min_stock_level' => $isVendeur ? 'prohibited' : 'required|integer|min:0',
            'unit' => 'required|string|max:50',
            'location' => 'nullable|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
            'expiration_date' => 'nullable|date',
            'alert_threshold_value' => 'nullable|integer|min:1',
            'alert_threshold_unit' => 'nullable|in:days,weeks,months',
            'images' => 'nullable|array',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max par image
            'delete_images' => 'nullable|array',
            'delete_images.*' => 'nullable|integer',
        ]);

        // Si c'est un vendeur, retirer les champs de stock des données validées
        if ($isVendeur) {
            unset($validated['stock_quantity'], $validated['min_stock_level']);
        }

        // Convertir les chaînes vides en null pour expiration_date
        if (isset($validated['expiration_date']) && $validated['expiration_date'] === '') {
            $validated['expiration_date'] = null;
            // Si la date d'expiration est supprimée, supprimer aussi les seuils d'alerte
            $validated['alert_threshold_value'] = null;
            $validated['alert_threshold_unit'] = null;
        }

        $product->update($validated);
        $product->refresh();

        // Gérer la suppression d'images si demandée (AVANT l'ajout de nouvelles)
        // PROTECTION CRITIQUE : Ne supprimer les images que si :
        // 1. Un nouveau fichier est ajouté (remplacement) OU
        // 2. delete_images est explicitement envoyé ET qu'aucun nouveau fichier n'est ajouté (suppression manuelle)
        $hasNewImages = $request->hasFile('images');
        $hasDeleteImages = $request->filled('delete_images') && is_array($request->delete_images) && !empty($request->delete_images);
        
        \Log::info('État de la mise à jour des images', [
            'has_new_images' => $hasNewImages,
            'has_delete_images' => $hasDeleteImages,
            'delete_images' => $request->delete_images ?? 'non défini',
            'product_id' => $product->id,
            'existing_images_count' => $product->getMedia('images')->count()
        ]);
        
        // PROTECTION : Si aucun nouveau fichier n'est ajouté, ne JAMAIS supprimer les images existantes
        // même si delete_images est envoyé (c'est probablement une erreur du frontend)
        if ($hasDeleteImages && !$hasNewImages) {
            // delete_images est envoyé mais aucun nouveau fichier n'est ajouté
            // C'est probablement une erreur - ne pas supprimer les images par sécurité
            \Log::warning('PROTECTION: delete_images envoyé sans nouveau fichier - aucune image ne sera supprimée', [
                'delete_images' => $request->delete_images,
                'product_id' => $product->id
            ]);
        } elseif ($hasDeleteImages && $hasNewImages) {
            // Nouveau fichier ajouté ET delete_images envoyé - c'est un remplacement, supprimer les anciennes images
            \Log::info('Suppression d\'images demandée (remplacement)', [
                'delete_images' => $request->delete_images,
                'product_id' => $product->id,
                'has_new_images' => true
            ]);
            foreach ($request->delete_images as $imageId) {
                if ($imageId) {
                    $media = $product->media()->find($imageId);
                    if ($media) {
                        $media->delete();
                        \Log::info('Image supprimée', ['media_id' => $imageId, 'product_id' => $product->id]);
                    }
                }
            }
        } else {
            \Log::info('Aucune suppression d\'image demandée', [
                'has_delete_images' => $hasDeleteImages,
                'has_new_images' => $hasNewImages,
                'product_id' => $product->id
            ]);
        }

        // Gérer l'upload de nouvelles images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $product->addMedia($image)
                    ->toMediaCollection('images', 'media');
                // Les conversions sont configurées avec ->nonQueued() donc elles sont générées automatiquement
            }
        }

        // Recharger les médias pour s'assurer qu'ils sont à jour
        // Utiliser unload et reload pour forcer le rechargement
        $product->unsetRelation('media');
        $product->refresh();
        $product->load('media');
        
        // Vérifier que les médias sont bien chargés (pour debug)
        // \Log::info('Médias après mise à jour:', ['count' => $product->getMedia('images')->count(), 'product_id' => $product->id]);

        // Vérifier si le produit est en stock faible
        if ($product->stock_quantity <= $product->min_stock_level) {
            NotificationService::notifyLowStock($product);
        }

        // Vérifier si le produit expire bientôt ou est expiré
        if ($product->expiration_date && ($product->isExpired() || $product->isExpiringSoon())) {
            NotificationService::notifyExpiringProduct($product);
        }

        // Si la requête vient d'Inertia, rediriger vers la page show
        if ($request->header('X-Inertia')) {
            return redirect()->route('products.show', $product)
                ->with('success', 'Produit mis à jour avec succès.');
        }

        return redirect()->route('products.index')
            ->with('success', 'Produit mis à jour avec succès.');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->checkPermission($request, 'products', 'delete');
        
        // Vérifier si le produit est utilisé dans des ventes
        $saleItemsCount = $product->saleItems()->count();
        
        if ($saleItemsCount > 0) {
            return back()->withErrors([
                'product' => "Impossible de supprimer ce produit car il est utilisé dans {$saleItemsCount} vente(s). Veuillez d'abord supprimer les ventes associées."
            ]);
        }

        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Produit supprimé avec succès.');
    }

    /**
     * Upload temporaire d'image (pour FilePond)
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
        ]);

        $path = $request->file('image')->store('temp', 'public');
        
        return response()->json([
            'path' => $path,
            'url' => asset('storage/' . $path),
        ]);
    }

    /**
     * Générer un SKU automatiquement
     */
    public function generateSku(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $sku = Product::generateSku($request->name, $request->category_id);

        if ($request->expectsJson()) {
            return response()->json(['sku' => $sku]);
        }

        return back()->with('sku', $sku);
    }
}
