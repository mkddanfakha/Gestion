<?php

use App\Auth\RolePresets;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->seed(PermissionSeeder::class);

    $user = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($user->fresh());

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});
