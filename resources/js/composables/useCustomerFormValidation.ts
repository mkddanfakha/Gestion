import { COUNTRY_CODES, SENEGAL_COUNTRY_CODE } from '@/data/countries'
import {
  IDENTITY_DOCUMENT_TYPE_VALUES,
  validateIdentityDocumentNumber,
} from '@/utils/customerIdentity'

export interface CustomerFormData {
  name: string
  email: string
  phone: string
  nationality: string
  identity_document_type: string
  identity_document_number: string
  birthday: string
  address: string
  city: string
  postal_code: string
  country: string
  notes: string
  is_active: boolean
}

export function createEmptyCustomerForm(): CustomerFormData {
  return {
    name: '',
    email: '',
    phone: '',
    nationality: SENEGAL_COUNTRY_CODE,
    identity_document_type: '',
    identity_document_number: '',
    birthday: '',
    address: '',
    city: '',
    postal_code: '',
    country: '',
    notes: '',
    is_active: true,
  }
}

export function validateCustomerField(fieldName: string, value: unknown, form?: CustomerFormData): string {
  switch (fieldName) {
    case 'name':
      if (!value || String(value).trim().length < 2) {
        return 'Le nom du client est requis (minimum 2 caractères)'
      }
      break

    case 'email':
      if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value))) {
        return 'Adresse email invalide'
      }
      break

    case 'phone':
      if (value && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(String(value))) {
        return 'Numéro de téléphone invalide'
      }
      break

    case 'nationality':
      if (value && !COUNTRY_CODES.has(String(value))) {
        return 'Nationalité invalide'
      }
      break

    case 'identity_document_type':
      if (value && !IDENTITY_DOCUMENT_TYPE_VALUES.includes(String(value))) {
        return 'Type de pièce invalide'
      }
      break

    case 'identity_document_number':
      if (!form) {
        if (value && String(value).trim().length < 3) {
          return 'Le numéro de pièce doit contenir au moins 3 caractères'
        }
        break
      }

      return validateIdentityDocumentNumber(
        form.nationality,
        form.identity_document_type,
        value,
      )
  }

  return ''
}

export function validateCustomerForm(form: CustomerFormData): Record<string, string> | null {
  const errors: Record<string, string> = {}

  for (const field of [
    'name',
    'email',
    'phone',
    'nationality',
    'identity_document_type',
    'identity_document_number',
  ] as const) {
    const error = validateCustomerField(field, form[field], form)
    if (error) {
      errors[field] = error
    }
  }

  const hasType = Boolean(form.identity_document_type)
  const hasNumber = Boolean(form.identity_document_number?.trim())

  if (hasType && !hasNumber) {
    errors.identity_document_number = 'Le numéro de pièce est requis lorsque le type est renseigné.'
  }

  if (hasNumber && !hasType) {
    errors.identity_document_type = 'Le type de pièce est requis lorsque le numéro est renseigné.'
  }

  return Object.keys(errors).length === 0 ? null : errors
}
