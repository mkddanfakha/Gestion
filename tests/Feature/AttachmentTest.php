<?php

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Supplier;
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

function createUserWithQuotePermissions(array $actions = ['view', 'create', 'update', 'delete']): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'user']);

    foreach ($actions as $action) {
        $permission = Permission::where('name', "quotes.{$action}")->firstOrFail();
        $user->permissions()->attach($permission);
    }

    return $user;
}

function createTestProduct(): Product
{
    $category = Category::create(['name' => 'Test', 'slug' => 'test-' . uniqid()]);

    return Product::create([
        'name' => 'Produit test',
        'sku' => 'SKU-' . uniqid(),
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
}

function createTestQuote(User $user, Product $product): Quote
{
    $quote = Quote::create([
        'quote_number' => Quote::generateQuoteNumber(),
        'user_id' => $user->id,
        'subtotal' => 2000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 2000,
        'status' => 'draft',
    ]);

    $quote->quoteItems()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 1000,
        'total_price' => 2000,
    ]);

    return $quote;
}

function createUserWithPurchaseOrderPermissions(array $actions = ['view', 'create', 'update', 'delete']): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'user']);

    foreach ($actions as $action) {
        $permission = Permission::where('name', "purchase-orders.{$action}")->firstOrFail();
        $user->permissions()->attach($permission);
    }

    return $user;
}

function createTestSupplier(): Supplier
{
    return Supplier::create([
        'name' => 'Fournisseur test ' . uniqid(),
        'status' => 'active',
    ]);
}

function createTestPurchaseOrder(User $user, Supplier $supplier, Product $product): PurchaseOrder
{
    $purchaseOrder = PurchaseOrder::create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'order_date' => now()->toDateString(),
        'status' => 'draft',
        'subtotal' => 2000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 2000,
    ]);

    $purchaseOrder->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 1000,
        'total_price' => 2000,
    ]);

    return $purchaseOrder;
}

function createUserWithDeliveryNotePermissions(array $actions = ['view', 'create', 'update', 'delete']): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'user']);

    foreach ($actions as $action) {
        $permission = Permission::where('name', "delivery-notes.{$action}")->firstOrFail();
        $user->permissions()->attach($permission);
    }

    return $user;
}

function createTestDeliveryNote(User $user, Supplier $supplier, Product $product, PurchaseOrder $purchaseOrder): DeliveryNote
{
    $deliveryNote = DeliveryNote::create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'purchase_order_id' => $purchaseOrder->id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 2000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 2000,
    ]);

    $deliveryNote->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 1000,
        'total_price' => 2000,
    ]);

    return $deliveryNote;
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

test('user can upload attachment on quote create', function () {
    $user = createUserWithQuotePermissions();
    $product = createTestProduct();
    $file = UploadedFile::fake()->create('cahier-des-charges.pdf', 100, 'application/pdf');

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
            'attachments' => [$file],
        ])
        ->assertRedirect(route('quotes.index'));

    $quote = Quote::first();
    expect($quote)->not->toBeNull();
    expect($quote->attachments)->toHaveCount(1);
    expect($quote->attachments->first()->original_name)->toBe('cahier-des-charges.pdf');
    expect(Storage::disk('local')->exists($quote->attachments->first()->path))->toBeTrue();
    expect(ActivityLog::where('action', ActivityLog::ACTION_ATTACHMENT_ADDED)->exists())->toBeTrue();
});

test('user can upload attachment on quote update', function () {
    $user = createUserWithQuotePermissions(['view', 'update']);
    $product = createTestProduct();
    $quote = createTestQuote($user, $product);
    $file = UploadedFile::fake()->image('plan.jpg');

    $this->actingAs($user)
        ->post(route('quotes.update', $quote), [
            '_method' => 'PUT',
            'status' => 'draft',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                ],
            ],
            'attachments' => [$file],
        ])
        ->assertRedirect(route('quotes.index'));

    $quote->refresh();
    expect($quote->attachments)->toHaveCount(1);
    expect($quote->attachments->first()->original_name)->toBe('plan.jpg');
});

