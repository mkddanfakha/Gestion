<?php

namespace App\Enums;

enum PaymentTransactionOrigin: string
{
    case Recorded = 'recorded';

    case HistoricalBackfill = 'historical_backfill';
}
