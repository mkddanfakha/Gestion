<?php

namespace App\Enums;

enum PayableType: string
{
    case Sale = 'sale';

    case SupplierInvoice = 'supplier_invoice';
}
