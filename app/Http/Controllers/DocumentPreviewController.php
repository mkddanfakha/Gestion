<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Sale;
use App\Services\DocumentPreviewFactory;
use App\Traits\GeneratesPdf;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document Access — aperçu des PDF générés par MKD-Pro (devis, BC, BL, factures).
 *
 * Autorisation, génération inline (preview) ou cache token, headers HTTP.
 * Fichiers uploadés : AttachmentController. Voir docs/document-manager.md.
 */
class DocumentPreviewController extends Controller
{
    use GeneratesPdf;

    public function __construct(
        private DocumentPreviewFactory $previewFactory,
    ) {}

    public function saleInvoice(Request $request)
    {
        $this->checkAnyPermission($request, 'sales', ['invoice', 'create', 'update']);

        $this->normalizePreviewRequest($request, [
            'customer_id',
            'payment_method',
            'notes',
            'due_date',
        ]);

        $validated = $request->validate([
            'sale_id' => 'nullable|exists:sales,id',
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

        $existing = null;
        if (! empty($validated['sale_id'])) {
            $existing = Sale::findOrFail($validated['sale_id']);
            $this->authorizeSaleAccess($request, $existing);
        }

        return $this->renderPreview(
            $request,
            'invoices.sale',
            ['sale' => $this->previewFactory->buildSale($validated, $existing)],
            $existing ? 'Facture_' . $existing->sale_number . '.pdf' : 'Apercu_Facture.pdf',
        );
    }

    public function quote(Request $request)
    {
        $this->checkAnyPermission($request, 'quotes', ['print', 'create', 'update']);

        $this->normalizePreviewRequest($request, [
            'customer_id',
            'notes',
            'valid_until',
        ]);

        $validated = $request->validate([
            'quote_id' => 'nullable|exists:quotes,id',
            'customer_id' => 'nullable|exists:customers,id',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'valid_until' => 'nullable|date',
            'status' => 'nullable|string|in:draft,sent,accepted,rejected,expired',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $existing = null;
        if (! empty($validated['quote_id'])) {
            $existing = Quote::findOrFail($validated['quote_id']);
        }

        return $this->renderPreview(
            $request,
            'quotes.quote',
            ['quote' => $this->previewFactory->buildQuote($validated, $existing)],
            $existing ? 'Devis_' . $existing->quote_number . '.pdf' : 'Apercu_Devis.pdf',
        );
    }

    public function purchaseOrder(Request $request)
    {
        $this->checkAnyPermission($request, 'purchase-orders', ['print', 'create', 'update']);

        $this->normalizePreviewRequest($request, [
            'expected_delivery_date',
            'notes',
        ]);

        $validated = $request->validate([
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
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

        $existing = null;
        if (! empty($validated['purchase_order_id'])) {
            $existing = PurchaseOrder::findOrFail($validated['purchase_order_id']);
        }

        return $this->renderPreview(
            $request,
            'purchase-orders.purchase-order',
            ['purchaseOrder' => $this->previewFactory->buildPurchaseOrder($validated, $existing)],
            $existing ? 'BC_' . $existing->po_number . '.pdf' : 'Apercu_BC.pdf',
        );
    }

    public function deliveryNote(Request $request)
    {
        $this->checkAnyPermission($request, 'delivery-notes', ['print', 'create', 'update']);

        $this->normalizePreviewRequest($request, [
            'notes',
            'invoice_number',
        ]);

        $validated = $request->validate([
            'delivery_note_id' => 'nullable|exists:delivery_notes,id',
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

        $existing = null;
        if (! empty($validated['delivery_note_id'])) {
            $existing = DeliveryNote::findOrFail($validated['delivery_note_id']);
        }

        return $this->renderPreview(
            $request,
            'delivery-notes.delivery-note',
            ['deliveryNote' => $this->previewFactory->buildDeliveryNote($validated, $existing)],
            $existing ? 'BL_' . $existing->delivery_number . '.pdf' : 'Apercu_BL.pdf',
        );
    }

    public function showCached(Request $request, string $token)
    {
        $userId = $request->user()?->id;

        if (! $userId) {
            abort(401, 'Vous devez être connecté.');
        }

        $cached = Cache::get("document_preview:{$userId}:{$token}");

        if (! is_array($cached) || ! isset($cached['path'], $cached['filename'])) {
            abort(404, 'Aperçu expiré ou introuvable.');
        }

        if (! Storage::disk('local')->exists($cached['path'])) {
            Cache::forget("document_preview:{$userId}:{$token}");

            abort(404, 'Aperçu expiré ou introuvable.');
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return Storage::disk('local')->response(
            $cached['path'],
            $cached['filename'],
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition . '; filename="' . addslashes($cached['filename']) . '"',
                'Cache-Control' => 'private, max-age=0, must-revalidate',
                'Pragma' => 'public',
                'Accept-Ranges' => 'bytes',
            ],
            $disposition,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderPreview(Request $request, string $view, array $data, string $filename)
    {
        try {
            $pdf = $this->generatePdfFromView($view, $data);

            if ($request->header('X-Document-Preview-Mode') === 'inline-url') {
                return $this->storePreviewForInlineUrl($request, $pdf, $filename);
            }

            return $this->pdfInlineResponse($pdf, $filename);
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la génération de l\'aperçu PDF: ' . $e->getMessage(), [
                'view' => $view,
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Impossible de générer l\'aperçu.',
                ], 500);
            }

            abort(500, 'Impossible de générer l\'aperçu.');
        }
    }

    /**
     * @param  list<string>  $actions
     */
    protected function checkAnyPermission(Request $request, string $resource, array $actions): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Vous devez être connecté.');
        }

        $user->refresh();

        foreach ($actions as $action) {
            if ($user->hasPermission($resource, $action)) {
                return;
            }
        }

        abort(403, "Accès refusé. Vous n'avez pas la permission d'afficher l'aperçu de ce document.");
    }

    protected function authorizeSaleAccess(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user?->isVendeur() && $sale->user_id !== $user->id) {
            abort(403, 'Accès refusé. Vous ne pouvez accéder qu\'à vos propres ventes.');
        }
    }

    private function storePreviewForInlineUrl(Request $request, Dompdf $dompdf, string $filename)
    {
        $token = (string) Str::uuid();
        $userId = $request->user()->id;
        $relativePath = "document-previews/{$userId}/{$token}.pdf";

        Storage::disk('local')->put($relativePath, $dompdf->output());

        Cache::put(
            "document_preview:{$userId}:{$token}",
            [
                'path' => $relativePath,
                'filename' => $filename,
            ],
            now()->addMinutes(10),
        );

        return response()->json([
            'preview_url' => route('documents.preview.show', ['token' => $token]),
            'filename' => $filename,
        ]);
    }

    /**
     * @param  list<string>  $fields
     */
    protected function normalizePreviewRequest(Request $request, array $fields = []): void
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($request->input($field) === '') {
                $normalized[$field] = null;
            }
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }
}
