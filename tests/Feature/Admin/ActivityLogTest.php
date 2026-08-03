<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;

test('guests cannot access activity logs', function () {
    $response = $this->get(route('admin.activity-logs.index'));
    $response->assertRedirect(route('login'));
});

test('non-admin users cannot access activity logs', function () {
    $user = User::factory()->create(['role' => 'user']);

    $this->actingAs($user)
        ->get(route('admin.activity-logs.index'))
        ->assertForbidden();
});

test('admin users can view activity logs index', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    ActivityLogger::logLogin($admin);

    $this->actingAs($admin)
        ->get(route('admin.activity-logs.index'))
        ->assertOk();
});

test('admin users can view activity log details', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $log = ActivityLogger::logLogin($admin);

    $this->actingAs($admin)
        ->get(route('admin.activity-logs.show', $log))
        ->assertOk();
});

test('activity logs cannot be updated or deleted', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $log = ActivityLogger::logLogin($admin);

    expect(ActivityLog::find($log->id)->update(['description' => 'hack']))->toBeFalse();
    expect(ActivityLog::find($log->id)->delete())->toBeFalse();
    expect(ActivityLog::find($log->id))->not->toBeNull();
});

test('activity logger records user ip and user agent', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user);

    $log = ActivityLogger::log(
        ActivityLog::ACTION_LOGIN,
        'Authentification',
        's\'est connecté',
        $user,
    );

    expect($log->user_id)->toBe($user->id);
    expect($log->subject_id)->toBe($user->id);
    expect($log->ip_address)->not->toBeNull();
});
