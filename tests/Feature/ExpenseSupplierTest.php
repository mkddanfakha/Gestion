<?php

use App\Models\Expense;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createExpenseAdmin(): User
{
    (new PermissionSeeder())->run();

    return User::factory()->create(['role' => 'admin']);
}

function createActiveSupplier(string $name = 'ABC Distribution'): Supplier
{
    return Supplier::create([
        'name' => $name,
        'status' => 'active',
    ]);
}

function validExpensePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Achat fournitures',
        'amount' => 15000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ], $overrides);
}

test('expense can be created without supplier', function () {
    $admin = createExpenseAdmin();

    $response = $this->actingAs($admin)->post(route('expenses.store'), validExpensePayload());

    $response->assertRedirect(route('expenses.index'));

    $expense = Expense::query()->first();

    expect($expense)->not->toBeNull()
        ->and($expense->supplier_id)->toBeNull()
        ->and($expense->vendor)->toBeNull();
});

test('expense can be created with supplier', function () {
    $admin = createExpenseAdmin();
    $supplier = createActiveSupplier();

    $response = $this->actingAs($admin)->post(route('expenses.store'), validExpensePayload([
        'supplier_id' => $supplier->id,
    ]));

    $response->assertRedirect(route('expenses.index'));

    $expense = Expense::query()->first();

    expect($expense->supplier_id)->toBe($supplier->id)
        ->and($expense->vendor)->toBe('ABC Distribution')
        ->and($expense->supplier?->name)->toBe('ABC Distribution');
});

test('expense creation rejects invalid supplier id', function () {
    $admin = createExpenseAdmin();

    $response = $this->actingAs($admin)->post(route('expenses.store'), validExpensePayload([
        'supplier_id' => 99999,
    ]));

    $response->assertSessionHasErrors(['supplier_id']);
    expect(Expense::query()->count())->toBe(0);
});

test('expense can be updated with supplier', function () {
    $admin = createExpenseAdmin();
    $supplier = createActiveSupplier('SONATEL');

    $expense = Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Dépense initiale',
        'amount' => 5000,
        'category' => 'transport',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'user_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin)->put(route('expenses.update', $expense), validExpensePayload([
        'title' => 'Dépense modifiée',
        'supplier_id' => $supplier->id,
    ]));

    $response->assertRedirect(route('expenses.index'));

    $expense->refresh();

    expect($expense->supplier_id)->toBe($supplier->id)
        ->and($expense->vendor)->toBe('SONATEL');
});

test('expense supplier can be removed on update', function () {
    $admin = createExpenseAdmin();
    $supplier = createActiveSupplier();

    $expense = Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Dépense liée',
        'amount' => 5000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'vendor' => $supplier->name,
        'user_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin)->put(route('expenses.update', $expense), validExpensePayload([
        'supplier_id' => '',
    ]));

    $response->assertRedirect(route('expenses.index'));

    $expense->refresh();

    expect($expense->supplier_id)->toBeNull()
        ->and($expense->vendor)->toBeNull();
});

test('deleting supplier nullifies expense supplier_id', function () {
    $admin = createExpenseAdmin();
    $supplier = createActiveSupplier();

    $expense = Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Dépense liée',
        'amount' => 5000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'vendor' => $supplier->name,
        'user_id' => $admin->id,
    ]);

    $supplier->delete();

    $expense->refresh();

    expect(Expense::query()->whereKey($expense->id)->exists())->toBeTrue()
        ->and($expense->supplier_id)->toBeNull();
});

test('expense show loads supplier relation', function () {
    $admin = createExpenseAdmin();
    $supplier = createActiveSupplier('Fournisseur Show');

    $expense = Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Dépense show',
        'amount' => 7000,
        'category' => 'maintenance',
        'payment_method' => 'bank_transfer',
        'expense_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'vendor' => $supplier->name,
        'user_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin)->get(route('expenses.show', $expense));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Expenses/Show')
            ->where('expense.supplier.name', 'Fournisseur Show')
        );
});

test('legacy expense vendor is preserved when supplier is not sent on update', function () {
    $admin = createExpenseAdmin();

    $expense = Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Ancienne dépense',
        'amount' => 3000,
        'category' => 'autres',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'vendor' => 'Fournisseur libre',
        'user_id' => $admin->id,
    ]);

    $this->actingAs($admin)->put(route('expenses.update', $expense), validExpensePayload([
        'title' => 'Ancienne dépense modifiée',
        'supplier_id' => '',
    ]))->assertRedirect(route('expenses.index'));

    $expense->refresh();

    expect($expense->supplier_id)->toBeNull()
        ->and($expense->vendor)->toBe('Fournisseur libre');
});

test('expense create page receives active suppliers', function () {
    $admin = createExpenseAdmin();
    createActiveSupplier('Fournisseur Actif');
    Supplier::create(['name' => 'Inactif', 'status' => 'inactive']);

    $response = $this->actingAs($admin)->get(route('expenses.create'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Expenses/Create')
            ->has('suppliers', 1)
            ->where('suppliers.0.name', 'Fournisseur Actif')
        );
});
