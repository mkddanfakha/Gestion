import { SENEGAL_COUNTRY_CODE } from '@/data/countries'

export const IDENTITY_DOCUMENT_TYPES = [
  { value: 'national_id', label: 'Carte nationale d\'identité', short: 'CNI' },
  { value: 'passport', label: 'Passeport', short: 'Passeport' },
  { value: 'residence_permit', label: 'Carte de séjour', short: 'Séjour' },
  { value: 'other', label: 'Autre', short: 'Autre' },
] as const

export type IdentityDocumentType = typeof IDENTITY_DOCUMENT_TYPES[number]['value']

export const IDENTITY_DOCUMENT_TYPE_VALUES = IDENTITY_DOCUMENT_TYPES.map((item) => item.value)

export const FOREIGN_DOCUMENT_MIN_LENGTH = 3
export const FOREIGN_DOCUMENT_MAX_LENGTH = 50

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

export function trimIdentityNumber(value: string): string {
  return value.trim()
}

export function getIdentityFormatHint(nationality?: string | null, type?: string | null): string | null {
  if (nationality === SENEGAL_COUNTRY_CODE && type === 'national_id') {
    return 'Format attendu : 13 chiffres'
  }

  if (nationality === SENEGAL_COUNTRY_CODE && type === 'passport') {
    return 'Format attendu : A suivi de 8 chiffres. Exemple : A12345678'
  }

  if (nationality && nationality !== SENEGAL_COUNTRY_CODE && type) {
    return 'Format du document selon le pays émetteur.'
  }

  return null
}

export function validateIdentityDocumentNumber(
  nationality: string | null | undefined,
  type: string | null | undefined,
  value: unknown,
): string {
  const raw = String(value ?? '')
  const trimmed = trimIdentityNumber(raw)

  if (!trimmed) {
    return ''
  }

  if (!type) {
    return ''
  }

  const normalizedNationality = nationality ? nationality.toUpperCase() : null

  if (normalizedNationality === SENEGAL_COUNTRY_CODE && type === 'national_id') {
    if (!/^[0-9]{13}$/.test(trimmed)) {
      return 'Le numéro de CNI sénégalaise doit comporter exactement 13 chiffres.'
    }

    return ''
  }

  if (normalizedNationality === SENEGAL_COUNTRY_CODE && type === 'passport') {
    if (/\s/.test(trimmed) || !/^A[0-9]{8}$/i.test(trimmed)) {
      return 'Le numéro de passeport sénégalais doit commencer par A et être suivi de 8 chiffres. Exemple : A12345678.'
    }

    return ''
  }

  if (trimmed.length < FOREIGN_DOCUMENT_MIN_LENGTH || trimmed.length > FOREIGN_DOCUMENT_MAX_LENGTH) {
    return 'Veuillez saisir un numéro de pièce d\'identité valide.'
  }

  if (!/^[A-Za-z0-9][A-Za-z0-9 .\/_-]*$/.test(trimmed)) {
    return 'Veuillez saisir un numéro de pièce d\'identité valide.'
  }

  if (/[\x00-\x1F\x7F]/.test(trimmed)) {
    return 'Veuillez saisir un numéro de pièce d\'identité valide.'
  }

  return ''
}
