import {
  applyExperimentConfiguration,
  clampFocusForExperiment,
  classifyReadResult,
  createComparisonBarcodeDetector,
  extractBarcodeGeometry,
  EXPECTED_BARCODE,
  measureVideoSharpness,
  normalizeDetections,
  pickBestNativeBarcode,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  type AppliedExperimentSnapshot,
  type ConfigurationStatus,
  type EnvironmentDiagnostics,
  type FocusDistanceCapabilities,
  type ReadResultType,
  type TrackCapabilitiesSnapshot,
  type TrackSettingsSnapshot,
  type ValidationStatus,
} from '@/utils/barcodeDistanceFocusExperiment'
import { average, computeRate } from '@/utils/barcodeSizeZoomComparison'

export const DEFAULT_EXPECTED_BARCODE = EXPECTED_BARCODE
export const DEFAULT_EXPECTED_FORMAT = 'ean_13'
export const DEFAULT_FOCUS_LEVELS = [0.18, 0.2, 0.22, 0.24, 0.26] as const
export const DEFAULT_REPETITIONS = 3
export const REPETITION_OPTIONS = [1, 2, 3, 5] as const
export const DEFAULT_DURATION_SECONDS = 30
export const DURATION_OPTIONS = [15, 20, 30, 45] as const
export const DEFAULT_SETTLE_MS = 1500
export const SETTLE_OPTIONS = [500, 1000, 1500, 2000, 3000] as const
export const DETECTION_INTERVAL_MS = 150
export const FIXED_ZOOM = 1
export const MEDIUM_GUIDE_WIDTH_RATIO = 0.45
export const MEDIUM_SIZE_TARGET = '40–50% de la largeur de l\'image'

export const STABILITY_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 720 },
    height: { ideal: 1280 },
    frameRate: { ideal: 30 },
  },
  audio: false,
}

export const MULTI_FRAME_THRESHOLDS = [2, 3, 4, 5] as const

export {
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  createComparisonBarcodeDetector,
  applyExperimentConfiguration,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  clampFocusForExperiment,
  classifyReadResult,
  extractBarcodeGeometry,
  pickBestNativeBarcode,
}
export type { EnvironmentDiagnostics, AppliedExperimentSnapshot, ValidationStatus }

export type StabilityCategory = 'STABLE' | 'PROMISING' | 'FAILED'
export type RepeatabilityStatus = 'REPEATABLE' | 'NOT_REPEATABLE' | 'INSUFFICIENT_DATA'
export type ConclusionKind =
  | 'RELIABLE_PATTERN'
  | 'ISOLATED_CORRECT'
  | 'NO_CORRECT'
  | 'UNSTABLE_INCORRECT'

export interface StabilityRunConfiguration {
  id: string
  focusRequested: number
  repetition: number
  expectedBarcode: string
  requestedZoom: number
  orderIndex: number
}

export interface StabilityRawDetection {
  id: string
  timestamp: string
  elapsedMs: number
  focusRequested: number
  focusActual: string
  repetition: number
  format: string
  rawValue: string
  classification: 'CORRECT' | 'INCORRECT'
  boundingBox: { x: number; y: number; width: number; height: number } | null
  boundingBoxWidth: number | null
  boundingBoxHeight: number | null
  widthRatio: number | null
  heightRatio: number | null
  sharpness: number | null
}

export interface DetectionSnapshot {
  rawValue: string
  classification: 'CORRECT' | 'INCORRECT'
  elapsedMs: number
  sharpness: number | null
  timestamp: string
}

