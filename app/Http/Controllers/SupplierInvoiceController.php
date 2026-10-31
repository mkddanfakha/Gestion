<?php

namespace App\Http\Controllers;

use App\Auth\AuthorizationService;
use App\Enums\PayableType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PermissionName;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Http\Requests\StoreSupplierInvoiceRequest;
use App\Models\PaymentTransaction;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\AttachmentService;
use App\Services\SupplierPaymentTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        protected AttachmentService $attachmentService,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $this->checkPermission($request, 'supplier-invoices', 'view');

        $query = SupplierInvoice::query()->with(['supplier:id,name', 'user:id,name']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $invoices = $query
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        if ($request->expectsJson()) {
            return response()->json(['supplier_invoices' => $invoices]);
        }

        return Inertia::render('SupplierInvoices/Index', [
            'supplierInvoices' => $invoices,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->checkPermission($request, 'supplier-invoices', 'create');

        return Inertia::render('SupplierInvoices/Create', [
            'suppliers' => Supplier::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'mobile']),
            'attachmentConfig' => $this->attachmentConfig(),
        ]);
    }

    public function show(Request $request, SupplierInvoice $supplierInvoice): JsonResponse|Response
    {
        $this->checkPermission($request, 'supplier-invoices', 'view');

        $supplierInvoice->load([
            'supplier:id,name',
            'purchaseOrder:id,po_number',
            'deliveryNote:id,delivery_number',
            'user:id,name',
            'attachments.uploadedBy',
        ]);

        $paymentPayload = $this->paymentUiPayload($request, $supplierInvoice);

        $payload = [
            'supplier_invoice' => $supplierInvoice,
            ...$paymentPayload,
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        $authorization = app(AuthorizationService::class);
        $user = $request->user();

        $canManageAttachments = $user !== null
            && $user->hasPermission('supplier-invoices', 'create')
            && $supplierInvoice->status === SupplierInvoice::STATUS_DRAFT;

        return Inertia::render('SupplierInvoices/Show', [
            'supplierInvoice' => $supplierInvoice,
            'paymentSummary' => $paymentPayload['payment_summary'],
            'canOpenInvoice' => $authorization->allows($user, PermissionName::SupplierInvoicesOpen)
                && $supplierInvoice->status === SupplierInvoice::STATUS_DRAFT,
            'canRecordPayment' => $paymentPayload['can_record_payment'],
            'canViewPaymentHistory' => $paymentPayload['can_view_payment_history'],
            'canVoidPayment' => $paymentPayload['can_void_payment'],
            'paymentTransactions' => $paymentPayload['can_view_payment_history']
                ? $paymentPayload['payment_transactions']
                : [],
            'paymentLedgerBlockedMessage' => $paymentPayload['payment_ledger_blocked_message'],
            'canManageAttachments' => $canManageAttachments,
        ]);
    }

    public function store(StoreSupplierInvoiceRequest $request): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'supplier-invoices', 'create');

        $invoice = SupplierInvoice::query()->create([
            ...$request->validatedForCreate(),
            'user_id' => $request->user()->id,
        ]);

        try {
            if ($request->hasFile('attachments')) {
                $this->attachmentService->addMany(
                    $invoice,
                    $request->file('attachments'),
                    $request->user(),
                );
            }
        } catch (InvalidArgumentException $e) {
            $this->discardDraftSupplierInvoiceAfterAttachmentFailure($invoice);

            if ($request->expectsJson()) {
                return response()->json(['errors' => ['attachments' => [$e->getMessage()]]], 422);
            }

            return back()->withErrors(['attachments' => $e->getMessage()])->withInput();
        } catch (Throwable $e) {
            $this->discardDraftSupplierInvoiceAfterAttachmentFailure($invoice);

            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'supplier_invoice' => $invoice->fresh(['supplier:id,name', 'attachments.uploadedBy']),
            ], 201);
        }

        return redirect()
            ->route('supplier-invoices.show', $invoice)
            ->with('success', 'Facture fournisseur enregistrée en brouillon.');
    }

    public function open(Request $request, SupplierInvoice $supplierInvoice): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'supplier-invoices', 'open');
        $this->checkPermission($request, 'supplier-invoices', 'view');

        if ($supplierInvoice->status !== SupplierInvoice::STATUS_DRAFT) {
            return $this->openFailureResponse(
                $request,
                $supplierInvoice,
                'Seule une facture en brouillon peut être ouverte.',
            );
        }

        if (round((float) $supplierInvoice->total_amount, 2) <= 0) {
            return $this->openFailureResponse(
                $request,
                $supplierInvoice,
                'Le montant total doit être strictement positif pour ouvrir la facture.',
            );
        }

        $supplierInvoice->update(['status' => SupplierInvoice::STATUS_OPEN]);

        if ($request->expectsJson()) {
            return response()->json([
                'supplier_invoice' => $supplierInvoice->fresh(),
            ]);
        }

        return redirect()
            ->route('supplier-invoices.show', $supplierInvoice)
            ->with('success', 'Facture fournisseur ouverte.');
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentUiPayload(Request $request, SupplierInvoice $invoice): array
    {
        $user = $request->user();
        $authorization = app(AuthorizationService::class);

        $summary = [
            'total_amount' => round((float) $invoice->total_amount, 2),
            'total_paid' => round((float) $invoice->paid_amount, 2),
            'remaining_balance' => round((float) $invoice->remaining_amount, 2),
            'payment_status' => $invoice->payment_status,
            'document_status' => $invoice->status,
        ];

        $canViewHistory = $authorization->allows($user, PermissionName::SupplierInvoicesPaymentsView);
        $remainingBalance = round((float) $invoice->remaining_amount, 2);

        $canRecord = $authorization->allows($user, PermissionName::SupplierInvoicesPaymentsRecord)
            && $invoice->status === SupplierInvoice::STATUS_OPEN
            && $remainingBalance > SupplierPaymentTransactionService::MONEY_EPSILON;
        $canVoid = $authorization->allows($user, PermissionName::SupplierInvoicesPaymentsVoid);

        $ledgerBlocked = null;

        try {
            app(SupplierPaymentTransactionService::class)->assertPaymentLedgerAligned($invoice);
        } catch (InvalidPaymentTransactionException $exception) {
            $ledgerBlocked = $exception->getMessage();
        }

        $transactions = [];

        if ($canViewHistory) {
            $methodLabels = [
                'cash' => 'Espèces',
                'card' => 'Carte',
                'bank_transfer' => 'Virement bancaire',
                'check' => 'Chèque',
                'orange_money' => 'Orange Money',
                'wave' => 'Wave',
            ];

            $transactions = PaymentTransaction::query()
                ->where('payable_type', PayableType::SupplierInvoice)
                ->where('payable_id', $invoice->id)
                ->whereIn('status', [
                    PaymentTransactionStatus::Posted,
                    PaymentTransactionStatus::Voided,
                ])
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->get()
                ->map(static function (PaymentTransaction $transaction) use ($methodLabels) {
                    $origin = $transaction->origin?->value ?? 'recorded';
                    $status = $transaction->status?->value ?? PaymentTransactionStatus::Posted->value;

                    return [
                        'id' => $transaction->id,
                        'amount' => round((float) $transaction->amount, 2),
                        'paid_at' => $transaction->paid_at?->toIso8601String(),
                        'payment_method' => $transaction->payment_method,
                        'payment_method_label' => $methodLabels[$transaction->payment_method] ?? $transaction->payment_method,
                        'reference' => $transaction->reference,
                        'origin' => $origin,
                        'origin_label' => $origin === 'historical_backfill' ? 'Reprise historique' : 'Règlement',
                        'status' => $status,
                        'void_reason' => $transaction->void_reason,
                        'voided_at' => $transaction->voided_at?->toIso8601String(),
                    ];
                })
                ->all();
        }

        return [
            'payment_summary' => $summary,
            'can_record_payment' => $canRecord && $ledgerBlocked === null,
            'can_view_payment_history' => $canViewHistory,
            'can_void_payment' => $canVoid && $ledgerBlocked === null,
            'payment_ledger_blocked_message' => $ledgerBlocked,
            'payment_transactions' => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attachmentConfig(): array
    {
        return [
            'maxFiles' => config('attachments.max_files', 10),
            'maxSizeKb' => config('attachments.max_size', 10240),
            'allowedExtensions' => config('attachments.allowed_extensions', []),
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ];
    }

    /**
     * Annule une facture brouillon créée lors d'un store dont le traitement des PJ a échoué.
     * La suppression du modèle déclenche HasAttachments::deleteFor pour les PJ restantes.
     */
    private function discardDraftSupplierInvoiceAfterAttachmentFailure(SupplierInvoice $invoice): void
    {
        if (! $invoice->exists) {
            return;
        }

        try {
            $invoice->delete();
        } catch (Throwable $deleteException) {
            Log::error('Échec de la suppression de la facture fournisseur après erreur de pièces jointes.', [
                'supplier_invoice_id' => $invoice->getKey(),
                'exception' => $deleteException,
            ]);

            throw $deleteException;
        }
    }

    private function openFailureResponse(
        Request $request,
        SupplierInvoice $supplierInvoice,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()
            ->route('supplier-invoices.show', $supplierInvoice)
            ->withErrors(['status' => $message]);
    }
}
