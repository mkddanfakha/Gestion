import { describe, expect, it } from 'vitest'
import {
  analyzeMultiFrameConfirmations,
  calculateBarcodeBenchmarkScore,
  determineQuagga2Status,
  finalizeQuagga2BenchmarkResult,
  QUAGGA2_BENCHMARK_CONFIGURATIONS,
  recordQuagga2Detection,
} from '@/utils/quagga2/quagga2Benchmark'
import { buildComparisonRows } from '@/utils/quagga2/engineComparisonStorage'
import {
  classifyQuagga2Detection,
  isValidEan13,
  normalizeQuaggaFormat,
} from '@/utils/quagga2/quagga2Validation'

describe('quagga2 validation', () => {
  it('validates EAN-13 expected barcode', () => {
    expect(isValidEan13('6043000070493')).toBe(true)
  })

  it('classifies wrong valid checksum as incorrect', () => {
    expect(classifyQuagga2Detection('5046006030493', 'ean_13', '6043000070493', 'ean_13')).toBe('WRONG_VALID_CHECKSUM')
  })

  it('normalizes quagga format names', () => {
    expect(normalizeQuaggaFormat('ean_13')).toBe('ean_13')
  })
})

describe('quagga2 benchmark score', () => {
  it('scores better configs higher', () => {
    const good = calculateBarcodeBenchmarkScore({
      frames: 100,
      detections: 10,
      correct: 5,
      falsePositiveRate: 20,
      timeToFirstCorrectMs: 1000,
      durationMs: 15000,
    })
    const bad = calculateBarcodeBenchmarkScore({
      frames: 100,
      detections: 10,
      correct: 0,
      falsePositiveRate: 100,
      timeToFirstCorrectMs: null,
      durationMs: 15000,
    })

    expect(good.total).toBeGreaterThan(bad.total)
  })

  it('marks no correct read status', () => {
    expect(determineQuagga2Status({ detections: 5, correct: 0, incorrectRepeated: false })).toBe('NO_CORRECT_READ')
  })
})

describe('quagga2 benchmark finalize', () => {
  it('computes metrics from detections', () => {
    const config = QUAGGA2_BENCHMARK_CONFIGURATIONS[0]!
    const detection = recordQuagga2Detection({
      configurationId: config.id,
      elapsedMs: 500,
      payload: {
        rawValue: '6043000070493',
        format: 'ean_13',
        box: null,
        frameWidth: 640,
        frameHeight: 480,
        widthRatio: 0.5,
      },
      config: config.config,
      expectedBarcode: '6043000070493',
      expectedFormat: 'ean_13',
    })

    const result = finalizeQuagga2BenchmarkResult({
      configuration: config,
      camera: {
        requestedWidth: 640,
        requestedHeight: 480,
        actualWidth: 480,
        actualHeight: 640,
        actualFps: 30,
        facingMode: 'environment',
      },
      frames: 100,
      detections: [detection],
      expectedBarcode: '6043000070493',
      durationMs: 15000,
    })

    expect(result.correct).toBe(1)
    expect(result.status).toBe('CORRECT_ONCE')
  })
})

describe('engine comparison rows', () => {
  it('builds winner column', () => {
    const rows = buildComparisonRows(
      {
        engine: 'barcode_detector',
        savedAt: new Date().toISOString(),
        device: { userAgent: 'ua', browser: 'Chrome', devicePixelRatio: 2 },
        camera: { facingMode: 'environment', requestedResolution: '640×480', actualResolution: '480×640', fps: 30 },
        expectedBarcode: '6043000070493',
        expectedFormat: 'ean_13',
        durationSeconds: 15,
        sourceLabel: 'test',
        metrics: {
          detections: 10,
          correct: 5,
          incorrect: 5,
          detectionRate: '10%',
          correctRate: '5%',
          falsePositiveRate: '50%',
          checkDigitValid: 8,
          temporalStability: '60%',
          timeToFirstDetectionMs: 1000,
          timeToFirstCorrectMs: 2000,
          overallScore: 70,
        },
      },
      null,
    )

    expect(rows.length).toBeGreaterThan(0)
    expect(rows[0]!.barcodeDetector).toBe('10')
  })
})
