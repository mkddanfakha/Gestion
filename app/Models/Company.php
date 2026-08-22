<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    public const DOCUMENT_INVOICE = 'invoice';

    public const DOCUMENT_QUOTE = 'quote';

    public const DOCUMENT_PURCHASE_ORDER = 'purchase_order';

    public const DOCUMENT_DELIVERY_NOTE = 'delivery_note';

    protected $fillable = [
        'name',
        'tagline',
        'address',
        'phone1',
        'phone2',
        'phone3',
        'email',
        'website',
        'rc_number',
        'ncc_number',
        'logo_path',
        'signature_path',
        'stamp_path',
        'print_signature_on_invoice',
        'print_stamp_on_invoice',
        'print_signature_on_quote',
        'print_stamp_on_quote',
        'print_signature_on_purchase_order',
        'print_stamp_on_purchase_order',
        'print_signature_on_delivery_note',
        'print_stamp_on_delivery_note',
    ];

    protected $appends = [
        'logo_url',
        'signature_url',
        'stamp_url',
    ];

    protected static function booted(): void
    {
        static::created(function (Company $company): void {
            Store::ensureDefaultForCompany($company);
        });
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventorySessions(): HasMany
    {
        return $this->hasMany(InventorySession::class);
    }

    public function defaultStore(): HasOne
    {
        return $this->hasOne(Store::class)->where('is_default', true);
    }

    protected function casts(): array
    {
        return [
            'print_signature_on_invoice' => 'boolean',
            'print_stamp_on_invoice' => 'boolean',
            'print_signature_on_quote' => 'boolean',
            'print_stamp_on_quote' => 'boolean',
            'print_signature_on_purchase_order' => 'boolean',
            'print_stamp_on_purchase_order' => 'boolean',
            'print_signature_on_delivery_note' => 'boolean',
            'print_stamp_on_delivery_note' => 'boolean',
        ];
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->resolveAssetUrl($this->logo_path));
    }

    protected function signatureUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->resolveAssetUrl($this->signature_path));
    }

    protected function stampUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->resolveAssetUrl($this->stamp_path));
    }

    public function getLogoAbsolutePathAttribute(): ?string
    {
        return $this->resolveAssetAbsolutePath($this->logo_path);
    }

    public function getSignatureAbsolutePathAttribute(): ?string
    {
        return $this->resolveAssetAbsolutePath($this->signature_path);
    }

    public function getStampAbsolutePathAttribute(): ?string
    {
        return $this->resolveAssetAbsolutePath($this->stamp_path);
    }

    public function shouldPrintSignature(string $documentType): bool
    {
        return match ($documentType) {
            self::DOCUMENT_INVOICE => (bool) $this->print_signature_on_invoice,
            self::DOCUMENT_QUOTE => (bool) $this->print_signature_on_quote,
            self::DOCUMENT_PURCHASE_ORDER => (bool) $this->print_signature_on_purchase_order,
            self::DOCUMENT_DELIVERY_NOTE => (bool) $this->print_signature_on_delivery_note,
            default => false,
        };
    }

    public function shouldPrintStamp(string $documentType): bool
    {
        return match ($documentType) {
            self::DOCUMENT_INVOICE => (bool) $this->print_stamp_on_invoice,
            self::DOCUMENT_QUOTE => (bool) $this->print_stamp_on_quote,
            self::DOCUMENT_PURCHASE_ORDER => (bool) $this->print_stamp_on_purchase_order,
            self::DOCUMENT_DELIVERY_NOTE => (bool) $this->print_stamp_on_delivery_note,
            default => false,
        };
    }

    public static function getInstance()
    {
        $company = self::first();

        if (! $company) {
            $company = self::create([
                'name' => 'ENTREPRISE SARL',
                'tagline' => 'Votre partenaire de confiance',
                'address' => '123 Avenue de la République, Abidjan, Côte d\'Ivoire',
                'phone1' => '+225 27 22 44 55 66',
                'phone2' => null,
                'phone3' => null,
                'email' => 'contact@entreprise.ci',
                'rc_number' => 'CI-ABJ-2024-A-12345',
                'ncc_number' => '12345678X',
            ]);
        }

        return $company;
    }

    private function resolveAssetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Storage::disk('media')->exists($path)) {
            return '/storage/' . ltrim($path, '/');
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return null;
    }

    private function resolveAssetAbsolutePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Storage::disk('media')->exists($path)) {
            return Storage::disk('media')->path($path);
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        return null;
    }
}
