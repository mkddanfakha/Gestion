<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\Countries;
use App\Services\ActivityLogger;
use App\Services\CustomerCrmService;
use App\Services\CustomerIdentityService;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Exports\CustomersExport;
use Maatwebsite\Excel\Facades\Excel;
use Dompdf\Dompdf;
use Dompdf\Options;

class CustomerController extends Controller
{
    public function __construct(
        private CustomerCrmService $customerCrmService,
        private CustomerIdentityService $customerIdentityService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'customers', 'view');
        
        $query = Customer::query();

        // Recherche par nom, email, téléphone ou pièce d'identité
        if ($request->filled('search')) {
            $this->customerIdentityService->applySearchFilter($query, (string) $request->search);
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

        try {
            $validated = $this->validateCustomerPayload($request);
            $customer = Customer::create($validated);
        } catch (ValidationException $exception) {
            return $this->respondValidationFailure($request, $exception);
        } catch (QueryException $exception) {
            if ($this->customerIdentityService->isUniqueConstraintViolation($exception)) {
                return $this->respondIdentityConflict($request, $request->all());
            }

            throw $exception;
        }

        ActivityLogger::logCreate('Client', $customer);

        if ($request->expectsJson()) {
            return response()->json($this->formatCustomerPayload($customer), 201);
        }

        return redirect()->route('customers.index')
            ->with('success', 'Client créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'view');

        $user = $request->user();
        $activeTab = $this->resolveActiveTab($request);

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'nationality' => $customer->nationality,
                'identity_document_type' => $customer->identity_document_type,
                'identity_document_type_label' => $this->customerIdentityService->typeLabel($customer->identity_document_type),
                'identity_document_number' => $customer->identity_document_number,
                'identity_document_number_masked' => $this->customerIdentityService->maskDocumentNumber($customer->identity_document_number),
                'birthday' => $customer->birthday?->toDateString(),
                'address' => $customer->address,
                'city' => $customer->city,
                'postal_code' => $customer->postal_code,
                'country' => $customer->country,
                'notes' => $customer->notes,
                'is_active' => $customer->is_active,
                'created_at' => $customer->created_at?->toIso8601String(),
            ],
            'crm' => $this->customerCrmService->buildSummary($customer, $user),
            'unpaidInvoices' => $this->customerCrmService->unpaidInvoices($customer, $user),
            'pendingQuotes' => $this->customerCrmService->pendingQuotes($customer),
            'activeTab' => $activeTab,
            'salesHistory' => $this->customerCrmService->paginateSales(
                $customer,
                $user,
                (int) $request->get('sales_page', 1),
            ),
            'paymentsHistory' => $this->customerCrmService->paginatePayments(
                $customer,
                $user,
                (int) $request->get('payments_page', 1),
            ),
            'invoicesHistory' => $this->customerCrmService->paginateInvoices(
                $customer,
                $user,
                (int) $request->get('invoices_page', 1),
            ),
            'quotesHistory' => $this->customerCrmService->paginateQuotes(
                $customer,
                (int) $request->get('quotes_page', 1),
            ),
            'activityHistory' => $this->customerCrmService->paginateActivity(
                $customer,
                (int) $request->get('activity_page', 1),
            ),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'edit');
        
        return Inertia::render('Customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'nationality' => $customer->nationality,
                'identity_document_type' => $customer->identity_document_type,
                'identity_document_number' => $customer->identity_document_number,
                'birthday' => $customer->birthday?->toDateString(),
                'address' => $customer->address,
                'city' => $customer->city,
                'postal_code' => $customer->postal_code,
                'country' => $customer->country,
                'notes' => $customer->notes,
                'is_active' => $customer->is_active,
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'update');

        try {
            $validated = $this->validateCustomerPayload($request, $customer);
            $customer->update($validated);
        } catch (ValidationException $exception) {
            return $this->respondValidationFailure($request, $exception, $customer);
        } catch (QueryException $exception) {
            if ($this->customerIdentityService->isUniqueConstraintViolation($exception)) {
                return $this->respondIdentityConflict($request, $request->all(), $customer);
            }

            throw $exception;
        }

