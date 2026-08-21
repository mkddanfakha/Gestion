/**
 * Service de réception codes-barres via douchette HID (clavier).
 * Ne dépend pas de la caméra.
 */

import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'

export const DEFAULT_MAX_INTER_KEY_DELAY_MS = 50
export const DEFAULT_MIN_SCAN_LENGTH = 4
export const DEFAULT_TERMINATOR_KEYS = ['Enter'] as const

export type BarcodeKeyboardScanSource = 'wedge-input' | 'global-listener' | 'manual-submit'

export interface BarcodeKeyboardScannerOptions {
  maxInterKeyDelayMs?: number
  minScanLength?: number
  terminatorKeys?: readonly string[]
  onScan: (barcode: string, source: BarcodeKeyboardScanSource) => void
  onInvalidScan?: (raw: string, reason: string) => void
}

export interface BarcodeKeyboardScannerSession {
  destroy: () => void
  reset: () => void
  submitManual: (rawValue: string) => void
}

export interface AttachInputOptions extends BarcodeKeyboardScannerOptions {
  input: HTMLInputElement
  autofocus?: boolean
}

function isTerminatorKey(key: string, terminators: readonly string[]): boolean {
  return terminators.includes(key)
}

function isPrintableCharacter(key: string): boolean {
  return key.length === 1 && !key.startsWith('Dead')
}

export function isLikelyRetailBarcode(value: string): boolean {
  const normalized = normalizeBarcode(value)

  if (!/^\d+$/.test(normalized)) {
    return isValidBarcode(normalized)
  }

  const length = normalized.length

  return length === 8 || length === 12 || length === 13 || (length >= DEFAULT_MIN_SCAN_LENGTH && length <= 14)
}

export function finalizeBarcodeScan(rawValue: string): { ok: true; barcode: string } | { ok: false; reason: string } {
  const barcode = normalizeBarcode(rawValue)

  if (!barcode) {
    return { ok: false, reason: 'empty' }
  }

  if (!isValidBarcode(barcode)) {
    return { ok: false, reason: 'invalid-characters' }
  }

  if (barcode.length < DEFAULT_MIN_SCAN_LENGTH) {
    return { ok: false, reason: 'too-short' }
  }

  return { ok: true, barcode }
}

export interface GlobalKeyState {
  buffer: string
  lastKeyAt: number
}

export function createGlobalKeyState(): GlobalKeyState {
  return { buffer: '', lastKeyAt: 0 }
}

export function processGlobalKeyEvent(
  state: GlobalKeyState,
  event: { key: string; now: number },
  options: {
    maxInterKeyDelayMs?: number
    terminatorKeys?: readonly string[]
  } = {},
): { state: GlobalKeyState; completedValue: string | null } {
  const maxInterKeyDelayMs = options.maxInterKeyDelayMs ?? DEFAULT_MAX_INTER_KEY_DELAY_MS
  const terminatorKeys = options.terminatorKeys ?? DEFAULT_TERMINATOR_KEYS
  let { buffer, lastKeyAt } = state

  if (lastKeyAt > 0 && event.now - lastKeyAt > maxInterKeyDelayMs) {
    buffer = ''
  }

  if (isTerminatorKey(event.key, terminatorKeys)) {
    const completedValue = buffer.trim() || null
    return { state: createGlobalKeyState(), completedValue }
  }

  if (!isPrintableCharacter(event.key)) {
    return { state: { buffer, lastKeyAt }, completedValue: null }
  }

  if (buffer === '' || event.now - lastKeyAt <= maxInterKeyDelayMs) {
    buffer += event.key
    lastKeyAt = event.now
  } else {
    buffer = event.key
    lastKeyAt = event.now
  }

  return { state: { buffer, lastKeyAt }, completedValue: null }
}