export interface StabilityConfigurationResult {
  configId: string
  focusRequested: number
  focusActual: string
  repetition: number
  zoomRequested: number
  zoomActual: string
  applied: AppliedExperimentSnapshot | null
  frames: number
  detections: number
  correct: number
  incorrect: number
  notFound: number
  detectionRate: string
  correctRate: string
  correctFrameRate: string
  distinctValues: number
  mostFrequentValue: string | null
  mostFrequentOccurrences: number
  correctOccurrences: number
  temporalStability: string | null
  correctStability: string | null
  longestIdenticalSequence: number
  longestCorrectSequence: number
  longestCorrectDetectionWindowMs: number
  averageDetectionIntervalMs: number | null
  minDetectionIntervalMs: number | null
  maxDetectionIntervalMs: number | null
  correctDetectionIntervalsMs: number[]
  averageSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  sharpnessAtDetection: number | null
  sharpnessAtCorrectDetection: number | null
  averageBarcodeWidthRatio: number | null
  stability: StabilityCategory
  configurationStatus: ConfigurationStatus
  orderIndex: number
}

export interface FocusAggregateSummary {
  focus: number
  totalFrames: number
  totalDetections: number
  correct: number
  incorrect: number
  detectionRate: string
  correctRate: string
  averageDistinctValues: string
  averageTemporalStability: string
  bestCorrectSequence: number
  averageSharpness: string
  repeatability: RepeatabilityStatus
}

export interface MultiFrameThresholdResult {
  threshold: number
  confirmations: number
  correctConfirmations: number
  incorrectConfirmations: number
}

export function resolveFocusLevels(
  requested: number[],
  capabilities: FocusDistanceCapabilities,
): number[] {
  return requested
    .map((value) => clampFocusForExperiment(value, capabilities))
    .filter((value): value is number => value != null)
}

export function isManualFocusSupported(capabilities: TrackCapabilitiesSnapshot): boolean {
  return capabilities.focusDistance.supported && capabilities.focusModes.includes('manual')
}

export function buildConfigurationOrder(options: {
  focusLevels: number[]
  repetitions: number
  expectedBarcode: string
  preserveOrder?: StabilityRunConfiguration[]
  randomized?: boolean
  randomSeed?: number
}): StabilityRunConfiguration[] {
  const base: StabilityRunConfiguration[] = []

  for (let repetition = 1; repetition <= options.repetitions; repetition += 1) {
    for (const focus of options.focusLevels) {
      base.push({
        id: `focus-${focus}-rep-${repetition}`,
        focusRequested: focus,
        repetition,
        expectedBarcode: options.expectedBarcode,
        requestedZoom: FIXED_ZOOM,
        orderIndex: 0,
      })
    }
  }

  if (options.preserveOrder && options.preserveOrder.length === base.length && !options.randomized) {
    return options.preserveOrder.map((item, index) => ({ ...item, orderIndex: index }))
  }

  const ordered = options.randomized
    ? seededShuffle(base, options.randomSeed ?? Date.now())
    : base

  return ordered.map((item, index) => ({ ...item, orderIndex: index }))
}

export function seededShuffle<T>(items: T[], seed: number): T[] {
  const shuffled = [...items]
  let state = seed >>> 0

  const random = (): number => {
    state = (state * 1664525 + 1013904223) >>> 0
    return state / 0x100000000
  }

  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(random() * (index + 1))
    ;[shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex]!, shuffled[index]!]
  }

  return shuffled
}

export function computeDistinctValues(rawValues: string[]): number {
  return new Set(rawValues.filter(Boolean)).size
}

export function computeMostFrequent(rawValues: string[]): { value: string | null; count: number } {
  if (rawValues.length === 0) {
    return { value: null, count: 0 }
  }

  const counts = new Map<string, number>()

  for (const value of rawValues) {
    counts.set(value, (counts.get(value) ?? 0) + 1)
  }

  let bestValue: string | null = null
  let bestCount = 0

  for (const [value, count] of counts.entries()) {
    if (count > bestCount) {
      bestValue = value
      bestCount = count
    }
  }

  return { value: bestValue, count: bestCount }
}

export function computeLongestIdenticalSequence(rawValues: string[]): number {
  if (rawValues.length === 0) {
    return 0
  }

  let longest = 1
  let current = 1

  for (let index = 1; index < rawValues.length; index += 1) {
    if (rawValues[index] === rawValues[index - 1]) {
      current += 1
      longest = Math.max(longest, current)
    } else {
      current = 1
    }
  }

  return longest
}

