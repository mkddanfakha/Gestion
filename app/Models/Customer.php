<?php

namespace App\Models;

use App\Services\CustomerIdentityService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    public const IDENTITY_TYPE_NATIONAL_ID = 'national_id';

    public const IDENTITY_TYPE_PASSPORT = 'passport';

    public const IDENTITY_TYPE_RESIDENCE_PERMIT = 'residence_permit';

    public const IDENTITY_TYPE_OTHER = 'other';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'nationality',
        'identity_document_type',
        'identity_document_number',
        'birthday',
        'address',
        'city',
        'postal_code',
        'country',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'birthday' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $service = app(CustomerIdentityService::class);

            if ($customer->identity_document_type && $customer->identity_document_number) {
                $customer->identity_document_number_normalized = $service->normalizeDocumentNumber(
                    $customer->identity_document_number,
                );
            } else {
                $customer->identity_document_type = null;
                $customer->identity_document_number = null;
                $customer->identity_document_number_normalized = null;
            }

            $customer->phone_normalized = $customer->phone
                ? $service->normalizePhone($customer->phone)
                : null;
        });
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
