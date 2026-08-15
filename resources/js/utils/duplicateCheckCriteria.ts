import type { DuplicateCheckPayload } from '@/composables/useCustomerDuplicateCheck'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function isDuplicateCheckEmail(value?: string | null): boolean {
  const email = value?.trim() ?? ''
  return email !== '' && EMAIL_REGEX.test(email)
}

export function isDuplicateCheckPhone(value?: string | null): boolean {
  const digits = (value ?? '').replace(/\D/g, '')
  return digits.length >= 8
}

export function isDuplicateCheckName(value?: string | null): boolean {
  return (value?.trim().length ?? 0) >= 2
}

export function isDuplicateCheckIdentityComplete(
  type?: string | null,
  number?: string | null,
): boolean {
  return Boolean(type && (number?.trim().length ?? 0) >= 3)
}

/**
 * Construit les paramètres exploitables pour /customers/check-duplicates.
 * Retourne null si aucun critère suffisamment complet n'est disponible.
 */
export function buildDuplicateCheckCriteria(
  payload: DuplicateCheckPayload,
): Record<string, string> | null {
  const params: Record<string, string> = {}

  if (isDuplicateCheckName(payload.name)) {
    params.name = payload.name!.trim()
  }

  if (isDuplicateCheckEmail(payload.email)) {
    params.email = payload.email!.trim()
  }

  if (isDuplicateCheckPhone(payload.phone)) {
    params.phone = payload.phone!.trim()
  }

  if (isDuplicateCheckIdentityComplete(payload.identity_document_type, payload.identity_document_number)) {
    params.identity_document_type = payload.identity_document_type!.trim()
    params.identity_document_number = payload.identity_document_number!.trim()
  }

  const excludeId = payload.exclude_id ?? payload.customer_id
  if (excludeId) {
    params.exclude_id = String(excludeId)
  }

  const hasSearchCriteria = Object.keys(params).some((key) => key !== 'exclude_id')
  return hasSearchCriteria ? params : null
}
