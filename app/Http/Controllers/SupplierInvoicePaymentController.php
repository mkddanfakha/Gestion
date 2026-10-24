<?php

namespace App\Http\Controllers;

use App\Enums\PayableType;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\PaymentTransaction;
use App\Models\SupplierInvoice;
use App\Services\ActivityLogger;
use App\Services\SupplierPaymentTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierInvoicePaymentController extends Controller
{
    public function __construct(
        protected SupplierPaymentTransactionService $paymentTransactionService,
    ) {}

    public function index(Request $request, SupplierInvoice $supplierInvoice): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'supplier-invoices.payments', 'view');
        $this->checkPermission($request, 'supplier-invoices', 'view');

        $controller = app(SupplierInvoiceController::class);
        $payload = $controller->paymentUiPayload($request, $supplierInvoice);

        if ($request->expectsJson()) {
            return response()->json([
                'supplier_invoice_id' => $supplierInvoice->id,
                'payment_transactions' => $payload['payment_transactions'],
            ]);
        }

        return redirect()
            ->route('supplier-invoices.show', $supplierInvoice)
            ->with('info', 'Historique des règlements disponible via l\'API JSON.');
    }

    public function store(Request $request, SupplierInvoice $supplierInvoice): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'supplier-invoices.payments', 'record');
        $this->checkPermission($request, 'supplier-invoices', 'view');

        if ($request->input('payment_method') === '') {
            $request->merge(['payment_method' => null]);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => [
                'required',
                'string',
                Rule::in(PaymentTransaction::PAYMENT_METHODS),
            ],
            'paid_at' => 'nullable|date',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|string|max:128',
            'status' => 'prohibited',
            'void_reason' => 'prohibited',
            'voided_at' => 'prohibited',
            'voided_by_user_id' => 'prohibited',
        ]);

        try {
            $transaction = $this->paymentTransactionService->recordForSupplierInvoice(
                (int) $supplierInvoice->id,
                $request->user(),
                [
                    'amount' => $validated['amount'],
                    'payment_method' => $validated['payment_method'],
                    'paid_at' => $validated['paid_at'] ?? null,
                    'reference' => $validated['reference'] ?? null,
                    'note' => $validated['note'] ?? null,
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                ],
            );
        } catch (InvalidPaymentTransactionException $exception) {
            return $this->paymentFailureResponse($request, $supplierInvoice, $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['payment_transaction' => $transaction], 201);
        }

        return redirect()
            ->route('supplier-invoices.show', $supplierInvoice)
            ->with('success', 'Règlement enregistré avec succès.');
    }

    public function void(Request $request, PaymentTransaction $payment): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'supplier-invoices.payments', 'void');

        if ($payment->payable_type !== PayableType::SupplierInvoice) {
            abort(404);
        }

        /** @var SupplierInvoice|null $invoice */
        $invoice = SupplierInvoice::query()->find($payment->payable_id);

        if ($invoice === null) {
            abort(404);
        }

        $this->checkPermission($request, 'supplier-invoices', 'view');

        $validated = $request->validate([
            'void_reason' => 'required|string|min:3|max:1000',
        ], [
            'void_reason.required' => 'Le motif d\'annulation est obligatoire.',
            'void_reason.min' => 'Le motif d\'annulation doit contenir au moins 3 caractères.',
        ]);

        try {
            $transaction = $this->paymentTransactionService->voidTransaction(
                $payment,
                $request->user(),
                $validated['void_reason'],
            );
        } catch (InvalidPaymentTransactionException $exception) {
            return $this->voidFailureResponse($request, $invoice, $exception->getMessage());
        }

        $invoice->refresh();
        ActivityLogger::logSupplierPaymentVoid($invoice, $transaction);

        if ($request->expectsJson()) {
            return response()->json(['payment_transaction' => $transaction]);
        }

        return redirect()
            ->route('supplier-invoices.show', $invoice)
            ->with('success', 'Règlement annulé avec succès.');
    }

    private function paymentFailureResponse(
        Request $request,
        SupplierInvoice $supplierInvoice,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => ['amount' => [$message]]], 422);
        }

        return back()->withErrors(['amount' => $message])->withInput();
    }

    private function voidFailureResponse(
        Request $request,
        SupplierInvoice $supplierInvoice,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => ['void_reason' => [$message]]], 422);
        }

        return back()->withErrors(['void_reason' => $message])->withInput();
    }
}
