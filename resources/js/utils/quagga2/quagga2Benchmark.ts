/**
 * Benchmark DEV Quagga2 — métriques et score expérimental.
 *
 * Score (max 100) :
 *   40 % exactitude (correct / frames)
 *   20 % stabilité (correct / detections)
 *   15 % détection (detections / frames)
 *   15 % résistance faux positifs (100 - falsePositiveRate)
 *   10 % vitesse (time to first correct)
 */

import { average, computeRate } from '@/utils/barcodeSizeZoomComparison'
import { computeDistinctValues, computeMostFrequent } from '@/utils/barcodeStabilityFocusRepeatability'
import {
  classifyQuagga2Detection,
  isCheckDigitValid,
  normalizeQuaggaFormat,
  type Quagga2DetectionKind,
} from '@/utils/quagga2/quagga2Validation'
import type { Quagga2CameraSnapshot, Quagga2DevConfig, Quagga2DetectionPayload } from '@/utils/quagga2/quagga2Scanner'

export const DEFAULT_EXPECTED_BARCODE = '6043000070493'
export const DEFAULT_EXPECTED_FORMAT = 'ean_13'
export const DEFAULT_BENCHMARK_DURATION_SECONDS = 15
export const DEFAULT_BENCHMARK_SETTLE_MS = 1500

export const BENCHMARK_SCORE_WEIGHTS = {
  accuracy: 0.40,
  stability: 0.20,
  detection: 0.15,
  falsePositiveResistance: 0.15,
  speed: 0.10,
} as const

export type Quagga2ConfigStatus =
  | 'NO_DETECTION'
  | 'NO_CORRECT_READ'
  | 'CORRECT_ONCE'
  | 'REPEATABLE_CORRECT'
  | 'INCORRECT_DECODING'
  | 'CONFIGURATION_ERROR'

export interface Quagga2BenchmarkConfiguration {
  id: string
  label: string
  config: Quagga2DevConfig
}

export interface Quagga2BenchmarkDetection {
  id: string
  timestamp: string
  elapsedMs: number
  configurationId: string
  rawValue: string
  format: string
  normalizedFormat: string
  checkDigitValid: boolean
  isExpected: boolean
  classification: Quagga2DetectionKind
  widthRatio: number | null
  frameWidth: number | null
  frameHeight: number | null
  config: Quagga2DevConfig
}

export interface MultiFrameConfirmationLevel {
  level: 'FIRST_READ' | 'TWO_CONFIRM' | 'THREE_CONFIRM'
  confirmations: number
  correct: number
  incorrect: number
  nonConfirmed: number
  timeToConfirmationMs: number | null
}

export interface Quagga2BenchmarkScoreBreakdown {
  total: number
  accuracy: number
  stability: number
  detection: number
  falsePositiveResistance: number
  speed: number
}

export interface Quagga2BenchmarkResult {
  configurationId: string
  label: string
  requestedWidth: number
  requestedHeight: number
  actualWidth: number | null
  actualHeight: number | null
  actualFps: number | null
  frames: number
  detections: number
  correct: number
  incorrect: number
  correctRate: string
  detectionRate: string
  distinctValues: number
  mostFrequent: string | null
  checkDigitValidDetections: number
  checkDigitInvalidDetections: number
  correctStability: string
  temporalStability: string
  falsePositiveRate: string
  averageWidthRatio: number | null
  timeToFirstDetectionMs: number | null
  timeToFirstCorrectMs: number | null
  multiFrameLevels: MultiFrameConfirmationLevel[]
  score: Quagga2BenchmarkScoreBreakdown
  status: Quagga2ConfigStatus
  errorMessage: string | null
}