export function computeLongestCorrectSequence(detections: DetectionSnapshot[]): number {
  return computeLongestIdenticalSequence(
    detections.filter((item) => item.classification === 'CORRECT').map((item) => item.rawValue),
  )
}

export function computeLongestCorrectSequenceFromValues(
  detections: DetectionSnapshot[],
  expectedBarcode: string,
): number {
  let longest = 0
  let current = 0

  for (const detection of detections) {
    if (detection.rawValue === expectedBarcode) {
      current += 1
      longest = Math.max(longest, current)
    } else {
      current = 0
    }
  }

  return longest
}

export function formatPercent(ratio: number | null): string | null {
  if (ratio == null || !Number.isFinite(ratio)) {
    return null
  }

  return `${(ratio * 100).toFixed(1)}%`
}

export function computeTemporalStability(mostFrequentCount: number, totalDetections: number): number | null {
  if (totalDetections <= 0) {
    return null
  }

  return mostFrequentCount / totalDetections
}

export function computeDetectionIntervals(elapsedMsList: number[]): {
  average: number | null
  min: number | null
  max: number | null
} {
  if (elapsedMsList.length < 2) {
    return { average: null, min: null, max: null }
  }

  const intervals: number[] = []

  for (let index = 1; index < elapsedMsList.length; index += 1) {
    intervals.push(elapsedMsList[index]! - elapsedMsList[index - 1]!)
  }

  return {
    average: average(intervals),
    min: Math.min(...intervals),
    max: Math.max(...intervals),
  }
}

export function computeCorrectDetectionIntervals(detections: DetectionSnapshot[]): number[] {
  const correctTimes = detections
    .filter((item) => item.classification === 'CORRECT')
    .map((item) => item.elapsedMs)

  if (correctTimes.length < 2) {
    return []
  }

  const intervals: number[] = []

  for (let index = 1; index < correctTimes.length; index += 1) {
    intervals.push(Math.round(correctTimes[index]! - correctTimes[index - 1]!))
  }

  return intervals
}

export function computeLongestCorrectDetectionWindowMs(detections: DetectionSnapshot[]): number {
  const correctTimes = detections
    .filter((item) => item.classification === 'CORRECT')
    .map((item) => item.elapsedMs)

  if (correctTimes.length === 0) {
    return 0
  }

  if (correctTimes.length === 1) {
    return 0
  }

  return Math.round(correctTimes.at(-1)! - correctTimes[0]!)
}

export function computeStabilityCategory(correct: number, frames: number): StabilityCategory {
  const correctRate = frames > 0 ? correct / frames : 0

  if (correct >= 3 && correctRate >= 0.3) {
    return 'STABLE'
  }

  if (correct >= 1 && correct <= 2) {
    return 'PROMISING'
  }

  return 'FAILED'
}

export function analyzeMultiFrameThreshold(
  detections: DetectionSnapshot[],
  threshold: number,
  expectedBarcode: string,
): { confirmations: number; correctConfirmations: number; incorrectConfirmations: number } {
  let confirmations = 0
  let correctConfirmations = 0
  let incorrectConfirmations = 0
  let streakValue: string | null = null
  let streakLength = 0

  for (const detection of detections) {
    if (detection.rawValue === streakValue) {
      streakLength += 1
    } else {
      streakValue = detection.rawValue
      streakLength = 1
    }

    if (streakLength >= threshold) {
      confirmations += 1

      if (streakValue === expectedBarcode) {
        correctConfirmations += 1
      } else {
        incorrectConfirmations += 1
      }

      streakLength = 0
      streakValue = null
    }
  }

  return { confirmations, correctConfirmations, incorrectConfirmations }
}

export function buildMultiFrameAnalysis(
  detections: DetectionSnapshot[],
  expectedBarcode: string,
): MultiFrameThresholdResult[] {
  return MULTI_FRAME_THRESHOLDS.map((threshold) => ({
    threshold,
    ...analyzeMultiFrameThreshold(detections, threshold, expectedBarcode),
  }))
}

