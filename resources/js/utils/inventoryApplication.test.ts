import { describe, expect, it } from 'vitest'
import {
  formatApplicationSummaryLine,
  inventoryApplicationConfirmMessage,
  isInventoryReadOnly,
  shouldShowApplyButton,
  shouldShowCloseButton,
  type InventoryApplicationSummary,
} from './inventoryApplication'

const sampleSummary: InventoryApplicationSummary = {
  total_items: 100,
  adjusted_items: 27,
  unchanged_items: 73,
  positive_adjustments: 10,
  negative_adjustments: 17,
  total_positive_quantity: 35,
  total_negative_quantity: 59,
  net_adjustment: -24,
}

describe('inventoryApplication', () => {
  it('shows apply only for validated sessions with permission', () => {
    expect(shouldShowApplyButton('validated', true, true)).toBe(true)
    expect(shouldShowApplyButton('validated', true, false)).toBe(false)
    expect(shouldShowApplyButton('review', true, true)).toBe(false)
  })

  it('shows close only for applied sessions with permission', () => {
    expect(shouldShowCloseButton('applied', true, true)).toBe(true)
    expect(shouldShowCloseButton('validated', true, true)).toBe(false)
  })

  it('marks closed and cancelled sessions as read only', () => {
    expect(isInventoryReadOnly('closed')).toBe(true)
    expect(isInventoryReadOnly('counting')).toBe(false)
  })

  it('builds confirmation message from adjusted item count', () => {
    expect(inventoryApplicationConfirmMessage(27)).toContain('27 produit(s)')
  })

  it('formats application summary line', () => {
    expect(formatApplicationSummaryLine(sampleSummary)).toBe(
      '27 produit(s) ajusté(s), 73 inchangé(s), net -24',
    )
  })
})
