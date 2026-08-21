import { describe, expect, it } from 'vitest'
import {
  analyzeMultiFrameLevels,
  analyzeWidthRatioBuckets,
  assignWidthRatioBucket,
  buildMatrixConfigurations,
  classifyDetection,
  computeExperimentalScore,
  computeGroupRepeatability,
  createEmptyMatrixResult,
  determineConclusion,
  determineMatrixConfigStatus,
  evaluateProductionCriteria,
  getEffectiveVideoDimensions,
  isCheckDigitValid,
  isValidEan13,
  isValidUpcA,
  RESOLUTION_PRESETS,
} from '@/utils/barcodeDecodeReliabilityMatrix'

const EXPECTED = '6043000070493'

describe('EAN-13 validation', () => {
  it('accepts the physical benchmark barcode', () => {
    expect(isValidEan13(EXPECTED)).toBe(true)
    expect(isCheckDigitValid('ean_13', EXPECTED)).toBe(true)
  })

  it('rejects invalid check digits', () => {
    expect(isValidEan13('6043000070490')).toBe(false)
    expect(isValidEan13('1234567890123')).toBe(false)
  })
})

describe('UPC-A validation', () => {
  it('validates 12-digit codes with correct check digit', () => {
    expect(isValidUpcA('036000291452')).toBe(true)
  })

  it('rejects invalid UPC-A codes', () => {
    expect(isValidUpcA('036000291450')).toBe(false)
    expect(isValidUpcA('604300007049')).toBe(false)
  })
})

describe('classifyDetection', () => {
  it('classifies expected EAN-13 reads', () => {
    expect(classifyDetection(EXPECTED, 'ean_13', EXPECTED, 'ean_13')).toBe('EXPECTED')
  })

  it('classifies correct value with wrong format', () => {
    expect(classifyDetection(EXPECTED, 'upc_a', EXPECTED, 'ean_13')).toBe('CORRECT_VALUE')
  })

  it('classifies valid wrong values separately from expected', () => {
    expect(classifyDetection('5901234123457', 'ean_13', EXPECTED, 'ean_13')).toBe('VALID_WRONG')
  })

  it('classifies invalid check digit reads', () => {
    expect(classifyDetection('6043000070490', 'ean_13', EXPECTED, 'ean_13')).toBe('INVALID')
  })

  it('classifies noise', () => {
    expect(classifyDetection('abc', 'unknown', EXPECTED, 'ean_13')).toBe('NOISE')
  })
})

describe('getEffectiveVideoDimensions', () => {
  it('detects swapped orientation', () => {
    const dims = getEffectiveVideoDimensions(1080, 1920, 1920, 1080)

    expect(dims.orientationSwapped).toBe(true)
    expect(dims.logicalWidth).toBe(1920)
    expect(dims.logicalHeight).toBe(1080)
  })

  it('keeps native dimensions when orientation matches', () => {
    const dims = getEffectiveVideoDimensions(1280, 720, 1280, 720)

    expect(dims.orientationSwapped).toBe(false)
    expect(dims.nativeWidth).toBe(1280)
    expect(dims.nativeHeight).toBe(720)
  })
})

describe('width ratio buckets', () => {
  it('assigns buckets for observed correct reads (~17%)', () => {
    expect(assignWidthRatioBucket(0.166)).toBe('16–18 %')
    expect(assignWidthRatioBucket(0.196)).toBe('18–20 %')
  })
})

describe('experimental score', () => {
  it('rewards correct reads and penalizes incorrect ones', () => {
    const good = computeExperimentalScore({
      expectedReads: 3,
      repetitionsWithCorrect: 2,
      multiFrameCorrectConfirmations: 2,
      checkDigitValidCorrect: 3,
      incorrectReads: 1,
      invalidReads: 2,
      distinctWrongValues: 2,
      repeatabilityRatio: 0.66,
    })

    const bad = computeExperimentalScore({
      expectedReads: 0,
      repetitionsWithCorrect: 0,
      multiFrameCorrectConfirmations: 0,
      checkDigitValidCorrect: 0,
      incorrectReads: 10,
      invalidReads: 8,
      distinctWrongValues: 6,
      repeatabilityRatio: 0,
    })

    expect(good).toBeGreaterThan(bad)
  })
})

describe('configuration status', () => {
  it('marks stable correct configurations', () => {
    expect(determineMatrixConfigStatus({
      detections: 5,
      expectedReads: 3,
      longestCorrectSequence: 2,
      temporalStability: '50%',
    })).toBe('STABLE_CORRECT')
  })

  it('marks no detection', () => {
    expect(determineMatrixConfigStatus({
      detections: 0,
      expectedReads: 0,
      longestCorrectSequence: 0,
      temporalStability: null,
    })).toBe('NO_DETECTION')
  })
})