export function createEmptyStabilityResult(config: StabilityRunConfiguration): StabilityConfigurationResult {
  return {
    configId: config.id,
    focusRequested: config.focusRequested,
    focusActual: '—',
    repetition: config.repetition,
    zoomRequested: config.requestedZoom,
    zoomActual: '—',
    applied: null,
    frames: 0,
    detections: 0,
    correct: 0,
    incorrect: 0,
    notFound: 0,
    detectionRate: '—',
    correctRate: '—',
    correctFrameRate: '—',
    distinctValues: 0,
    mostFrequentValue: null,
    mostFrequentOccurrences: 0,
    correctOccurrences: 0,
    temporalStability: null,
    correctStability: null,
    longestIdenticalSequence: 0,
    longestCorrectSequence: 0,
    longestCorrectDetectionWindowMs: 0,
    averageDetectionIntervalMs: null,
    minDetectionIntervalMs: null,
    maxDetectionIntervalMs: null,
    correctDetectionIntervalsMs: [],
    averageSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    sharpnessAtDetection: null,
    sharpnessAtCorrectDetection: null,
    averageBarcodeWidthRatio: null,
    stability: 'FAILED',
    configurationStatus: 'NOT_APPLIED',
    orderIndex: config.orderIndex,
  }
}

export function finalizeStabilityResult(
  config: StabilityRunConfiguration,
  applied: AppliedExperimentSnapshot,
  stats: {
    frames: number
    detections: DetectionSnapshot[]
    notFound: number
    sharpnessValues: number[]
    sharpnessAtDetections: number[]
    sharpnessAtCorrectDetections: number[]
    widthRatios: number[]
  },
): StabilityConfigurationResult {
  const rawValues = stats.detections.map((item) => item.rawValue)
  const correct = stats.detections.filter((item) => item.classification === 'CORRECT').length
  const incorrect = stats.detections.filter((item) => item.classification === 'INCORRECT').length
  const mostFrequent = computeMostFrequent(rawValues)
  const detectionIntervals = computeDetectionIntervals(stats.detections.map((item) => item.elapsedMs))
  const temporalRatio = computeTemporalStability(mostFrequent.count, stats.detections.length)
  const correctRatio = computeTemporalStability(correct, stats.detections.length)

  const configurationStatus: ConfigurationStatus =
    applied.configurationStatus !== 'VALID'
      ? applied.configurationStatus === 'APPLY_ERROR' ? 'APPLY_ERROR' : 'NOT_APPLIED'
      : correct === 0 && stats.detections.length === 0 && stats.frames > 0
        ? 'NO_DETECTION'
        : 'VALID'

  return {
    configId: config.id,
    focusRequested: config.focusRequested,
    focusActual: applied.actualFocusDistance,
    repetition: config.repetition,
    zoomRequested: config.requestedZoom,
    zoomActual: applied.actualZoom,
    applied,
    frames: stats.frames,
    detections: stats.detections.length,
    correct,
    incorrect,
    notFound: stats.notFound,
    detectionRate: computeRate(stats.detections.length, stats.frames),
    correctRate: computeRate(correct, stats.frames),
    correctFrameRate: computeRate(correct, stats.frames),
    distinctValues: computeDistinctValues(rawValues),
    mostFrequentValue: mostFrequent.value,
    mostFrequentOccurrences: mostFrequent.count,
    correctOccurrences: correct,
    temporalStability: formatPercent(temporalRatio),
    correctStability: formatPercent(correctRatio),
    longestIdenticalSequence: computeLongestIdenticalSequence(rawValues),
    longestCorrectSequence: computeLongestCorrectSequenceFromValues(stats.detections, config.expectedBarcode),
    longestCorrectDetectionWindowMs: computeLongestCorrectDetectionWindowMs(stats.detections),
    averageDetectionIntervalMs: detectionIntervals.average != null ? Math.round(detectionIntervals.average) : null,
    minDetectionIntervalMs: detectionIntervals.min,
    maxDetectionIntervalMs: detectionIntervals.max,
    correctDetectionIntervalsMs: computeCorrectDetectionIntervals(stats.detections),
    averageSharpness: average(stats.sharpnessValues),
    minSharpness: stats.sharpnessValues.length ? Math.min(...stats.sharpnessValues) : null,
    maxSharpness: stats.sharpnessValues.length ? Math.max(...stats.sharpnessValues) : null,
    sharpnessAtDetection: average(stats.sharpnessAtDetections),
    sharpnessAtCorrectDetection: average(stats.sharpnessAtCorrectDetections),
    averageBarcodeWidthRatio: stats.widthRatios.length
      ? Number((stats.widthRatios.reduce((sum, value) => sum + value, 0) / stats.widthRatios.length).toFixed(4))
      : null,
    stability: computeStabilityCategory(correct, stats.frames),
    configurationStatus,
    orderIndex: config.orderIndex,
  }
}

