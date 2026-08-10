<?php

namespace App\Modules\NotificationCenter\Tests\Feature;

use App\Integrations\NotificationCenter\GestionGroupedPreviewProvider;
use App\Models\Product;
use App\Models\User;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationGroupedProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_grouped_alert_stores_product_previews_in_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $categoryId = \App\Models\Category::query()->create([
            'name' => 'Alimentation',
            'slug' => 'alimentation',
            'color' => '#ff0000',
        ])->id;

        Product::query()->create([
            'name' => 'Riz 25 kg',
            'sku' => 'RIZ-25',
            'price' => 100,
            'stock_quantity' => 0,
            'min_stock_level' => 5,
            'unit' => 'sac',
            'category_id' => $categoryId,
            'is_active' => true,
        ]);

        app(NotificationService::class)->syncGroupedAlert('stock_out');

        $notification = Notification::query()
            ->where('user_id', $admin->id)
            ->where('type', 'stock_out')
            ->first();

        $this->assertNotNull($notification);
        $this->assertTrue($notification->metadata['grouped'] ?? false);
        $this->assertSame(1, $notification->metadata['count'] ?? 0);
        $this->assertNotEmpty($notification->metadata['products'] ?? []);
        $this->assertSame('Riz 25 kg', $notification->metadata['products'][0]['name'] ?? null);
        $this->assertSame('RIZ-25', $notification->metadata['products'][0]['reference'] ?? null);
        $this->assertSame(0, $notification->metadata['products'][0]['stock'] ?? null);
    }

    public function test_api_notification_resource_includes_refreshed_products(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $categoryId = \App\Models\Category::query()->create([
            'name' => 'Alimentation',
            'slug' => 'alimentation',
            'color' => '#ff0000',
        ])->id;

        $product = Product::query()->create([
            'name' => 'Sucre 50 kg',
            'sku' => 'SUC-50',
            'price' => 80,
            'stock_quantity' => 0,
            'min_stock_level' => 10,
            'unit' => 'sac',
            'category_id' => $categoryId,
            'is_active' => true,
        ]);

        Notification::create([
            'user_id' => $admin->id,
            'notification_type' => 'stock_out',
            'notification_id' => 0,
            'type' => 'stock_out',
            'priority' => 'critical',
            'status' => 'active',
            'entity_type' => 'product',
            'entity_id' => 0,
            'group_key' => 'stock_out:grouped',
            'title' => 'Rupture de stock',
            'description' => '1 produit(s) sont actuellement en rupture de stock.',
            'metadata' => [
                'grouped' => true,
                'count' => 1,
                'entity_ids' => [$product->id],
                'products' => [],
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.0.metadata.products.0.name', 'Sucre 50 kg');
        $response->assertJsonPath('data.0.metadata.products.0.reference', 'SUC-50');
    }

    public function test_preview_provider_maps_product_fields(): void
    {
        $categoryId = \App\Models\Category::query()->create([
            'name' => 'Alimentation',
            'slug' => 'alimentation',
            'color' => '#ff0000',
        ])->id;

        $product = Product::query()->create([
            'name' => 'Huile 5L',
            'sku' => 'HUI-5',
            'price' => 50,
            'stock_quantity' => 0,
            'min_stock_level' => 8,
            'unit' => 'bidon',
            'category_id' => $categoryId,
            'is_active' => true,
        ]);

        $previews = app(GestionGroupedPreviewProvider::class)->buildPreviews('stock_out', [$product->id]);

        $this->assertCount(1, $previews);
        $this->assertSame('Huile 5L', $previews[0]['name']);
        $this->assertSame('HUI-5', $previews[0]['reference']);
        $this->assertSame(0, $previews[0]['stock']);
        $this->assertSame(8, $previews[0]['minimum_stock']);
        $this->assertSame('Alimentation', $previews[0]['category']);
    }
}
