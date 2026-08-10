<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ActivityLogger;
use App\Services\CompanyLogoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyLogoService $logoService,
    ) {}

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

        ActivityLogger::logUpdate('Entreprise', $company);

        return redirect()->route('company.edit')
            ->with('success', 'Informations de l\'entreprise mises à jour avec succès.');
    }

    /**
     * Importer ou remplacer le logo de l'entreprise
     */
    public function uploadLogo(Request $request)
    {
        $this->checkPermission($request, 'company', 'update');

        $request->validate([
            'logo' => 'required|file|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        $company = Company::getInstance();
        $hadLogo = (bool) $company->logo_path;

        $this->logoService->store($company, $request->file('logo'));

        if ($hadLogo) {
            ActivityLogger::logCompanyLogoReplace($company);
        } else {
            ActivityLogger::logCompanyLogoAdd($company);
        }

        return redirect()->route('company.edit')
            ->with('success', $hadLogo ? 'Logo remplacé avec succès.' : 'Logo importé avec succès.');
    }

    /**
     * Supprimer le logo de l'entreprise
     */
    public function deleteLogo(Request $request)
    {
        $this->checkPermission($request, 'company', 'update');

        $company = Company::getInstance();

        if (! $company->logo_path) {
            return redirect()->route('company.edit')
                ->with('info', 'Aucun logo à supprimer.');
        }

        $this->logoService->delete($company);
        ActivityLogger::logCompanyLogoDelete($company);

        return redirect()->route('company.edit')
            ->with('success', 'Logo supprimé avec succès.');
    }
}
