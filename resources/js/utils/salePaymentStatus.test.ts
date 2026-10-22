import { describe, expect, it } from 'vitest'
import {
  calculateCashChangeAmount,
  calculateSalePaymentState,
  getCheckoutPaymentTypeIcon,
  getPaymentMethodIcon,
  getPaymentStatusIcon,
  getPaymentStatusLabel,
  isCheckoutPaymentMethodRequired,
  resolveCashAmountDue,
  resolveEffectiveDownPaymentAmount,
} from './salePaymentStatus'

describe('calculateSalePaymentState', () => {
  it('reste en attente sans encaissement', () => {
    const state = calculateSalePaymentState(25000, 0)
    expect(state.paymentStatus).toBe('pending')
    expect(state.remainingAmount).toBe(25000)
  })

  it('partiel lorsque l’acompte est inférieur au total', () => {
    const state = calculateSalePaymentState(25000, 7000)
    expect(state.paymentStatus).toBe('partial')
    expect(state.downPaymentAmount).toBe(7000)
    expect(state.remainingAmount).toBe(18000)
  })

  it('payé lorsque l’acompte couvre le total', () => {
    const state = calculateSalePaymentState(25000, 25000)
    expect(state.paymentStatus).toBe('paid')
    expect(state.remainingAmount).toBe(0)
  })
})

describe('resolveEffectiveDownPaymentAmount — sans acompte', () => {
  it('encaisse le total si le montant reçu couvre la vente', () => {
    expect(
      resolveEffectiveDownPaymentAmount({
        totalAmount: 25000,
        downPaymentAmount: 0,
        paymentMethod: 'cash',
        cashReceivedAmount: 30000,
        paymentType: 'without_deposit',
      }),
    ).toBe(25000)
  })

  it('n’encaisse rien si le montant reçu est insuffisant', () => {
    expect(
      resolveEffectiveDownPaymentAmount({
        totalAmount: 25000,
        downPaymentAmount: 0,
        paymentMethod: 'cash',
        cashReceivedAmount: 10000,
        paymentType: 'without_deposit',
      }),
    ).toBe(0)
  })

  it('encaisse le total dès qu’un moyen autre que espèces est choisi', () => {
    for (const paymentMethod of ['card', 'bank_transfer', 'check', 'orange_money', 'wave']) {
      expect(
        resolveEffectiveDownPaymentAmount({
          totalAmount: 25000,
          downPaymentAmount: 0,
          paymentMethod,
          cashReceivedAmount: 0,
          paymentType: 'without_deposit',
        }),
      ).toBe(25000)
    }
  })

  it('reste en attente tant qu’aucun moyen n’est sélectionné', () => {
    expect(
      resolveEffectiveDownPaymentAmount({
        totalAmount: 25000,
        downPaymentAmount: 0,
        paymentMethod: '',
        cashReceivedAmount: 0,
        paymentType: 'without_deposit',
      }),
    ).toBe(0)
  })

  it('marque la vente payée pour un moyen non espèces sans acompte', () => {
    const effective = resolveEffectiveDownPaymentAmount({
      totalAmount: 25000,
      downPaymentAmount: 0,
      paymentMethod: 'wave',
      cashReceivedAmount: 0,
      paymentType: 'without_deposit',
    })
    expect(calculateSalePaymentState(25000, effective).paymentStatus).toBe('paid')
  })
})

describe('resolveEffectiveDownPaymentAmount — avec acompte', () => {
  it('encaisse l’acompte conservé, pas le montant reçu brut', () => {
    expect(
      resolveEffectiveDownPaymentAmount({
        totalAmount: 25000,
        downPaymentAmount: 7000,
        paymentMethod: 'cash',
        cashReceivedAmount: 10000,
        paymentType: 'with_deposit',
      }),
    ).toBe(7000)
  })

  it('ne passe pas automatiquement au payé intégral si le reçu couvre le reste dû', () => {
    const effective = resolveEffectiveDownPaymentAmount({
      totalAmount: 25000,
      downPaymentAmount: 7000,
      paymentMethod: 'cash',
      cashReceivedAmount: 20000,
      paymentType: 'with_deposit',
    })
    expect(effective).toBe(7000)
    expect(calculateSalePaymentState(25000, effective).paymentStatus).toBe('partial')
  })

  it('plafonne l’acompte au total de la vente', () => {
    expect(
      resolveEffectiveDownPaymentAmount({
        totalAmount: 25000,
        downPaymentAmount: 30000,
        paymentMethod: 'cash',
        cashReceivedAmount: 35000,
        paymentType: 'with_deposit',
      }),
    ).toBe(25000)
  })
})

