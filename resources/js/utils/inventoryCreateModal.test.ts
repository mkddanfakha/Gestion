import { describe, expect, it } from 'vitest'
import {
  buildInventoryCreatePayload,
  resolveInventoryCreateErrorMessage,
} from './inventoryCreateModal'

describe('inventoryCreateModal helpers', () => {
  it('builds category payload with parsed category id', () => {
    const result = buildInventoryCreatePayload({
      name: ' Inventaire cat ',
      description: '',
      scope_type: 'category',
      category_id: '12',
    })

    expect(result.payload).toEqual({
      name: 'Inventaire cat',
      description: null,
      scope_type: 'category',
      scope_value: { category_id: 12 },
    })
    expect(result.formMessage).toBe('')
  })

  it('rejects category payload without selected category', () => {
    const result = buildInventoryCreatePayload({
      name: 'Inventaire cat',
      description: '',
      scope_type: 'category',
      category_id: '',
    })

    expect(result.payload).toBeNull()
    expect(result.fieldErrors.category_id).toBe('Veuillez sélectionner une catégorie.')
    expect(result.formMessage).toContain('sélectionner une catégorie')
  })

  it('maps backend validation errors to modal fields', () => {
    const resolved = resolveInventoryCreateErrorMessage({
      'scope_value.category_id': ['Une catégorie est requise pour ce périmètre.'],
    })

    expect(resolved.fieldErrors.category_id).toBe('Une catégorie est requise pour ce périmètre.')
    expect(resolved.formMessage).toContain('catégorie')
  })

  it('maps backend business errors to modal message', () => {
    const resolved = resolveInventoryCreateErrorMessage({
      message: ['Une session d\'inventaire active existe déjà pour ce magasin.'],
    })

    expect(resolved.formMessage).toBe('Une session d\'inventaire active existe déjà pour ce magasin.')
  })

  it('builds complete payload without scope_value', () => {
    const result = buildInventoryCreatePayload({
      name: 'Inventaire complet',
      description: 'Test',
      scope_type: 'complete',
      category_id: '',
    })

    expect(result.payload).toEqual({
      name: 'Inventaire complet',
      description: 'Test',
      scope_type: 'complete',
      scope_value: null,
    })
  })
})
