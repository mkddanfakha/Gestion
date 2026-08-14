<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ActivityLogger;
use App\Services\CompanyAssetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyAssetService $assetService,
    ) {}

    public function edit(Request $request)
    {
        $this->checkPermission($request, 'company', 'view');

        $company = Company::getInstance();

        return Inertia::render('Company/Edit', [
            'company' => $company,
        ]);
    }

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
            'print_signature_on_invoice' => 'boolean',
            'print_stamp_on_invoice' => 'boolean',
            'print_signature_on_quote' => 'boolean',
            'print_stamp_on_quote' => 'boolean',
            'print_signature_on_purchase_order' => 'boolean',
            'print_stamp_on_purchase_order' => 'boolean',
            'print_signature_on_delivery_note' => 'boolean',
            'print_stamp_on_delivery_note' => 'boolean',
        ]);

        $company = Company::getInstance();
        $printPreferenceKeys = [
            'print_signature_on_invoice',
            'print_stamp_on_invoice',
            'print_signature_on_quote',
            'print_stamp_on_quote',
            'print_signature_on_purchase_order',
            'print_stamp_on_purchase_order',
            'print_signature_on_delivery_note',
            'print_stamp_on_delivery_note',
        ];

        $previousPrintPreferences = $company->only($printPreferenceKeys);
        $company->update($validated);
        $newPrintPreferences = $company->only($printPreferenceKeys);

        ActivityLogger::logUpdate('Entreprise', $company);

        if ($previousPrintPreferences !== $newPrintPreferences) {
            ActivityLogger::logCompanyPrintPreferencesUpdate($company);
        }

        return redirect()->route('company.edit')
            ->with('success', 'Informations de l\'entreprise mises à jour avec succès.');
    }

    public function uploadLogo(Request $request)
    {
        return $this->uploadAsset($request, CompanyAssetService::TYPE_LOGO, 'logo');
    }

    public function deleteLogo(Request $request)
    {
        return $this->deleteAsset($request, CompanyAssetService::TYPE_LOGO, 'logo', 'logo');
    }

    public function uploadSignature(Request $request)
    {
        return $this->uploadAsset($request, CompanyAssetService::TYPE_SIGNATURE, 'signature');
    }

    public function deleteSignature(Request $request)
    {
        return $this->deleteAsset($request, CompanyAssetService::TYPE_SIGNATURE, 'signature', 'signature');
    }

    public function uploadStamp(Request $request)
    {
        return $this->uploadAsset($request, CompanyAssetService::TYPE_STAMP, 'stamp');
    }

    public function deleteStamp(Request $request)
    {
        return $this->deleteAsset($request, CompanyAssetService::TYPE_STAMP, 'stamp', 'cachet');
    }

    private function uploadAsset(Request $request, string $type, string $fieldName)
    {
        $this->checkPermission($request, 'company', 'update');

        $request->validate([
            $fieldName => 'required|file|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        $company = Company::getInstance();
        $pathField = match ($type) {
            CompanyAssetService::TYPE_LOGO => 'logo_path',
            CompanyAssetService::TYPE_SIGNATURE => 'signature_path',
            CompanyAssetService::TYPE_STAMP => 'stamp_path',
            default => null,
        };

        $hadAsset = $pathField ? (bool) $company->{$pathField} : false;

        $this->assetService->store($company, $request->file($fieldName), $type);

        match ($type) {
            CompanyAssetService::TYPE_LOGO => $hadAsset
                ? ActivityLogger::logCompanyLogoReplace($company)
                : ActivityLogger::logCompanyLogoAdd($company),
            CompanyAssetService::TYPE_SIGNATURE => $hadAsset
                ? ActivityLogger::logCompanySignatureReplace($company)
                : ActivityLogger::logCompanySignatureAdd($company),
            CompanyAssetService::TYPE_STAMP => $hadAsset
                ? ActivityLogger::logCompanyStampReplace($company)
                : ActivityLogger::logCompanyStampAdd($company),
            default => null,
        };

        $messages = [
            CompanyAssetService::TYPE_LOGO => $hadAsset ? 'Logo remplacé avec succès.' : 'Logo importé avec succès.',
            CompanyAssetService::TYPE_SIGNATURE => $hadAsset ? 'Signature remplacée avec succès.' : 'Signature importée avec succès.',
            CompanyAssetService::TYPE_STAMP => $hadAsset ? 'Cachet remplacé avec succès.' : 'Cachet importé avec succès.',
        ];

        return redirect()->route('company.edit')
            ->with('success', $messages[$type] ?? 'Fichier enregistré avec succès.');
    }

    private function deleteAsset(Request $request, string $type, string $fieldName, string $label)
    {
        $this->checkPermission($request, 'company', 'update');

        $company = Company::getInstance();
        $pathField = match ($type) {
            CompanyAssetService::TYPE_LOGO => 'logo_path',
            CompanyAssetService::TYPE_SIGNATURE => 'signature_path',
            CompanyAssetService::TYPE_STAMP => 'stamp_path',
            default => null,
        };

        if (! $pathField || ! $company->{$pathField}) {
            return redirect()->route('company.edit')
                ->with('info', "Aucun {$label} à supprimer.");
        }

        $this->assetService->delete($company, $type);

        match ($type) {
            CompanyAssetService::TYPE_LOGO => ActivityLogger::logCompanyLogoDelete($company),
            CompanyAssetService::TYPE_SIGNATURE => ActivityLogger::logCompanySignatureDelete($company),
            CompanyAssetService::TYPE_STAMP => ActivityLogger::logCompanyStampDelete($company),
            default => null,
        };

        return redirect()->route('company.edit')
            ->with('success', ucfirst($label) . ' supprimé avec succès.');
    }
}
