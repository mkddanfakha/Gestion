/**
 * Helpers RBAC frontend partagés (noms legacy ↔ canoniques).
 * Source de vérité métier : PermissionCatalog PHP — compatibilité d'affichage / checks Vue uniquement.
 */

/** Permissions legacy → canonique (aligné PermissionCatalog). */
export const LEGACY_PERMISSION_ALIASES: Record<string, string> = {
  'products.edit': 'products.update',
  'categories.edit': 'categories.update',
  'customers.edit': 'customers.update',
  'sales.edit': 'sales.update',
  'quotes.edit': 'quotes.update',
  'expenses.edit': 'expenses.update',
  'suppliers.edit': 'suppliers.update',
  'purchase-orders.edit': 'purchase-orders.update',
  'delivery-notes.edit': 'delivery-notes.update',
  'company.edit': 'company.update',
  'inventory.review': 'inventory.reopen',
}

export const CANONICAL_TO_LEGACY: Record<string, string> = Object.fromEntries(
  Object.entries(LEGACY_PERMISSION_ALIASES).map(([legacy, canonical]) => [canonical, legacy]),
)

export function isLegacyPermissionName(name: string): boolean {
  return Object.prototype.hasOwnProperty.call(LEGACY_PERMISSION_ALIASES, name)
}

/**
 * Noms à vérifier pour une permission demandée (canonique + legacy éventuel).
 */
export function permissionNamesToCheck(permission: string): string[] {
  const names = [permission]
  const canonical = LEGACY_PERMISSION_ALIASES[permission]
  if (canonical) {
    names.push(canonical)
  }
  const legacy = CANONICAL_TO_LEGACY[permission]
  if (legacy) {
    names.push(legacy)
  }
  return [...new Set(names)]
}

export type PermissionGridItem = {
  id: number
  name: string
  action: string
  description?: string | null
}

/**
 * Retire les permissions legacy de la grille admin lorsqu’un équivalent canonique existe.
 */
export function filterCanonicalPermissionGrid(
  permissionsByResource: Record<string, PermissionGridItem[]>,
): Record<string, PermissionGridItem[]> {
  const result: Record<string, PermissionGridItem[]> = {}

  for (const [resource, permissions] of Object.entries(permissionsByResource)) {
    const names = new Set(permissions.map((p) => p.name))
    const filtered = permissions.filter((permission) => {
      if (!isLegacyPermissionName(permission.name)) {
        return true
      }
      const canonical = LEGACY_PERMISSION_ALIASES[permission.name]
      return !names.has(canonical)
    })
    if (filtered.length > 0) {
      result[resource] = filtered
    }
  }

  return result
}

/** Labels d’actions pour la grille (canonique privilégié). */
export function permissionActionLabel(action: string): string {
  const labels: Record<string, string> = {
    view: 'Voir',
    create: 'Créer',
    update: 'Modifier',
    edit: 'Modifier (legacy)',
    delete: 'Supprimer',
    export: 'Exporter',
    invoice: 'Factures',
    download: 'Télécharger',
    print: 'Imprimer',
    validate: 'Valider',
    reopen: 'Rouvrir',
    review: 'Rouvrir (legacy)',
    count: 'Compter',
    submit: 'Soumettre',
    apply: 'Appliquer',
    cancel: 'Annuler',
    close: 'Clôturer',
  }
  return labels[action] || action
}
