import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { permissionNamesToCheck } from '@/utils/rbacPermissions'

const LEGACY_UPDATE_ACTION = 'edit'
const CANONICAL_UPDATE_ACTION = 'update'

type AuthUser = {
  role?: string
  permissions?: string[]
}

/**
 * API frontend unique pour le RBAC.
 * Source de vérité : permissions Inertia (AuthorizationService::forUser).
 * Ne remplace jamais le contrôle backend.
 */
export const usePermissions = () => {
  const page = usePage()

  const user = computed(() => (page.props.auth as { user?: AuthUser } | undefined)?.user)
  const isAdmin = computed(() => user.value?.role === 'admin')
  const isVendeur = computed(() => user.value?.role === 'vendeur')
  const isGestionnaire = computed(() => user.value?.role === 'gestionnaire')
  const userPermissions = computed(() => user.value?.permissions ?? [])

  const permissionSet = computed(() => new Set(userPermissions.value))

  const ownsAny = (names: string[]): boolean => {
    for (const name of names) {
      if (permissionSet.value.has(name)) {
        return true
      }
    }
    return false
  }

  /**
   * Vérifie une permission par nom complet (ex. products.update).
   * Admin → true. Compatibilité legacy edit/update et review/reopen.
   */
  const can = (permission: string): boolean => {
    if (isAdmin.value) {
      return true
    }

    if (!permission) {
      return false
    }

    return ownsAny(permissionNamesToCheck(permission))
  }

  /**
   * Vérifier si l'utilisateur a une permission resource + action.
   */
  const hasPermission = (resource: string, action: string): boolean => {
    return can(`${resource}.${action}`)
  }

  /**
   * Vérifier si l'utilisateur a une permission par son nom.
   */
  const hasPermissionByName = (permissionName: string): boolean => {
    return can(permissionName)
  }

  /**
   * Permission canonique de modification : *.update
   * Compatibilité legacy : *.edit.
   */
  const canUpdate = (resource: string): boolean => {
    return can(`${resource}.${CANONICAL_UPDATE_ACTION}`)
  }

  /**
   * Permission canonique de réouverture inventaire : inventory.reopen
   * Compatibilité legacy : inventory.review.
   */
  const canReopenInventory = (): boolean => {
    return can('inventory.reopen')
  }

  const canCreate = (resource: string): boolean => {
    return can(`${resource}.create`)
  }

  const canView = (resource: string): boolean => {
    return can(`${resource}.view`)
  }

  /**
   * Alias rétrocompatible de canUpdate() — préférer canUpdate() pour les nouveaux appels.
   * Conservé volontairement (Phase N3) : ne pas supprimer tant que des appels externes potentiels existent.
   *
   * @deprecated Prefer canUpdate()
   */
  const canEdit = (resource: string): boolean => {
    return canUpdate(resource)
  }

  const canDelete = (resource: string): boolean => {
    return can(`${resource}.delete`)
  }

  const canDownload = (resource: string): boolean => {
    return can(`${resource}.download`)
  }

  const canRestore = (resource: string): boolean => {
    return can(`${resource}.restore`)
  }

  /**
   * Au moins une permission.
   * - canAny(['sales.create', 'products.view'])
   * - canAny('sales', ['create', 'update'])  (API historique)
   */
  function canAny(permissions: string[]): boolean
  function canAny(resource: string, actions: string[]): boolean
  function canAny(permissionOrResource: string | string[], actions?: string[]): boolean {
    if (Array.isArray(permissionOrResource)) {
      if (permissionOrResource.length === 0) {
        return false
      }
      return permissionOrResource.some((permission) => can(permission))
    }

    const resource = permissionOrResource
    const actionList = actions ?? []

    return actionList.some((action) => {
      if (action === CANONICAL_UPDATE_ACTION || action === LEGACY_UPDATE_ACTION) {
        return canUpdate(resource)
      }

      if (resource === 'inventory' && (action === 'reopen' || action === 'review')) {
        return canReopenInventory()
      }

      return can(`${resource}.${action}`)
    })
  }

  /**
   * Toutes les permissions (noms complets) doivent être accordées.
   */
  const canAll = (permissions: string[]): boolean => {
    if (permissions.length === 0) {
      return false
    }

    return permissions.every((permission) => can(permission))
  }

  return {
    isAdmin,
    isVendeur,
    isGestionnaire,
    userPermissions,
    can,
    canAny,
    canAll,
    hasPermission,
    hasPermissionByName,
    canCreate,
    canView,
    canEdit,
    canUpdate,
    canReopenInventory,
    canDelete,
    canDownload,
    canRestore,
  }
}
