<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Inertia;

// Routes de broadcast pour l'authentification des canaux
Broadcast::routes(['middleware' => ['web', 'auth']]);

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Produits
    Route::resource('products', ProductController::class);
    Route::post('/products/generate-sku', [ProductController::class, 'generateSku'])->name('products.generate-sku');
    Route::post('/products/upload-image', [ProductController::class, 'uploadImage'])->name('products.upload-image');
    
    // Catégories
    Route::resource('categories', CategoryController::class);
    
    // Clients
    Route::resource('customers', CustomerController::class);
    Route::get('/customers/export/excel', [CustomerController::class, 'exportExcel'])->name('customers.export.excel');
    Route::get('/customers/export/pdf', [CustomerController::class, 'exportPdf'])->name('customers.export.pdf');
    
    // Ventes
    Route::resource('sales', SaleController::class);
    Route::get('/sales/{sale}/invoice/download', [SaleController::class, 'downloadInvoice'])->name('sales.invoice.download');
    Route::get('/sales/{sale}/invoice/print', [SaleController::class, 'printInvoice'])->name('sales.invoice.print');
    
    // Devis
    Route::resource('quotes', QuoteController::class);
    Route::get('/quotes/{quote}/download', [QuoteController::class, 'downloadQuote'])->name('quotes.download');
    Route::get('/quotes/{quote}/print', [QuoteController::class, 'printQuote'])->name('quotes.print');
    
    // Dépenses
    Route::resource('expenses', ExpenseController::class);
    
    // Fournisseurs
    Route::resource('suppliers', SupplierController::class);
    Route::get('/suppliers/export/excel', [SupplierController::class, 'exportExcel'])->name('suppliers.export.excel');
    Route::get('/suppliers/export/pdf', [SupplierController::class, 'exportPdf'])->name('suppliers.export.pdf');
    
    // Bons de commande
    Route::resource('purchase-orders', PurchaseOrderController::class);
    Route::get('/purchase-orders/{purchaseOrder}/download', [PurchaseOrderController::class, 'downloadPurchaseOrder'])->name('purchase-orders.download');
    Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'printPurchaseOrder'])->name('purchase-orders.print');
    
    // Bons de livraison
    // Routes spécifiques AVANT la route resource pour éviter les conflits
    Route::post('/delivery-notes/{deliveryNote}/validate', [DeliveryNoteController::class, 'validate'])
        ->middleware(EnsureUserIsAdmin::class)
        ->name('delivery-notes.validate');
    Route::get('/delivery-notes/{deliveryNote}/download', [DeliveryNoteController::class, 'downloadDeliveryNote'])->name('delivery-notes.download');
    Route::get('/delivery-notes/{deliveryNote}/print', [DeliveryNoteController::class, 'printDeliveryNote'])->name('delivery-notes.print');
    // Facture/BL fournisseur: upload/affichage/suppression (AVANT la route resource)
    // Utiliser des contraintes pour éviter les conflits avec la route resource
    Route::post('/delivery-notes/{deliveryNote}/invoice', [DeliveryNoteController::class, 'uploadInvoice'])
        ->where('deliveryNote', '[0-9]+')
        ->name('delivery-notes.invoice.upload');
    Route::get('/delivery-notes/{deliveryNote}/invoice', [DeliveryNoteController::class, 'showInvoice'])
        ->where('deliveryNote', '[0-9]+')
        ->name('delivery-notes.invoice.show');
    Route::delete('/delivery-notes/{deliveryNote}/invoice', [DeliveryNoteController::class, 'deleteInvoice'])
        ->where('deliveryNote', '[0-9]+')
        ->name('delivery-notes.invoice.delete');
    // Route resource en dernier avec exclusion de 'invoice' pour éviter les conflits
    Route::resource('delivery-notes', DeliveryNoteController::class)->except(['invoice']);
    
    // Informations de l'entreprise
    Route::get('/company', [CompanyController::class, 'edit'])->name('company.edit');
    Route::put('/company', [CompanyController::class, 'update'])->name('company.update');
    
           // Notifications
           Route::post('/notifications/mark-as-read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
           Route::post('/notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');
           Route::post('/notifications/test', [NotificationController::class, 'testNotification'])->name('notifications.test');
    
    // Administration - Routes protégées par le middleware admin
    Route::middleware([EnsureUserIsAdmin::class])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class);
        Route::resource('backups', \App\Http\Controllers\Admin\BackupController::class)->only(['index', 'store', 'destroy']);
        Route::get('/backups/{backup}/download', [\App\Http\Controllers\Admin\BackupController::class, 'download'])->name('backups.download');
        Route::post('/backups/{backup}/restore', [\App\Http\Controllers\Admin\BackupController::class, 'restore'])->name('backups.restore');
        Route::post('/backups/import', [\App\Http\Controllers\Admin\BackupController::class, 'import'])->name('backups.import');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
