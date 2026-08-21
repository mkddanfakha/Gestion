/**
 * Validation DEV Quagga2 — réutilise les utilitaires checksum existants.
 */

import {
  computeEan13CheckDigit,
  computeEan8CheckDigit,
  isCheckDigitValid,
  isValidEan13,
  isValidEan8,
  isValidUpcA,
  matchesExpectedBarcode,
  matchesExpectedValue,
  type ExpectedBarcodeSpec,
} from '@/utils/barcodeReliabilityPhase2'

export {
  computeEan13CheckDigit,
  computeEan8CheckDigit,
  isCheckDigitValid,
  isValidEan13,
  isValidEan8,
  isValidUpcA,
  matchesExpectedBarcode,
  matchesExpectedValue,
  type ExpectedBarcodeSpec,
}

export type Quagga2DetectionKind =
  | 'NO_DETECTION'
  | 'INVALID_FORMAT'
  | 'INVALID_CHECKSUM'
  | 'WRONG_VALID_CHECKSUM'
  | 'CORRECT'
  | 'INCORRECT'
  | 'FALSE_POSITIVE'

export function normalizeQuaggaFormat(format: string | undefined): string {
  if (!format) {
    return 'unknown'
  }

  const normalized = format.toLowerCase().replace(/-/g, '_')

  if (normalized.includes('ean_13') || normalized === 'ean') {
    return 'ean_13'
  }

  if (normalized.includes('ean_8')) {
    return 'ean_8'
  }

  if (normalized.includes('upc_a') || normalized === 'upc') {
    return 'upc_a'
  }

  if (normalized.includes('upc_e')) {
    return 'upc_e'
  }

  return normalized
}

export function classifyQuagga2Detection(
  rawValue: string,
  format: string | undefined,
  expectedBarcode: string,
  expectedFormat: string,
): Quagga2DetectionKind {
  if (!rawValue) {
    return 'NO_DETECTION'
  }

  if (!/^\d+$/.test(rawValue)) {
    return 'INVALID_FORMAT'
  }

  const normalizedFormat = normalizeQuaggaFormat(format)
  const checkDigitValid = isCheckDigitValid(normalizedFormat, rawValue)
  const isExpected =
    rawValue === expectedBarcode
    && normalizedFormat === expectedFormat.replace('-', '_')

  if (isExpected && checkDigitValid) {
    return 'CORRECT'
  }

  if (checkDigitValid && rawValue !== expectedBarcode) {
    return 'WRONG_VALID_CHECKSUM'
  }

  if (!checkDigitValid) {
    return rawValue.length >= 8 ? 'INVALID_CHECKSUM' : 'FALSE_POSITIVE'
  }

  return 'INCORRECT'
}
