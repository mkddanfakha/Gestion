<?php

namespace Tests\Feature;

use App\Models\FormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormDraftControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upsert_and_fetch_sale_draft(): void
    {
        $user = User::factory()->create();

        $payload = [
            'form_type' => 'sale',
            'mode' => 'create',
            'entity_id' => null,
            'data' => [
                'customer_id' => 1,
                'items' => [],
            ],
            'version' => 1,
            'instance_id' => 'test-instance',
        ];

        $response = $this->actingAs($user)->putJson(route('drafts.upsert'), $payload);

        $response->assertOk()
            ->assertJsonPath('draft.formType', 'sale')
            ->assertJsonPath('draft.userId', $user->id);

        $this->assertDatabaseHas('form_drafts', [
            'user_id' => $user->id,
            'form_type' => 'sale',
            'mode' => 'create',
        ]);

        $fetch = $this->actingAs($user)->getJson(route('drafts.show', [
            'form_type' => 'sale',
            'mode' => 'create',
        ]));

        $fetch->assertOk()
            ->assertJsonPath('draft.data.customer_id', 1);
    }

    public function test_user_cannot_access_another_users_draft(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $draft = FormDraft::query()->create([
            'user_id' => $owner->id,
            'form_type' => 'sale',
            'mode' => 'create',
            'entity_id' => null,
            'data' => ['notes' => 'secret'],
            'version' => 1,
            'instance_id' => 'owner-instance',
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($other)->deleteJson(route('drafts.destroy', ['draft' => $draft->id]));

        $response->assertForbidden();
        $this->assertDatabaseHas('form_drafts', ['id' => $draft->id]);
    }

    public function test_local_only_form_type_is_rejected_for_server_sync(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(route('drafts.upsert'), [
            'form_type' => 'customer',
            'mode' => 'create',
            'data' => ['name' => 'Test'],
        ]);

        $response->assertStatus(422);
    }
}
