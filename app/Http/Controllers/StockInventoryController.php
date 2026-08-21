<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ActivityLogger;
use App\Services\ProductBarcodeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockInventoryController extends Controller
{
    public function index(Request $request)
    {
        $this->checkPermission($request, 'products', 'view');

        $user = $request->user();
        $canAdjustStock = $user
            && ! $user->hasRole('vendeur')
            && $user->hasPermission('products', 'update');

        return Inertia::render('StockInventory/Index', [
            'canAdjustStock' => $canAdjustStock,
        ]);
    }

    public function count(Request $request, ProductBarcodeService $barcodeService)
    {
        $this->checkPermission($request, 'products', 'update');

        $user = $request->user();

        if ($user && $user->hasRole('vendeur')) {
            abort(403, 'Les vendeurs ne peuvent pas ajuster le stock.');
        }

        $validated = $request->validate([
            'barcode' => 'required|string|max:255',
            'counted_quantity' => 'required|integer|min:0',
        ]);

        $normalized = $barcodeService->normalize($validated['barcode']);
        $product = $barcodeService->findByBarcode($normalized);

        if (! $product) {
            return response()->json([
                'message' => 'Produit introuvable pour ce code-barres.',
                'barcode' => $normalized,
            ], 404);
        }

        $previousStock = (int) $product->stock_quantity;
        $countedQuantity = (int) $validated['counted_quantity'];

        $product->update([
            'stock_quantity' => $countedQuantity,
        ]);

        ActivityLogger::logUpdate(
            'Inventaire',
            $product,
            sprintf(
                'Stock ajusté via inventaire (%s → %s, barcode %s)',
                $previousStock,
                $countedQuantity,
                $normalized,
            ),
        );

        return response()->json([
            'product' => $barcodeService->formatProductPayload($product->fresh(['category'])),
            'previous_stock' => $previousStock,
            'counted_quantity' => $countedQuantity,
            'delta' => $countedQuantity - $previousStock,
        ]);
    }
}