export function computeFocusRepeatability(
  results: StabilityConfigurationResult[],
  focus: number,
): RepeatabilityStatus {
  const reps = results.filter((item) => Math.abs(item.focusRequested - focus) < 0.001)

  if (reps.length < 2) {
    return 'INSUFFICIENT_DATA'
  }

  const repsWithCorrect = reps.filter((item) => item.correct > 0).length
  const totalCorrect = reps.reduce((sum, item) => sum + item.correct, 0)

  if (repsWithCorrect >= 2 && totalCorrect >= 2) {
    return 'REPEATABLE'
  }

  if (totalCorrect >= 3 && repsWithCorrect >= 1 && reps.every((item) => item.distinctValues <= 6)) {
    const dominantValues = reps.map((item) => item.mostFrequentValue).filter(Boolean)
    const sameDominant = dominantValues.length > 0 && new Set(dominantValues).size === 1

    if (sameDominant && repsWithCorrect >= 1) {
      return 'REPEATABLE'
    }
  }

  return 'NOT_REPEATABLE'
}

export function aggregateByFocus(
  results: StabilityConfigurationResult[],
  focusLevels: number[],
): FocusAggregateSummary[] {
  return focusLevels.map((focus) => {
    const items = results.filter((item) => Math.abs(item.focusRequested - focus) < 0.001)
    const totalFrames = items.reduce((sum, item) => sum + item.frames, 0)
    const totalDetections = items.reduce((sum, item) => sum + item.detections, 0)
    const correct = items.reduce((sum, item) => sum + item.correct, 0)
    const incorrect = items.reduce((sum, item) => sum + item.incorrect, 0)
    const distinctAvg = items.length
      ? items.reduce((sum, item) => sum + item.distinctValues, 0) / items.length
      : 0
    const temporalValues = items
      .map((item) => item.temporalStability)
      .filter((value): value is string => value != null)
      .map((value) => Number.parseFloat(value))
      .filter((value) => Number.isFinite(value))
    const temporalAvg = temporalValues.length
      ? temporalValues.reduce((sum, value) => sum + value, 0) / temporalValues.length
      : null
    const sharpnessValues = items
      .map((item) => item.averageSharpness)
      .filter((value): value is number => value != null)

    return {
      focus,
      totalFrames,
      totalDetections,
      correct,
      incorrect,
      detectionRate: computeRate(totalDetections, totalFrames),
      correctRate: computeRate(correct, totalFrames),
      averageDistinctValues: items.length ? distinctAvg.toFixed(1) : '—',
      averageTemporalStability: temporalAvg != null ? `${temporalAvg.toFixed(1)}%` : '—',
      bestCorrectSequence: items.length ? Math.max(...items.map((item) => item.longestCorrectSequence)) : 0,
      averageSharpness: sharpnessValues.length
        ? String(Math.round(sharpnessValues.reduce((sum, value) => sum + value, 0) / sharpnessValues.length))
        : '—',
      repeatability: computeFocusRepeatability(results, focus),
    }
  })
}

