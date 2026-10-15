<?php

namespace App\Models;

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    public const PAYMENT_METHODS = [
        'cash',
        'card',
        'bank_transfer',
        'check',
        'orange_money',
        'wave',
    ];

    protected $fillable = [
        'payable_type',
        'payable_id',
        'amount',
        'paid_at',
        'payment_method',
        'reference',
        'note',
        'origin',
        'user_id',
        'status',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
        'payable_type' => PayableType::class,
        'status' => PaymentTransactionStatus::class,
        'origin' => PaymentTransactionOrigin::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isPosted(): bool
    {
        return $this->status === PaymentTransactionStatus::Posted;
    }
}
