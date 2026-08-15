export const IDENTITY_DOCUMENT_TYPES = [
  { value: 'national_id', label: 'Carte d\'identité', short: 'CNI' },
  { value: 'passport', label: 'Passeport', short: 'Passeport' },
  { value: 'other', label: 'Autre', short: 'Autre' },
] as const

export type IdentityDocumentType = typeof IDENTITY_DOCUMENT_TYPES[number]['value']

export interface CustomerDuplicateMatch {
  id: number
  name: string
  phone?: string | null
  email?: string | null
  identity_document_type?: string | null
  identity_document_type_label?: string | null
  identity_document_type_short?: string | null
  identity_document_number_masked?: string | null
}

export interface CustomerDuplicateAnalysis {
  identity_available: boolean
  identity_conflict: CustomerDuplicateMatch | null
  phone_match: CustomerDuplicateMatch | null
  email_match: CustomerDuplicateMatch | null
  similar_names: CustomerDuplicateMatch[]
  has_duplicates?: boolean
  matches?: Array<CustomerDuplicateMatch & { match_type?: string }>
}

export function getIdentityTypeLabel(type?: string | null): string {
  if (!type) {
    return ''
  }

  return IDENTITY_DOCUMENT_TYPES.find((item) => item.value === type)?.label ?? type
}

export function getIdentityTypeShort(type?: string | null): string {
  if (!type) {
    return ''
  }

  return IDENTITY_DOCUMENT_TYPES.find((item) => item.value === type)?.short ?? type
}

export function maskIdentityNumber(number?: string | null): string {
  if (!number) {
    return ''
  }

  const normalized = number.replace(/[\s-]+/g, '').toUpperCase()
  if (normalized.length <= 4) {
    return '•'.repeat(normalized.length)
  }

  return `${'•'.repeat(Math.max(0, normalized.length - 4))}${normalized.slice(-4)}`
}

export function formatIdentityLine(match: CustomerDuplicateMatch): string {
  const parts = [match.name]

  if (match.phone) {
    parts.push(match.phone)
  }

  if (match.identity_document_type_short && match.identity_document_number_masked) {
    parts.push(`${match.identity_document_type_short} : ${match.identity_document_number_masked}`)
  }

  return parts.join(' • ')
}
