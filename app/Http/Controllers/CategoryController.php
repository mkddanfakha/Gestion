<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'categories', 'view');
        
        $categories = Category::withCount('products')->orderBy('name')->get();
        
        return Inertia::render('Categories/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'categories', 'create');
        
        return Inertia::render('Categories/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'categories', 'create');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string|max:7',
        ]);

        Category::create($validated);

        return redirect()->route('categories.index')
            ->with('success', 'Catégorie créée avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Category $category)
    {
        $this->checkPermission($request, 'categories', 'view');
        
        $category->loadCount('products');
        $category->load(['products' => function($query) {
            $query->orderBy('name')->with('media');
        }]);
        
        // Ajouter l'URL de la première image pour chaque produit
        $category->products->transform(function ($product) {
            $firstImage = $product->getFirstMedia('images');
            $product->image_url = $firstImage ? $firstImage->getUrl('thumb') : null;
            return $product;
        });
        
        return Inertia::render('Categories/Show', [
            'category' => $category,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Category $category)
    {
        $this->checkPermission($request, 'categories', 'edit');
        
        return Inertia::render('Categories/Edit', [
            'category' => $category,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $this->checkPermission($request, 'categories', 'update');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string|max:7',
        ]);

        $category->update($validated);

        return redirect()->route('categories.index')
            ->with('success', 'Catégorie mise à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Category $category)
    {
        $this->checkPermission($request, 'categories', 'delete');
        
        // Vérifier si la catégorie est utilisée dans des produits
        $productsCount = $category->products()->count();
        
        if ($productsCount > 0) {
            return back()->withErrors([
                'category' => "Impossible de supprimer cette catégorie car elle est utilisée par {$productsCount} produit(s). Veuillez d'abord déplacer ou supprimer les produits associés."
            ]);
        }

        $category->delete();

        return redirect()->route('categories.index')
            ->with('success', 'Catégorie supprimée avec succès.');
    }
}
