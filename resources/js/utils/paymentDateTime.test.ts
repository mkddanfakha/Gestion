import { describe, expect, it } from 'vitest'
import { formatPaymentDateTime, formatPaymentDateTimeForInput } from './paymentDateTime'

describe('paymentDateTime', () => {
  it('affiche un instant ISO en UTC (fr-FR)', () => {
    expect(formatPaymentDateTime('2025-10-18T22:01:00.000Z')).toMatch(/18\/10\/2025.*22:01/)
  })

  it('produit la même horloge UTC pour l’input datetime-local', () => {
    const instant = new Date('2025-10-18T22:01:00.000Z')
    expect(formatPaymentDateTimeForInput(instant)).toBe('2025-10-18T22:01')
    expect(formatPaymentDateTime(instant)).toMatch(/18\/10\/2025.*22:01/)
  })

  it('gère le changement de jour calendaire en UTC', () => {
    const instant = new Date('2025-10-19T00:30:00.000Z')
    expect(formatPaymentDateTimeForInput(instant)).toBe('2025-10-19T00:30')
    expect(formatPaymentDateTime(instant)).toMatch(/19\/10\/2025.*00:30/)
  })
})
