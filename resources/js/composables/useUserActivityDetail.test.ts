import { describe, expect, it } from 'vitest'
import {
  buildUserActivityDetailUrl,
  mapDetailHttpError,
} from '@/composables/useUserActivityDetail'

describe('useUserActivityDetail helpers', () => {
  it('construit une URL de détail sans navigation métier', () => {
    expect(buildUserActivityDetailUrl('quote', 4)).toBe('/user-activities/quote/4')
    expect(buildUserActivityDetailUrl('sale', 12)).toBe('/user-activities/sale/12')
    expect(buildUserActivityDetailUrl('inventory', 5)).toBe('/user-activities/inventory/5')
  })

  it('ne pointe jamais vers /quotes/{id} ou /sales/{id}', () => {
    const url = buildUserActivityDetailUrl('quote', 4)
    expect(url).not.toContain('/quotes/')
    expect(url).not.toContain('{id}')
  })

  it('mappe les erreurs HTTP 403 et 404', () => {
    expect(mapDetailHttpError(403)).toContain('permission')
    expect(mapDetailHttpError(404)).toBe('Document introuvable.')
    expect(mapDetailHttpError(500)).toBe('Impossible de charger les détails.')
  })
})