        ActivityLogger::logUpdate('Client', $customer);

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Client mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $this->checkPermission($request, 'customers', 'delete');

        ActivityLogger::logDelete('Client', $customer);
        
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
                $this->customerIdentityService->applySearchFilter($query, (string) $request->search);
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

    /**
     * Recherche légère pour les autocomplétions (ventes, devis, etc.).
     */
    public function autocomplete(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $user->refresh();

        $canSearch = $user->hasPermission('sales', 'create')
            || $user->hasPermission('sales', 'edit')
            || $user->hasPermission('customers', 'view');

        if (!$canSearch) {
            abort(403);
        }

        $search = trim((string) $request->query('q', ''));

        $query = Customer::query()->orderBy('name')->limit(20);

        if ($search !== '') {
            $this->customerIdentityService->applySearchFilter($query, $search);
        }

        return response()->json(
            $query->get([
                'id',
                'name',
                'email',
                'phone',
                'identity_document_type',
                'identity_document_number',
            ])->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'nationality' => $customer->nationality,
                'identity_document_type' => $customer->identity_document_type,
                'identity_document_type_short' => $this->customerIdentityService->typeShortLabel($customer->identity_document_type),
                'identity_document_number_masked' => $this->customerIdentityService->maskDocumentNumber($customer->identity_document_number),
            ])->values(),
        );
    }

    public function checkDuplicates(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $user->refresh();

        $canCheck = $user->hasPermission('customers', 'create')
            || $user->hasPermission('customers', 'update')
            || $user->hasPermission('sales', 'create');

        if (!$canCheck) {
            abort(403);
        }

        $criteria = $this->customerIdentityService->parseDuplicateCheckCriteria($request->all());
        $excludeId = $criteria['exclude_id'] ?? null;
        unset($criteria['exclude_id']);

        if ($criteria === []) {
            return response()->json([
                'identity_available' => true,
                'identity_conflict' => null,
                'phone_match' => null,
                'email_match' => null,
                'similar_names' => [],
                'has_duplicates' => false,
                'matches' => [],
            ]);
        }

        $canViewCustomers = $user->hasPermission('customers', 'view');
        $analysis = $this->customerIdentityService->analyzeDuplicates($criteria, $excludeId);

        if (!$canViewCustomers) {
            $analysis = $this->sanitizeDuplicateAnalysis($analysis);
        }

        return response()->json($analysis);
    }

    public function potentialDuplicates(Request $request)
    {
        $this->checkPermission($request, 'customers', 'view');

        $user = $request->user();
        $canViewDetails = $user?->hasPermission('customers', 'view') ?? false;

        $groups = $this->customerIdentityService->findPotentialDuplicateGroups();

        return Inertia::render('Customers/PotentialDuplicates', [
            'groups' => $groups,
            'canViewCustomerDetails' => $canViewDetails,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCustomerPayload(Request $request, ?Customer $customer = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,' . ($customer?->id ?? 'NULL'),
            'phone' => 'nullable|string|max:20',
            'nationality' => ['nullable', Rule::in(Countries::codes())],
            'identity_document_type' => ['nullable', Rule::in(CustomerIdentityService::TYPE_VALUES)],
            'identity_document_number' => 'nullable|string|max:50',
            'birthday' => 'nullable|date|before_or_equal:today',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
        ]);

        if (($validated['birthday'] ?? '') === '') {
            $validated['birthday'] = null;
        }

        if (($validated['nationality'] ?? '') === '') {
            $validated['nationality'] = null;
        }

        if (($validated['identity_document_type'] ?? '') === '') {
            $validated['identity_document_type'] = null;
        }

        if (($validated['identity_document_number'] ?? '') === '') {
            $validated['identity_document_number'] = null;
        }

        return $this->customerIdentityService->prepareValidatedAttributes($validated, $customer);
    }

    private function respondValidationFailure(Request $request, ValidationException $exception, ?Customer $customer = null)
    {
        $existingCustomer = null;

        if ($request->filled('identity_document_type') && $request->filled('identity_document_number')) {
            $existingCustomer = $this->customerIdentityService->findIdentityConflict(
                (string) $request->input('identity_document_type'),
                (string) $request->input('identity_document_number'),
                $customer?->id ?? ($request->filled('exclude_id') ? $request->integer('exclude_id') : null),
            );
        }

        if ($request->expectsJson()) {
            $response = response()->json([
                'message' => collect($exception->errors())->flatten()->first()
                    ?? 'Impossible d\'enregistrer le client.',
                'errors' => $exception->errors(),
                'existing_customer' => $existingCustomer && $request->user()?->hasPermission('customers', 'view')
                    ? $this->customerIdentityService->formatCustomerMatch($existingCustomer)
                    : ($existingCustomer ? ['id' => $existingCustomer->id] : null),
            ], 422);

            return $response;
        }

        throw $exception;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respondIdentityConflict(Request $request, array $payload, ?Customer $customer = null)
    {
        $type = (string) ($payload['identity_document_type'] ?? '');
        $number = (string) ($payload['identity_document_number'] ?? '');
        $existingCustomer = $this->customerIdentityService->findIdentityConflict($type, $number, $customer?->id);

        $message = 'Ce numéro de pièce est déjà utilisé par un autre client.';
        $errors = ['identity_document_number' => [$message]];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => $errors,
                'existing_customer' => $existingCustomer && $request->user()?->hasPermission('customers', 'view')
                    ? $this->customerIdentityService->formatCustomerMatch($existingCustomer)
                    : ($existingCustomer ? ['id' => $existingCustomer->id] : null),
            ], 422);
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCustomerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'identity_document_type' => $customer->identity_document_type,
            'identity_document_type_short' => $this->customerIdentityService->typeShortLabel($customer->identity_document_type),
            'identity_document_number_masked' => $this->customerIdentityService->maskDocumentNumber($customer->identity_document_number),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function sanitizeDuplicateAnalysis(array $analysis): array
    {
        $sanitizeMatch = function (?array $match) {
            if (!$match) {
                return null;
            }

            return [
                'id' => $match['id'],
                'name' => $match['name'],
            ];
        };

        $analysis['identity_conflict'] = $sanitizeMatch($analysis['identity_conflict'] ?? null);
        $analysis['phone_match'] = $sanitizeMatch($analysis['phone_match'] ?? null);
        $analysis['email_match'] = $sanitizeMatch($analysis['email_match'] ?? null);
        $analysis['similar_names'] = collect($analysis['similar_names'] ?? [])
            ->map(fn (array $match) => $sanitizeMatch($match))
            ->filter()
            ->values()
            ->all();
        $analysis['matches'] = collect($analysis['matches'] ?? [])
            ->map(fn (array $match) => $sanitizeMatch($match))
            ->filter()
            ->values()
            ->all();

        return $analysis;
    }

    private function resolveActiveTab(Request $request): string
    {
        $allowedTabs = ['sales', 'payments', 'invoices', 'quotes', 'activity'];
        $tab = $request->get('tab');

        if (is_string($tab) && in_array($tab, $allowedTabs, true)) {
            return $tab;
        }

        $pageParamToTab = [
            'activity_page' => 'activity',
            'payments_page' => 'payments',
            'invoices_page' => 'invoices',
            'quotes_page' => 'quotes',
            'sales_page' => 'sales',
        ];

        foreach ($pageParamToTab as $pageParam => $inferredTab) {
            if ($request->has($pageParam) && (int) $request->get($pageParam) > 1) {
                return $inferredTab;
            }
        }

        return 'sales';
    }
}