export function buildBestFocusRanking(
  results: StabilityConfigurationResult[],
): StabilityConfigurationResult[] {
  return [...results].sort((left, right) => {
    if (right.correct !== left.correct) {
      return right.correct - left.correct
    }

    const leftCorrectRate = left.correct / Math.max(left.frames, 1)
    const rightCorrectRate = right.correct / Math.max(right.frames, 1)

    if (rightCorrectRate !== leftCorrectRate) {
      return rightCorrectRate - leftCorrectRate
    }

    if (right.longestCorrectSequence !== left.longestCorrectSequence) {
      return right.longestCorrectSequence - left.longestCorrectSequence
    }

    const leftRepeatable = computeFocusRepeatability(results, left.focusRequested) === 'REPEATABLE' ? 1 : 0
    const rightRepeatable = computeFocusRepeatability(results, right.focusRequested) === 'REPEATABLE' ? 1 : 0

    if (rightRepeatable !== leftRepeatable) {
      return rightRepeatable - leftRepeatable
    }

    const leftDetectionRate = left.detections / Math.max(left.frames, 1)
    const rightDetectionRate = right.detections / Math.max(right.frames, 1)

    return rightDetectionRate - leftDetectionRate
  })
}

export function determineConclusionKind(
  results: StabilityConfigurationResult[],
): ConclusionKind {
  const totalCorrect = results.reduce((sum, item) => sum + item.correct, 0)
  const totalDetections = results.reduce((sum, item) => sum + item.detections, 0)
  const maxCorrectInConfig = Math.max(0, ...results.map((item) => item.correct))
  const focusLevels = [...new Set(results.map((item) => item.focusRequested))]
  const repeatableFocuses = focusLevels.filter(
    (focus) => computeFocusRepeatability(results, focus) === 'REPEATABLE',
  )

  if (totalCorrect === 0) {
    return totalDetections > 0 ? 'UNSTABLE_INCORRECT' : 'NO_CORRECT'
  }

  if (totalCorrect === 1 || maxCorrectInConfig === 1) {
    return 'ISOLATED_CORRECT'
  }

  if (repeatableFocuses.length > 0 || totalCorrect >= 3) {
    return 'RELIABLE_PATTERN'
  }

  return 'ISOLATED_CORRECT'
}

export function buildStabilityConclusion(
  results: StabilityConfigurationResult[],
  focusLevels: number[],
): string {
  const kind = determineConclusionKind(results)
  const ranking = buildBestFocusRanking(results)
  const best = ranking[0]
  const lines = ['=== BENCHMARK CONCLUSION ===', '']

  if (kind === 'RELIABLE_PATTERN') {
    const repeatable = focusLevels.filter((focus) => computeFocusRepeatability(results, focus) === 'REPEATABLE')
    lines.push(
      'RELIABLE PATTERN OBSERVED',
      '',
      best ? `Best observed focus: ${best.focusRequested}` : 'Best observed focus: —',
      best ? `Correct reads: ${best.correct}` : '',
      best ? `Correct rate: ${best.correctRate}` : '',
      best ? `Longest correct sequence: ${best.longestCorrectSequence}` : '',
      repeatable.length ? `Repeatable focus values: ${repeatable.join(', ')}` : 'Repeatability: limited',
    )
  } else if (kind === 'ISOLATED_CORRECT') {
    lines.push(
      'ISOLATED CORRECT READ',
      '',
      'A correct read was observed, but repeatability was not demonstrated.',
      '',
      'A correct read was observed, but the sample',
      'is insufficient to establish repeatability.',
      '',
      'Further testing is recommended.',
    )
  } else if (kind === 'UNSTABLE_INCORRECT') {
    lines.push(
      'UNSTABLE / INCORRECT DECODING',
      '',
      'The detector is producing barcode-like decodes, but no reliable correct value was observed.',
    )
  } else {
    lines.push(
      'NO CORRECT READ OBSERVED',
      '',
      'No correct barcode detection was recorded during this experiment.',
    )
  }

  const hasRepeatableCorrect = focusLevels.some(
    (focus) => computeFocusRepeatability(results, focus) === 'REPEATABLE'
      && results.some((item) => Math.abs(item.focusRequested - focus) < 0.001 && item.correct > 0),
  )

  if (!hasRepeatableCorrect && kind !== 'NO_CORRECT') {
    lines.push('', 'NO RELIABLE FOCUS CONFIGURATION', '', 'No focus produced repeatable correct reads across repetitions.')
  }

  lines.push(
    '',
    'Experimental result only.',
    'Do not integrate automatically into the production scanner.',
    '',
    'EXPERIMENTAL RESULT ONLY',
    'DO NOT INTEGRATE AUTOMATICALLY INTO THE PRODUCTION SCANNER.',
  )

  return lines.join('\n')
}

