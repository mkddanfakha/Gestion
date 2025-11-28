import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Composable pour vérifier les permissions de l'utilisateur
 */
export const usePermissions = () => {
  const page = usePage()
  
  const user = computed(() => (page.props.auth as any)?.user)
  const isAdmin = computed(() => user.value?.role === 'admin')
  const isVendeur = computed(() => user.value?.role === 'vendeur')
  const userPermissions = computed(() => user.value?.permissions || [])

  /**
   * Vérifier si l'utilisateur a une permission spécifique
   */
  const hasPermission = (resource: string, action: string): boolean => {
    // Les administrateurs ont toutes les permissions
    if (isAdmin.value) {
      return true
    }

    const permissionName = `${resource}.${action}`
    return userPermissions.value.includes(permissionName)
  }

  /**
   * Vérifier si l'utilisateur a une permission par son nom
   */
  const hasPermissionByName = (permissionName: string): boolean => {
    // Les administrateurs ont toutes les permissions
    if (isAdmin.value) {
      return true
    }

    return userPermissions.value.includes(permissionName)
  }

  /**
   * Vérifier si l'utilisateur peut créer une ressource
   */
  const canCreate = (resource: string): boolean => {
    return hasPermission(resource, 'create')
  }

  /**
   * Vérifier si l'utilisateur peut voir une ressource
   */
  const canView = (resource: string): boolean => {
    return hasPermission(resource, 'view')
  }

  /**
   * Vérifier si l'utilisateur peut modifier une ressource
   */
  const canEdit = (resource: string): boolean => {
    return hasPermission(resource, 'edit')
  }

  /**
   * Vérifier si l'utilisateur peut mettre à jour une ressource
   */
  const canUpdate = (resource: string): boolean => {
    return hasPermission(resource, 'update')
  }

  /**
   * Vérifier si l'utilisateur peut supprimer une ressource
   */
  const canDelete = (resource: string): boolean => {
    return hasPermission(resource, 'delete')
  }

  /**
   * Vérifier si l'utilisateur peut télécharger une ressource
   */
  const canDownload = (resource: string): boolean => {
    return hasPermission(resource, 'download')
  }

  /**
   * Vérifier si l'utilisateur peut restaurer une ressource
   */
  const canRestore = (resource: string): boolean => {
    return hasPermission(resource, 'restore')
  }

  return {
    isAdmin,
    isVendeur,
    userPermissions,
    hasPermission,
    hasPermissionByName,
    canCreate,
    canView,
    canEdit,
    canUpdate,
    canDelete,
    canDownload,
    canRestore,
  }
}

