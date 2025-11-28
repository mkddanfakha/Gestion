<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
