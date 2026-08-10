<?php

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Notifications\NotificationAudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('admin receives stock alerts but seller does not', function () {
    Event::fake([\App\Events\NotificationSent::class]);

    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $seller = User::factory()->create(['role' => 'vendeur', 'is_active' => true]);
    $category = Category::create(['name' => 'Test', 'slug' => 'test']);

    $product = Product::create([
        'name' => 'Sucre',
        'sku' => 'SKU-001',
        'price' => 1000,
        'stock_quantity' => 0,
        'min_stock_level' => 5,
        'unit' => 'kg',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    app(NotificationService::class)->handleProductStockChange($product);

    expect(Notification::query()->forUser($admin->id)->active()->count())->toBeGreaterThan(0);
    expect(Notification::query()->forUser($seller->id)->active()->count())->toBe(0);
});

test('manager receives inventory alerts but not backup notifications audience', function () {
    $manager = User::factory()->create(['role' => 'gestionnaire', 'is_active' => true]);
    $resolver = app(NotificationAudienceResolver::class);

    expect($resolver->canReceiveInventoryAlerts($manager))->toBeTrue();
    expect($resolver->resolveUserIds(NotificationType::LowStock))->toContain($manager->id);
    expect($resolver->resolveUserIds(NotificationType::BackupFailed))->not->toContain($manager->id);
});

test('seller scoped notification targets only owning vendeur', function () {
    Event::fake([\App\Events\NotificationSent::class]);

    $seller = User::factory()->create(['role' => 'vendeur', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $sale = Sale::create([
        'sale_number' => 'VTE-001',
        'user_id' => $seller->id,
        'payment_method' => 'cash',
        'subtotal' => 1000,
        'total_amount' => 1000,
        'payment_status' => 'paid',
        'status' => 'completed',
    ]);

    app(NotificationService::class)->handleSaleCompleted($sale);

    expect(Notification::query()->forUser($seller->id)->byType(NotificationType::SaleCompleted)->active()->exists())->toBeTrue();
    expect(Notification::query()->forUser($admin->id)->byType(NotificationType::SaleCompleted)->active()->exists())->toBeFalse();
});

test('grouped low stock notification is not duplicated', function () {
    Event::fake([\App\Events\NotificationSent::class]);

    User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $category = Category::create(['name' => 'Test', 'slug' => 'test-2']);

    Product::create([
        'name' => 'Riz',
        'sku' => 'SKU-002',
        'price' => 500,
        'stock_quantity' => 1,
        'min_stock_level' => 5,
        'unit' => 'kg',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    Product::create([
        'name' => 'Huile',
        'sku' => 'SKU-003',
        'price' => 800,
        'stock_quantity' => 2,
        'min_stock_level' => 5,
        'unit' => 'L',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $service = app(NotificationService::class);
    $service->syncGroupedAlert(NotificationType::LowStock);
    $service->syncGroupedAlert(NotificationType::LowStock);

    $groupedCount = Notification::query()
        ->byType(NotificationType::LowStock)
        ->active()
        ->grouped()
        ->count();

    expect($groupedCount)->toBe(1);
});

test('stock replenishment resolves active low stock notification', function () {
    Event::fake([\App\Events\NotificationSent::class]);

    User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $category = Category::create(['name' => 'Test', 'slug' => 'test-3']);

    $product = Product::create([
        'name' => 'Café',
        'sku' => 'SKU-004',
        'price' => 1200,
        'stock_quantity' => 1,
        'min_stock_level' => 5,
        'unit' => 'kg',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $service = app(NotificationService::class);
    $service->handleProductStockChange($product);

    expect(Notification::query()->byType(NotificationType::LowStock)->active()->exists())->toBeTrue();

    $product->update(['stock_quantity' => 20]);
    $service->handleProductStockChange($product->fresh());

    expect(Notification::query()->byType(NotificationType::LowStock)->active()->exists())->toBeFalse();
    expect(Notification::query()->byType(NotificationType::LowStock)->resolved()->exists())->toBeTrue();
});

test('paid invoice resolves invoice due grouped notification', function () {
    Event::fake([\App\Events\NotificationSent::class]);

    User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $sale = Sale::create([
        'sale_number' => 'VTE-002',
        'payment_method' => 'cash',
        'subtotal' => 5000,
        'total_amount' => 5000,
        'remaining_amount' => 5000,
        'payment_status' => 'pending',
        'due_date' => now()->toDateString(),
        'status' => 'completed',
    ]);

    $service = app(NotificationService::class);
    $service->handleSaleInvoiceDue($sale);

    expect(Notification::query()->byType(NotificationType::InvoiceDue)->active()->exists())->toBeTrue();

    $sale->update(['payment_status' => 'paid', 'remaining_amount' => 0]);
    $service->handleSaleInvoiceDue($sale->fresh());

    expect(Notification::query()->byType(NotificationType::InvoiceDue)->active()->exists())->toBeFalse();
});

test('legacy dismissed notifications still use read_at only', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Notification::create([
        'user_id' => $user->id,
        'notification_type' => 'low_stock',
        'notification_id' => 10,
        'type' => NotificationType::LowStock->value,
        'priority' => 'warning',
        'status' => NotificationStatus::Active->value,
        'read_at' => null,
    ]);

    expect(app(\App\Repositories\NotificationRepository::class)->isLegacyDismissed($user->id, 'low_stock', 10))->toBeFalse();

    Notification::query()->where('user_id', $user->id)->update(['read_at' => now()]);

    expect(app(\App\Repositories\NotificationRepository::class)->isLegacyDismissed($user->id, 'low_stock', 10))->toBeTrue();
});
