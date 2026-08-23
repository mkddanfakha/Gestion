import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { filterCanonicalPermissionGrid, permissionNamesToCheck } from '@/utils/rbacPermissions'

const mockPageProps = ref({
  auth: {
    user: {
      role: 'user',
      permissions: [] as string[],
    } as { role: string; permissions: string[] } | null,
  },
})

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({
    props: mockPageProps.value,
  }),
}))

import { usePermissions } from '@/composables/usePermissions'

describe('usePermissions — API RBAC frontend', () => {
  beforeEach(() => {
    mockPageProps.value = {
      auth: {
        user: {
          role: 'user',
          permissions: [],
        },
      },
    }
  })

  it('admin.can(any) = true même sans liste de permissions', () => {
    mockPageProps.value.auth.user = { role: 'admin', permissions: [] }
    const { can, canUpdate, canAll, canAny } = usePermissions()
    expect(can('products.delete')).toBe(true)
    expect(canUpdate('sales')).toBe(true)
    expect(canAll(['a', 'b'])).toBe(true)
    expect(canAny(['sales.create', 'inventory.apply'])).toBe(true)
  })

  it('vendeur : dashboard.view et sales selon permissions exposées', () => {
    mockPageProps.value.auth.user = {
      role: 'vendeur',
      permissions: ['dashboard.view', 'sales.create', 'sales.view', 'products.view'],
    }
    const { can, canReopenInventory } = usePermissions()
    expect(can('dashboard.view')).toBe(true)
    expect(can('sales.create')).toBe(true)
    expect(can('inventory.reopen')).toBe(false)
    expect(canReopenInventory()).toBe(false)
  })

  it('gestionnaire : pas de sales.*, inventaire selon permissions', () => {
    mockPageProps.value.auth.user = {
      role: 'gestionnaire',
      permissions: ['inventory.view', 'inventory.reopen', 'products.view', 'dashboard.view'],
    }
    const { can } = usePermissions()
    expect(can('sales.create')).toBe(false)
    expect(can('sales.update')).toBe(false)
    expect(can('sales.delete')).toBe(false)
    expect(can('inventory.view')).toBe(true)
    expect(can('inventory.reopen')).toBe(true)
  })

  it('canUpdate accepte products.update et products.edit legacy', () => {
    mockPageProps.value.auth.user.permissions = ['products.update']
    expect(usePermissions().canUpdate('products')).toBe(true)

    mockPageProps.value.auth.user.permissions = ['products.edit']
    expect(usePermissions().canUpdate('products')).toBe(true)

    mockPageProps.value.auth.user.permissions = ['products.view']
    expect(usePermissions().canUpdate('products')).toBe(false)
  })

  it('canEdit est un alias de canUpdate', () => {
    mockPageProps.value.auth.user.permissions = ['customers.edit']
    const { canEdit, canUpdate } = usePermissions()
    expect(canEdit('customers')).toBe(true)
    expect(canUpdate('customers')).toBe(true)
  })

  it('canReopenInventory accepte inventory.review legacy', () => {
    mockPageProps.value.auth.user.permissions = ['inventory.review']
    expect(usePermissions().canReopenInventory()).toBe(true)
    expect(usePermissions().can('inventory.reopen')).toBe(true)
  })

  it('canAny (noms complets) et canAny (resource, actions)', () => {
    mockPageProps.value.auth.user.permissions = ['sales.edit', 'products.view']
    const { canAny } = usePermissions()
    expect(canAny(['sales.create', 'products.view'])).toBe(true)
    expect(canAny(['sales.delete', 'inventory.apply'])).toBe(false)
    expect(canAny('sales', ['update'])).toBe(true)
    expect(canAny('sales', ['edit'])).toBe(true)
  })

  it('canAll exige toutes les permissions', () => {
    mockPageProps.value.auth.user.permissions = ['products.view', 'sales.view']
    const { canAll } = usePermissions()
    expect(canAll(['products.view', 'sales.view'])).toBe(true)
    expect(canAll(['products.view', 'sales.create'])).toBe(false)
    expect(canAll([])).toBe(false)
  })

  it('permission inconnue = false (non-admin)', () => {
    mockPageProps.value.auth.user.permissions = ['products.view']
    expect(usePermissions().can('unknown.permission')).toBe(false)
  })

  it('utilisateur non authentifié = false', () => {
    mockPageProps.value.auth.user = null
    const { can, canAny, canAll } = usePermissions()
    expect(can('products.view')).toBe(false)
    expect(canAny(['products.view'])).toBe(false)
    expect(canAll(['products.view'])).toBe(false)
  })
})

describe('rbacPermissions helpers', () => {
  it('permissionNamesToCheck couvre legacy et canonique', () => {
    expect(permissionNamesToCheck('products.update')).toEqual(
      expect.arrayContaining(['products.update', 'products.edit']),
    )
    expect(permissionNamesToCheck('inventory.review')).toEqual(
      expect.arrayContaining(['inventory.review', 'inventory.reopen']),
    )
  })

  it('filterCanonicalPermissionGrid masque edit/review si update/reopen présents', () => {
    const filtered = filterCanonicalPermissionGrid({
      products: [
        { id: 1, name: 'products.view', action: 'view' },
        { id: 2, name: 'products.edit', action: 'edit' },
        { id: 3, name: 'products.update', action: 'update' },
      ],
      inventory: [
        { id: 4, name: 'inventory.review', action: 'review' },
        { id: 5, name: 'inventory.reopen', action: 'reopen' },
      ],
    })

    expect(filtered.products.map((p) => p.name)).toEqual(['products.view', 'products.update'])
    expect(filtered.inventory.map((p) => p.name)).toEqual(['inventory.reopen'])
  })
})
