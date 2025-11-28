<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Exports\CustomersExport;
use Maatwebsite\Excel\Facades\Excel;
use Dompdf\Dompdf;
use Dompdf\Options;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'customers', 'view');
        
        $query = Customer::query();

        // Recherche par nom ou email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->withCount('sales')->orderBy('name')->paginate(15);

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'customers', 'create');
        
        return Inertia::render('Customers/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'customers', 'create');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',
            'credit_limit' => 'required|numeric|min:0.01',
            'is_active' => 'boolean',
        ]);

        Customer::create($validated);

        return redirect()->route('customers.index')
            ->with('success', 'Client créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'view');
        
        $customer->loadCount('sales');
        $customer->load(['sales' => function($query) {
            $query->orderBy('created_at', 'desc')->limit(10);
        }]);
        
        return Inertia::render('Customers/Show', [
            'customer' => $customer,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'edit');
        
        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'update');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',
            'credit_limit' => 'required|numeric|min:0.01',
            'is_active' => 'boolean',
        ]);

        $customer->update($validated);

        return redirect()->route('customers.index')
            ->with('success', 'Client mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'delete');
        
        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', 'Client supprimé avec succès.');
    }

    /**
     * Export customers to Excel
     */
    public function exportExcel(Request $request)
    {
        $this->checkPermission($request, 'customers', 'export');
        
        $search = $request->get('search');
        $filename = 'clients_' . date('Y-m-d_His') . '.xlsx';
        
        return Excel::download(new CustomersExport($search), $filename);
    }

    /**
     * Export customers to PDF
     */
    public function exportPdf(Request $request)
    {
        $this->checkPermission($request, 'customers', 'export');
        
        try {
            $query = Customer::query()->withCount('sales');

            // Appliquer les mêmes filtres que la page index
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $customers = $query->orderBy('name')->get();
            $company = Company::getInstance();

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isPhpEnabled', true);
            $options->set('chroot', base_path());

            $dompdf = new Dompdf($options);
            $html = view('exports.customers-pdf', compact('customers', 'company'))->render();
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            $pdfContent = $dompdf->output();
            $filename = 'clients_' . date('Y-m-d_His') . '.pdf';

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
