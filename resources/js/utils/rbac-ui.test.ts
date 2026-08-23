import { describe, expect, it } from 'vitest'
import {
  buildPermissionMatrix,
  filterPermissions,
  groupPermissionsByModule,
  matchesAuditFilter,
  permissionCountLabel,
  roleHasPermission,
  type RbacPermission,
  type RbacRole,
} from '@/utils/rbacUi'

const permissions: RbacPermission[] = [
  {
    name: 'products.view',
    module: 'products',
    action: 'view',
    label: 'Voir les produits',
    description: 'Consulter',
    sort: 10,
  },
  {
    name: 'products.update',
    module: 'products',
    action: 'update',
    label: 'Modifier les produits',
    sort: 30,
    sensitive: false,
  },
  {
    name: 'sales.view',
    module: 'sales',
    action: 'view',
    label: 'Voir les ventes',
    sort: 10,
  },
  {
    name: 'sales.create',
    module: 'sales',
    action: 'create',
    label: 'Créer une vente',
    sort: 20,
  },
  {
    name: 'inventory.reopen',
    module: 'inventory',
    action: 'reopen',
    label: 'Réouvrir un inventaire',
    sort: 46,
    sensitive: true,
  },
  {
    name: 'inventory.apply',
    module: 'inventory',
    action: 'apply',
    label: 'Appliquer un inventaire',
    sort: 60,
    sensitive: true,
  },
]

function role(
  name: string,
  opts: Partial<RbacRole> & { permissions?: string[] },
): RbacRole {
  return {
    name,
    label: name,
    badge: '',
    description: '',
    detail: '',
    icon: 'bi-person',
    isBypass: false,
    isCustom: false,
    permissions: opts.permissions ?? [],
    permissionCount: opts.permissions?.length ?? 0,
    usersCount: 0,
    modules: [],
    ...opts,
  }
}

describe('rbacUi — admin bypass', () => {
  it('admin → accès complet', () => {
    const admin = role('admin', { isBypass: true, permissions: [], permissionCount: null })
    expect(roleHasPermission(admin, 'sales.delete')).toBe(true)
    expect(permissionCountLabel(admin)).toBe('Accès complet')
  })
})

describe('rbacUi — vendeur', () => {
  it('sales.view et dashboard.view selon permissions', () => {
    const vendeur = role('vendeur', {
      permissions: ['sales.view', 'dashboard.view', 'products.view'],
    })
    expect(roleHasPermission(vendeur, 'sales.view')).toBe(true)
    expect(roleHasPermission(vendeur, 'dashboard.view')).toBe(true)
    expect(roleHasPermission(vendeur, 'inventory.reopen')).toBe(false)
  })
})

describe('rbacUi — gestionnaire', () => {
  it('aucune permission sales.*', () => {
    const gestionnaire = role('gestionnaire', {
      permissions: [
        'products.view',
        'inventory.reopen',
        'inventory.view',
        'dashboard.view',
      ],
    })
    expect(roleHasPermission(gestionnaire, 'sales.view')).toBe(false)
    expect(roleHasPermission(gestionnaire, 'sales.create')).toBe(false)
    expect(roleHasPermission(gestionnaire, 'sales.update')).toBe(false)
    expect(roleHasPermission(gestionnaire, 'sales.delete')).toBe(false)
    expect(roleHasPermission(gestionnaire, 'inventory.reopen')).toBe(true)
  })
})

describe('rbacUi — recherche / filtres / regroupement', () => {
  it('recherche inventaire', () => {
    const found = filterPermissions(permissions, { query: 'inventaire' })
    expect(found.every((p) => p.module === 'inventory')).toBe(true)
    expect(found.some((p) => p.name === 'inventory.reopen')).toBe(true)
  })

  it('filtre lecture', () => {
    const found = filterPermissions(permissions, { filter: 'view' })
    expect(found.every((p) => p.action === 'view')).toBe(true)
  })

  it('regroupement par module', () => {
    const groups = groupPermissionsByModule(permissions)
    expect(groups.map((g) => g.module).sort()).toEqual([
      'inventory',
      'products',
      'sales',
    ])
  })
})

describe('rbacUi — matrice / différences', () => {
  it('détecte les différences gestionnaire vs vendeur', () => {
    const gestionnaire = role('gestionnaire', {
      permissions: ['products.view', 'products.update', 'inventory.reopen'],
    })
    const vendeur = role('vendeur', {
      permissions: ['products.view', 'sales.view', 'sales.create'],
    })

    const diffs = buildPermissionMatrix(permissions, [gestionnaire, vendeur], {
      differencesOnly: true,
    })

    expect(diffs.some((row) => row.permission.name === 'sales.view')).toBe(true)
    expect(diffs.some((row) => row.permission.name === 'products.view')).toBe(false)
    expect(
      diffs.find((row) => row.permission.name === 'sales.view')?.cells.gestionnaire,
    ).toBe(false)
    expect(
      diffs.find((row) => row.permission.name === 'sales.view')?.cells.vendeur,
    ).toBe(true)
  })
})

describe('rbacUi — audit filters', () => {
  it('filtre refus de sécurité', () => {
    expect(matchesAuditFilter('rbac.last_admin_change_denied', 'denied')).toBe(true)
    expect(matchesAuditFilter('rbac.role_changed', 'denied')).toBe(false)
    expect(matchesAuditFilter('rbac.permissions_changed', 'permissions')).toBe(true)
  })
})
