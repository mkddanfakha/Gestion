<?php

use App\Models\ActivityLog;
use App\Models\Quote;
use App\Models\User;
use App\Services\ActivityLogger;
use Database\Seeders\PermissionSeeder;

test('quote create update and delete are logged via ActivityLogger', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $quote = Quote::create([
        'quote_number' => 'DE250901001',
        'user_id' => $user->id,
        'subtotal' => 10000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 10000,
        'status' => 'draft',
        'quote_date' => now(),
    ]);

    $createLog = ActivityLogger::logCreate('Devis', $quote);

    expect($createLog->module)->toBe('Devis')
        ->and($createLog->action)->toBe(ActivityLog::ACTION_CREATE)
        ->and($createLog->description)->toContain('DE250901001');

    $quote->update(['total_amount' => 12000]);

    $updateLog = ActivityLogger::logUpdate('Devis', $quote);

    expect($updateLog->action)->toBe(ActivityLog::ACTION_UPDATE)
        ->and((float) $updateLog->old_values['total_amount'])->toBe(10000.0)
        ->and((float) $updateLog->new_values['total_amount'])->toBe(12000.0);

    ActivityLogger::logDelete('Devis', $quote);
    $quote->delete();

    expect(ActivityLog::where('module', 'Devis')->count())->toBe(3);
});

test('http quote store produit une seule entree ActivityLog Devis', function () {
    (new PermissionSeeder())->run();
    $user = User::factory()->create(['role' => 'admin']);
    $product = createTestProduct();

    $before = ActivityLog::where('module', 'Devis')->count();

    $this->actingAs($user)
        ->post(route('quotes.store'), [
            'status' => 'draft',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ])
        ->assertRedirect(route('quotes.index'));

    expect(ActivityLog::where('module', 'Devis')->count())->toBe($before + 1);
    expect(ActivityLog::where('module', 'Devis')->where('action', ActivityLog::ACTION_CREATE)->count())->toBe(1);
    expect(Quote::count())->toBe(1);
});
