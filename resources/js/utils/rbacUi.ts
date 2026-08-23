/**
 * Helpers UI Phase M — regroupement, recherche, matrice, sensibilité.
 * Source de vérité métier : payload Laravel (PermissionCatalog / RolePresets).
 */

export type RbacPermission = {
  name: string
  module: string
  action: string
  label: string
  description?: string
  legacy?: boolean
  canonicalName?: string
  sort?: number
  sensitive?: boolean
  id?: number
}

export type RbacRole = {
  name: string
  label: string
  badge: string
  description: string
  detail: string
  icon: string
  isBypass: boolean
  isCustom: boolean
  permissions: string[]
  permissionCount: number | null
  usersCount: number
  modules: Array<{ key: string; label: string }>
}

export type RbacPermissionGroup = {
  module: string
  label: string
  permissions: RbacPermission[]
}

export const DEFAULT_MODULE_LABELS: Record<string, string> = {
  products: 'Produits',
  categories: 'Catégories',
  customers: 'Clients',
  sales: 'Ventes',
  quotes: 'Devis',
  expenses: 'Dépenses',
  suppliers: 'Fournisseurs',
  'purchase-orders': 'Bons de commande',
  'delivery-notes': 'Bons de livraison',
  company: 'Entreprise',
  dashboard: 'Tableau de bord',
  inventory: 'Inventaire',
  backups: 'Sauvegardes',
}

/** Classification UI sensible (documentée) — indépendante du backend. */
export function isSensitivePermissionUi(
  permission: Pick<RbacPermission, 'module' | 'action' | 'name' | 'sensitive'>,
): boolean {
  if (permission.sensitive === true) {
    return true
  }
  if (permission.module === 'backups') {
    return true
  }
  return (
    ['delete', 'validate', 'apply', 'cancel', 'close', 'export', 'reopen'].includes(
      permission.action,
    ) || permission.name.includes('admin')
  )
}

export function moduleLabel(
  module: string,
  labels: Record<string, string> = DEFAULT_MODULE_LABELS,
): string {
  return labels[module] ?? module
}

export function groupPermissionsByModule(
  permissions: RbacPermission[],
  labels: Record<string, string> = DEFAULT_MODULE_LABELS,
): RbacPermissionGroup[] {
  const map = new Map<string, RbacPermission[]>()

  for (const permission of permissions) {
    const list = map.get(permission.module) ?? []
    list.push(permission)
    map.set(permission.module, list)
  }

  return [...map.entries()]
    .map(([module, items]) => ({
      module,
      label: moduleLabel(module, labels),
      permissions: [...items].sort(
        (a, b) => (a.sort ?? 0) - (b.sort ?? 0) || a.name.localeCompare(b.name),
      ),
    }))
    .sort((a, b) => a.label.localeCompare(b.label, 'fr'))
}

export function matchesPermissionSearch(
  permission: RbacPermission,
  query: string,
  labels: Record<string, string> = DEFAULT_MODULE_LABELS,
): boolean {
  const q = query.trim().toLowerCase()
  if (!q) {
    return true
  }

  const haystack = [
    permission.name,
    permission.label,
    permission.description ?? '',
    permission.module,
    permission.action,
    moduleLabel(permission.module, labels),
  ]
    .join(' ')
    .toLowerCase()

  return haystack.includes(q)
}

export type PermissionFilter =
  | 'all'
  | 'view'
  | 'create'
  | 'update'
  | 'delete'
  | 'inventory'
  | 'admin'

export function matchesPermissionFilter(
  permission: RbacPermission,
  filter: PermissionFilter,
): boolean {
  switch (filter) {
    case 'all':
      return true
    case 'view':
    case 'create':
    case 'update':
    case 'delete':
      return permission.action === filter
    case 'inventory':
      return permission.module === 'inventory'
    case 'admin':
      return permission.module === 'backups' || permission.name.includes('admin')
    default:
      return true
  }
}

export function filterPermissions(
  permissions: RbacPermission[],
  options: {
    query?: string
    filter?: PermissionFilter
    labels?: Record<string, string>
  } = {},
): RbacPermission[] {
  const { query = '', filter = 'all', labels = DEFAULT_MODULE_LABELS } = options
  return permissions.filter(
    (permission) =>
      matchesPermissionFilter(permission, filter) &&
      matchesPermissionSearch(permission, query, labels),
  )
}

export function roleHasPermission(role: RbacRole, permissionName: string): boolean {
  if (role.isBypass) {
    return true
  }
  return role.permissions.includes(permissionName)
}

export type MatrixRow = {
  permission: RbacPermission
  cells: Record<string, boolean>
}

/**
 * Matrice dynamique rôles × permissions (permissions fournies = source catalogue).
 */
export function buildPermissionMatrix(
  permissions: RbacPermission[],
  roles: RbacRole[],
  options: { differencesOnly?: boolean } = {},
): MatrixRow[] {
  const rows: MatrixRow[] = permissions.map((permission) => {
    const cells: Record<string, boolean> = {}
    for (const role of roles) {
      cells[role.name] = roleHasPermission(role, permission.name)
    }
    return { permission, cells }
  })

  if (!options.differencesOnly) {
    return rows
  }

  return rows.filter((row) => {
    const values = roles.map((role) => row.cells[role.name])
    return values.some((value) => value !== values[0])
  })
}

export function permissionCountLabel(role: RbacRole): string {
  if (role.isBypass) {
    return 'Accès complet'
  }
  if (role.isCustom) {
    return 'Permissions personnalisées'
  }
  const count = role.permissionCount ?? role.permissions.length
  return `${count} permission${count > 1 ? 's' : ''}`
}

export type AuditFilter =
  | 'all'
  | 'roles'
  | 'permissions'
  | 'activation'
  | 'admins'
  | 'denied'

export function matchesAuditFilter(
  action: string,
  filter: AuditFilter,
): boolean {
  switch (filter) {
    case 'all':
      return true
    case 'roles':
      return action === 'rbac.role_changed'
    case 'permissions':
      return (
        action === 'rbac.permissions_changed' || action === 'rbac.permissions_synced'
      )
    case 'activation':
      return (
        action === 'rbac.user_activated' || action === 'rbac.user_deactivated'
      )
    case 'admins':
      return (
        action === 'rbac.admin_removed' ||
        action === 'rbac.last_admin_change_denied'
      )
    case 'denied':
      return action === 'rbac.last_admin_change_denied'
    default:
      return true
  }
}
