/**
 * Pure helpers for backup restore Inertia visit callbacks.
 * Prevents HTTP 403 Inertia error pages from being treated as success.
 */

export type RestoreInertiaPage = {
  component?: string
  props?: {
    flash?: {
      success?: string
      error?: string
    }
    error?: string
    errors?: Record<string, string | string[]>
  }
}

export type RestoreSuccessResolution =
  | { outcome: 'success'; message: string; refreshList: true }
  | { outcome: 'error'; message: string; refreshList: false }

const RESTORE_ACCESS_DENIED_MESSAGE =
  'La restauration n\'a pas été effectuée. Accès refusé.'

const RESTORE_GENERIC_FAILURE_MESSAGE =
  'La restauration n\'a pas pu être effectuée.'

const RESTORE_VALIDATION_FALLBACK_MESSAGE =
  'La restauration a échoué. Vérifiez la cible et réessayez.'

export function isRestoreInertiaErrorPage(component: string | undefined): boolean {
  return typeof component === 'string' && component.startsWith('errors/')
}

function nonEmptyString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null
  }
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

/**
 * Resolve a restore router.post onSuccess page.
 * Never returns success without an explicit flash.success.
 */
export function resolveRestoreSuccessResponse(page: RestoreInertiaPage): RestoreSuccessResolution {
  const props = page.props ?? {}
  const pageError = nonEmptyString(props.error)

  if (isRestoreInertiaErrorPage(page.component) || pageError) {
    return {
      outcome: 'error',
      message: pageError ?? RESTORE_ACCESS_DENIED_MESSAGE,
      refreshList: false,
    }
  }

  const flashError = nonEmptyString(props.flash?.error)
  if (flashError) {
    return {
      outcome: 'error',
      message: flashError,
      refreshList: false,
    }
  }

  const flashSuccess = nonEmptyString(props.flash?.success)
  if (flashSuccess) {
    return {
      outcome: 'success',
      message: flashSuccess,
      refreshList: true,
    }
  }

  return {
    outcome: 'error',
    message: RESTORE_GENERIC_FAILURE_MESSAGE,
    refreshList: false,
  }
}

/**
 * Resolve a restore router.post onError payload (422, 409, 500, …).
 */
export function resolveRestoreErrorResponse(
  errors: unknown,
  fallback: string = RESTORE_VALIDATION_FALLBACK_MESSAGE,
): string {
  if (typeof errors === 'string' && errors.trim()) {
    return errors.trim()
  }

  if (errors && typeof errors === 'object') {
    const record = errors as Record<string, unknown>
    for (const key of Object.keys(record)) {
      const value = record[key]
      if (typeof value === 'string' && value.trim()) {
        return value.trim()
      }
      if (Array.isArray(value) && typeof value[0] === 'string' && value[0].trim()) {
        return value[0].trim()
      }
    }
  }

  return fallback
}
