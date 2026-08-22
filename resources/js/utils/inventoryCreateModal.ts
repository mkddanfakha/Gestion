export type InventoryCreateFormState = {
  name: string
  description: string
  scope_type: 'complete' | 'stock_positive' | 'category'
  category_id: string | number
}

export type InventoryCreatePayload = {
  name: string
  description: string | null
  scope_type: InventoryCreateFormState['scope_type']
  scope_value: { category_id: number } | null
}

export function flattenInventoryCreateErrors(
  errors: Record<string, string | string[]>,
): Record<string, string> {
  const flattened: Record<string, string> = {}

  for (const [key, value] of Object.entries(errors)) {
    flattened[key] = Array.isArray(value) ? value[0] : value
  }

  return flattened
}

export function buildInventoryCreatePayload(
  form: InventoryCreateFormState,
): { payload: InventoryCreatePayload | null; fieldErrors: Record<string, string>; formMessage: string } {
  const fieldErrors: Record<string, string> = {}
  const payload: InventoryCreatePayload = {
    name: form.name.trim(),
    description: form.description.trim() || null,
    scope_type: form.scope_type,
    scope_value: null,
  }

  if (form.scope_type === 'category') {
    const parsed = Number.parseInt(String(form.category_id), 10)

    if (!Number.isFinite(parsed) || parsed <= 0) {
      fieldErrors.category_id = 'Veuillez sélectionner une catégorie.'
      return {
        payload: null,
        fieldErrors,
        formMessage: 'Impossible de créer l\'inventaire. Veuillez sélectionner une catégorie.',
      }
    }

    payload.scope_value = { category_id: parsed }
  }

  return { payload, fieldErrors, formMessage: '' }
}

export function resolveInventoryCreateErrorMessage(
  errors: Record<string, string | string[]>,
): { fieldErrors: Record<string, string>; formMessage: string } {
  const flattened = flattenInventoryCreateErrors(errors)
  const fieldErrors: Record<string, string> = {}

  if (flattened['scope_value.category_id']) {
    fieldErrors.category_id = flattened['scope_value.category_id']
  }

  if (flattened.name) {
    fieldErrors.name = flattened.name
  }

  if (flattened.message) {
    return { fieldErrors, formMessage: flattened.message }
  }

  if (flattened.scope_value) {
    return { fieldErrors, formMessage: flattened.scope_value }
  }

  const firstError = Object.values(flattened)[0]

  return {
    fieldErrors,
    formMessage: firstError ?? 'Impossible de créer l\'inventaire. Veuillez vérifier les informations saisies.',
  }
}
