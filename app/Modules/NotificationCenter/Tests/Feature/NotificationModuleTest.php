<?php

namespace App\Modules\NotificationCenter\Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Modules\NotificationCenter\DTO\CreateNotificationData;
use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_notification_via_service(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $service = app(NotificationService::class);

        $notification = $service->create(new CreateNotificationData(
            userId: $user->id,
            title: 'Test',
            description: 'Description test',
            type: 'system_info',
            priority: NotificationPriority::Info,
        ));

        $this->assertDatabaseHas('notification_reads', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'type' => 'system_info',
        ]);
    }

    public function test_api_lists_notifications(): void
    {
        app(NotificationSettingsService::class)->ensureDefaults();

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Product::factory()->create([
            'name' => 'Hello',
            'stock_quantity' => 2,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications?severity=warning');

        $response->assertOk()->assertJsonPath('data.0.product.name', 'Hello');
    }

    public function test_mark_as_read_legacy_route(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)->postJson('/notifications/mark-as-read', [
            'type' => 'low_stock',
            'id' => 42,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('notification_reads', [
            'user_id' => $user->id,
            'notification_type' => 'low_stock',
            'notification_id' => 42,
        ]);
    }
}
