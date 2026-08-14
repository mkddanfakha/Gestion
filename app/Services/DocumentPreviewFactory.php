<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

class DocumentPreviewFactory
{
    public function buildSale(array $validated, ?Sale $existing = null): Sale
    {
        $subtotal = collect($validated['items'])->sum(
            fn (array $item) => $item['quantity'] * $item['unit_price']
        );

        $taxAmount = (float) ($validated['tax_amount'] ?? 0);
        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $downPaymentAmount = (float) ($validated['down_payment_amount'] ?? 0);
        $totalAmount = $subtotal + $taxAmount - $discountAmount;

        $paymentState = SalePaymentService::calculatePaymentState($totalAmount, $downPaymentAmount);
        $paymentMethod = SalePaymentService::resolvePaymentMethod(
            $validated['payment_method'] ?? null,
            $paymentState['down_payment_amount'],
        );

        $sale = new Sale();
        $sale->forceFill([
            'sale_number' => $existing?->sale_number ?? 'APERÇU',
            'customer_id' => $validated['customer_id'] ?? null,
            'user_id' => $existing?->user_id ?? auth()->id(),
            'payment_method' => $paymentMethod,
            'notes' => $validated['notes'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'down_payment_amount' => $paymentState['down_payment_amount'],
            'remaining_amount' => $paymentState['remaining_amount'],
            'payment_status' => $paymentState['payment_status'],
            'total_amount' => $totalAmount,
            'status' => $existing?->status ?? 'completed',
            'created_at' => $existing?->created_at ?? now(),
        ]);
        $sale->exists = (bool) $existing;

        $sale->setRelation('saleItems', $this->buildSaleItems($validated['items']));
        $sale->setRelation('customer', $this->resolveCustomer($validated['customer_id'] ?? null));
        $sale->setRelation('user', $this->resolveUser($existing?->user_id));

        return $sale;
    }

    public function buildQuote(array $validated, ?Quote $existing = null): Quote
    {
        $subtotal = collect($validated['items'])->sum(
            fn (array $item) => $item['quantity'] * $item['unit_price']
        );

        $taxAmount = (float) ($validated['tax_amount'] ?? 0);
        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $totalAmount = $subtotal + $taxAmount - $discountAmount;

        $quote = new Quote();
        $quote->forceFill([
            'quote_number' => $existing?->quote_number ?? 'APERÇU',
            'customer_id' => $validated['customer_id'] ?? null,
            'user_id' => $existing?->user_id ?? auth()->id(),
            'notes' => $validated['notes'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'status' => $validated['status'] ?? $existing?->status ?? 'draft',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'created_at' => $existing?->created_at ?? now(),
        ]);
        $quote->exists = (bool) $existing;

        $quote->setRelation('quoteItems', $this->buildQuoteItems($validated['items']));
        $quote->setRelation('customer', $this->resolveCustomer($validated['customer_id'] ?? null));
        $quote->setRelation('user', $this->resolveUser($existing?->user_id));

        return $quote;
    }

    public function buildPurchaseOrder(array $validated, ?PurchaseOrder $existing = null): PurchaseOrder
    {
        $subtotal = (float) ($validated['subtotal'] ?? collect($validated['items'])->sum(
            fn (array $item) => $item['quantity'] * $item['unit_price']
        ));
        $taxAmount = (float) ($validated['tax_amount'] ?? 0);
        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $totalAmount = (float) ($validated['total_amount'] ?? ($subtotal + $taxAmount - $discountAmount));

        $purchaseOrder = new PurchaseOrder();
        $purchaseOrder->forceFill([
            'po_number' => $existing?->po_number ?? 'APERÇU',
            'supplier_id' => $validated['supplier_id'],
            'user_id' => $existing?->user_id ?? auth()->id(),
            'order_date' => $validated['order_date'],
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
            'status' => $validated['status'],
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'notes' => $validated['notes'] ?? null,
            'created_at' => $existing?->created_at ?? now(),
        ]);
        $purchaseOrder->exists = (bool) $existing;

        $purchaseOrder->setRelation('items', $this->buildPurchaseOrderItems($validated['items']));
        $purchaseOrder->setRelation('supplier', Supplier::find($validated['supplier_id']));
        $purchaseOrder->setRelation('user', $this->resolveUser($existing?->user_id));

        return $purchaseOrder;
    }

    public function buildDeliveryNote(array $validated, ?DeliveryNote $existing = null): DeliveryNote
    {
        $subtotal = (float) ($validated['subtotal'] ?? collect($validated['items'])->sum(
            fn (array $item) => $item['quantity'] * $item['unit_price']
        ));
        $taxAmount = (float) ($validated['tax_amount'] ?? 0);
        $discountAmount = (float) ($validated['discount_amount'] ?? 0);
        $totalAmount = (float) ($validated['total_amount'] ?? ($subtotal + $taxAmount - $discountAmount));

        $deliveryNote = new DeliveryNote();
        $deliveryNote->forceFill([
            'delivery_number' => $existing?->delivery_number ?? 'APERÇU',
            'purchase_order_id' => $validated['purchase_order_id'],
            'supplier_id' => $validated['supplier_id'],
            'user_id' => $existing?->user_id ?? auth()->id(),
            'delivery_date' => $validated['delivery_date'],
            'status' => $validated['status'],
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'notes' => $validated['notes'] ?? null,
            'invoice_number' => $validated['invoice_number'] ?? null,
            'created_at' => $existing?->created_at ?? now(),
        ]);
        $deliveryNote->exists = (bool) $existing;

        $deliveryNote->setRelation('items', $this->buildDeliveryNoteItems($validated['items']));
        $deliveryNote->setRelation('supplier', Supplier::find($validated['supplier_id']));
        $deliveryNote->setRelation('purchaseOrder', PurchaseOrder::find($validated['purchase_order_id']));
        $deliveryNote->setRelation('user', $this->resolveUser($existing?->user_id));

        return $deliveryNote;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, SaleItem>
     */
    private function buildSaleItems(array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index) {
            $saleItem = new SaleItem();
            $saleItem->forceFill([
                'id' => $index + 1,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
            ]);
            $saleItem->setRelation('product', Product::find($item['product_id']));

            return $saleItem;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, QuoteItem>
     */
    private function buildQuoteItems(array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index) {
            $quoteItem = new QuoteItem();
            $quoteItem->forceFill([
                'id' => $index + 1,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
            ]);
            $quoteItem->setRelation('product', Product::find($item['product_id']));

            return $quoteItem;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, PurchaseOrderItem>
     */
    private function buildPurchaseOrderItems(array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index) {
            $poItem = new PurchaseOrderItem();
            $poItem->forceFill([
                'id' => $index + 1,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'] ?? ($item['quantity'] * $item['unit_price']),
            ]);
            $poItem->setRelation('product', Product::find($item['product_id']));

            return $poItem;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, DeliveryNoteItem>
     */
    private function buildDeliveryNoteItems(array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index) {
            $dnItem = new DeliveryNoteItem();
            $dnItem->forceFill([
                'id' => $index + 1,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'] ?? ($item['quantity'] * $item['unit_price']),
            ]);
            $dnItem->setRelation('product', Product::find($item['product_id']));

            return $dnItem;
        });
    }

    private function resolveCustomer(?int $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }

        return Customer::find($customerId);
    }

    private function resolveUser(?int $userId): ?User
    {
        if ($userId) {
            return User::find($userId);
        }

        return auth()->user();
    }
}
