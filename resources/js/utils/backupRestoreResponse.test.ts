import { describe, expect, it } from 'vitest'
import {
  isRestoreInertiaErrorPage,
  resolveRestoreErrorResponse,
  resolveRestoreSuccessResponse,
  type RestoreInertiaPage,
} from './backupRestoreResponse'

describe('isRestoreInertiaErrorPage', () => {
  it('detects errors/403', () => {
    expect(isRestoreInertiaErrorPage('errors/403')).toBe(true)
  })

  it('detects other errors/* pages', () => {
    expect(isRestoreInertiaErrorPage('errors/409')).toBe(true)
    expect(isRestoreInertiaErrorPage('errors/500')).toBe(true)
  })
})

describe('resolveRestoreSuccessResponse', () => {
  it('TEST 1 — 403 errors/403 → error, no refresh', () => {
    const page: RestoreInertiaPage = {
      component: 'errors/403',
      props: { error: 'Accès refusé.' },
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result).toEqual({
      outcome: 'error',
      message: 'Accès refusé.',
      refreshList: false,
    })
  })

  it('TEST 2 — props.error without flash → error, no refresh', () => {
    const page: RestoreInertiaPage = {
      component: 'Admin/Backups/Index',
      props: { error: 'Opération interdite.' },
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result.outcome).toBe('error')
    expect(result.refreshList).toBe(false)
    expect(result.message).toBe('Opération interdite.')
  })

  it('TEST 3 — real success with flash.success → success + refresh', () => {
    const page: RestoreInertiaPage = {
      component: 'Admin/Backups/Index',
      props: {
        flash: { success: 'Restauration terminée.' },
      },
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result).toEqual({
      outcome: 'success',
      message: 'Restauration terminée.',
      refreshList: true,
    })
  })

  it('TEST 4 — flash.error → error, no refresh', () => {
    const page: RestoreInertiaPage = {
      component: 'Admin/Backups/Index',
      props: {
        flash: { error: 'La restauration a échoué.' },
      },
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result).toEqual({
      outcome: 'error',
      message: 'La restauration a échoué.',
      refreshList: false,
    })
  })

  it('TEST 5 — errors/409 page → error, no refresh', () => {
    const page: RestoreInertiaPage = {
      component: 'errors/409',
      props: { error: 'already in progress' },
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result.outcome).toBe('error')
    expect(result.refreshList).toBe(false)
  })

  it('TEST 8 — no flash.success and no error data → generic error, no success fallback', () => {
    const page: RestoreInertiaPage = {
      component: 'Admin/Backups/Index',
      props: { flash: {} },
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result.outcome).toBe('error')
    expect(result.refreshList).toBe(false)
    expect(result.message).not.toContain('Restauration terminée')
  })

  it('403 without props.error uses safe access denied message', () => {
    const page: RestoreInertiaPage = {
      component: 'errors/403',
      props: {},
    }
    const result = resolveRestoreSuccessResponse(page)
    expect(result.outcome).toBe('error')
    expect(result.message).toBe('La restauration n\'a pas été effectuée. Accès refusé.')
  })

  it('flash.success takes precedence only when no error page signal', () => {
    const page: RestoreInertiaPage = {
      component: 'Admin/Backups/Index',
      props: {
        flash: { success: 'OK', error: undefined },
      },
    }
    expect(resolveRestoreSuccessResponse(page).outcome).toBe('success')
  })
})

describe('resolveRestoreErrorResponse', () => {
  it('TEST 6 — 422 validation errors → first message', () => {
    expect(
      resolveRestoreErrorResponse({
        target_database: 'Champ obligatoire.',
      }),
    ).toBe('Champ obligatoire.')
  })

  it('TEST 7 — 500-style string error', () => {
    expect(resolveRestoreErrorResponse('Erreur serveur interne.')).toBe('Erreur serveur interne.')
  })

  it('TEST 5 — 409 via onError payload', () => {
    expect(resolveRestoreErrorResponse({ message: 'already in progress' })).toBe('already in progress')
  })

  it('falls back when errors object is empty', () => {
    expect(resolveRestoreErrorResponse({})).toBe(
      'La restauration a échoué. Vérifiez la cible et réessayez.',
    )
  })
})
