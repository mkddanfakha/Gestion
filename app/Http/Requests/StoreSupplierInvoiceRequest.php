<?php

namespace App\Http\Requests;

use App\Models\DeliveryNote;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $supplierId = $this->input('supplier_id');

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('supplier_invoices', 'invoice_number')
                    ->where(fn ($query) => $query->where('supplier_id', $supplierId)),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'delivery_note_id' => ['nullable', 'integer', 'exists:delivery_notes,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.config('attachments.max_files', 10)],
            'attachments.*' => ['file'],
            'status' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $supplierId = (int) $this->input('supplier_id');
            $purchaseOrderId = $this->input('purchase_order_id');
            $deliveryNoteId = $this->input('delivery_note_id');

            if ($purchaseOrderId !== null && $purchaseOrderId !== '') {
                $po = PurchaseOrder::query()->find((int) $purchaseOrderId);

                if ($po !== null && (int) $po->supplier_id !== $supplierId) {
                    $validator->errors()->add(
                        'purchase_order_id',
                        'Le bon de commande ne correspond pas au fournisseur sélectionné.',
                    );
                }
            }

            if ($deliveryNoteId !== null && $deliveryNoteId !== '') {
                $deliveryNote = DeliveryNote::query()->find((int) $deliveryNoteId);

                if ($deliveryNote === null) {
                    return;
                }

                if ((int) $deliveryNote->supplier_id !== $supplierId) {
                    $validator->errors()->add(
                        'delivery_note_id',
                        'Le bon de livraison ne correspond pas au fournisseur sélectionné.',
                    );
                }

                if ($purchaseOrderId !== null && $purchaseOrderId !== ''
                    && $deliveryNote->purchase_order_id !== null
                    && (int) $deliveryNote->purchase_order_id !== (int) $purchaseOrderId) {
                    $validator->errors()->add(
                        'delivery_note_id',
                        'Le bon de livraison ne correspond pas au bon de commande sélectionné.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedForCreate(): array
    {
        $validated = $this->validated();

        return [
            'supplier_id' => (int) $validated['supplier_id'],
            'invoice_number' => $validated['invoice_number'],
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'] ?? null,
            'total_amount' => round((float) $validated['total_amount'], 2),
            'purchase_order_id' => isset($validated['purchase_order_id'])
                ? (int) $validated['purchase_order_id']
                : null,
            'delivery_note_id' => isset($validated['delivery_note_id'])
                ? (int) $validated['delivery_note_id']
                : null,
            'notes' => $validated['notes'] ?? null,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ];
    }
}