export function buildStabilityReport(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  expectedBarcode: string
  expectedFormat: string
  focusLevels: number[]
  repetitions: number
  durationSeconds: number
  settleMs: number
  randomized: boolean
  randomSeed: number | null
  configurationOrder: StabilityRunConfiguration[]
  results: StabilityConfigurationResult[]
  rawDetections: StabilityRawDetection[]
  multiFrameAnalysis: MultiFrameThresholdResult[]
  focusSummary: FocusAggregateSummary[]
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE STABILITY FOCUS REPEATABILITY ===',
    '',
    `Date: ${new Date().toISOString()}`,
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    '',
    'Camera:',
    `Resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    'Focus capabilities:',
    `Min: ${options.capabilities.focusDistance.min ?? '—'}`,
    `Max: ${options.capabilities.focusDistance.max ?? '—'}`,
    `Step: ${options.capabilities.focusDistance.step ?? '—'}`,
    '',
    'Zoom capabilities:',
    `Min: ${options.capabilities.zoom.min ?? '—'}`,
    `Max: ${options.capabilities.zoom.max ?? '—'}`,
    '',
    `Barcode expected: ${options.expectedBarcode}`,
    `Expected format: ${options.expectedFormat}`,
    `Size target: ${MEDIUM_SIZE_TARGET}`,
    `Zoom: ${FIXED_ZOOM}×`,
    `Focus list: ${options.focusLevels.join(', ')}`,
    `Repetitions: ${options.repetitions}`,
    `Duration: ${options.durationSeconds}s`,
    `Settle time: ${options.settleMs}ms`,
    `Order mode: ${options.randomized ? 'RANDOMIZED' : 'FIXED'}`,
    options.randomSeed != null ? `Random seed: ${options.randomSeed}` : '',
    '',
    'Configuration order used:',
    ...options.configurationOrder.map(
      (item, index) => `${index + 1}. focus ${item.focusRequested} — repetition ${item.repetition}`,
    ),
    '',
    'RESULTS',
    '',
  ]

  for (const result of [...options.results].sort((a, b) => a.orderIndex - b.orderIndex)) {
    lines.push(
      `FOCUS ${result.focusRequested} — REPETITION ${result.repetition}`,
      `Focus actual: ${result.focusActual}`,
      `Zoom actual: ${result.zoomActual}×`,
      `Validation focus mode: ${result.applied?.focusModeValidation ?? '—'}`,
      `Validation focus distance: ${result.applied?.focusDistanceValidation ?? '—'}`,
      `Validation zoom: ${result.applied?.zoomValidation ?? '—'}`,
      `Frames: ${result.frames}`,
      `Detections: ${result.detections}`,
      `Correct: ${result.correct}`,
      `Incorrect: ${result.incorrect}`,
      `Detection rate: ${result.detectionRate}`,
      `Correct rate: ${result.correctRate}`,
      `Distinct values: ${result.distinctValues}`,
      `Most frequent: ${result.mostFrequentValue ?? '—'}`,
      `Occurrences: ${result.mostFrequentOccurrences} / ${result.detections}`,
      `Temporal stability: ${result.temporalStability ?? '—'}`,
      `Correct stability: ${result.correctStability ?? '—'}`,
      `Longest identical sequence: ${result.longestIdenticalSequence}`,
      `Longest correct sequence: ${result.longestCorrectSequence}`,
      `Longest correct detection window: ${result.longestCorrectDetectionWindowMs} ms`,
      `Average sharpness: ${result.averageSharpness ?? '—'}`,
      `Average barcode width: ${result.averageBarcodeWidthRatio != null ? `${(result.averageBarcodeWidthRatio * 100).toFixed(1)}%` : '—'}`,
      `Stability: ${result.stability}`,
      '',
    )
  }

  lines.push('RAW DETECTIONS', '')

  for (const entry of options.rawDetections) {
    lines.push(
      `${entry.timestamp} — focus ${entry.focusRequested} — repetition ${entry.repetition}`,
      `format: ${entry.format}`,
      `rawValue: ${entry.rawValue}`,
      `classification: ${entry.classification}`,
      `boundingBox: ${entry.boundingBoxWidth ?? '—'} × ${entry.boundingBoxHeight ?? '—'}`,
      `widthRatio: ${entry.widthRatio != null ? `${(entry.widthRatio * 100).toFixed(1)}%` : '—'}`,
      `sharpness: ${entry.sharpness ?? '—'}`,
      '',
    )
  }

  lines.push(
    'SUMMARY BY FOCUS',
    'FOCUS | TOTAL FRAMES | TOTAL DETECTIONS | CORRECT | CORRECT RATE | DETECTION RATE | DISTINCT VALUES | TEMPORAL STABILITY | REPEATABILITY',
    '',
  )

  for (const row of options.focusSummary) {
    lines.push(
      `${row.focus} | ${row.totalFrames} | ${row.totalDetections} | ${row.correct} | ${row.correctRate} | ${row.detectionRate} | ${row.averageDistinctValues} | ${row.averageTemporalStability} | ${row.repeatability}`,
    )
  }

  lines.push('', 'MULTI-FRAME ANALYSIS', 'Threshold | Confirmations | Correct confirmations | Incorrect confirmations', '')

  for (const row of options.multiFrameAnalysis) {
    lines.push(`${row.threshold} | ${row.confirmations} | ${row.correctConfirmations} | ${row.incorrectConfirmations}`)
  }

  lines.push('', options.conclusion, '')

  return lines.join('\n')
}

export function buildStabilityExportJson(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  expectedBarcode: string
  expectedFormat: string
  focusLevels: number[]
  repetitions: number
  durationSeconds: number
  settleMs: number
  randomized: boolean
  randomSeed: number | null
  configurationOrder: StabilityRunConfiguration[]
  results: StabilityConfigurationResult[]
  rawDetections: StabilityRawDetection[]
  multiFrameAnalysis: MultiFrameThresholdResult[]
  focusSummary: FocusAggregateSummary[]
  conclusion: string
}): string {
  return JSON.stringify(
    {
      metadata: {
        exportedAt: new Date().toISOString(),
        experiment: 'BARCODE_STABILITY_FOCUS_REPEATABILITY',
      },
      environment: options.environment,
      camera: options.trackSettings,
      capabilities: options.capabilities,
      testParameters: {
        expectedBarcode: options.expectedBarcode,
        expectedFormat: options.expectedFormat,
        sizeTarget: MEDIUM_SIZE_TARGET,
        zoom: FIXED_ZOOM,
        focusLevels: options.focusLevels,
        repetitions: options.repetitions,
        durationSeconds: options.durationSeconds,
        settleMs: options.settleMs,
        randomized: options.randomized,
        randomSeed: options.randomSeed,
      },
      configurationOrder: options.configurationOrder,
      configurationResults: options.results,
      rawDetections: options.rawDetections,
      multiFrameAnalysis: options.multiFrameAnalysis,
      summaryByFocus: options.focusSummary,
      conclusion: options.conclusion,
    },
    null,
    2,
  )
}

export function classifyDetection(rawValue: string, expectedBarcode: string): 'CORRECT' | 'INCORRECT' {
  const result = classifyReadResult(rawValue, expectedBarcode)
  return result === 'CORRECT' ? 'CORRECT' : 'INCORRECT'
}

export type { ReadResultType }
