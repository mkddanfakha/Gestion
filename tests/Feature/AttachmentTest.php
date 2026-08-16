<?php

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createUserWithExpensePermissions(array $actions = ['view', 'create', 'update', 'delete']): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'user']);

    foreach ($actions as $action) {
        $permission = Permission::where('name', "expenses.{$action}")->firstOrFail();
        $user->permissions()->attach($permission);
    }

    return $user;
}

function createTestExpense(User $user): Expense
{
    return Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Achat test',
        'amount' => 1000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'user_id' => $user->id,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    config(['attachments.disk' => 'local']);
});

test('user can upload attachment on expense create', function () {
    $user = createUserWithExpensePermissions();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('expenses.store'), [
            'title' => 'Dépense avec justificatif',
            'amount' => 1500,
            'category' => 'fournitures',
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'attachments' => [$file],
        ])
        ->assertRedirect(route('expenses.index'));

    $expense = Expense::first();
    expect($expense)->not->toBeNull();
    expect($expense->attachments)->toHaveCount(1);
    expect($expense->attachments->first()->original_name)->toBe('facture.pdf');
    expect(Storage::disk('local')->exists($expense->attachments->first()->path))->toBeTrue();

    expect(ActivityLog::where('action', ActivityLog::ACTION_ATTACHMENT_ADDED)->exists())->toBeTrue();
});

test('expense create rejects forbidden file type', function () {
    $user = createUserWithExpensePermissions();
    $file = UploadedFile::fake()->create('script.php', 10, 'application/x-php');

    $this->actingAs($user)
        ->post(route('expenses.store'), [
            'title' => 'Dépense invalide',
            'amount' => 1500,
            'category' => 'fournitures',
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'attachments' => [$file],
        ])
        ->assertSessionHasErrors('attachments');

    expect(Expense::count())->toBe(0);
});

test('user can download own expense attachment', function () {
    $user = createUserWithExpensePermissions(['view']);
    $expense = createTestExpense($user);
    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($expense, $file, $user);
    $attachment = $expense->attachments()->first();

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

test('gestionnaire cannot download attachment of another users expense', function () {
    (new PermissionSeeder())->run();

    $owner = User::factory()->create(['role' => 'gestionnaire']);
    $other = User::factory()->create(['role' => 'gestionnaire']);
    $permission = Permission::where('name', 'expenses.view')->firstOrFail();
    $other->permissions()->attach($permission);

    $expense = createTestExpense($owner);
    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($expense, $file, $owner);
    $attachment = $expense->attachments()->first();

    $this->actingAs($other)
        ->get(route('attachments.download', $attachment))
        ->assertForbidden();
});

test('user can delete attachment with update permission', function () {
    $user = createUserWithExpensePermissions(['view', 'update']);
    $expense = createTestExpense($user);
    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($expense, $file, $user);
    $attachment = $expense->attachments()->first();

    $this->actingAs($user)
        ->delete(route('attachments.destroy', $attachment))
        ->assertRedirect();

    expect(Attachment::count())->toBe(0);
    expect(ActivityLog::where('action', ActivityLog::ACTION_ATTACHMENT_DELETED)->exists())->toBeTrue();
});

test('deleting expense removes attachments', function () {
    $user = createUserWithExpensePermissions(['view', 'delete']);
    $expense = createTestExpense($user);
    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($expense, $file, $user);
    $path = $expense->attachments()->first()->path;

    $expense->delete();

    expect(Attachment::count())->toBe(0);
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('attachments cleanup dry run lists orphan files', function () {
    Storage::disk('local')->put('attachments/expenses/1/orphan.pdf', 'test');

    $this->artisan('attachments:cleanup', ['--dry-run' => true])
        ->expectsOutputToContain('orphan.pdf')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists('attachments/expenses/1/orphan.pdf'))->toBeTrue();
});

test('expense index includes attachments count', function () {
    $user = createUserWithExpensePermissions(['view']);
    $expense = createTestExpense($user);
    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');
    app(\App\Services\AttachmentService::class)->add($expense, $file, $user);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('expenses.data', 1)
            ->where('expenses.data.0.attachments_count', 1)
        );
});
