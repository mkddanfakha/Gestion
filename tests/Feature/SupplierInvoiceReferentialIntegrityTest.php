<?php

use App\Models\DeliveryNote;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function referentialIntegrityAdmin(): User
{
    (new PermissionSeeder())->run();

    return User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function referentialIntegritySupplier(): Supplier
{
    return Supplier::create([
        'name' => 'Fournisseur référentiel '.uniqid(),
        'status' => 'active',
    ]);
}

function referentialIntegrityInvoice(
    Supplier $supplier,
    User $author,
    array $overrides = [],
): SupplierInvoice {
    return SupplierInvoice::query()->create(array_merge([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'REF-'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'total_amount' => 50000,
        'status' => SupplierInvoice::STATUS_DRAFT,
        'user_id' => $author->id,
    ], $overrides));
}

test('supplier deletion is blocked when supplier invoices exist', function () {
    $admin = referentialIntegrityAdmin();
    $supplier = referentialIntegritySupplier();
    $invoice = referentialIntegrityInvoice($supplier, $admin);

    $this->actingAs($admin)
        ->delete(route('suppliers.destroy', $supplier))
        ->assertSessionHasErrors('supplier');

    expect(Supplier::query()->whereKey($supplier->id)->exists())->toBeTrue()
        ->and(SupplierInvoice::query()->whereKey($invoice->id)->exists())->toBeTrue();
});

test('supplier deletion succeeds when no supplier invoices exist', function () {
    $admin = referentialIntegrityAdmin();
    $supplier = referentialIntegritySupplier();

    $this->actingAs($admin)
        ->delete(route('suppliers.destroy', $supplier))
        ->assertRedirect(route('suppliers.index'));

    expect(Supplier::query()->whereKey($supplier->id)->exists())->toBeFalse();
});

test('purchase order deletion is blocked when referenced by supplier invoice', function () {
    $admin = referentialIntegrityAdmin();
    $supplier = referentialIntegritySupplier();

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'order_date' => now()->toDateString(),
        'status' => 'draft',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    referentialIntegrityInvoice($supplier, $admin, [
        'purchase_order_id' => $purchaseOrder->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('purchase-orders.destroy', $purchaseOrder))
        ->assertSessionHasErrors('message');

    expect(PurchaseOrder::query()->whereKey($purchaseOrder->id)->exists())->toBeTrue();
});

test('purchase order deletion succeeds when not referenced by supplier invoice', function () {
    $admin = referentialIntegrityAdmin();
    $supplier = referentialIntegritySupplier();

    $purchaseOrder = PurchaseOrder::query()->create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'order_date' => now()->toDateString(),
        'status' => 'draft',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    $this->actingAs($admin)
        ->delete(route('purchase-orders.destroy', $purchaseOrder))
        ->assertRedirect(route('purchase-orders.index'));

    expect(PurchaseOrder::query()->whereKey($purchaseOrder->id)->exists())->toBeFalse();
});

test('delivery note deletion is blocked when referenced by supplier invoice', function () {
    $admin = referentialIntegrityAdmin();
    $supplier = referentialIntegritySupplier();

    $deliveryNote = DeliveryNote::query()->create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    referentialIntegrityInvoice($supplier, $admin, [
        'delivery_note_id' => $deliveryNote->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('delivery-notes.destroy', $deliveryNote))
        ->assertSessionHasErrors('message');

    expect(DeliveryNote::query()->whereKey($deliveryNote->id)->exists())->toBeTrue();
});

test('delivery note deletion succeeds when not referenced by supplier invoice', function () {
    $admin = referentialIntegrityAdmin();
    $supplier = referentialIntegritySupplier();

    $deliveryNote = DeliveryNote::query()->create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    $this->actingAs($admin)
        ->delete(route('delivery-notes.destroy', $deliveryNote))
        ->assertRedirect(route('delivery-notes.index'));

    expect(DeliveryNote::query()->whereKey($deliveryNote->id)->exists())->toBeFalse();
});

test('user deletion is blocked when supplier invoices reference the user', function () {
    $adminA = referentialIntegrityAdmin();
    $adminB = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $author = User::factory()->create([
        'role' => User::ROLE_GESTIONNAIRE,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $supplier = referentialIntegritySupplier();
    referentialIntegrityInvoice($supplier, $author);

    $this->actingAs($adminB)
        ->delete(route('admin.users.destroy', $author))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('error');

    expect(User::query()->whereKey($author->id)->exists())->toBeTrue();
});

test('user deletion succeeds when no supplier invoices reference the user', function () {
    $adminA = referentialIntegrityAdmin();
    $adminB = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $target = User::factory()->create([
        'role' => User::ROLE_GESTIONNAIRE,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($adminB)
        ->delete(route('admin.users.destroy', $target))
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($target->id)->exists())->toBeFalse();
});

test('supplier deletion without permission is forbidden', function () {
    (new PermissionSeeder())->run();

    $vendeur = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $vendeur->permissions()->sync(
        Permission::query()->whereIn('name', ['suppliers.view'])->pluck('id'),
    );

    $supplier = referentialIntegritySupplier();

    $this->actingAs($vendeur)
        ->delete(route('suppliers.destroy', $supplier))
        ->assertForbidden();

    expect(Supplier::query()->whereKey($supplier->id)->exists())->toBeTrue();
});
