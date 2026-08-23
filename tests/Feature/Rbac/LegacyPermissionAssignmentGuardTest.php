<?php

use App\Auth\AssignablePermissionResolver;
use App\Auth\AuthorizationService;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function n4Admin(): User
{
    return User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function n4PermissionId(string $name): int
{
    return (int) Permission::query()->where('name', $name)->value('id');
}

test('formulaire users : products.edit soumis devient products.update uniquement', function () {
    $admin = n4Admin();
    $email = 'n4-edit-'.uniqid().'@example.com';

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'N4 Edit Guard',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [n4PermissionId('products.edit')],
        ])
        ->assertRedirect();

    $user = User::query()->where('email', $email)->firstOrFail();
    $names = $user->permissions()->pluck('name')->all();

    expect($names)->toBe(['products.update'])
        ->and($names)->not->toContain('products.edit');
});

test('formulaire users : inventory.review soumis devient inventory.reopen uniquement', function () {
    $admin = n4Admin();
    $email = 'n4-review-'.uniqid().'@example.com';

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'N4 Review Guard',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [n4PermissionId('inventory.review')],
        ])
        ->assertRedirect();

    $names = User::query()->where('email', $email)->firstOrFail()->permissions()->pluck('name')->all();

    expect($names)->toBe(['inventory.reopen'])
        ->and($names)->not->toContain('inventory.review');
});

test('melange edit + update deduplique en un seul update', function () {
    $ids = AssignablePermissionResolver::canonicalizeIds([
        n4PermissionId('products.edit'),
        n4PermissionId('products.update'),
    ]);

    expect($ids)->toHaveCount(1)
        ->and($ids[0])->toBe(n4PermissionId('products.update'));
});

test('plusieurs permissions canonisent sans doublon', function () {
    $ids = AssignablePermissionResolver::canonicalizeIds([
        n4PermissionId('products.edit'),
        n4PermissionId('products.view'),
        n4PermissionId('sales.edit'),
        n4PermissionId('sales.update'),
    ]);

    $names = Permission::query()->whereIn('id', $ids)->pluck('name')->sort()->values()->all();

    expect($names)->toEqualCanonicalizing(['products.update', 'products.view', 'sales.update']);
});

test('permission inconnue hors catalogue nest pas ecrite', function () {
    $orphan = Permission::query()->create([
        'name' => 'orphan.fake_action',
        'resource' => 'orphan',
        'action' => 'fake_action',
        'description' => 'orphan',
    ]);

    $ids = AssignablePermissionResolver::canonicalizeIds([
        $orphan->id,
        n4PermissionId('products.view'),
    ]);

    expect($ids)->toBe([n4PermissionId('products.view')]);
});

test('update user avec pivot legacy le convertit en canonique', function () {
    $admin = n4Admin();
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync([n4PermissionId('products.edit')]);

    expect(app(AuthorizationService::class)->allows($user->fresh(), 'products.update'))->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [n4PermissionId('products.edit')],
        ])
        ->assertRedirect();

    $names = $user->fresh()->permissions()->pluck('name')->all();

    expect($names)->toBe(['products.update'])
        ->and($names)->not->toContain('products.edit');
});

test('grille admin n expose aucune permission legacy', function () {
    $names = AssignablePermissionResolver::adminGridByResource()
        ->flatten(1)
        ->pluck('name')
        ->all();

    foreach ($names as $name) {
        expect(AssignablePermissionResolver::isWritableName($name))->toBeTrue();
    }

    expect($names)->not->toContain('products.edit')
        ->and($names)->not->toContain('inventory.review')
        ->and($names)->toContain('products.update')
        ->and($names)->toContain('inventory.reopen');
});
