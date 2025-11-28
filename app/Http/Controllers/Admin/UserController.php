<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Obtenir les IDs des permissions pour le rôle vendeur
     */
    private function getVendeurPermissionIds(): array
    {
        $permissionNames = [
            // Permissions pour les ventes
            'sales.create',
            'sales.edit',
            'sales.update',
            'sales.delete',
            'sales.view',
            'sales.invoice', // Télécharger/Imprimer les factures
            // Permissions pour les devis (toutes les permissions)
            'quotes.create',
            'quotes.edit',
            'quotes.update',
            'quotes.delete',
            'quotes.view',
            'quotes.download',
            'quotes.print',
            // Permissions pour les produits (lecture seule)
            'products.view',
            // Permissions pour les clients
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.update',
        ];

        return Permission::whereIn('name', $permissionNames)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Obtenir les IDs des permissions pour le rôle gestionnaire
     */
    private function getGestionnairePermissionIds(): array
    {
        $permissionNames = [
            // Permissions pour le dashboard
            'dashboard.view',
            // Permissions pour les produits (toutes les permissions)
            'products.view',
            'products.create',
            'products.edit',
            'products.update',
            'products.delete',
            // Permissions pour les catégories (toutes les permissions)
            'categories.view',
            'categories.create',
            'categories.edit',
            'categories.update',
            'categories.delete',
            // Permissions pour les devis (toutes les permissions)
            'quotes.view',
            'quotes.create',
            'quotes.edit',
            'quotes.update',
            'quotes.delete',
            'quotes.download',
            'quotes.print',
            // Permissions pour les dépenses (toutes les permissions)
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'expenses.update',
            'expenses.delete',
            // Permissions pour les fournisseurs (toutes les permissions)
            'suppliers.view',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.update',
            'suppliers.delete',
            'suppliers.export',
            // Permissions pour les bons de commande (toutes les permissions)
            'purchase-orders.view',
            'purchase-orders.create',
            'purchase-orders.edit',
            'purchase-orders.update',
            'purchase-orders.delete',
            'purchase-orders.download',
            'purchase-orders.print',
            // Permissions pour les bons de livraison (toutes les permissions)
            'delivery-notes.view',
            'delivery-notes.create',
            'delivery-notes.edit',
            'delivery-notes.update',
            'delivery-notes.delete',
            'delivery-notes.validate',
            'delivery-notes.download',
            'delivery-notes.print',
            'delivery-notes.invoice',
        ];

        return Permission::whereIn('name', $permissionNames)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::with('permissions')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Récupérer toutes les permissions disponibles, groupées par ressource
        $allPermissions = Permission::orderBy('resource')->orderBy('action')->get();
        $permissionsByResource = $allPermissions->groupBy('resource');
        
        return Inertia::render('Admin/Users/Create', [
            'permissionsByResource' => $permissionsByResource->map(function ($permissions) {
                return $permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'action' => $permission->action,
                        'description' => $permission->description,
                    ];
                });
            }),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:admin,user,vendeur,gestionnaire',
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Attacher les permissions selon le rôle
        if ($user->role === 'admin') {
            // Les admins ont toutes les permissions par défaut, pas besoin d'en assigner
            $user->permissions()->sync([]);
        } elseif ($user->role === 'vendeur') {
            // Assigner automatiquement les permissions du vendeur
            $vendeurPermissionIds = $this->getVendeurPermissionIds();
            $user->permissions()->sync($vendeurPermissionIds);
        } elseif ($user->role === 'gestionnaire') {
            // Assigner automatiquement les permissions du gestionnaire
            $gestionnairePermissionIds = $this->getGestionnairePermissionIds();
            $user->permissions()->sync($gestionnairePermissionIds);
        } elseif (isset($validated['permissions'])) {
            // Pour les autres rôles, utiliser les permissions fournies
            $user->permissions()->attach($validated['permissions']);
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'Utilisateur créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return Inertia::render('Admin/Users/Show', [
            'user' => $user,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        $user->load('permissions');
        
        // Récupérer toutes les permissions disponibles, groupées par ressource
        $allPermissions = Permission::orderBy('resource')->orderBy('action')->get();
        $permissionsByResource = $allPermissions->groupBy('resource');
        
        // Récupérer les IDs des permissions de l'utilisateur
        $userPermissionIds = $user->permissions->pluck('id')->toArray();
        
        return Inertia::render('Admin/Users/Edit', [
            'user' => $user,
            'permissionsByResource' => $permissionsByResource->map(function ($permissions) {
                return $permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'action' => $permission->action,
                        'description' => $permission->description,
                    ];
                });
            }),
            'userPermissionIds' => $userPermissionIds,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:admin,user,vendeur,gestionnaire',
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->is_active = $validated['is_active'] ?? true;

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        // Synchroniser les permissions selon le rôle
        if ($user->role === 'admin') {
            // Les admins ont toutes les permissions par défaut, pas besoin d'en assigner
            $user->permissions()->sync([]);
        } elseif ($user->role === 'vendeur') {
            // Assigner automatiquement les permissions du vendeur
            $vendeurPermissionIds = $this->getVendeurPermissionIds();
            $user->permissions()->sync($vendeurPermissionIds);
        } elseif ($user->role === 'gestionnaire') {
            // Assigner automatiquement les permissions du gestionnaire
            $gestionnairePermissionIds = $this->getGestionnairePermissionIds();
            $user->permissions()->sync($gestionnairePermissionIds);
        } elseif (isset($validated['permissions'])) {
            // Pour les autres rôles, utiliser les permissions fournies
            $user->permissions()->sync($validated['permissions']);
        } else {
            // Si aucun rôle spécial et pas de permissions fournies, supprimer toutes les permissions
            $user->permissions()->sync([]);
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'Utilisateur mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();
        
        // Vérifier que l'utilisateur actuel est administrateur
        if (!$currentUser || !$currentUser->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès refusé. Seuls les administrateurs peuvent supprimer des utilisateurs.'], 403);
            }
            return redirect()->route('admin.users.index')
                ->with('error', 'Accès refusé. Seuls les administrateurs peuvent supprimer des utilisateurs.');
        }
        
        // Empêcher la suppression de l'utilisateur actuel (même pour les admins)
        if ($user->id === $currentUser->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 403);
            }
            return redirect()->route('admin.users.index')
                ->with('error', 'Vous ne pouvez pas supprimer votre propre compte. Un autre administrateur doit le faire.');
        }

        $user->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Utilisateur supprimé avec succès.'], 200);
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'Utilisateur supprimé avec succès.');
    }
}
