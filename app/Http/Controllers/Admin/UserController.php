<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminProtectionService;
use App\Auth\AssignablePermissionResolver;
use App\Auth\AuthorizationService;
use App\Auth\LastAdminProtectionException;
use App\Auth\RbacAuditService;
use App\Auth\RolePresets;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(
        private readonly AdminProtectionService $adminProtection,
        private readonly AuthorizationService $authorization,
        private readonly RbacAuditService $rbacAudit,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $usersQuery = User::with('permissions')
            ->orderBy('created_at', 'desc');

        if ($request->filled('role') && in_array($request->string('role')->toString(), [
            User::ROLE_ADMIN,
            User::ROLE_VENDEUR,
            User::ROLE_GESTIONNAIRE,
            User::ROLE_USER,
        ], true)) {
            $usersQuery->where('role', $request->string('role')->toString());
        }

        $users = $usersQuery->paginate(15)->withQueryString();

        $lastAdminId = null;
        if ($this->adminProtection->countActiveAdmins() === 1) {
            $lastAdminId = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->value('id');
        }

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'lastActiveAdminId' => $lastAdminId,
            'filters' => [
                'role' => $request->string('role')->toString() ?: null,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Users/Create', [
            'permissionsByResource' => AssignablePermissionResolver::adminGridByResource(),
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

        $actor = $request->user();

        DB::transaction(function () use ($validated, $actor) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $permissionsBefore = [];

            if ($user->role === 'admin') {
                $user->permissions()->sync([]);
            } elseif ($user->role === User::ROLE_VENDEUR) {
                $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));
            } elseif ($user->role === User::ROLE_GESTIONNAIRE) {
                $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));
            } elseif (isset($validated['permissions'])) {
                $user->permissions()->attach(
                    AssignablePermissionResolver::canonicalizeIds($validated['permissions'])
                );
            }

            $this->authorization->forgetCachedPermissions($user);

            $permissionsAfter = $this->rbacAudit->currentPermissionNames($user);

            ActivityLogger::logCreate('Utilisateur', $user);

            $this->rbacAudit->recordUserMutation(
                target: $user,
                oldRole: $user->role,
                newRole: $user->role,
                oldActive: (bool) $user->is_active,
                newActive: (bool) $user->is_active,
                permissionsBefore: $permissionsBefore,
                permissionsAfter: $permissionsAfter,
                roleChanged: false,
                presetSynced: in_array($user->role, [
                    User::ROLE_ADMIN,
                    User::ROLE_VENDEUR,
                    User::ROLE_GESTIONNAIRE,
                ], true),
                actor: $actor,
            );
        });

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

        $userPermissionIds = AssignablePermissionResolver::canonicalizeIds(
            $user->permissions->pluck('id')->all()
        );

        return Inertia::render('Admin/Users/Edit', [
            'user' => $user,
            'permissionsByResource' => AssignablePermissionResolver::adminGridByResource(),
            'userPermissionIds' => $userPermissionIds,
            'isLastActiveAdmin' => $this->adminProtection->isLastAdmin($user),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:admin,user,vendeur,gestionnaire',
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $actor = $request->user();

        try {
            $this->adminProtection->withAdminLock($user, function (User $lockedUser) use ($validated, $actor): void {
                $newRole = $validated['role'];
                $willBeActive = (bool) ($validated['is_active'] ?? true);

                $this->adminProtection->guardChangeRole($lockedUser, $newRole);
                $this->adminProtection->guardDeactivate($lockedUser, $willBeActive);

                $oldRole = $lockedUser->role;
                $oldActive = (bool) $lockedUser->is_active;
                $permissionsBefore = $this->rbacAudit->currentPermissionNames($lockedUser);
                $roleChanged = $oldRole !== $newRole;
                $presetSynced = in_array($newRole, [
                    User::ROLE_ADMIN,
                    User::ROLE_VENDEUR,
                    User::ROLE_GESTIONNAIRE,
                ], true);

                $lockedUser->name = $validated['name'];
                $lockedUser->email = $validated['email'];
                $lockedUser->role = $newRole;
                $lockedUser->is_active = $willBeActive;

                if (! empty($validated['password'])) {
                    $lockedUser->password = Hash::make($validated['password']);
                }

                $lockedUser->save();

                if ($lockedUser->role === 'admin') {
                    $lockedUser->permissions()->sync([]);
                } elseif ($lockedUser->role === User::ROLE_VENDEUR) {
                    $lockedUser->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));
                } elseif ($lockedUser->role === User::ROLE_GESTIONNAIRE) {
                    $lockedUser->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));
                } elseif (isset($validated['permissions'])) {
                    $lockedUser->permissions()->sync(
                        AssignablePermissionResolver::canonicalizeIds($validated['permissions'])
                    );
                    $presetSynced = false;
                } else {
                    $lockedUser->permissions()->sync([]);
                    $presetSynced = false;
                }

                $this->authorization->forgetCachedPermissions($lockedUser);

                $permissionsAfter = $this->rbacAudit->currentPermissionNames($lockedUser);

                ActivityLogger::logUpdate('Utilisateur', $lockedUser);

                $this->rbacAudit->recordUserMutation(
                    target: $lockedUser,
                    oldRole: $oldRole,
                    newRole: $newRole,
                    oldActive: $oldActive,
                    newActive: $willBeActive,
                    permissionsBefore: $permissionsBefore,
                    permissionsAfter: $permissionsAfter,
                    roleChanged: $roleChanged,
                    presetSynced: $presetSynced && ! $roleChanged,
                    actor: $actor,
                );
            });
        } catch (LastAdminProtectionException $e) {
            $this->rbacAudit->recordLastAdminChangeDenied($e->target, $e->operation, $actor);
            abort(403, $e->getMessage());
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

        if (! $currentUser || ! $currentUser->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès refusé. Seuls les administrateurs peuvent supprimer des utilisateurs.'], 403);
            }

            return redirect()->route('admin.users.index')
                ->with('error', 'Accès refusé. Seuls les administrateurs peuvent supprimer des utilisateurs.');
        }

        try {
            return $this->adminProtection->withAdminLock($user, function (User $lockedUser) use ($request, $currentUser) {
                $this->adminProtection->guardRemoveAdmin($lockedUser);

                if ($lockedUser->id === $currentUser->id) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 403);
                    }

                    return redirect()->route('admin.users.index')
                        ->with('error', 'Vous ne pouvez pas supprimer votre propre compte. Un autre administrateur doit le faire.');
                }

                $wasAdmin = $lockedUser->role === User::ROLE_ADMIN;
                $role = $lockedUser->role;

                ActivityLogger::logDelete('Utilisateur', $lockedUser);

                if ($wasAdmin) {
                    $this->rbacAudit->recordAdminRemoved($lockedUser, $role, $currentUser);
                }

                $lockedUser->delete();

                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Utilisateur supprimé avec succès.'], 200);
                }

                return redirect()->route('admin.users.index')
                    ->with('success', 'Utilisateur supprimé avec succès.');
            });
        } catch (LastAdminProtectionException $e) {
            $this->rbacAudit->recordLastAdminChangeDenied($e->target, $e->operation, $currentUser);
            abort(403, $e->getMessage());
        }
    }
}
