<?php

namespace App\Http\Controllers;

use App\Enums\PayableType;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\PaymentTransaction;
use App\Models\Sale;
use App\Services\ActivityLogger;
use App\Services\SalePaymentTransactionService;
use Illuminate\Http\Request;

class SalePaymentController extends Controller
{
    public function __construct(
        protected SalePaymentTransactionService $paymentTransactionService,
    ) {}

    /**
     * Empêcher un vendeur d'encaisser sur les ventes des autres utilisateurs.
     */
    protected function authorizeSaleAccess(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user?->isVendeur() && $sale->user_id !== $user->id) {
            abort(403, 'Accès refusé. Vous ne pouvez encaisser que vos propres ventes.');
        }
    }

    public function store(Request $request, Sale $sale)
    {
        $this->checkPermission($request, 'sales', 'payments.record');
        $this->checkPermission($request, 'sales', 'view');
        $this->authorizeSaleAccess($request, $sale);

        if ($request->input('payment_method') === '') {
            $request->merge(['payment_method' => null]);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,check,orange_money,wave',
            'paid_at' => 'nullable|date',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        try {
            $transaction = $this->paymentTransactionService->recordForSale(
                (int) $sale->id,
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
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        $sale->refresh();

        if ((float) $sale->down_payment_amount > 0) {
            ActivityLogger::logPayment($sale);
        }

        return redirect()
            ->route('sales.show', $sale->id)
            ->with('success', 'Règlement enregistré avec succès.');
    }

    public function void(Request $request, PaymentTransaction $payment)
    {
        $this->checkPermission($request, 'sales.payments', 'void');

        if ($payment->payable_type !== PayableType::Sale) {
            abort(404);
        }

        /** @var Sale|null $sale */
        $sale = Sale::query()->find($payment->payable_id);

        if ($sale === null) {
            abort(404);
        }

        $this->checkPermission($request, 'sales', 'view');
        $this->authorizeSaleAccess($request, $sale);

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
            return back()->withErrors(['void_reason' => $exception->getMessage()])->withInput();
        }

        $sale->refresh();
        ActivityLogger::logPaymentVoid($sale, $transaction);

        return redirect()
            ->route('sales.show', $sale->id)
            ->with('success', 'Règlement annulé avec succès.');
    }
}