export const QUAGGA2_BENCHMARK_CONFIGURATIONS: Quagga2BenchmarkConfiguration[] = [
  {
    id: 'A',
    label: '640×480 / patch medium / halfSample',
    config: { width: 640, height: 480, patchSize: 'medium', halfSample: true, locate: true, frequency: 10, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
  {
    id: 'B',
    label: '640×480 / patch large / halfSample',
    config: { width: 640, height: 480, patchSize: 'large', halfSample: true, locate: true, frequency: 10, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
  {
    id: 'C',
    label: '640×480 / patch small / halfSample',
    config: { width: 640, height: 480, patchSize: 'small', halfSample: true, locate: true, frequency: 10, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
  {
    id: 'D',
    label: '1280×720 / patch medium / halfSample',
    config: { width: 1280, height: 720, patchSize: 'medium', halfSample: true, locate: true, frequency: 10, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
  {
    id: 'E',
    label: '1280×720 / patch large / halfSample',
    config: { width: 1280, height: 720, patchSize: 'large', halfSample: true, locate: true, frequency: 10, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
  {
    id: 'F',
    label: '1920×1080 / patch medium / halfSample',
    config: { width: 1920, height: 1080, patchSize: 'medium', halfSample: true, locate: true, frequency: 8, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
  {
    id: 'G',
    label: '1920×1080 / patch large / no halfSample',
    config: { width: 1920, height: 1080, patchSize: 'large', halfSample: false, locate: true, frequency: 8, numOfWorkers: 2, readers: ['ean_reader', 'ean_8_reader', 'upc_reader'] },
  },
]

export function recordQuagga2Detection(input: {
  configurationId: string
  elapsedMs: number
  payload: Quagga2DetectionPayload
  config: Quagga2DevConfig
  expectedBarcode: string
  expectedFormat: string
}): Quagga2BenchmarkDetection {
  const normalizedFormat = normalizeQuaggaFormat(input.payload.format)
  const checkDigitValid = isCheckDigitValid(normalizedFormat, input.payload.rawValue)
  const isExpected = input.payload.rawValue === input.expectedBarcode && normalizedFormat === input.expectedFormat.replace('-', '_')
  const classification = classifyQuagga2Detection(
    input.payload.rawValue,
    input.payload.format,
    input.expectedBarcode,
    input.expectedFormat,
  )

  return {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    elapsedMs: input.elapsedMs,
    configurationId: input.configurationId,
    rawValue: input.payload.rawValue,
    format: input.payload.format,
    normalizedFormat,
    checkDigitValid,
    isExpected,
    classification,
    widthRatio: input.payload.widthRatio,
    frameWidth: input.payload.frameWidth,
    frameHeight: input.payload.frameHeight,
    config: input.config,
  }
}

export function analyzeMultiFrameConfirmations(
  detections: Quagga2BenchmarkDetection[],
  expectedBarcode: string,
): MultiFrameConfirmationLevel[] {
  const levels: Array<{ level: MultiFrameConfirmationLevel['level']; required: number }> = [
    { level: 'FIRST_READ', required: 1 },
    { level: 'TWO_CONFIRM', required: 2 },
    { level: 'THREE_CONFIRM', required: 3 },
  ]

  const correctDetections = detections.filter((item) => item.classification === 'CORRECT')

  return levels.map(({ level, required }) => {
    let confirmations = 0
    let correct = 0
    let incorrect = 0
    let streak = 0
    let streakStartMs: number | null = null
    let timeToConfirmationMs: number | null = null

    for (const detection of detections) {
      if (detection.rawValue === expectedBarcode && detection.classification === 'CORRECT') {
        streak += 1

        if (streak === 1) {
          streakStartMs = detection.elapsedMs
        }
      } else {
        streak = 0
        streakStartMs = null
      }

      if (streak >= required) {
        confirmations += 1
        correct += 1
        timeToConfirmationMs = streakStartMs != null
          ? detection.elapsedMs - streakStartMs
          : timeToConfirmationMs
        streak = 0
        streakStartMs = null
      } else if (detection.classification !== 'CORRECT' && detection.classification !== 'NO_DETECTION') {
        incorrect += 1
      }
    }

    return {
      level,
      confirmations,
      correct,
      incorrect,
      nonConfirmed: Math.max(0, detections.length - confirmations),
      timeToConfirmationMs,
    }
  })
}

export function calculateBarcodeBenchmarkScore(input: {
  frames: number
  detections: number
  correct: number
  falsePositiveRate: number
  timeToFirstCorrectMs: number | null
  durationMs: number
}): Quagga2BenchmarkScoreBreakdown {
  const accuracy = input.frames > 0 ? Math.min(100, (input.correct / input.frames) * 100) : 0
  const stability = input.detections > 0 ? Math.min(100, (input.correct / input.detections) * 100) : 0
  const detection = input.frames > 0 ? Math.min(100, (input.detections / input.frames) * 100) : 0
  const falsePositiveResistance = Math.max(0, 100 - input.falsePositiveRate)
  let speed = 0

  if (input.timeToFirstCorrectMs != null && input.durationMs > 0) {
    speed = Math.max(0, 100 - (input.timeToFirstCorrectMs / input.durationMs) * 100)
  }

  const total = Number((
    accuracy * BENCHMARK_SCORE_WEIGHTS.accuracy
    + stability * BENCHMARK_SCORE_WEIGHTS.stability
    + detection * BENCHMARK_SCORE_WEIGHTS.detection
    + falsePositiveResistance * BENCHMARK_SCORE_WEIGHTS.falsePositiveResistance
    + speed * BENCHMARK_SCORE_WEIGHTS.speed
  ).toFixed(2))

  return {
    total,
    accuracy: Number(accuracy.toFixed(2)),
    stability: Number(stability.toFixed(2)),
    detection: Number(detection.toFixed(2)),
    falsePositiveResistance: Number(falsePositiveResistance.toFixed(2)),
    speed: Number(speed.toFixed(2)),
  }
}

export function determineQuagga2Status(input: {
  detections: number
  correct: number
  incorrectRepeated: boolean
}): Quagga2ConfigStatus {
  if (input.detections === 0) {
    return 'NO_DETECTION'
  }

  if (input.correct === 0) {
    return input.incorrectRepeated ? 'INCORRECT_DECODING' : 'NO_CORRECT_READ'
  }

  if (input.correct === 1) {
    return 'CORRECT_ONCE'
  }

  return 'REPEATABLE_CORRECT'
}

export function finalizeQuagga2BenchmarkResult(input: {
  configuration: Quagga2BenchmarkConfiguration
  camera: Quagga2CameraSnapshot
  frames: number
  detections: Quagga2BenchmarkDetection[]
  expectedBarcode: string
  durationMs: number
  errorMessage?: string | null
}): Quagga2BenchmarkResult {
  const values = input.detections.map((item) => item.rawValue)
  const mostFrequent = computeMostFrequent(values)
  const correct = input.detections.filter((item) => item.classification === 'CORRECT').length
  const incorrect = input.detections.length - correct
  const checkDigitValidDetections = input.detections.filter((item) => item.checkDigitValid).length
  const falsePositiveRate = input.detections > 0 ? ((incorrect / input.detections) * 100) : 0
  const temporalStability = input.detections.length > 0 ? (mostFrequent.count / input.detections) * 100 : 0
  const correctStability = input.detections.length > 0 ? (correct / input.detections) * 100 : 0
  const widthRatios = input.detections.map((item) => item.widthRatio).filter((value): value is number => value != null)
  const firstDetection = input.detections[0] ?? null
  const firstCorrect = input.detections.find((item) => item.classification === 'CORRECT') ?? null
  const incorrectRepeated = input.detections.filter((item) => item.classification !== 'CORRECT').length >= 3

  return {
    configurationId: input.configuration.id,
    label: input.configuration.label,
    requestedWidth: input.camera.requestedWidth,
    requestedHeight: input.camera.requestedHeight,
    actualWidth: input.camera.actualWidth,
    actualHeight: input.camera.actualHeight,
    actualFps: input.camera.actualFps,
    frames: input.frames,
    detections: input.detections.length,
    correct,
    incorrect,
    correctRate: computeRate(correct, input.frames),
    detectionRate: computeRate(input.detections.length, input.frames),
    distinctValues: computeDistinctValues(values),
    mostFrequent: mostFrequent.value,
    checkDigitValidDetections,
    checkDigitInvalidDetections: input.detections.length - checkDigitValidDetections,
    correctStability: `${correctStability.toFixed(1)}%`,
    temporalStability: `${temporalStability.toFixed(1)}%`,
    falsePositiveRate: `${falsePositiveRate.toFixed(1)}%`,
    averageWidthRatio: widthRatios.length ? Number(average(widthRatios)!.toFixed(4)) : null,
    timeToFirstDetectionMs: firstDetection?.elapsedMs ?? null,
    timeToFirstCorrectMs: firstCorrect?.elapsedMs ?? null,
    multiFrameLevels: analyzeMultiFrameConfirmations(input.detections, input.expectedBarcode),
    score: calculateBarcodeBenchmarkScore({
      frames: input.frames,
      detections: input.detections.length,
      correct,
      falsePositiveRate,
      timeToFirstCorrectMs: firstCorrect?.elapsedMs ?? null,
      durationMs: input.durationMs,
    }),
    status: input.errorMessage
      ? 'CONFIGURATION_ERROR'
      : determineQuagga2Status({ detections: input.detections.length, correct, incorrectRepeated }),
    errorMessage: input.errorMessage ?? null,
  }
}

export function buildQuagga2BenchmarkCsv(results: Quagga2BenchmarkResult[]): string {
  const header = [
    'configurationId', 'label', 'requestedResolution', 'actualResolution', 'frames', 'detections',
    'correct', 'correctRate', 'detectionRate', 'falsePositiveRate', 'temporalStability',
    'timeToFirstDetection', 'timeToFirstCorrect', 'score', 'status',
  ].join(',')

  const rows = results.map((item) => [
    item.configurationId,
    item.label,
    `${item.requestedWidth}x${item.requestedHeight}`,
    `${item.actualWidth ?? '—'}x${item.actualHeight ?? '—'}`,
    item.frames,
    item.detections,
    item.correct,
    item.correctRate,
    item.detectionRate,
    item.falsePositiveRate,
    item.temporalStability,
    item.timeToFirstDetectionMs ?? '',
    item.timeToFirstCorrectMs ?? '',
    item.score.total,
    item.status,
  ].join(','))

  return [header, ...rows].join('\n')
}

export function buildQuagga2BenchmarkReport(options: {
  expectedBarcode: string
  expectedFormat: string
  durationSeconds: number
  settleMs: number
  results: Quagga2BenchmarkResult[]
  detections: Quagga2BenchmarkDetection[]
}): string {
  const best = [...options.results].sort((a, b) => b.score.total - a.score.total)[0] ?? null

  return [
    '=== QUAGGA2 BENCHMARK REPORT ===',
    '',
    `Expected: ${options.expectedBarcode} (${options.expectedFormat})`,
    `Duration: ${options.durationSeconds}s`,
    `Settle: ${options.settleMs}ms`,
    '',
    `Configurations tested: ${options.results.length}`,
    `Total detections: ${options.detections.length}`,
    best ? `Best configuration: ${best.label} (score ${best.score.total})` : 'Best configuration: —',
    '',
    'EXPERIMENTAL ONLY — NOT PRODUCTION',
  ].join('\n')
}
