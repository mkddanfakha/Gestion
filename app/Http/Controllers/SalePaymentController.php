<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidPaymentTransactionException;
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
}
