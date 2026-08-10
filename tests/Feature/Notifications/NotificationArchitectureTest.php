<?php

use App\Enums\NotificationAudience;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('notification enums expose configured values', function () {
    expect(NotificationType::LowStock->value)->toBe('low_stock');
    expect(NotificationType::InvoiceDue->value)->toBe('invoice_due');
    expect(NotificationPriority::Critical->value)->toBe('critical');
    expect(NotificationStatus::Active->value)->toBe('active');
    expect(NotificationAudience::Manager->userRole())->toBe('gestionnaire');
});

test('notification type maps legacy strings from config', function () {
    expect(NotificationType::fromLegacy('sale_due_today'))->toBe(NotificationType::InvoiceDue);
    expect(NotificationType::fromLegacy('low_stock'))->toBe(NotificationType::LowStock);
    expect(NotificationType::fromLegacy('expiring_product'))->toBe(NotificationType::ProductExpiring);
    expect(NotificationType::InvoiceDue->toLegacyType())->toBe('sale_due_today');
});

test('invoice due notifications are classified as info', function () {
    expect(NotificationType::InvoiceDue->defaultPriority())->toBe(NotificationPriority::Info);
    expect(config('notification-center.types.invoice_due.priority'))->toBe('info');
});

test('notification model helpers work with new columns', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $notification = Notification::create([
        'user_id' => $user->id,
        'notification_type' => 'low_stock',
        'notification_id' => 42,
        'type' => NotificationType::LowStock->value,
        'priority' => NotificationPriority::Warning->value,
        'status' => NotificationStatus::Active->value,
        'entity_type' => 'product',
        'entity_id' => 42,
    ]);

    expect($notification->isWarning())->toBeTrue();
    expect($notification->isUnread())->toBeTrue();
    expect($notification->isResolved())->toBeFalse();
    expect($notification->resolved_entity_id)->toBe(42);
    expect($notification->legacy_type)->toBe('low_stock');

    $notification->markAsRead($user);

    expect($notification->fresh()->isUnread())->toBeFalse();
    expect($notification->fresh()->isResolved())->toBeTrue();
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('notification service create persists and fills enum columns', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $service = app(NotificationService::class);

    $notification = $service->createLegacy([
        'type' => NotificationType::SystemInfo,
        'user_id' => $user->id,
        'metadata' => ['message' => 'Maintenance planifiée'],
        'broadcast' => false,
    ]);

    expect($notification->type)->toBe(NotificationType::SystemInfo);
    expect($notification->priority)->toBe(NotificationPriority::Info);
    expect($notification->status)->toBe(NotificationStatus::Active);
    expect($notification->metadata)->toBe(['message' => 'Maintenance planifiée']);
});

test('notification service mark as read keeps legacy compatibility', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $service = app(NotificationService::class);
    $service->markAsRead($user, 'low_stock', 10);

    $this->assertDatabaseHas('notification_reads', [
        'user_id' => $user->id,
        'notification_type' => 'low_stock',
        'notification_id' => 10,
        'type' => NotificationType::LowStock->value,
        'priority' => NotificationPriority::Warning->value,
        'entity_id' => 10,
    ]);

    expect(app(NotificationRepository::class)->isLegacyDismissed($user->id, 'low_stock', 10))->toBeTrue();
});

test('legacy notification rows remain readable after migration columns', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Notification::create([
        'user_id' => $user->id,
        'notification_type' => 'sale_due_today',
        'notification_id' => 5,
        'read_at' => now(),
    ]);

    $readIds = app(NotificationRepository::class)->getLegacyReadIdsByType($user->id);

    expect($readIds['sale_due_today'] ?? [])->toContain(5);
});

test('notification repository filters unread active and critical notifications', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $repository = app(NotificationRepository::class);

    Notification::create([
        'user_id' => $user->id,
        'notification_type' => 'low_stock',
        'notification_id' => 1,
        'type' => NotificationType::LowStock->value,
        'priority' => NotificationPriority::Warning->value,
        'status' => NotificationStatus::Active->value,
    ]);

    Notification::create([
        'user_id' => $user->id,
        'notification_type' => 'stock_out',
        'notification_id' => 2,
        'type' => NotificationType::StockOut->value,
        'priority' => NotificationPriority::Critical->value,
        'status' => NotificationStatus::Active->value,
    ]);

    expect($repository->getUnread($user->id))->toHaveCount(2);
    expect($repository->getCritical($user->id))->toHaveCount(1);
    expect($repository->getByType(NotificationType::StockOut, $user->id))->toHaveCount(1);
});

test('notification controller mark as read delegates to service', function () {
    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($user);

    $response = $this->postJson(route('notifications.mark-as-read'), [
        'type' => 'low_stock',
        'id' => 77,
    ]);

    $response->assertOk()->assertJson(['success' => true]);

    $this->assertDatabaseHas('notification_reads', [
        'user_id' => $user->id,
        'notification_type' => 'low_stock',
        'notification_id' => 77,
    ]);
});

test('notification service create for admins targets active admin users', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    User::factory()->create(['role' => 'vendeur', 'is_active' => true]);

    $service = app(NotificationService::class);

    $notifications = $service->createForAdmins([
        'type' => NotificationType::UserCreated,
        'metadata' => ['name' => 'Nouveau'],
        'broadcast' => false,
    ]);

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->user_id)->toBe($admin->id);
    expect($notifications->first()->audience)->toBe(NotificationAudience::Admin);
});

test('expiring products are ordered by closest expiration date first', function () {
    $provider = app(\App\Integrations\NotificationCenter\GestionGroupedEntityProvider::class);
    $category = \App\Models\Category::create(['name' => 'Péremption', 'slug' => 'peremption']);

    $soonest = \App\Models\Product::create([
        'name' => 'Produit A',
        'sku' => 'EXP-001',
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
        'expiration_date' => now()->addDay()->toDateString(),
        'alert_threshold_value' => 7,
        'alert_threshold_unit' => 'days',
    ]);

    $latest = \App\Models\Product::create([
        'name' => 'Produit D',
        'sku' => 'EXP-004',
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
        'expiration_date' => now()->addDays(7)->toDateString(),
        'alert_threshold_value' => 7,
        'alert_threshold_unit' => 'days',
    ]);

    $middle = \App\Models\Product::create([
        'name' => 'Produit B',
        'sku' => 'EXP-002',
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
        'expiration_date' => now()->addDays(2)->toDateString(),
        'alert_threshold_value' => 7,
        'alert_threshold_unit' => 'days',
    ]);

    expect($provider->getEntityIdsForType('product_expiring'))->toBe([
        $soonest->id,
        $middle->id,
        $latest->id,
    ]);
});

test('expired products are ordered by most recent expiration first', function () {
    $provider = app(\App\Integrations\NotificationCenter\GestionGroupedEntityProvider::class);
    $category = \App\Models\Category::create(['name' => 'Expirés', 'slug' => 'expires']);

    $yesterday = \App\Models\Product::create([
        'name' => 'Produit récent',
        'sku' => 'EXP-101',
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
        'expiration_date' => now()->subDay()->toDateString(),
        'alert_threshold_value' => 7,
        'alert_threshold_unit' => 'days',
    ]);

    $weekAgo = \App\Models\Product::create([
        'name' => 'Produit ancien',
        'sku' => 'EXP-102',
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
        'expiration_date' => now()->subDays(7)->toDateString(),
        'alert_threshold_value' => 7,
        'alert_threshold_unit' => 'days',
    ]);

    expect($provider->getEntityIdsForType('product_expired'))->toBe([
        $yesterday->id,
        $weekAgo->id,
    ]);
});
