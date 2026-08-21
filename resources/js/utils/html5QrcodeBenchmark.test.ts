import { describe, expect, it } from 'vitest'
import {
  buildHtml5QrcodeBenchmarkReport,
  determineHtml5QrcodeStatus,
  finalizeHtml5QrcodeBenchmarkResult,
  HTML5_QRCODE_BENCHMARK_CONFIGURATIONS,
  recordHtml5QrcodeDetection,
  shouldCountDetection,
} from '@/utils/html5QrcodeBenchmark'
import { buildLibraryComparisonTable } from '@/utils/quagga2/engineComparisonStorage'
import { calculateBarcodeBenchmarkScore } from '@/utils/quagga2/quagga2Benchmark'
import { classifyQuagga2Detection, isValidEan13 } from '@/utils/quagga2/quagga2Validation'

describe('html5-qrcode validation reuse', () => {
  it('validates EAN-13 expected barcode', () => {
    expect(isValidEan13('6043000070493')).toBe(true)
  })

  it('classifies expected barcode as correct', () => {
    expect(classifyQuagga2Detection('6043000070493', 'EAN_13', '6043000070493', 'ean_13')).toBe('CORRECT')
  })
})

describe('html5-qrcode deduplication', () => {
  it('ignores rapid duplicate values within window', () => {
    const previous = recordHtml5QrcodeDetection({
      configurationId: '1',
      configurationLabel: 'test',
      elapsedMs: 1000,
      payload: { rawValue: '6043000070493', format: 'EAN_13' },
      config: HTML5_QRCODE_BENCHMARK_CONFIGURATIONS[0]!.config,
      camera: {
        requestedWidth: 640,
        requestedHeight: 480,
        actualWidth: 480,
        actualHeight: 640,
        actualFps: 30,
        facingMode: 'environment',
        qrBoxRatio: 0.7,
        html5Config: HTML5_QRCODE_BENCHMARK_CONFIGURATIONS[0]!.config,
      },
      expectedBarcode: '6043000070493',
      expectedFormat: 'ean_13',
      previousCounted: null,
    })

    expect(shouldCountDetection(previous, '6043000070493', 1200)).toBe(false)
    expect(shouldCountDetection(previous, '6043000070493', 1700)).toBe(true)
  })
})

describe('html5-qrcode benchmark score', () => {
  it('reuses quagga2 weighted score', () => {
    const score = calculateBarcodeBenchmarkScore({
      frames: 100,
      detections: 10,
      correct: 5,
      falsePositiveRate: 20,
      timeToFirstCorrectMs: 1000,
      durationMs: 15000,
    })

    expect(score.total).toBeGreaterThan(0)
    expect(score.accuracy).toBeGreaterThan(0)
  })
})

describe('html5-qrcode benchmark finalize', () => {
  it('computes metrics from detections', () => {
    const config = HTML5_QRCODE_BENCHMARK_CONFIGURATIONS[1]!
    const camera = {
      requestedWidth: 640,
      requestedHeight: 480,
      actualWidth: 480,
      actualHeight: 640,
      actualFps: 30,
      facingMode: 'environment',
      qrBoxRatio: 0.7,
      html5Config: config.config,
    }

    const detection = recordHtml5QrcodeDetection({
      configurationId: config.id,
      configurationLabel: config.label,
      elapsedMs: 500,
      payload: { rawValue: '6043000070493', format: 'EAN_13' },
      config: config.config,
      camera,
      expectedBarcode: '6043000070493',
      expectedFormat: 'ean_13',
      previousCounted: null,
    })

    const result = finalizeHtml5QrcodeBenchmarkResult({
      configuration: config,
      camera,
      frames: 100,
      allDetections: [detection],
      expectedBarcode: '6043000070493',
      durationMs: 15000,
    })

    expect(result.correct).toBe(1)
    expect(result.status).toBe('CORRECT_ONCE')
    expect(result.actualWidth).toBe(480)
  })

  it('marks repeatable correct status', () => {
    const config = HTML5_QRCODE_BENCHMARK_CONFIGURATIONS[0]!
    const camera = {
      requestedWidth: 640,
      requestedHeight: 480,
      actualWidth: 640,
      actualHeight: 480,
      actualFps: 30,
      facingMode: 'environment',
      qrBoxRatio: 0.6,
      html5Config: config.config,
    }

    const detections = [500, 1200, 2000].map((elapsedMs, index) =>
      recordHtml5QrcodeDetection({
        configurationId: config.id,
        configurationLabel: config.label,
        elapsedMs,
        payload: { rawValue: '6043000070493', format: 'EAN_13' },
        config: config.config,
        camera,
        expectedBarcode: '6043000070493',
        expectedFormat: 'ean_13',
        previousCounted: index > 0 ? recordHtml5QrcodeDetection({
          configurationId: config.id,
          configurationLabel: config.label,
          elapsedMs: [500, 1200][index - 1]!,
          payload: { rawValue: '6043000070493', format: 'EAN_13' },
          config: config.config,
          camera,
          expectedBarcode: '6043000070493',
          expectedFormat: 'ean_13',
          previousCounted: null,
        }) : null,
      }),
    )

    const result = finalizeHtml5QrcodeBenchmarkResult({
      configuration: config,
      camera,
      frames: 200,
      allDetections: detections,
      expectedBarcode: '6043000070493',
      durationMs: 15000,
    })

    expect(result.correct).toBe(3)
    expect(result.status).toBe('REPEATABLE_CORRECT')
  })
})

describe('html5-qrcode status helper', () => {
  it('detects unstable decoding', () => {
    expect(determineHtml5QrcodeStatus({
      frames: 100,
      detections: 5,
      correct: 0,
      incorrect: 5,
      distinctValues: 3,
      mostFrequent: '030000030493',
      expectedBarcode: '6043000070493',
      temporalStability: 40,
    })).toBe('UNSTABLE_DECODING')
  })
})

describe('library comparison table', () => {
  it('shows unavailable engines as dashes', () => {
    const rows = buildLibraryComparisonTable(null, null, null)

    expect(rows).toHaveLength(3)
    expect(rows.every((row) => row.score === '—')).toBe(true)
  })
})

describe('html5-qrcode report', () => {
  it('builds benchmark report header', () => {
    const report = buildHtml5QrcodeBenchmarkReport({
      expectedBarcode: '6043000070493',
      expectedFormat: 'ean_13',
      durationSeconds: 15,
      settleMs: 1500,
      results: [],
      detections: [],
    })

    expect(report).toContain('=== HTML5-QRCODE BENCHMARK REPORT ===')
    expect(report).toContain('6043000070493')
  })
})
