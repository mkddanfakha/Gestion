export interface CustomerFormData {
  name: string
  email: string
  phone: string
  address: string
  city: string
  postal_code: string
  country: string
  is_active: boolean
}

export function createEmptyCustomerForm(): CustomerFormData {
  return {
    name: '',
    email: '',
    phone: '',
    address: '',
    city: '',
    postal_code: '',
    country: '',
    is_active: true,
  }
}

export function validateCustomerField(fieldName: string, value: unknown): string {
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
  }

  return ''
}

export function validateCustomerForm(form: CustomerFormData): Record<string, string> | null {
  const errors: Record<string, string> = {}

  const nameError = validateCustomerField('name', form.name)
  if (nameError) {
    errors.name = nameError
  }

  const emailError = validateCustomerField('email', form.email)
  if (emailError) {
    errors.email = emailError
  }

  const phoneError = validateCustomerField('phone', form.phone)
  if (phoneError) {
    errors.phone = phoneError
  }

  return Object.keys(errors).length === 0 ? null : errors
}