export function createBarcodeKeyboardScanner(options: BarcodeKeyboardScannerOptions): BarcodeKeyboardScannerSession {
  const maxInterKeyDelayMs = options.maxInterKeyDelayMs ?? DEFAULT_MAX_INTER_KEY_DELAY_MS
  const minScanLength = options.minScanLength ?? DEFAULT_MIN_SCAN_LENGTH
  const terminatorKeys = options.terminatorKeys ?? DEFAULT_TERMINATOR_KEYS

  let state = createGlobalKeyState()
  let destroyed = false

  const reset = (): void => {
    state = createGlobalKeyState()
  }

  const emitScan = (rawValue: string, source: BarcodeKeyboardScanSource): void => {
    const result = finalizeBarcodeScan(rawValue)

    if (!result.ok) {
      options.onInvalidScan?.(rawValue, result.reason)
      return
    }

    if (result.barcode.length < minScanLength) {
      options.onInvalidScan?.(rawValue, 'too-short')
      return
    }

    options.onScan(result.barcode, source)
  }

  const handleKeyDown = (event: KeyboardEvent): void => {
    if (destroyed) {
      return
    }

    const outcome = processGlobalKeyEvent(state, { key: event.key, now: performance.now() }, {
      maxInterKeyDelayMs,
      terminatorKeys,
    })

    state = outcome.state

    if (outcome.completedValue) {
      event.preventDefault()
      emitScan(outcome.completedValue, 'global-listener')
    }
  }

  document.addEventListener('keydown', handleKeyDown, true)

  return {
    destroy: () => {
      destroyed = true
      document.removeEventListener('keydown', handleKeyDown, true)
      reset()
    },
    reset,
    submitManual: (rawValue: string) => {
      emitScan(rawValue, 'manual-submit')
    },
  }
}

export function attachBarcodeKeyboardScannerToInput(options: AttachInputOptions): BarcodeKeyboardScannerSession {
  const maxInterKeyDelayMs = options.maxInterKeyDelayMs ?? DEFAULT_MAX_INTER_KEY_DELAY_MS
  const minScanLength = options.minScanLength ?? DEFAULT_MIN_SCAN_LENGTH
  const terminatorKeys = options.terminatorKeys ?? DEFAULT_TERMINATOR_KEYS
  const { input } = options

  let lastKeyAt = 0
  let wedgeSequenceActive = false
  let destroyed = false

  const reset = (): void => {
    lastKeyAt = 0
    wedgeSequenceActive = false
  }

  const emitScan = (rawValue: string, source: BarcodeKeyboardScanSource): void => {
    const result = finalizeBarcodeScan(rawValue)

    if (!result.ok) {
      options.onInvalidScan?.(rawValue, result.reason)
      return
    }

    if (result.barcode.length < minScanLength) {
      options.onInvalidScan?.(rawValue, 'too-short')
      return
    }

    options.onScan(result.barcode, source)
  }

  const handleKeyDown = (event: KeyboardEvent): void => {
    if (destroyed || event.target !== input) {
      return
    }

    const now = performance.now()

    if (isTerminatorKey(event.key, terminatorKeys)) {
      event.preventDefault()
      const value = input.value
      input.value = ''
      reset()
      emitScan(value, wedgeSequenceActive ? 'wedge-input' : 'manual-submit')
      return
    }

    if (!isPrintableCharacter(event.key)) {
      return
    }

    if (lastKeyAt === 0) {
      wedgeSequenceActive = false
    } else if (now - lastKeyAt <= maxInterKeyDelayMs) {
      wedgeSequenceActive = true
    } else {
      wedgeSequenceActive = false
    }

    lastKeyAt = now
  }

  input.addEventListener('keydown', handleKeyDown)

  if (options.autofocus !== false) {
    queueMicrotask(() => {
      if (!destroyed && document.activeElement !== input) {
        input.focus()
      }
    })
  }

  return {
    destroy: () => {
      destroyed = true
      input.removeEventListener('keydown', handleKeyDown)
      reset()
    },
    reset,
    submitManual: (rawValue: string) => {
      emitScan(rawValue, 'manual-submit')
    },
  }
}

export function refocusBarcodeInput(input: HTMLInputElement | null | undefined): void {
  if (!input || input.disabled) {
    return
  }

  queueMicrotask(() => {
    if (!input.disabled) {
      input.focus()
      input.select()
    }
  })
}
