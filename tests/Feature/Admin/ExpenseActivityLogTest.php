<?php

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\User;
use App\Services\ActivityLogger;

test('expense create update and delete are logged', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $expense = Expense::create([
        'expense_number' => 'EXP-250801001',
        'title' => 'Fournitures bureau',
        'amount' => 15000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'user_id' => $user->id,
    ]);

    $createLog = ActivityLogger::logCreate('Dépense', $expense);

    expect($createLog->module)->toBe('Dépense');
    expect($createLog->action)->toBe(ActivityLog::ACTION_CREATE);
    expect($createLog->description)->toContain('EXP-250801001');

    $expense->update(['amount' => 20000]);

    $updateLog = ActivityLogger::logUpdate('Dépense', $expense);

    expect($updateLog->action)->toBe(ActivityLog::ACTION_UPDATE);
    expect((float) $updateLog->old_values['amount'])->toBe(15000.0);
    expect((float) $updateLog->new_values['amount'])->toBe(20000.0);

    ActivityLogger::logDelete('Dépense', $expense);
    $expense->delete();

    expect(ActivityLog::where('module', 'Dépense')->count())->toBe(3);
});
