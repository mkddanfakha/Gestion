import { DRAFT_EXPIRATION_DAYS } from './config'
import type { FormDraftRecord } from './types'

export function addDays(date: Date, days: number): Date {
  const result = new Date(date)
  result.setDate(result.getDate() + days)
  return result
}

export function isDraftExpired(record: FormDraftRecord, now = new Date()): boolean {
  return new Date(record.expiresAt).getTime() <= now.getTime()
}

export function computeExpiresAt(expirationDays = DRAFT_EXPIRATION_DAYS): string {
  return addDays(new Date(), expirationDays).toISOString()
}

export function sanitizeDraftData(
  data: Record<string, unknown>,
  excludedFields: string[],
): Record<string, unknown> {
  const excluded = new Set(excludedFields.map((field) => field.toLowerCase()))
  const result: Record<string, unknown> = {}

  for (const [key, value] of Object.entries(data)) {
    if (excluded.has(key.toLowerCase())) {
      continue
    }

    if (value instanceof File || value instanceof Blob) {
      continue
    }

    if (Array.isArray(value)) {
      result[key] = value.map((item) =>
        typeof item === 'object' && item !== null && !(item instanceof File)
          ? sanitizeDraftData(item as Record<string, unknown>, excludedFields)
          : item instanceof File || item instanceof Blob
            ? undefined
            : item,
      )
      continue
    }

    if (typeof value === 'object' && value !== null) {
      result[key] = sanitizeDraftData(value as Record<string, unknown>, excludedFields)
      continue
    }

    result[key] = value
  }

  return result
}

function normalizeComparableValue(value: unknown): unknown {
  if (value === null || value === undefined || value === '') {
    return null
  }

  if (typeof value === 'number' && Number.isNaN(value)) {
    return null
  }

  if (Array.isArray(value)) {
    const normalized = value
      .map((item) => normalizeComparableValue(item))
      .filter((item) => item !== null)

    return normalized.length > 0 ? normalized : null
  }

  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)
      .map(([key, nestedValue]) => [key, normalizeComparableValue(nestedValue)] as const)
      .filter(([, nestedValue]) => nestedValue !== null)
      .sort(([a], [b]) => a.localeCompare(b))

    if (entries.length === 0) {
      return null
    }

    return Object.fromEntries(entries)
  }

  return value
}

export function stableStringify(value: unknown): string {
  return JSON.stringify(normalizeComparableValue(value))
}

export function hasMeaningfulDraftChanges(
  draftData: Record<string, unknown>,
  baseline: Record<string, unknown>,
): boolean {
  return stableStringify(draftData) !== stableStringify(baseline)
}

export function isEmptyDraftData(data: Record<string, unknown>): boolean {
  return stableStringify(data) === stableStringify({})
}

export function pickNewerDraft(
  localDraft: FormDraftRecord | null,
  remoteDraft: FormDraftRecord | null,
): FormDraftRecord | null {
  if (!localDraft) {
    return remoteDraft
  }

  if (!remoteDraft) {
    return localDraft
  }

  const localTime = new Date(localDraft.updatedAt).getTime()
  const remoteTime = new Date(remoteDraft.updatedAt).getTime()

  if (remoteTime > localTime) {
    return remoteDraft
  }

  if (remoteTime < localTime) {
    return localDraft
  }

  return remoteDraft.version >= localDraft.version ? remoteDraft : localDraft
}

export function formatDraftDate(isoDate: string): string {
  try {
    return new Intl.DateTimeFormat('fr-FR', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(isoDate))
  } catch {
    return isoDate
  }
}

export function formatRelativeSavedAt(isoDate: string | null): string | null {
  if (!isoDate) {
    return null
  }

  const diffMs = Date.now() - new Date(isoDate).getTime()
  const diffSec = Math.floor(diffMs / 1000)

  if (diffSec < 10) {
    return 'à l\'instant'
  }

  if (diffSec < 60) {
    return `il y a ${diffSec} s`
  }

  const diffMin = Math.floor(diffSec / 60)
  if (diffMin < 60) {
    return diffMin === 1 ? 'il y a 1 minute' : `il y a ${diffMin} minutes`
  }

  return formatDraftDate(isoDate)
}
