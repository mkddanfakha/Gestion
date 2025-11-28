<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    /**
     * Afficher le formulaire d'édition des informations de l'entreprise
     */
    public function edit(Request $request)
    {
        $this->checkPermission($request, 'company', 'view');
        
        $company = Company::getInstance();
        
        return Inertia::render('Company/Edit', [
            'company' => $company,
        ]);
    }

    /**
     * Mettre à jour les informations de l'entreprise
     */
    public function update(Request $request)
    {
        $this->checkPermission($request, 'company', 'update');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone1' => 'nullable|string|max:50',
            'phone2' => 'nullable|string|max:50',
            'phone3' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'rc_number' => 'nullable|string|max:100',
            'ncc_number' => 'nullable|string|max:100',
        ]);

        $company = Company::getInstance();
        $company->update($validated);

        return redirect()->route('company.edit')
            ->with('success', 'Informations de l\'entreprise mises à jour avec succès.');
    }
}
