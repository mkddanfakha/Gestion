import type { DraftFormType, DraftMode } from './types'

const KEY_PREFIX = 'mkd-draft:v1'

export function buildDraftStorageKey(
  userId: number,
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
): string {
  const entityPart = entityId === null ? 'new' : String(entityId)
  return `${KEY_PREFIX}:${userId}:${formType}:${mode}:${entityPart}`
}

export function createInstanceId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return `inst-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export function createDraftId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return `draft-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export const DRAFT_BROADCAST_CHANNEL = 'mkd-draft-sync'

export function parseDraftStorageKey(key: string): {
  userId: number
  formType: DraftFormType
  mode: DraftMode
  entityId: number | null
} | null {
  const parts = key.split(':')
  if (parts.length < 6 || parts[0] !== 'mkd-draft' || parts[1] !== 'v1') {
    return null
  }

  const userId = Number(parts[2])
  const formType = parts[3] as DraftFormType
  const mode = parts[4] as DraftMode
  const entityPart = parts[5]

  if (!Number.isFinite(userId)) {
    return null
  }

  return {
    userId,
    formType,
    mode,
    entityId: entityPart === 'new' ? null : Number(entityPart),
  }
}
