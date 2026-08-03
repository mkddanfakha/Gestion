<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Audit\AuditChangePresenter;
use App\Services\Audit\AuditFieldTranslator;
use App\Services\Audit\ChangeDetector;

test('change detector uses updating snapshot even after model refresh', function () {
    $category = Category::create([
        'name' => 'Riz 25 kg',
        'description' => 'Test',
        'color' => '#000000',
    ]);

    $category->update(['name' => 'Riz parfumé 25 kg', 'color' => '#ffffff']);
    $category->refresh();

    $changes = ChangeDetector::detectChanges($category);

    expect($changes['old_values']['name'])->toBe('Riz 25 kg');
    expect($changes['new_values']['name'])->toBe('Riz parfumé 25 kg');
    expect($changes['old_values']['color'])->toBe('#000000');
    expect($changes['new_values']['color'])->toBe('#ffffff');
});

test('change detector excludes sensitive and timestamp fields', function () {
    $user = User::factory()->create([
        'name' => 'Ancien nom',
        'email' => 'ancien@example.com',
    ]);

    $user->update([
        'name' => 'Nouveau nom',
        'password' => bcrypt('secret-password'),
    ]);

    $changes = ChangeDetector::detectChanges($user);

    expect($changes['old_values'])->toHaveKey('name');
    expect($changes['old_values'])->not->toHaveKey('password');
    expect($changes['new_values'])->not->toHaveKey('password');
});

test('change detector stores full record on deletion', function () {
    $category = Category::create([
        'name' => 'À supprimer',
        'description' => 'Desc',
        'color' => '#ffffff',
    ]);

    $changes = ChangeDetector::captureDeletion($category);

    expect($changes['old_values']['name'])->toBe('À supprimer');
    expect($changes['new_values'])->toBeNull();
});

test('audit field translator resolves readable labels and booleans', function () {
    expect(AuditFieldTranslator::label('price'))->toBe('Prix');
    expect(AuditFieldTranslator::label('stock_quantity'))->toBe('Stock');
    expect(AuditFieldTranslator::label('customer_id'))->toBe('Client');
    expect(AuditFieldTranslator::formatValue('is_active', true))->toBe('Oui');
});

test('audit change presenter hides create and delete snapshots', function () {
    $createRows = AuditChangePresenter::present(null, ['name' => 'Test'], ActivityLog::ACTION_CREATE);
    $deleteRows = AuditChangePresenter::present(['name' => 'Test'], null, ActivityLog::ACTION_DELETE);

    expect($createRows)->toBe([]);
    expect($deleteRows)->toBe([]);
});

test('activity logger stores old and new values on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $category = Category::create([
        'name' => 'Ancien',
        'description' => 'Desc',
        'color' => '#111111',
    ]);

    $category->update(['name' => 'Nouveau']);

    $this->actingAs($admin);
    $log = ActivityLogger::logUpdate('Catégorie', $category);

    expect($log->old_values)->toBe(['name' => 'Ancien']);
    expect($log->new_values)->toBe(['name' => 'Nouveau']);
});

test('activity log search finds changed values', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $category = Category::create([
        'name' => 'Ancien',
        'description' => 'Desc',
        'color' => '#111111',
    ]);
    $category->update(['name' => '5200']);

    $this->actingAs($admin);
    ActivityLogger::logUpdate('Catégorie', $category);

    $this->actingAs($admin)
        ->get(route('admin.activity-logs.index', ['search' => '5200']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/ActivityLogs/Index')
            ->has('activityLogs.data', 1)
        );
});
