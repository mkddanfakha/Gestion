<?php

namespace App\Modules\NotificationCenter\Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Modules\NotificationCenter\Enums\NotificationStatus;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationGroupedAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_reactivates_resolved_grouped_notification_instead_of_inserting_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $categoryId = \App\Models\Category::query()->create([
            'name' => 'Test',
            'slug' => 'test',
            'color' => '#000000',
        ])->id;

        Product::query()->create([
            'name' => 'Produit stock faible',
            'sku' => 'TST-001',
            'price' => 10,
            'stock_quantity' => 3,
            'min_stock_level' => 5,
            'unit' => 'pièce',
            'category_id' => $categoryId,
            'is_active' => true,
        ]);

        Notification::create([
            'user_id' => $admin->id,
            'notification_type' => 'low_stock',
            'notification_id' => 0,
            'type' => 'low_stock',
            'priority' => 'warning',
            'status' => NotificationStatus::Resolved->value,
            'entity_type' => 'product',
            'entity_id' => 0,
            'group_key' => 'low_stock:grouped',
            'metadata' => ['grouped' => true, 'count' => 0, 'title' => 'Ancien'],
            'resolved_at' => now(),
        ]);

        $service = app(NotificationService::class);

        $service->syncGroupedAlert('low_stock');

        $this->assertSame(1, Notification::query()->where('user_id', $admin->id)->where('type', 'low_stock')->count());

        $notification = Notification::query()->where('user_id', $admin->id)->where('type', 'low_stock')->first();
        $this->assertSame(NotificationStatus::Active, $notification->status);
        $this->assertNull($notification->read_at);
    }
}
