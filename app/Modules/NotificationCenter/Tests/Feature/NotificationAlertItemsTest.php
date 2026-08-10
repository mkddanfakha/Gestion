<?php

namespace App\Modules\NotificationCenter\Tests\Feature;

use App\Integrations\NotificationCenter\NotificationAlertItemService;
use App\Models\Product;
use App\Models\User;
use App\Modules\NotificationCenter\Models\NotificationTypeSetting;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationAlertItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(NotificationSettingsService::class)->ensureDefaults();
    }

    public function test_counts_return_unread_alert_totals(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Product::factory()->create([
            'stock_quantity' => 0,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        Product::factory()->create([
            'stock_quantity' => 2,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications/counts');

        $response->assertOk()
            ->assertJsonPath('data.critical', 1)
            ->assertJsonPath('data.warning', 1)
            ->assertJsonPath('data.all', 2);
    }

    public function test_index_paginates_warning_alerts(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        foreach (range(1, 7) as $index) {
            Product::factory()->create([
                'name' => "Produit warning {$index}",
                'stock_quantity' => 2,
                'min_stock_level' => 5,
                'is_active' => true,
            ]);
        }

        $firstPage = $this->actingAs($user)->getJson('/api/notifications?severity=warning&per_page=5');

        $firstPage->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $secondPage = $this->actingAs($user)->getJson('/api/notifications?severity=warning&page=2&per_page=5');

        $secondPage->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_mark_as_read_decrements_visible_alerts(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $product = Product::factory()->create([
            'stock_quantity' => 2,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/notifications/mark-as-read', [
            'type' => 'low_stock',
            'id' => $product->id,
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/notifications/counts')
            ->assertJsonPath('data.warning', 0);
    }

    public function test_search_filters_by_product_name(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Product::factory()->create([
            'name' => 'Riz 25 kg',
            'stock_quantity' => 2,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        Product::factory()->create([
            'name' => 'Sucre 50 kg',
            'stock_quantity' => 2,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications?severity=warning&search=riz');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Riz 25 kg');
    }

    public function test_alert_item_service_avoids_n_plus_one_product_loading(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        foreach (range(1, 3) as $index) {
            Product::factory()->create([
                'stock_quantity' => 2,
                'min_stock_level' => 5,
                'is_active' => true,
            ]);
        }

        Product::enableQueryLog();

        $service = app(NotificationAlertItemService::class);
        $result = $service->paginateForUser($user->id, [
            'priority' => 'warning',
            'per_page' => 5,
        ], $user);

        $queries = Product::query()->getConnection()->getQueryLog();
        $productQueries = collect($queries)->filter(function (array $query) {
            return str_contains(strtolower($query['query']), 'products');
        });

        $this->assertCount(3, $result['data']);
        $this->assertLessThanOrEqual(2, $productQueries->count());
    }

    public function test_seller_does_not_receive_alerts_when_recipient_disabled(): void
    {
        foreach (['stock_out', 'low_stock', 'product_expired', 'product_expiring', 'invoice_due'] as $type) {
            NotificationTypeSetting::query()->where('type', $type)->update([
                'recipients' => [
                    'admin' => true,
                    'manager' => true,
                    'seller' => false,
                ],
            ]);
        }

        app(NotificationSettingsService::class)->clearCache();

        $seller = User::factory()->create(['role' => 'vendeur', 'is_active' => true]);

        Product::factory()->create([
            'stock_quantity' => 0,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        Product::factory()->create([
            'stock_quantity' => 2,
            'min_stock_level' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->getJson('/api/notifications/counts')
            ->assertOk()
            ->assertJsonPath('data.all', 0)
            ->assertJsonPath('data.critical', 0)
            ->assertJsonPath('data.warning', 0);

        $this->actingAs($seller)->getJson('/api/notifications?severity=warning')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
