<?php

namespace App\Enums;

enum PaymentTransactionStatus: string
{
    case Posted = 'posted';

    case Voided = 'voided';
}
