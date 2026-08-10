<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
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
    ];

    protected $appends = [
        'logo_url',
    ];

    protected function logoUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->logo_path) {
                return null;
            }

            if (Storage::disk('media')->exists($this->logo_path)) {
                return '/storage/' . ltrim($this->logo_path, '/');
            }

            if (Storage::disk('public')->exists($this->logo_path)) {
                return Storage::disk('public')->url($this->logo_path);
            }

            return null;
        });
    }

    /**
     * Chemin absolu du logo pour DomPDF (chroot = base_path()).
     */
    public function getLogoAbsolutePathAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        if (Storage::disk('media')->exists($this->logo_path)) {
            return Storage::disk('media')->path($this->logo_path);
        }

        if (Storage::disk('public')->exists($this->logo_path)) {
            return Storage::disk('public')->path($this->logo_path);
        }

        return null;
    }

    /**
     * Récupérer l'instance unique de l'entreprise (singleton)
     */
    public static function getInstance()
    {
        $company = self::first();
        
        if (!$company) {
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
}
