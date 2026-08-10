<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\NotificationService;

class ProductObserver
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function created(Product $product): void
    {
        $this->notificationService->handleProductStockChange($product);
        $this->notificationService->handleProductExpirationChange($product);
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged(['stock_quantity', 'min_stock_level', 'is_active'])) {
            $this->notificationService->handleProductStockChange($product);
        }

        if ($product->wasChanged(['expiration_date', 'alert_threshold_value', 'alert_threshold_unit', 'is_active'])) {
            $this->notificationService->handleProductExpirationChange($product);
        }
    }

    public function deleted(Product $product): void
    {
        $this->notificationService->resolveProductNotifications($product);
    }
}
