<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission($request, 'expenses', 'view');
        
        $expenses = Expense::with('user')
            ->orderBy('expense_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Ajouter les attributs calculés pour chaque dépense
        $expenses->getCollection()->transform(function ($expense) {
            $expense->setAttribute('category_label', $expense->category_label);
            $expense->setAttribute('payment_method_label', $expense->payment_method_label);
            return $expense;
        });

        // Calculer les statistiques sur toutes les dépenses (pas seulement la page actuelle)
        $now = now();
        
        // Dépenses du mois en cours
        $monthlyExpenses = Expense::whereYear('expense_date', $now->year)
            ->whereMonth('expense_date', $now->month)
            ->sum('amount');
        
        // Dépenses de la semaine en cours (du lundi au dimanche)
        $startOfWeek = $now->copy()->startOfWeek(); // Lundi de la semaine
        $endOfWeek = $now->copy()->endOfWeek(); // Dimanche de la semaine
        
        $weeklyExpenses = Expense::whereBetween('expense_date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')])
            ->sum('amount');
        
        // Total de toutes les dépenses
        $totalExpenses = Expense::sum('amount');

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
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
        
        return Inertia::render('Expenses/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission($request, 'expenses', 'create');
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|in:fournitures,equipement,marketing,transport,formation,maintenance,utilities,autres',
            'payment_method' => 'required|in:cash,bank_transfer,credit_card,mobile_money,orange_money,wave,check',
            'expense_date' => 'required|date',
            'receipt_number' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Générer automatiquement le numéro de dépense
        $validated['expense_number'] = Expense::generateExpenseNumber();
        $validated['user_id'] = auth()->id();

        Expense::create($validated);

        return redirect()->route('expenses.index')
            ->with('success', 'Dépense créée avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'view');
        
        $expense->load('user');

        // Ajouter les attributs calculés
        $expense->setAttribute('category_label', $expense->category_label);
        $expense->setAttribute('payment_method_label', $expense->payment_method_label);

        return Inertia::render('Expenses/Show', [
            'expense' => $expense,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'edit');
        
        return Inertia::render('Expenses/Edit', [
            'expense' => $expense,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'update');
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|in:fournitures,equipement,marketing,transport,formation,maintenance,utilities,autres',
            'payment_method' => 'required|in:cash,bank_transfer,credit_card,mobile_money,orange_money,wave,check',
            'expense_date' => 'required|date',
            'receipt_number' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $expense->update($validated);

        return redirect()->route('expenses.index')
            ->with('success', 'Dépense modifiée avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Expense $expense)
    {
        $this->checkPermission($request, 'expenses', 'delete');
        
        $expense->delete();

        return redirect()->route('expenses.index')
            ->with('success', 'Dépense supprimée avec succès.');
    }
}
