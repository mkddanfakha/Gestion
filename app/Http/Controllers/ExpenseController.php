<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class ExpenseController extends Controller
{
    public function __construct(
        protected AttachmentService $attachmentService,
    ) {}

    /**
     * Empêcher un gestionnaire d'accéder aux dépenses des autres utilisateurs.
     */
    protected function authorizeExpenseAccess(Request $request, Expense $expense): void
    {
        $user = $request->user();

        if ($user?->isGestionnaire() && $expense->user_id !== $user->id) {
            abort(403, 'Accès refusé. Vous ne pouvez accéder qu\'à vos propres dépenses.');
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'expenses', 'view');

        $query = Expense::query()->visibleTo($request->user());
        
        $expenses = (clone $query)
            ->with(['user', 'supplier:id,name'])
            ->withCount('attachments')
            ->orderBy('expense_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Ajouter les attributs calculés pour chaque dépense
        $expenses->getCollection()->transform(function ($expense) {
            $expense->setAttribute('category_label', $expense->category_label);
            $expense->setAttribute('payment_method_label', $expense->payment_method_label);
            return $expense;
        });

        // Calculer les statistiques sur les dépenses visibles
        $now = now();
        
        // Dépenses du mois en cours
        $monthlyExpenses = (clone $query)
            ->whereYear('expense_date', $now->year)
            ->whereMonth('expense_date', $now->month)
            ->sum('amount');
        
        // Dépenses de la semaine en cours (du lundi au dimanche)
        $startOfWeek = $now->copy()->startOfWeek(); // Lundi de la semaine
        $endOfWeek = $now->copy()->endOfWeek(); // Dimanche de la semaine
        
        $weeklyExpenses = (clone $query)
            ->whereBetween('expense_date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')])
            ->sum('amount');
        
        // Total des dépenses visibles
        $totalExpenses = (clone $query)->sum('amount');

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'statistics' => [
                'total' => $totalExpenses,
                'monthly' => $monthlyExpenses,
                'weekly' => $weeklyExpenses,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->checkPermission($request, 'expenses', 'create');
        
        return Inertia::render('Expenses/Create', [
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'email', 'phone', 'mobile']),
            'attachmentConfig' => $this->attachmentConfig(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'expenses', 'create');

        $this->normalizeSupplierId($request);
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|in:fournitures,equipement,marketing,transport,formation,maintenance,utilities,autres',
            'payment_method' => 'required|in:cash,bank_transfer,credit_card,mobile_money,orange_money,wave,check',
            'expense_date' => 'required|date',
            'receipt_number' => 'nullable|string|max:255',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'notes' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:' . config('attachments.max_files', 10),
            'attachments.*' => 'file',
        ]);

        unset($validated['attachments']);

        $this->syncVendorFromSupplier($validated);

        // Générer automatiquement le numéro de dépense
        $validated['expense_number'] = Expense::generateExpenseNumber();
        $validated['user_id'] = auth()->id();

        $expense = Expense::create($validated);

        try {
            if ($request->hasFile('attachments')) {
                $this->attachmentService->addMany(
                    $expense,
                    $request->file('attachments'),
                    $request->user()
                );
            }
        } catch (InvalidArgumentException $e) {
            ActivityLogger::logDelete('Dépense', $expense);
            $expense->delete();

            return back()->withErrors(['attachments' => $e->getMessage()])->withInput();
        }

        ActivityLogger::logCreate('Dépense', $expense);

        return redirect()->route('expenses.index')
            ->with('success', 'Dépense créée avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'view');
        $this->authorizeExpenseAccess($request, $expense);
        
        $expense->load(['user', 'supplier:id,name,email,phone,mobile', 'attachments.uploadedBy']);

        // Ajouter les attributs calculés
        $expense->setAttribute('category_label', $expense->category_label);
        $expense->setAttribute('payment_method_label', $expense->payment_method_label);

        return Inertia::render('Expenses/Show', [
            'expense' => $expense,
            'canUpdateExpense' => $request->user()?->hasPermission('expenses', 'update') ?? false,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'edit');
        $this->authorizeExpenseAccess($request, $expense);

        $expense->load(['attachments.uploadedBy', 'supplier:id,name,email,phone,mobile']);

        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'email', 'phone', 'mobile']);
        if ($expense->supplier_id && !$suppliers->contains('id', $expense->supplier_id) && $expense->supplier) {
            $suppliers->push($expense->supplier);
        }
        
        return Inertia::render('Expenses/Edit', [
            'expense' => $expense,
            'suppliers' => $suppliers->sortBy('name')->values(),
            'attachmentConfig' => $this->attachmentConfig(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'update');
        $this->authorizeExpenseAccess($request, $expense);

        $this->normalizeSupplierId($request);
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|in:fournitures,equipement,marketing,transport,formation,maintenance,utilities,autres',
            'payment_method' => 'required|in:cash,bank_transfer,credit_card,mobile_money,orange_money,wave,check',
            'expense_date' => 'required|date',
            'receipt_number' => 'nullable|string|max:255',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'notes' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:' . config('attachments.max_files', 10),
            'attachments.*' => 'file',
        ]);

        unset($validated['attachments']);

        $this->syncVendorFromSupplier($validated, $expense);

        $expense->update($validated);

        try {
            if ($request->hasFile('attachments')) {
                $this->attachmentService->addMany(
                    $expense,
                    $request->file('attachments'),
                    $request->user()
                );
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['attachments' => $e->getMessage()])->withInput();
        }

        ActivityLogger::logUpdate('Dépense', $expense);

        return redirect()->route('expenses.index')
            ->with('success', 'Dépense modifiée avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'delete');
        $this->authorizeExpenseAccess($request, $expense);

        ActivityLogger::logDelete('Dépense', $expense);
        
        $expense->delete();

        return redirect()->route('expenses.index')
            ->with('success', 'Dépense supprimée avec succès.');
    }

    protected function attachmentConfig(): array
    {
        return [
            'maxFiles' => config('attachments.max_files', 10),
            'maxSizeKb' => config('attachments.max_size', 10240),
            'allowedExtensions' => config('attachments.allowed_extensions', []),
            'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
        ];
    }

    protected function normalizeSupplierId(Request $request): void
    {
        if ($request->has('supplier_id') && blank($request->input('supplier_id'))) {
            $request->merge(['supplier_id' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function syncVendorFromSupplier(array &$validated, ?Expense $expense = null): void
    {
        if (!array_key_exists('supplier_id', $validated)) {
            return;
        }

        if (!empty($validated['supplier_id'])) {
            $validated['vendor'] = Supplier::query()
                ->whereKey($validated['supplier_id'])
                ->value('name');

            return;
        }

        if ($expense === null || $expense->supplier_id !== null) {
            $validated['vendor'] = null;

            return;
        }

        unset($validated['vendor']);
    }
}
