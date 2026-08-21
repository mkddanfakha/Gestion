import { describe, expect, it } from 'vitest'
import {
  BARCODE_SCORE_WEIGHTS,
  calculateBarcodeConfidenceScore,
  classifyPhase2Detection,
  computeCorrectStability,
  computeEan8CheckDigit,
  computeFalsePositiveRate,
  computeOverallScore,
  computeTemporalStability,
  DEFAULT_VALIDATION_POLICY,
  determineConfigStatus,
  evaluateValidationPolicy,
  isCheckDigitValid,
  isValidEan13,
  isValidEan8,
  isValidUpcA,
  matchesExpectedBarcode,
  type ExpectedBarcodeSpec,
  type Phase2RawDetection,
} from '@/utils/barcodeReliabilityPhase2'

const EXPECTED: ExpectedBarcodeSpec[] = [{ value: '6043000070493', format: 'ean_13' }]

describe('checksum validation', () => {
  it('validates EAN-13 benchmark barcode', () => {
    expect(isValidEan13('6043000070493')).toBe(true)
    expect(isCheckDigitValid('ean_13', '6043000070493')).toBe(true)
  })

  it('rejects invalid EAN-13', () => {
    expect(isValidEan13('6043000070490')).toBe(false)
  })

  it('validates UPC-A', () => {
    expect(isValidUpcA('036000291452')).toBe(true)
  })

  it('validates EAN-8', () => {
    expect(computeEan8CheckDigit('9638507')).toBe(4)
    expect(isValidEan8('96385074')).toBe(true)
  })
})

describe('classification', () => {
  it('marks wrong valid checksum separately', () => {
    expect(classifyPhase2Detection({
      rawValue: '5046006030493',
      format: 'ean_13',
      expectedBarcodes: EXPECTED,
      checkDigitValid: isCheckDigitValid('ean_13', '5046006030493'),
      sameValueDistinctFrameCount: 1,
      validated: false,
      hasAnyDetection: true,
    })).toBe('WRONG_VALID_CHECKSUM')
  })

  it('marks expected single read', () => {
    expect(classifyPhase2Detection({
      rawValue: '6043000070493',
      format: 'ean_13',
      expectedBarcodes: EXPECTED,
      checkDigitValid: true,
      sameValueDistinctFrameCount: 1,
      validated: false,
      hasAnyDetection: true,
    })).toBe('EXPECTED_SINGLE')
  })
})

describe('confidence score', () => {
  it('maxes near 100 for ideal expected detection', () => {
    const score = calculateBarcodeConfidenceScore({
      checkDigitValid: true,
      formatMatches: true,
      isExpected: true,
      sameValueCount: 4,
      temporalStabilityPercent: 95,
      sharpness: 900,
      widthRatio: 0.75,
    })

    expect(score).toBeGreaterThanOrEqual(80)
    expect(score).toBeLessThanOrEqual(100)
  })

  it('weights sum to 100 at maximum', () => {
    const total = Object.values(BARCODE_SCORE_WEIGHTS).reduce((sum, value) => sum + value, 0)

    expect(total).toBe(100)
  })
})

describe('temporal metrics', () => {
  it('computes stability for repeated values', () => {
    expect(computeTemporalStability(['a', 'a', 'a', 'b'])).toBe(75)
  })

  it('computes correct stability', () => {
    expect(computeCorrectStability(['6043000070493', '6043000070493', '123'], EXPECTED)).toBeCloseTo(66.67, 1)
  })
})

describe('validation policy', () => {
  const baseDetection = (frameIndex: number, elapsedMs: number): Phase2RawDetection => ({
    id: String(frameIndex),
    timestamp: 't',
    elapsedMs,
    configurationId: 'c1',
    frameIndex,
    detectionIndex: 0,
    rawValue: '6043000070493',
    format: 'ean_13',
    checkDigitValid: true,
    isExpected: true,
    videoWidth: 640,
    videoHeight: 480,
    requestedResolution: '640×480',
    actualResolution: '640×480',
    requestedFocus: 0.22,
    actualFocus: '0.22',
    requestedZoom: 1,
    actualZoom: '1×',
    sharpness: 500,
    widthRatio: 0.75,
    confidenceScore: 90,
    validationState: 'EXPECTED_SINGLE',
  })

  it('requires distinct frames for confirmations', () => {
    const detections = [
      baseDetection(1, 100),
      { ...baseDetection(1, 150), id: '2' },
      baseDetection(2, 400),
      baseDetection(3, 700),
    ]

    const result = evaluateValidationPolicy(detections, EXPECTED, DEFAULT_VALIDATION_POLICY)

    expect(result.validationCount).toBe(3)
    expect(result.validated).toBe(true)
  })

  it('rejects single detection', () => {
    const result = evaluateValidationPolicy([baseDetection(1, 100)], EXPECTED, DEFAULT_VALIDATION_POLICY)

    expect(result.validated).toBe(false)
  })
})

describe('rates and overall score', () => {
  it('computes false positive rate', () => {
    expect(computeFalsePositiveRate({ detections: 5, expectedDetections: 3 })).toBe(40)
  })

  it('ranks better configuration higher', () => {
    const good = computeOverallScore({
      expectedRate: 20,
      validationRate: 60,
      temporalStability: 80,
      falsePositiveRate: 10,
      detectionRate: 30,
      timeToValidationMs: 2000,
      durationMs: 15000,
    })
    const bad = computeOverallScore({
      expectedRate: 2,
      validationRate: 0,
      temporalStability: 20,
      falsePositiveRate: 90,
      detectionRate: 50,
      timeToValidationMs: null,
      durationMs: 15000,
    })

    expect(good.overall).toBeGreaterThan(bad.overall)
  })
})

describe('config status', () => {
  it('marks validated configs', () => {
    expect(determineConfigStatus({
      frames: 100,
      detections: 5,
      expectedDetections: 3,
      validationCount: 3,
      validated: true,
      errorMessage: null,
    })).toBe('VALIDATED')
  })

  it('marks no detection', () => {
    expect(determineConfigStatus({
      frames: 100,
      detections: 0,
      expectedDetections: 0,
      validationCount: 0,
      validated: false,
      errorMessage: null,
    })).toBe('NO_DETECTION')
  })
})

describe('matchesExpectedBarcode', () => {
  it('requires exact value and format', () => {
    expect(matchesExpectedBarcode('6043000070493', 'ean_13', EXPECTED)).toBe(true)
    expect(matchesExpectedBarcode('6043000070493', 'upc_a', EXPECTED)).toBe(false)
  })
})
