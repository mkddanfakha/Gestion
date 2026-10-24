<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasAttachments;
    protected $fillable = [
        'name',
        'contact_person',
        'email',
        'phone',
        'mobile',
        'address',
        'city',
        'country',
        'tax_id',
        'notes',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    /**
     * Obtenir le libellé du statut
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->status === 'active' ? 'Actif' : 'Inactif';
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
