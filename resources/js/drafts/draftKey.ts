import type { DraftFormType, DraftMode } from './types'

const KEY_PREFIX = 'mkd-draft:v1'
const SCOPE_MARKER = ':ctx:'

export function normalizeScopeContext(scopeContext?: string | null): string {
  if (!scopeContext) {
    return ''
  }

  const trimmed = scopeContext.trim()

  return trimmed === '' ? '' : trimmed
}

export function buildDraftScopeKey(
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  scopeContext?: string | null,
): string {
  const base = `${formType}:${mode}:${entityId === null ? 'new' : String(entityId)}`
  const normalizedScope = normalizeScopeContext(scopeContext)

  return normalizedScope ? `${base}:${normalizedScope}` : base
}

export function buildDraftStorageKey(
  userId: number,
  formType: DraftFormType,
  mode: DraftMode,
  entityId: number | null,
  scopeContext?: string | null,
): string {
  const entityPart = entityId === null ? 'new' : String(entityId)
  const normalizedScope = normalizeScopeContext(scopeContext)
  const contextPart = normalizedScope ? `${SCOPE_MARKER}${normalizedScope}` : ''

  return `${KEY_PREFIX}:${userId}:${formType}:${mode}:${entityPart}${contextPart}`
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
  scopeContext: string | null
} | null {
  if (!key.startsWith(`${KEY_PREFIX}:`)) {
    return null
  }

  const withoutPrefix = key.slice(KEY_PREFIX.length + 1)
  const scopeIndex = withoutPrefix.indexOf(SCOPE_MARKER)

  const scopePart =
    scopeIndex >= 0 ? withoutPrefix.slice(scopeIndex + SCOPE_MARKER.length) : null
  const scopedBody = scopeIndex >= 0 ? withoutPrefix.slice(0, scopeIndex) : withoutPrefix

  const parts = scopedBody.split(':')
  if (parts.length < 4) {
    return null
  }

  const userId = Number(parts[0])
  const formType = parts[1] as DraftFormType
  const mode = parts[2] as DraftMode
  const entityPart = parts[3]

  if (!Number.isFinite(userId)) {
    return null
  }

  return {
    userId,
    formType,
    mode,
    entityId: entityPart === 'new' ? null : Number(entityPart),
    scopeContext: scopePart && scopePart.length > 0 ? scopePart : null,
  }
}