describe('calculateCashChangeAmount', () => {
  it('sans acompte : monnaie = reçu - total', () => {
    expect(
      calculateCashChangeAmount({
        totalAmount: 25000,
        downPaymentAmount: 0,
        cashReceivedAmount: 30000,
        paymentType: 'without_deposit',
      }),
    ).toBe(5000)
  })

  it('sans acompte : pas de monnaie négative', () => {
    expect(
      calculateCashChangeAmount({
        totalAmount: 25000,
        downPaymentAmount: 0,
        cashReceivedAmount: 10000,
        paymentType: 'without_deposit',
      }),
    ).toBe(0)
  })

  it('avec acompte : monnaie = reçu - acompte', () => {
    expect(
      calculateCashChangeAmount({
        totalAmount: 25000,
        downPaymentAmount: 7000,
        cashReceivedAmount: 10000,
        paymentType: 'with_deposit',
      }),
    ).toBe(3000)
  })

  it('avec acompte : pas de monnaie si reçu inférieur à l’acompte', () => {
    expect(
      calculateCashChangeAmount({
        totalAmount: 25000,
        downPaymentAmount: 7000,
        cashReceivedAmount: 5000,
        paymentType: 'with_deposit',
      }),
    ).toBe(0)
  })
})

describe('isCheckoutPaymentMethodRequired', () => {
  it('exige un moyen pour le paiement sans acompte', () => {
    expect(
      isCheckoutPaymentMethodRequired({
        paymentType: 'without_deposit',
        totalAmount: 25000,
        downPaymentAmount: 0,
      }),
    ).toBe(true)
  })

  it('n’exige pas de moyen pour un acompte nul', () => {
    expect(
      isCheckoutPaymentMethodRequired({
        paymentType: 'with_deposit',
        totalAmount: 25000,
        downPaymentAmount: 0,
      }),
    ).toBe(false)
  })

  it('exige un moyen lorsque l’acompte est positif', () => {
    expect(
      isCheckoutPaymentMethodRequired({
        paymentType: 'with_deposit',
        totalAmount: 25000,
        downPaymentAmount: 7000,
      }),
    ).toBe(true)
  })
})

describe('resolveCashAmountDue', () => {
  it('sans acompte exige le total', () => {
    expect(
      resolveCashAmountDue({
        totalAmount: 25000,
        downPaymentAmount: 0,
        paymentType: 'without_deposit',
      }),
    ).toBe(25000)
  })

  it('avec acompte exige au minimum l’acompte', () => {
    expect(
      resolveCashAmountDue({
        totalAmount: 25000,
        downPaymentAmount: 7000,
        paymentType: 'with_deposit',
      }),
    ).toBe(7000)
  })
})

describe('libellés et icônes de statut', () => {
  it('expose les libellés métier attendus', () => {
    expect(getPaymentStatusLabel('paid')).toBe('Payé')
    expect(getPaymentStatusLabel('partial')).toBe('Partiel')
    expect(getPaymentStatusLabel('pending')).toBe('En attente')
  })

  it('expose des icônes Bootstrap pour chaque statut', () => {
    expect(getPaymentStatusIcon('paid')).toContain('bi-')
    expect(getPaymentStatusIcon('partial')).toContain('bi-')
    expect(getPaymentStatusIcon('pending')).toContain('bi-')
  })

  it('expose des icônes pour le type de paiement caisse', () => {
    expect(getCheckoutPaymentTypeIcon('without_deposit')).toBe('bi-receipt')
    expect(getCheckoutPaymentTypeIcon('with_deposit')).toBe('bi-piggy-bank')
  })

  it('expose une icône pour les moyens de paiement courants', () => {
    expect(getPaymentMethodIcon('cash')).toContain('bi-')
    expect(getPaymentMethodIcon('card')).toContain('bi-')
  })
})
