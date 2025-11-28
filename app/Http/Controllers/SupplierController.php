<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Exports\SuppliersExport;
use Maatwebsite\Excel\Facades\Excel;
use Dompdf\Dompdf;
use Dompdf\Options;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'suppliers', 'view');
        
        $query = Supplier::query();

        // Recherche par nom, contact_person, email ou téléphone
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        // Filtrer par statut
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $suppliers = $query->orderBy('name')->paginate(15);

        // Ajouter les attributs calculés pour chaque fournisseur
        $suppliers->getCollection()->transform(function ($supplier) {
            $supplier->setAttribute('status_label', $supplier->status_label);
            return $supplier;
        });

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'suppliers', 'create');
        
        return Inertia::render('Suppliers/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'suppliers', 'create');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:suppliers',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'tax_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        Supplier::create($validated);

        return redirect()->route('suppliers.index')
            ->with('success', 'Fournisseur créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Supplier $supplier)
    {
        $this->checkPermission($request, 'suppliers', 'view');
        
        // Ajouter les attributs calculés
        $supplier->setAttribute('status_label', $supplier->status_label);

        return Inertia::render('Suppliers/Show', [
            'supplier' => $supplier,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Supplier $supplier)
    {
        $this->checkPermission($request, 'suppliers', 'edit');
        
        return Inertia::render('Suppliers/Edit', [
            'supplier' => $supplier,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Supplier $supplier)
    {
        $this->checkPermission($request, 'suppliers', 'update');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:suppliers,email,' . $supplier->id,
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'tax_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        $supplier->update($validated);

        return redirect()->route('suppliers.index')
            ->with('success', 'Fournisseur mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Supplier $supplier)
    {
        $this->checkPermission($request, 'suppliers', 'delete');
        
        $supplier->delete();

        return redirect()->route('suppliers.index')
            ->with('success', 'Fournisseur supprimé avec succès.');
    }

    /**
     * Export suppliers to Excel
     */
    public function exportExcel(Request $request)
    {
        $this->checkPermission($request, 'suppliers', 'export');
        
        $search = $request->get('search');
        $status = $request->get('status');
        $filename = 'fournisseurs_' . date('Y-m-d_His') . '.xlsx';
        
        return Excel::download(new SuppliersExport($search, $status), $filename);
    }

    /**
     * Export suppliers to PDF
     */
    public function exportPdf(Request $request)
    {
        $this->checkPermission($request, 'suppliers', 'export');
        
        try {
            $query = Supplier::query();

            // Appliquer les mêmes filtres que la page index
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('mobile', 'like', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $suppliers = $query->orderBy('name')->get();
            $company = Company::getInstance();

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isPhpEnabled', true);
            $options->set('chroot', base_path());

            $dompdf = new Dompdf($options);
            $html = view('exports.suppliers-pdf', compact('suppliers', 'company'))->render();
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            $pdfContent = $dompdf->output();
            $filename = 'fournisseurs_' . date('Y-m-d_His') . '.pdf';

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Content-Length', strlen($pdfContent))
                ->header('Cache-Control', 'private, max-age=0, must-revalidate')
                ->header('Pragma', 'public');
        } catch (\Exception $e) {
            abort(500, 'Erreur lors de la génération du PDF: ' . $e->getMessage());
        }
    }
}
