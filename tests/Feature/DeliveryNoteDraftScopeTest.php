<?php

namespace Tests\Feature;

use App\Models\FormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryNoteDraftScopeTest extends TestCase
{
    use RefreshDatabase;

    private function upsertDeliveryNoteDraft(
        User $user,
        string $scopeContext,
        array $data,
    ) {
        return $this->actingAs($user)->putJson(route('drafts.upsert'), [
            'form_type' => 'delivery_note',
            'mode' => 'create',
            'entity_id' => null,
            'scope_context' => $scopeContext,
            'data' => $data,
            'version' => 1,
            'instance_id' => "instance-{$scopeContext}",
        ]);
    }

    private function fetchDeliveryNoteDraft(User $user, ?string $scopeContext = null)
    {
        $query = [
            'form_type' => 'delivery_note',
            'mode' => 'create',
        ];

        if ($scopeContext !== null) {
            $query['scope_context'] = $scopeContext;
        }

        return $this->actingAs($user)->getJson(route('drafts.show', $query));
    }

    private function deleteDeliveryNoteDraft(User $user, string $scopeContext)
    {
        return $this->actingAs($user)->deleteJson(route('drafts.destroy-scope'), [
            'form_type' => 'delivery_note',
            'mode' => 'create',
            'entity_id' => null,
            'scope_context' => $scopeContext,
        ]);
    }

    public function test_standalone_and_purchase_order_drafts_are_stored_separately(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'standalone', [
            'items' => [['product_id' => 1, 'quantity' => 10]],
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'items' => [['product_id' => 2, 'quantity' => 5]],
        ])->assertOk();

        $this->assertDatabaseCount('form_drafts', 2);

        $this->fetchDeliveryNoteDraft($user, 'standalone')
            ->assertOk()
            ->assertJsonPath('draft.scopeContext', 'standalone')
            ->assertJsonPath('draft.data.items.0.quantity', 10);

        $this->fetchDeliveryNoteDraft($user, 'from-po:12')
            ->assertOk()
            ->assertJsonPath('draft.scopeContext', 'from-po:12')
            ->assertJsonPath('draft.data.items.0.quantity', 5);
    }

    public function test_different_purchase_order_drafts_do_not_collide(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'items' => [['product_id' => 2, 'quantity' => 5]],
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'from-po:25', [
            'items' => [['product_id' => 3, 'quantity' => 8]],
        ])->assertOk();

        $this->fetchDeliveryNoteDraft($user, 'from-po:12')
            ->assertOk()
            ->assertJsonPath('draft.data.items.0.quantity', 5);

        $this->fetchDeliveryNoteDraft($user, 'from-po:25')
            ->assertOk()
            ->assertJsonPath('draft.data.items.0.quantity', 8);
    }

    public function test_fetching_standalone_draft_does_not_return_purchase_order_draft(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'items' => [['product_id' => 2, 'quantity' => 5]],
        ])->assertOk();

        $this->fetchDeliveryNoteDraft($user, 'standalone')
            ->assertOk()
            ->assertJsonPath('draft', null);
    }

    public function test_fetching_purchase_order_draft_does_not_return_standalone_draft(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'standalone', [
            'items' => [['product_id' => 1, 'quantity' => 10]],
        ])->assertOk();

        $this->fetchDeliveryNoteDraft($user, 'from-po:12')
            ->assertOk()
            ->assertJsonPath('draft', null);
    }

    public function test_deleting_one_scope_does_not_remove_other_scopes(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'standalone', [
            'items' => [['product_id' => 1, 'quantity' => 10]],
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'items' => [['product_id' => 2, 'quantity' => 5]],
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'from-po:25', [
            'items' => [['product_id' => 3, 'quantity' => 8]],
        ])->assertOk();

        $this->deleteDeliveryNoteDraft($user, 'from-po:12')->assertOk();

        $this->assertDatabaseMissing('form_drafts', [
            'user_id' => $user->id,
            'form_type' => 'delivery_note',
            'scope_context' => 'from-po:12',
        ]);

        $this->assertDatabaseHas('form_drafts', [
            'user_id' => $user->id,
            'form_type' => 'delivery_note',
            'scope_context' => 'standalone',
        ]);

        $this->assertDatabaseHas('form_drafts', [
            'user_id' => $user->id,
            'form_type' => 'delivery_note',
            'scope_context' => 'from-po:25',
        ]);
    }

    public function test_server_sync_persists_scope_context(): void
    {
        $user = User::factory()->create();

        $response = $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'items' => [['product_id' => 2, 'quantity' => 5]],
        ]);

        $response->assertOk()
            ->assertJsonPath('draft.scopeContext', 'from-po:12');

        $this->assertDatabaseHas('form_drafts', [
            'user_id' => $user->id,
            'form_type' => 'delivery_note',
            'mode' => 'create',
            'scope_context' => 'from-po:12',
        ]);
    }

    public function test_fetching_from_po_select_without_draft_returns_null(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('drafts.show', [
            'form_type' => 'delivery_note',
            'mode' => 'create',
            'scope_context' => 'from-po:select',
        ]))
            ->assertOk()
            ->assertJsonPath('draft', null);
    }

    public function test_fetching_from_po_select_returns_only_its_own_draft(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'from-po:select', [
            'items' => [['product_id' => 4, 'quantity' => 2]],
        ])->assertOk();

        $this->actingAs($user)->getJson(route('drafts.show', [
            'form_type' => 'delivery_note',
            'mode' => 'create',
            'scope_context' => 'from-po:select',
        ]))
            ->assertOk()
            ->assertJsonPath('draft.scopeContext', 'from-po:select')
            ->assertJsonPath('draft.data.items.0.quantity', 2);
    }

    public function test_from_po_select_draft_is_isolated_from_standalone_and_purchase_order_scopes(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'standalone', [
            'items' => [['product_id' => 1, 'quantity' => 10]],
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'items' => [['product_id' => 2, 'quantity' => 5]],
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'from-po:select', [
            'items' => [['product_id' => 4, 'quantity' => 2]],
        ])->assertOk();

        $this->fetchDeliveryNoteDraft($user, 'from-po:select')
            ->assertOk()
            ->assertJsonPath('draft.scopeContext', 'from-po:select')
            ->assertJsonPath('draft.data.items.0.quantity', 2);

        $this->fetchDeliveryNoteDraft($user, 'standalone')
            ->assertOk()
            ->assertJsonPath('draft.data.items.0.quantity', 10);

        $this->fetchDeliveryNoteDraft($user, 'from-po:12')
            ->assertOk()
            ->assertJsonPath('draft.data.items.0.quantity', 5);
    }

    public function test_fetching_standalone_without_draft_returns_null(): void
    {
        $user = User::factory()->create();

        $this->fetchDeliveryNoteDraft($user, 'standalone')
            ->assertOk()
            ->assertJsonPath('draft', null);
    }

    public function test_legacy_draft_without_scope_context_remains_accessible(): void
    {
        $user = User::factory()->create();

        FormDraft::query()->create([
            'user_id' => $user->id,
            'form_type' => 'delivery_note',
            'mode' => 'create',
            'entity_id' => null,
            'scope_context' => '',
            'data' => ['items' => [['product_id' => 9, 'quantity' => 3]]],
            'version' => 1,
            'instance_id' => 'legacy-instance',
            'expires_at' => now()->addDays(7),
        ]);

        $this->fetchDeliveryNoteDraft($user)
            ->assertOk()
            ->assertJsonPath('draft.data.items.0.quantity', 3);

        $this->fetchDeliveryNoteDraft($user, 'standalone')
            ->assertOk()
            ->assertJsonPath('draft', null);
    }

    public function test_edit_draft_uses_entity_id_without_scope_context(): void
    {
        $user = User::factory()->create();

        FormDraft::query()->create([
            'user_id' => $user->id,
            'form_type' => 'delivery_note',
            'mode' => 'edit',
            'entity_id' => 7,
            'scope_context' => '',
            'data' => ['notes' => 'BL edit draft'],
            'version' => 1,
            'instance_id' => 'edit-instance',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($user)->getJson(route('drafts.show', [
            'form_type' => 'delivery_note',
            'mode' => 'edit',
            'entity_id' => 7,
        ]))
            ->assertOk()
            ->assertJsonPath('draft.data.notes', 'BL edit draft');
    }

    public function test_sale_drafts_without_scope_context_remain_compatible(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('drafts.upsert'), [
            'form_type' => 'sale',
            'mode' => 'create',
            'entity_id' => null,
            'data' => ['customer_id' => 4],
            'version' => 1,
            'instance_id' => 'sale-instance',
        ])->assertOk();

        $this->actingAs($user)->getJson(route('drafts.show', [
            'form_type' => 'sale',
            'mode' => 'create',
        ]))
            ->assertOk()
            ->assertJsonPath('draft.data.customer_id', 4);
    }

    public function test_standalone_draft_preserves_items_when_supplier_changes(): void
    {
        $user = User::factory()->create();

        $items = [
            [
                'product_id' => 10,
                'quantity' => 10,
                'unit_price' => 12000,
                'total_price' => 120000,
            ],
        ];

        $this->upsertDeliveryNoteDraft($user, 'standalone', [
            'supplier_id' => 1,
            'purchase_order_id' => null,
            'items' => $items,
        ])->assertOk();

        $this->upsertDeliveryNoteDraft($user, 'standalone', [
            'supplier_id' => 2,
            'purchase_order_id' => null,
            'items' => $items,
        ])->assertOk();

        $this->fetchDeliveryNoteDraft($user, 'standalone')
            ->assertOk()
            ->assertJsonPath('draft.data.supplier_id', 2)
            ->assertJsonPath('draft.data.purchase_order_id', null)
            ->assertJsonPath('draft.data.items.0.product_id', 10)
            ->assertJsonPath('draft.data.items.0.quantity', 10);
    }

    public function test_from_po_draft_preserves_purchase_order_context(): void
    {
        $user = User::factory()->create();

        $this->upsertDeliveryNoteDraft($user, 'from-po:12', [
            'supplier_id' => 5,
            'purchase_order_id' => 12,
            'items' => [
                [
                    'product_id' => 3,
                    'quantity' => 4,
                    'unit_price' => 5000,
                    'total_price' => 20000,
                ],
            ],
        ])->assertOk();

        $this->fetchDeliveryNoteDraft($user, 'from-po:12')
            ->assertOk()
            ->assertJsonPath('draft.scopeContext', 'from-po:12')
            ->assertJsonPath('draft.data.supplier_id', 5)
            ->assertJsonPath('draft.data.purchase_order_id', 12)
            ->assertJsonPath('draft.data.items.0.product_id', 3)
            ->assertJsonPath('draft.data.items.0.quantity', 4);
    }
}
