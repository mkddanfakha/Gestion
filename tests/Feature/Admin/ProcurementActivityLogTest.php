<?php

use App\Models\ActivityLog;
use App\Models\DeliveryNote;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;

test('supplier activities are logged', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $supplier = Supplier::create([
        'name' => 'Fournisseur Test',
        'status' => 'active',
    ]);

    ActivityLogger::logCreate('Fournisseur', $supplier);

    $supplier->update(['name' => 'Fournisseur Modifié']);
    ActivityLogger::logUpdate('Fournisseur', $supplier);

    ActivityLogger::logDelete('Fournisseur', $supplier);
    $supplier->delete();

    expect(ActivityLog::where('module', 'Fournisseur')->count())->toBe(3);
});

test('purchase order activities are logged', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $supplier = Supplier::create([
        'name' => 'Fournisseur BC',
        'status' => 'active',
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'order_date' => now()->toDateString(),
        'status' => 'draft',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    ActivityLogger::logCreate('Bon de commande', $purchaseOrder);

    $purchaseOrder->update(['status' => 'confirmed']);
    ActivityLogger::logValidate('Bon de commande', $purchaseOrder);

    ActivityLogger::logDelete('Bon de commande', $purchaseOrder);
    $purchaseOrder->delete();

    expect(ActivityLog::where('module', 'Bon de commande')->count())->toBe(3);
});

test('delivery note activities are logged', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $supplier = Supplier::create([
        'name' => 'Fournisseur BL',
        'status' => 'active',
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'order_date' => now()->toDateString(),
        'status' => 'draft',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    $deliveryNote = DeliveryNote::create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'purchase_order_id' => $purchaseOrder->id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 1000,
    ]);

    ActivityLogger::logCreate('Bon de livraison', $deliveryNote);

    $deliveryNote->update(['notes' => 'Livraison partielle']);
    ActivityLogger::logUpdate('Bon de livraison', $deliveryNote);

    $deliveryNote->update(['status' => 'cancelled']);
    ActivityLogger::logCancel('Bon de livraison', $deliveryNote);

    ActivityLogger::logDelete('Bon de livraison', $deliveryNote);
    $deliveryNote->delete();

    expect(ActivityLog::where('module', 'Bon de livraison')->count())->toBe(4);
});
