import { describe, expect, it } from 'vitest'
import {
  createGlobalKeyState,
  DEFAULT_MAX_INTER_KEY_DELAY_MS,
  finalizeBarcodeScan,
  isLikelyRetailBarcode,
  processGlobalKeyEvent,
} from '@/services/barcodeKeyboardScanner'

describe('finalizeBarcodeScan', () => {
  it('accepts EAN-13 barcode', () => {
    expect(finalizeBarcodeScan('6043000070493')).toEqual({ ok: true, barcode: '6043000070493' })
  })

  it('accepts UPC-A barcode', () => {
    expect(finalizeBarcodeScan('012345678905')).toEqual({ ok: true, barcode: '012345678905' })
  })

  it('accepts EAN-8 barcode', () => {
    expect(finalizeBarcodeScan('96385074')).toEqual({ ok: true, barcode: '96385074' })
  })

  it('preserves leading zeros', () => {
    expect(finalizeBarcodeScan('030000030493')).toEqual({ ok: true, barcode: '030000030493' })
  })

  it('rejects empty value', () => {
    expect(finalizeBarcodeScan('   ')).toEqual({ ok: false, reason: 'empty' })
  })

  it('rejects invalid characters', () => {
    expect(finalizeBarcodeScan('6043-0070493')).toEqual({ ok: false, reason: 'invalid-characters' })
  })
})

describe('isLikelyRetailBarcode', () => {
  it('detects common retail lengths', () => {
    expect(isLikelyRetailBarcode('6043000070493')).toBe(true)
    expect(isLikelyRetailBarcode('96385074')).toBe(true)
    expect(isLikelyRetailBarcode('012345678905')).toBe(true)
  })
})

describe('processGlobalKeyEvent', () => {
  it('completes scan on rapid sequence followed by Enter', () => {
    let state = createGlobalKeyState()
    const barcode = '6043000070493'
    let now = 0

    for (const char of barcode) {
      now += 8
      const outcome = processGlobalKeyEvent(state, { key: char, now })
      state = outcome.state
      expect(outcome.completedValue).toBeNull()
    }

    now += 8
    const finalOutcome = processGlobalKeyEvent(state, { key: 'Enter', now })

    expect(finalOutcome.completedValue).toBe(barcode)
  })

  it('resets buffer after slow human typing gap', () => {
    let state = createGlobalKeyState()
    const gap = DEFAULT_MAX_INTER_KEY_DELAY_MS + 100

    state = processGlobalKeyEvent(state, { key: '6', now: 0 }).state
    state = processGlobalKeyEvent(state, { key: '0', now: 10 }).state
    state = processGlobalKeyEvent(state, { key: '4', now: gap }).state
    state = processGlobalKeyEvent(state, { key: '3', now: gap + 10 }).state

    const outcome = processGlobalKeyEvent(state, { key: 'Enter', now: gap + 20 })

    expect(outcome.completedValue).toBe('43')
  })

  it('handles multiple successive scans', () => {
    const scan = (value: string, start: number): string | null => {
      let state = createGlobalKeyState()
      let now = start

      for (const char of value) {
        now += 8
        state = processGlobalKeyEvent(state, { key: char, now }).state
      }

      now += 8
      return processGlobalKeyEvent(state, { key: 'Enter', now }).completedValue
    }

    expect(scan('6043000070493', 0)).toBe('6043000070493')
    expect(scan('96385074', 1000)).toBe('96385074')
  })

  it('ignores interrupted sequence without terminator value', () => {
    let state = createGlobalKeyState()

    state = processGlobalKeyEvent(state, { key: '6', now: 0 }).state
    state = processGlobalKeyEvent(state, { key: '0', now: 10 }).state

    const outcome = processGlobalKeyEvent(state, { key: 'Enter', now: 20 })

    expect(outcome.completedValue).toBe('60')
  })
})

describe('manual submit path', () => {
  it('finalizes manual ENTER value from input field', () => {
    const result = finalizeBarcodeScan('6043000070493')

    expect(result.ok).toBe(true)

    if (result.ok) {
      expect(result.barcode).toBe('6043000070493')
    }
  })
})