describe('repeatability and conclusion', () => {
  it('computes group repeatability during a run', () => {
    const preset = RESOLUTION_PRESETS[0]!
    const config = {
      id: 'A-test',
      phase: 'A' as const,
      resolutionPreset: preset,
      focusRequested: 0.22,
      repetition: 2,
      placementGuideLabel: 'free',
      expectedBarcode: EXPECTED,
      expectedFormat: 'ean_13',
      requestedZoom: 1,
      orderIndex: 0,
    }

    const prior = {
      ...createEmptyMatrixResult({ ...config, id: 'prior', repetition: 1 }),
      expectedReads: 1,
      frames: 10,
    }

    const repeatability = computeGroupRepeatability(config, 2, [prior])

    expect(repeatability.repetitionsWithCorrect).toBe(2)
    expect(repeatability.totalRepetitionsInGroup).toBe(2)
  })

  it('returns NO_RELIABLE_CONFIGURATION when nothing is correct', () => {
    const preset = RESOLUTION_PRESETS[0]!
    const config = buildMatrixConfigurations({
      phase: 'A',
      resolutionPresets: [preset],
      focusLevels: [0.22],
      repetitions: 1,
      placementGuideLabel: 'free',
      expectedBarcode: EXPECTED,
      expectedFormat: 'ean_13',
      orderMode: 'FIXED',
    })[0]!

    const result = createEmptyMatrixResult(config)
    const criteria = evaluateProductionCriteria([result])

    expect(determineConclusion([result], criteria)).toBe('NO_RELIABLE_CONFIGURATION')
    expect(criteria.meetsCriteria).toBe(false)
  })
})

describe('multi-frame analysis', () => {
  it('counts identical streak confirmations', () => {
    const preset = RESOLUTION_PRESETS[0]!
    const levels = analyzeMultiFrameLevels([
      {
        id: '1',
        timestamp: 't1',
        elapsedMs: 0,
        configId: 'c1',
        phase: 'A',
        repetition: 1,
        resolutionLabel: preset.label,
        focusRequested: 0.22,
        focusActual: '0.22',
        zoomActual: '1×',
        placementGuideLabel: 'free',
        format: 'ean_13',
        rawValue: EXPECTED,
        classification: 'EXPECTED',
        checkDigitValid: true,
        hammingDistance: 0,
        matchingDigits: 13,
        boundingBoxWidth: 100,
        boundingBoxHeight: 50,
        widthRatio: 0.17,
        heightRatio: 0.08,
        nativeVideoWidth: 1280,
        nativeVideoHeight: 720,
        logicalVideoWidth: 1280,
        logicalVideoHeight: 720,
        sharpness: 500,
      },
      {
        id: '2',
        timestamp: 't2',
        elapsedMs: 150,
        configId: 'c1',
        phase: 'A',
        repetition: 1,
        resolutionLabel: preset.label,
        focusRequested: 0.22,
        focusActual: '0.22',
        zoomActual: '1×',
        placementGuideLabel: 'free',
        format: 'ean_13',
        rawValue: EXPECTED,
        classification: 'EXPECTED',
        checkDigitValid: true,
        hammingDistance: 0,
        matchingDigits: 13,
        boundingBoxWidth: 100,
        boundingBoxHeight: 50,
        widthRatio: 0.17,
        heightRatio: 0.08,
        nativeVideoWidth: 1280,
        nativeVideoHeight: 720,
        logicalVideoWidth: 1280,
        logicalVideoHeight: 720,
        sharpness: 520,
      },
    ], EXPECTED)

    expect(levels.find((item) => item.level === 2)?.correctConfirmations).toBe(1)
  })
})

describe('width ratio bucket aggregation', () => {
  it('aggregates detections by measured width ratio', () => {
    const preset = RESOLUTION_PRESETS[0]!
    const base = {
      configId: 'c1',
      phase: 'A' as const,
      repetition: 1,
      resolutionLabel: preset.label,
      focusRequested: 0.22,
      focusActual: '0.22',
      zoomActual: '1×',
      placementGuideLabel: 'free',
      format: 'ean_13',
      checkDigitValid: true,
      hammingDistance: 0,
      matchingDigits: 13,
      boundingBoxWidth: 200,
      boundingBoxHeight: 80,
      heightRatio: 0.08,
      nativeVideoWidth: 1280,
      nativeVideoHeight: 720,
      logicalVideoWidth: 1280,
      logicalVideoHeight: 720,
      sharpness: 500,
      elapsedMs: 0,
      timestamp: 't',
    }

    const buckets = analyzeWidthRatioBuckets([
      {
        ...base,
        id: '1',
        rawValue: EXPECTED,
        classification: 'EXPECTED',
        widthRatio: 0.17,
      },
      {
        ...base,
        id: '2',
        rawValue: '5901234123457',
        classification: 'VALID_WRONG',
        widthRatio: 0.17,
      },
    ])

    const bucket = buckets.find((item) => item.bucketLabel === '16–18 %')

    expect(bucket?.detections).toBe(2)
    expect(bucket?.expectedReads).toBe(1)
  })
})
