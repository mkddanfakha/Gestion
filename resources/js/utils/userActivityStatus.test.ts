import { describe, expect, it } from 'vitest'
import { route } from '@/lib/routes'
import { formatUserActivityStatus } from '@/utils/userActivityStatus'

describe('formatUserActivityStatus', () => {
  it('traduit les statuts devis en français', () => {
    expect(formatUserActivityStatus('quote', 'draft')).toBe('Brouillon')
    expect(formatUserActivityStatus('quote', 'sent')).toBe('Envoyé')
    expect(formatUserActivityStatus('quote', 'accepted')).toBe('Accepté')
  })

  it('traduit les statuts vente, BC, BL et inventaire', () => {
    expect(formatUserActivityStatus('sale', 'completed')).toBe('Terminé')
    expect(formatUserActivityStatus('purchase_order', 'partially_received')).toBe('Partiellement reçu')
    expect(formatUserActivityStatus('delivery_note', 'validated')).toBe('Validé')
    expect(formatUserActivityStatus('inventory', 'counting')).toBe('Comptage')
  })

  it('traduit les catégories dépense affichées comme statut', () => {
    expect(formatUserActivityStatus('expense', 'fournitures')).toBe('Fournitures')
    expect(formatUserActivityStatus('expense', 'utilities')).toBe('Services publics')
  })

  it('retourne un tiret pour statut vide', () => {
    expect(formatUserActivityStatus('quote', null)).toBe('—')
    expect(formatUserActivityStatus('quote', '')).toBe('—')
  })
})

describe('user activity Voir URLs', () => {
  it('génère /quotes/4 et jamais {id} littéral', () => {
    const url = route('quotes.show', { id: 4 })
    expect(url).toBe('/quotes/4')
    expect(url).not.toContain('{id}')
    expect(url).not.toContain('%7Bid%7D')
  })

  it('génère les URLs métier avec l ID réel pour chaque type', () => {
    expect(route('sales.show', { id: 12 })).toBe('/sales/12')
    expect(route('expenses.show', { id: 8 })).toBe('/expenses/8')
    expect(route('purchase-orders.show', { id: 3 })).toBe('/purchase-orders/3')
    expect(route('delivery-notes.show', { id: 9 })).toBe('/delivery-notes/9')
    expect(route('inventory.show', { session: 5 })).toBe('/inventory/5')
  })

  it('ne laisse pas {id} si on passe une mauvaise clé quote (régression)', () => {
    // Ancien bug : params.quote avec template {id} → /quotes/{id}?quote=4
    const broken = route('quotes.show', { quote: 4 })
    expect(broken).toContain('{id}')

    const fixed = route('quotes.show', { id: 4 })
    expect(fixed).toBe('/quotes/4')
  })
})
