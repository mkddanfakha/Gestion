<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
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
}
