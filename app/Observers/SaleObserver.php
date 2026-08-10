<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\NotificationService;

class SaleObserver
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function created(Sale $sale): void
    {
        $this->notificationService->handleSaleInvoiceDue($sale);
        $this->notificationService->handleSaleCompleted($sale);
    }

    public function updated(Sale $sale): void
    {
        if ($sale->wasChanged(['due_date', 'payment_status', 'remaining_amount', 'total_amount'])) {
            $this->notificationService->handleSaleInvoiceDue($sale);
        }
    }

    public function deleted(Sale $sale): void
    {
        $this->notificationService->handleSaleCancelled($sale);
    }
}