test('user can download quote attachment with view permission', function () {
    $user = createUserWithQuotePermissions(['view']);
    $product = createTestProduct();
    $quote = createTestQuote($user, $product);
    $file = UploadedFile::fake()->create('proposition.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($quote, $file, $user);
    $attachment = $quote->attachments()->first();

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

test('deleting quote removes attachments', function () {
    $user = createUserWithQuotePermissions(['view', 'delete']);
    $product = createTestProduct();
    $quote = createTestQuote($user, $product);
    $file = UploadedFile::fake()->create('conditions.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($quote, $file, $user);
    $path = $quote->attachments()->first()->path;

    $quote->delete();

    expect(Attachment::count())->toBe(0);
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('user can upload attachment on purchase order create', function () {
    $user = createUserWithPurchaseOrderPermissions();
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $file = UploadedFile::fake()->create('devis-fournisseur.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'total_price' => 1000,
                ],
            ],
            'attachments' => [$file],
        ])
        ->assertRedirect(route('purchase-orders.index'));

    $purchaseOrder = PurchaseOrder::first();
    expect($purchaseOrder)->not->toBeNull();
    expect($purchaseOrder->attachments)->toHaveCount(1);
    expect($purchaseOrder->attachments->first()->original_name)->toBe('devis-fournisseur.pdf');
    expect(ActivityLog::where('action', ActivityLog::ACTION_ATTACHMENT_ADDED)->exists())->toBeTrue();
});

test('user can upload attachment on purchase order update', function () {
    $user = createUserWithPurchaseOrderPermissions(['view', 'update']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $file = UploadedFile::fake()->image('plan-produit.jpg');

    $this->actingAs($user)
        ->post(route('purchase-orders.update', $purchaseOrder), [
            '_method' => 'PUT',
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 2000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 2000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'total_price' => 2000,
                ],
            ],
            'attachments' => [$file],
        ])
        ->assertRedirect(route('purchase-orders.index'));

    $purchaseOrder->refresh();
    expect($purchaseOrder->attachments)->toHaveCount(1);
});

test('user can download purchase order attachment with view permission', function () {
    $user = createUserWithPurchaseOrderPermissions(['view']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $file = UploadedFile::fake()->create('facture-proforma.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($purchaseOrder, $file, $user);
    $attachment = $purchaseOrder->attachments()->first();

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

test('deleting purchase order removes attachments', function () {
    $user = createUserWithPurchaseOrderPermissions(['view', 'delete']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $file = UploadedFile::fake()->create('conditions.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($purchaseOrder, $file, $user);
    $path = $purchaseOrder->attachments()->first()->path;

    $purchaseOrder->delete();

    expect(Attachment::count())->toBe(0);
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('user can upload attachment on delivery note create', function () {
    $user = createUserWithDeliveryNotePermissions();
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $purchaseOrder->update(['status' => 'confirmed']);
    $file = UploadedFile::fake()->create('bon-livraison-signe.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('delivery-notes.store'), [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseOrder->id,
            'delivery_date' => now()->toDateString(),
            'status' => 'pending',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'total_price' => 1000,
                ],
            ],
            'attachments' => [$file],
        ])
        ->assertRedirect(route('delivery-notes.index'));

    $deliveryNote = DeliveryNote::first();
    expect($deliveryNote)->not->toBeNull();
    expect($deliveryNote->attachments)->toHaveCount(1);
    expect($deliveryNote->attachments->first()->original_name)->toBe('bon-livraison-signe.pdf');
});

test('user can upload attachment on delivery note update', function () {
    $user = createUserWithDeliveryNotePermissions(['view', 'update']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $purchaseOrder->update(['status' => 'confirmed']);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);
    $file = UploadedFile::fake()->image('photo-marchandise.jpg');

    $this->actingAs($user)
        ->post(route('delivery-notes.update', $deliveryNote), [
            '_method' => 'PUT',
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseOrder->id,
            'delivery_date' => now()->toDateString(),
            'status' => 'pending',
            'subtotal' => 2000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 2000,
            'items' => [
                [
                    'id' => $deliveryNote->items()->first()->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'total_price' => 2000,
                ],
            ],
            'attachments' => [$file],
        ])
        ->assertRedirect(route('delivery-notes.show', $deliveryNote));

    $deliveryNote->refresh();
    expect($deliveryNote->attachments)->toHaveCount(1);
});

test('adding delivery note attachment does not change product stock', function () {
    $user = createUserWithDeliveryNotePermissions(['view', 'update']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $product->update(['stock_quantity' => 25]);
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $purchaseOrder->update(['status' => 'confirmed']);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);
    $file = UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($deliveryNote, $file, $user);

    $product->refresh();
    expect($product->stock_quantity)->toBe(25);
    expect($deliveryNote->attachments)->toHaveCount(1);
});

test('user can download delivery note attachment with view permission', function () {
    $user = createUserWithDeliveryNotePermissions(['view']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);
    $file = UploadedFile::fake()->create('bon-reception.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($deliveryNote, $file, $user);
    $attachment = $deliveryNote->attachments()->first();

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

test('deleting delivery note removes attachments', function () {
    $user = createUserWithDeliveryNotePermissions(['view', 'delete']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);
    $file = UploadedFile::fake()->create('conditions.pdf', 100, 'application/pdf');

    app(\App\Services\AttachmentService::class)->add($deliveryNote, $file, $user);
    $path = $deliveryNote->attachments()->first()->path;

    $deliveryNote->delete();

    expect(Attachment::count())->toBe(0);
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});
