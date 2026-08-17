import { getCsrfToken } from '@/lib/csrf'
import { route } from '@/lib/routes'
import { buildDraftScopeKey, normalizeScopeContext } from './draftKey'
import type { DraftApiRecord, DraftFormType, DraftMode, DraftSyncPayload, FormDraftRecord } from './types'

let syncInFlight = false
const pendingSyncKeys = new Set<string>()

function buildSyncKey(payload: DraftSyncPayload): string {
  return buildDraftScopeKey(
    payload.form_type,
    payload.mode,
    payload.entity_id ?? null,
    payload.scope_context,
  )
}

function mapApiRecord(apiRecord: DraftApiRecord): FormDraftRecord {
  return {
    id: apiRecord.id,
    userId: apiRecord.userId,
    formType: apiRecord.formType,
    mode: apiRecord.mode,
    entityId: apiRecord.entityId,
    scopeContext: normalizeScopeContext(apiRecord.scopeContext) || null,
    data: apiRecord.data,
    createdAt: apiRecord.createdAt,
    updatedAt: apiRecord.updatedAt,
    expiresAt: apiRecord.expiresAt,
    version: apiRecord.version,
    instanceId: apiRecord.instanceId ?? '',
    serverId: apiRecord.id,
  }
}

function resolveDraftRoute(name: string, params: Record<string, unknown> = {}): string | null {
  const url = route(name, params)

  if (!url || url === '#') {
    console.warn(`[draft] Route API introuvable: ${name}`)
    return null
  }

  return url
}

export async function fetchServerDraft(
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  scopeContext?: string | null,
): Promise<FormDraftRecord | null> {
  try {
    const params = new URLSearchParams({
      form_type: formType,
      mode,
    })

    if (entityId !== null) {
      params.set('entity_id', String(entityId))
    }

    const normalizedScope = normalizeScopeContext(scopeContext)
    if (normalizedScope) {
      params.set('scope_context', normalizedScope)
    }

    const endpoint = resolveDraftRoute('drafts.show')
    if (!endpoint) {
      return null
    }

    const response = await fetch(`${endpoint}?${params.toString()}`, {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      return null
    }

    const json = (await response.json()) as { draft: DraftApiRecord | null }
    return json.draft ? mapApiRecord(json.draft) : null
  } catch {
    return null
  }
}

export async function syncDraftToServer(payload: DraftSyncPayload): Promise<FormDraftRecord | null> {
  const syncKey = buildSyncKey(payload)

  if (syncInFlight && pendingSyncKeys.has(syncKey)) {
    return null
  }

  pendingSyncKeys.add(syncKey)
  syncInFlight = true

  try {
    const endpoint = resolveDraftRoute('drafts.upsert')
    if (!endpoint) {
      return null
    }

    const normalizedScope = normalizeScopeContext(payload.scope_context)

    const response = await fetch(endpoint, {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        form_type: payload.form_type,
        mode: payload.mode,
        entity_id: payload.entity_id ?? null,
        scope_context: normalizedScope || null,
        data: payload.data,
        version: payload.version,
        instance_id: payload.instance_id,
      }),
    })

    if (!response.ok) {
      return null
    }

    const json = (await response.json()) as { draft: DraftApiRecord }
    return mapApiRecord(json.draft)
  } catch {
    return null
  } finally {
    pendingSyncKeys.delete(syncKey)
    syncInFlight = false
  }
}

export async function deleteServerDraftByScope(
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  scopeContext?: string | null,
): Promise<boolean> {
  try {
    const endpoint = resolveDraftRoute('drafts.destroy-scope')
    if (!endpoint) {
      return false
    }

    const normalizedScope = normalizeScopeContext(scopeContext)

    const response = await fetch(endpoint, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        form_type: formType,
        mode,
        entity_id: entityId,
        scope_context: normalizedScope || null,
      }),
    })

    return response.ok
  } catch {
    return false
  }
}

export async function deleteServerDraftById(draftId: string): Promise<boolean> {
  try {
    const endpoint = resolveDraftRoute('drafts.destroy', { draft: draftId })
    if (!endpoint) {
      return false
    }

    const response = await fetch(endpoint, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
    })

    return response.ok
  } catch {
    return false
  }
}

export function isOnline(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine
}

export { buildDraftScopeKey, normalizeScopeContext }
