<?php

namespace App\Enums;

/**
 * Identifiants des permissions MKD-Pro (V1).
 *
 * Source de vérité des noms de permissions (canoniques et legacy) en catalogue.
 */
enum PermissionName: string
{
    // backups
    case BackupsView = 'backups.view';
    case BackupsCreate = 'backups.create';
    case BackupsDownload = 'backups.download';
    case BackupsDelete = 'backups.delete';
    case BackupsRestore = 'backups.restore';

    // categories
    case CategoriesView = 'categories.view';
    case CategoriesCreate = 'categories.create';
    case CategoriesEdit = 'categories.edit';
    case CategoriesUpdate = 'categories.update';
    case CategoriesDelete = 'categories.delete';

    // company
    case CompanyView = 'company.view';
    case CompanyEdit = 'company.edit';
    case CompanyUpdate = 'company.update';

    // customers
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersEdit = 'customers.edit';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersExport = 'customers.export';

    // dashboard
    case DashboardView = 'dashboard.view';

    // delivery-notes
    case DeliveryNotesView = 'delivery-notes.view';
    case DeliveryNotesCreate = 'delivery-notes.create';
    case DeliveryNotesEdit = 'delivery-notes.edit';
    case DeliveryNotesUpdate = 'delivery-notes.update';
    case DeliveryNotesDelete = 'delivery-notes.delete';
    case DeliveryNotesValidate = 'delivery-notes.validate';
    case DeliveryNotesDownload = 'delivery-notes.download';
    case DeliveryNotesPrint = 'delivery-notes.print';

    // expenses
    case ExpensesView = 'expenses.view';
    case ExpensesCreate = 'expenses.create';
    case ExpensesEdit = 'expenses.edit';
    case ExpensesUpdate = 'expenses.update';
    case ExpensesDelete = 'expenses.delete';

    // inventory
    case InventoryView = 'inventory.view';
    case InventoryCreate = 'inventory.create';
    case InventoryCount = 'inventory.count';
    case InventorySubmit = 'inventory.submit';
    case InventoryReview = 'inventory.review';
    case InventoryReopen = 'inventory.reopen';
    case InventoryValidate = 'inventory.validate';
    case InventoryApply = 'inventory.apply';
    case InventoryCancel = 'inventory.cancel';
    case InventoryClose = 'inventory.close';
    case InventoryExport = 'inventory.export';

    // products
    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsEdit = 'products.edit';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    // purchase-orders
    case PurchaseOrdersView = 'purchase-orders.view';
    case PurchaseOrdersCreate = 'purchase-orders.create';
    case PurchaseOrdersEdit = 'purchase-orders.edit';
    case PurchaseOrdersUpdate = 'purchase-orders.update';
    case PurchaseOrdersDelete = 'purchase-orders.delete';
    case PurchaseOrdersDownload = 'purchase-orders.download';
    case PurchaseOrdersPrint = 'purchase-orders.print';

    // quotes
    case QuotesView = 'quotes.view';
    case QuotesCreate = 'quotes.create';
    case QuotesEdit = 'quotes.edit';
    case QuotesUpdate = 'quotes.update';
    case QuotesDelete = 'quotes.delete';
    case QuotesDownload = 'quotes.download';
    case QuotesPrint = 'quotes.print';

    // sales
    case SalesView = 'sales.view';
    case SalesCreate = 'sales.create';
    case SalesEdit = 'sales.edit';
    case SalesUpdate = 'sales.update';
    case SalesDelete = 'sales.delete';
    case SalesInvoice = 'sales.invoice';

    // suppliers
    case SuppliersView = 'suppliers.view';
    case SuppliersCreate = 'suppliers.create';
    case SuppliersEdit = 'suppliers.edit';
    case SuppliersUpdate = 'suppliers.update';
    case SuppliersDelete = 'suppliers.delete';
    case SuppliersExport = 'suppliers.export';

    // user-activities (journal commercial par utilisateur)
    case UserActivitiesView = 'user-activities.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom($name);
    }
}
