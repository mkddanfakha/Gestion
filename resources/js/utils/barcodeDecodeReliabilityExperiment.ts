import {
  applyExperimentConfiguration,
  clampFocusForExperiment,
  createComparisonBarcodeDetector,
  EXPECTED_BARCODE,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  type AppliedExperimentSnapshot,
  type EnvironmentDiagnostics,
  type TrackCapabilitiesSnapshot,
  type TrackSettingsSnapshot,
} from '@/utils/barcodeDistanceFocusExperiment'
import {
  computeDistinctValues,
  computeMostFrequent,
  computeLongestIdenticalSequence,
  isManualFocusSupported,
  seededShuffle,
} from '@/utils/barcodeStabilityFocusRepeatability'
import { average, computeRate } from '@/utils/barcodeSizeZoomComparison'

export const EXPERIMENTAL_FOCUS = 0.22
export const FIXED_ZOOM = 1
export const DEFAULT_EXPECTED_BARCODE = EXPECTED_BARCODE
export const DEFAULT_EXPECTED_FORMAT = 'ean_13'
export const DEFAULT_DURATION_SECONDS = 15
export const DURATION_OPTIONS = [10, 15, 30] as const
export const DEFAULT_SETTLE_MS = 1500
export const SETTLE_OPTIONS = [1000, 1500, 2000] as const
export const DETECTION_INTERVAL_MS = 150
export const CROP_MARGIN_RATIO = 0.15

export {
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  createComparisonBarcodeDetector,
  applyExperimentConfiguration,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  isManualFocusSupported,
  clampFocusForExperiment,
}
export type { EnvironmentDiagnostics, AppliedExperimentSnapshot }

export type InputMode = 'VIDEO' | 'CANVAS'
export type OrderMode = 'FIXED' | 'RANDOMIZED'
export type ConfigResultStatus =
  | 'NO_DETECTION'
  | 'INCORRECT_DECODING'
  | 'UNSTABLE_DECODING'
  | 'CORRECT_ONCE'
  | 'REPEATABLE_CORRECT'

export type MultiFrameStrategy = 'FIRST_READ' | 'TWO_CONFIRM' | 'THREE_CONFIRM'

export interface SizeTargetSpec {
  id: string
  label: string
  minWidthRatio: number
  maxWidthRatio: number
  guideWidthRatio: number
}

export interface ResolutionPreset {
  id: string
  label: string
  width: number
  height: number
}

export interface ReliabilityConfiguration {
  id: string
  resolutionPreset: ResolutionPreset
  sizeTarget: SizeTargetSpec
  focusRequested: number
  requestedZoom: number
  expectedBarcode: string
  expectedFormat: string
  orderIndex: number
}

export interface ReliabilityRawDetection {
  id: string
  timestamp: string
  elapsedMs: number
  configId: string
  resolutionLabel: string
  sizeTargetLabel: string
  inputMode: InputMode
  focusRequested: number
  focusActual: string
  zoomActual: string
  format: string
  rawValue: string
  classification: 'CORRECT' | 'INCORRECT'
  checkDigitValid: boolean
  hammingDistance: number | null
  matchingDigits: number
  boundingBox: { x: number; y: number; width: number; height: number } | null
  widthRatio: number | null
  heightRatio: number | null
  videoWidth: number
  videoHeight: number
  sharpness: number | null
}

export interface CapturedFrameRecord {
  id: string
  timestamp: string
  configId: string
  fullFrameDataUrl: string
  cropDataUrl: string | null
  resolutionLabel: string
  focusRequested: number
  focusActual: string
  zoomRequested: number
  zoomActual: string
  rawValue: string
  format: string
  sharpness: number | null
  boundingBox: { x: number; y: number; width: number; height: number } | null
  widthRatio: number | null
}

export interface ReliabilityConfigurationResult {
  configId: string
  resolutionLabel: string
  requestedWidth: number
  requestedHeight: number
  actualWidth: number | null
  actualHeight: number | null
  actualFrameRate: string
  sizeTargetLabel: string
  inputMode: InputMode
  focusRequested: number
  focusActual: string
  zoomRequested: number
  zoomActual: string
  applied: AppliedExperimentSnapshot | null
  frames: number
  detections: number
  correct: number
  incorrect: number
  detectionRate: string
  correctRate: string
  distinctValues: number
  mostFrequentValue: string | null
  mostFrequentOccurrences: number
  temporalStability: string | null
  correctStability: string | null
  checkDigitValidDetections: number
  averageSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  averageBarcodeWidthRatio: number | null
  longestIdenticalSequence: number
  longestCorrectSequence: number
  longestCorrectWindowMs: number
  repeatability: string
  status: ConfigResultStatus
  orderIndex: number
}

export interface DecodePatternRow {
  rawValue: string
  occurrences: number
  format: string
  checkDigitValid: boolean
  hammingDistance: number | null
  matchingDigits: number
}

export interface MultiFrameStrategyResult {
  strategy: MultiFrameStrategy
  confirmations: number
  correctConfirmations: number
  incorrectConfirmations: number
}

export const SIZE_TARGET_SPECS: SizeTargetSpec[] = [
  { id: 'A', label: '20–30%', minWidthRatio: 0.2, maxWidthRatio: 0.3, guideWidthRatio: 0.25 },
  { id: 'B', label: '30–40%', minWidthRatio: 0.3, maxWidthRatio: 0.4, guideWidthRatio: 0.35 },
  { id: 'C', label: '40–50%', minWidthRatio: 0.4, maxWidthRatio: 0.5, guideWidthRatio: 0.45 },
  { id: 'D', label: '50–60%', minWidthRatio: 0.5, maxWidthRatio: 0.6, guideWidthRatio: 0.55 },
  { id: 'E', label: '60–70%', minWidthRatio: 0.6, maxWidthRatio: 0.7, guideWidthRatio: 0.65 },
  { id: 'F', label: '70–80%', minWidthRatio: 0.7, maxWidthRatio: 0.8, guideWidthRatio: 0.75 },
]

export const RESOLUTION_PRESETS: ResolutionPreset[] = [
  { id: '1280x720', label: '1280×720', width: 1280, height: 720 },
  { id: '1920x1080', label: '1920×1080', width: 1920, height: 1080 },
  { id: '640x480', label: '640×480', width: 640, height: 480 },
]

export function buildResolutionConstraints(preset: ResolutionPreset): MediaStreamConstraints {
  return {
    video: {
      facingMode: { ideal: 'environment' },
      width: { ideal: preset.width },
      height: { ideal: preset.height },
      frameRate: { ideal: 30 },
    },
    audio: false,
  }
}

/**
 * Calcule les ratios de taille du code-barres par rapport aux pixels vidéo réels.
 *
 * - sourceWidth = video.videoWidth (pixels intrinsèques du flux, pas clientWidth/CSS)
 * - sourceHeight = video.videoHeight
 * - boundingBox.width / height = valeurs retournées par BarcodeDetector
 */
export function calculateBarcodeSizeRatio(
  boundingBox: { width: number; height: number },
  videoWidth: number,
  videoHeight: number,
): {
  widthRatio: number | null
  heightRatio: number | null
  sourceWidth: number
  sourceHeight: number
  boundingBoxWidth: number
  boundingBoxHeight: number
} {
  return {
    sourceWidth: videoWidth,
    sourceHeight: videoHeight,
    boundingBoxWidth: boundingBox.width,
    boundingBoxHeight: boundingBox.height,
    widthRatio: videoWidth > 0 ? Number((boundingBox.width / videoWidth).toFixed(4)) : null,
    heightRatio: videoHeight > 0 ? Number((boundingBox.height / videoHeight).toFixed(4)) : null,
  }
}

export function computeEan13CheckDigit(digits12: string): number | null {
  if (!/^\d{12}$/.test(digits12)) {
    return null
  }

  let sum = 0

  for (let index = 0; index < 12; index += 1) {
    const digit = Number.parseInt(digits12[index]!, 10)
    sum += digit * (index % 2 === 0 ? 1 : 3)
  }

  return (10 - (sum % 10)) % 10
}

export function isValidEan13(value: string): boolean {
  if (!/^\d{13}$/.test(value)) {
    return false
  }

  const expectedCheck = computeEan13CheckDigit(value.slice(0, 12))

  return expectedCheck != null && expectedCheck === Number.parseInt(value[12]!, 10)
}

export function isExpectedBarcode(rawValue: string, expectedBarcode: string): boolean {
  return rawValue === expectedBarcode
}

export function isCorrectRead(
  rawValue: string,
  format: string,
  expectedBarcode: string,
  expectedFormat: string,
): boolean {
  return format === expectedFormat && rawValue === expectedBarcode
}

export function computeHammingDistance(expected: string, observed: string): number | null {
  if (expected.length !== observed.length) {
    return null
  }

  let distance = 0

  for (let index = 0; index < expected.length; index += 1) {
    if (expected[index] !== observed[index]) {
      distance += 1
    }
  }

  return distance
}

export function computeMatchingDigits(expected: string, observed: string): number {
  const length = Math.min(expected.length, observed.length)
  let matches = 0

  for (let index = 0; index < length; index += 1) {
    if (expected[index] === observed[index]) {
      matches += 1
    }
  }

  return matches
}

export function buildDigitComparison(expected: string, observed: string): Array<{
  position: number
  expected: string
  observed: string
  match: boolean
}> {
  const length = Math.max(expected.length, observed.length)

  return Array.from({ length }, (_, index) => ({
    position: index + 1,
    expected: expected[index] ?? '—',
    observed: observed[index] ?? '—',
    match: expected[index] === observed[index],
  }))
}

export function isWidthRatioInTarget(ratio: number | null, target: SizeTargetSpec): boolean {
  if (ratio == null) {
    return false
  }

  return ratio >= target.minWidthRatio && ratio <= target.maxWidthRatio
}

export function buildReliabilityConfigurations(options: {
  resolutionPresets: ResolutionPreset[]
  sizeTargets: SizeTargetSpec[]
  focusRequested: number
  expectedBarcode: string
  expectedFormat: string
  orderMode: OrderMode
  randomSeed?: number
  preserveOrder?: ReliabilityConfiguration[]
}): ReliabilityConfiguration[] {
  const base: ReliabilityConfiguration[] = []

  for (const resolutionPreset of options.resolutionPresets) {
    for (const sizeTarget of options.sizeTargets) {
      base.push({
        id: `${resolutionPreset.id}-${sizeTarget.id}`,
        resolutionPreset,
        sizeTarget,
        focusRequested: options.focusRequested,
        requestedZoom: FIXED_ZOOM,
        expectedBarcode: options.expectedBarcode,
        expectedFormat: options.expectedFormat,
        orderIndex: 0,
      })
    }
  }

  if (options.preserveOrder && options.preserveOrder.length === base.length && options.orderMode === 'FIXED') {
    return options.preserveOrder.map((item, index) => ({ ...item, orderIndex: index }))
  }

  const ordered = options.orderMode === 'RANDOMIZED'
    ? seededShuffle(base, options.randomSeed ?? Date.now())
    : base

  return ordered.map((item, index) => ({ ...item, orderIndex: index }))
}

export function formatPercent(ratio: number | null): string | null {
  if (ratio == null || !Number.isFinite(ratio)) {
    return null
  }

  return `${(ratio * 100).toFixed(1)}%`
}

export function determineConfigStatus(
  result: Pick<ReliabilityConfigurationResult, 'detections' | 'correct' | 'temporalStability'>,
): ConfigResultStatus {
  if (result.detections === 0) {
    return 'NO_DETECTION'
  }

  if (result.correct >= 2) {
    return 'REPEATABLE_CORRECT'
  }

  if (result.correct === 1) {
    return 'CORRECT_ONCE'
  }

  const temporal = result.temporalStability != null
    ? Number.parseFloat(result.temporalStability)
    : 0

  if (Number.isFinite(temporal) && temporal >= 50) {
    return 'UNSTABLE_DECODING'
  }

  return 'INCORRECT_DECODING'
}

export function analyzeMultiFrameStrategies(
  detections: Array<{ rawValue: string; classification: 'CORRECT' | 'INCORRECT' }>,
  expectedBarcode: string,
): MultiFrameStrategyResult[] {
  const strategies: MultiFrameStrategy[] = ['FIRST_READ', 'TWO_CONFIRM', 'THREE_CONFIRM']

  return strategies.map((strategy) => {
    if (strategy === 'FIRST_READ') {
      const first = detections[0]

      return {
        strategy,
        confirmations: first ? 1 : 0,
        correctConfirmations: first?.classification === 'CORRECT' ? 1 : 0,
        incorrectConfirmations: first && first.classification !== 'CORRECT' ? 1 : 0,
      }
    }

    const threshold = strategy === 'TWO_CONFIRM' ? 2 : 3
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

        if (detection.rawValue === expectedBarcode) {
          correctConfirmations += 1
        } else {
          incorrectConfirmations += 1
        }

        streakLength = 0
        streakValue = null
      }
    }

    return { strategy, confirmations, correctConfirmations, incorrectConfirmations }
  })
}

export function buildDecodePatternAnalysis(
  detections: ReliabilityRawDetection[],
  expectedBarcode: string,
): DecodePatternRow[] {
  const grouped = new Map<string, ReliabilityRawDetection[]>()

  for (const detection of detections) {
    const list = grouped.get(detection.rawValue) ?? []
    list.push(detection)
    grouped.set(detection.rawValue, list)
  }

  return [...grouped.entries()]
    .map(([rawValue, items]) => ({
      rawValue,
      occurrences: items.length,
      format: items[0]?.format ?? '—',
      checkDigitValid: isValidEan13(rawValue),
      hammingDistance: computeHammingDistance(expectedBarcode, rawValue),
      matchingDigits: computeMatchingDigits(expectedBarcode, rawValue),
    }))
    .sort((left, right) => right.occurrences - left.occurrences)
}

export function createEmptyReliabilityResult(config: ReliabilityConfiguration): ReliabilityConfigurationResult {
  return {
    configId: config.id,
    resolutionLabel: config.resolutionPreset.label,
    requestedWidth: config.resolutionPreset.width,
    requestedHeight: config.resolutionPreset.height,
    actualWidth: null,
    actualHeight: null,
    actualFrameRate: '—',
    sizeTargetLabel: config.sizeTarget.label,
    inputMode: 'VIDEO',
    focusRequested: config.focusRequested,
    focusActual: '—',
    zoomRequested: config.requestedZoom,
    zoomActual: '—',
    applied: null,
    frames: 0,
    detections: 0,
    correct: 0,
    incorrect: 0,
    detectionRate: '—',
    correctRate: '—',
    distinctValues: 0,
    mostFrequentValue: null,
    mostFrequentOccurrences: 0,
    temporalStability: null,
    correctStability: null,
    checkDigitValidDetections: 0,
    averageSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    averageBarcodeWidthRatio: null,
    longestIdenticalSequence: 0,
    longestCorrectSequence: 0,
    longestCorrectWindowMs: 0,
    repeatability: '—',
    status: 'NO_DETECTION',
    orderIndex: config.orderIndex,
  }
}

export function finalizeReliabilityResult(
  config: ReliabilityConfiguration,
  applied: AppliedExperimentSnapshot,
  options: {
    inputMode: InputMode
    actualWidth: number | null
    actualHeight: number | null
    actualFrameRate: string
    frames: number
    detections: ReliabilityRawDetection[]
    sharpnessValues: number[]
    widthRatios: number[]
  },
): ReliabilityConfigurationResult {
  const rawValues = options.detections.map((item) => item.rawValue)
  const correct = options.detections.filter((item) => item.classification === 'CORRECT').length
  const incorrect = options.detections.filter((item) => item.classification === 'INCORRECT').length
  const mostFrequent = computeMostFrequent(rawValues)
  const temporalRatio = mostFrequent.count > 0 && options.detections.length > 0
    ? mostFrequent.count / options.detections.length
    : null
  const correctRatio = options.detections.length > 0 ? correct / options.detections.length : null
  const correctTimes = options.detections
    .filter((item) => item.classification === 'CORRECT')
    .map((item) => item.elapsedMs)

  let longestCorrectSequence = 0
  let currentCorrect = 0

  for (const detection of options.detections) {
    if (detection.classification === 'CORRECT') {
      currentCorrect += 1
      longestCorrectSequence = Math.max(longestCorrectSequence, currentCorrect)
    } else {
      currentCorrect = 0
    }
  }

  const longestCorrectWindowMs = correctTimes.length >= 2
    ? Math.round(correctTimes.at(-1)! - correctTimes[0]!)
    : 0

  const base: ReliabilityConfigurationResult = {
    configId: config.id,
    resolutionLabel: config.resolutionPreset.label,
    requestedWidth: config.resolutionPreset.width,
    requestedHeight: config.resolutionPreset.height,
    actualWidth: options.actualWidth,
    actualHeight: options.actualHeight,
    actualFrameRate: options.actualFrameRate,
    sizeTargetLabel: config.sizeTarget.label,
    inputMode: options.inputMode,
    focusRequested: config.focusRequested,
    focusActual: applied.actualFocusDistance,
    zoomRequested: config.requestedZoom,
    zoomActual: applied.actualZoom,
    applied,
    frames: options.frames,
    detections: options.detections.length,
    correct,
    incorrect,
    detectionRate: computeRate(options.detections.length, options.frames),
    correctRate: computeRate(correct, options.frames),
    distinctValues: computeDistinctValues(rawValues),
    mostFrequentValue: mostFrequent.value,
    mostFrequentOccurrences: mostFrequent.count,
    temporalStability: formatPercent(temporalRatio),
    correctStability: formatPercent(correctRatio),
    checkDigitValidDetections: options.detections.filter((item) => item.checkDigitValid).length,
    averageSharpness: average(options.sharpnessValues),
    minSharpness: options.sharpnessValues.length ? Math.min(...options.sharpnessValues) : null,
    maxSharpness: options.sharpnessValues.length ? Math.max(...options.sharpnessValues) : null,
    averageBarcodeWidthRatio: options.widthRatios.length
      ? Number((options.widthRatios.reduce((sum, value) => sum + value, 0) / options.widthRatios.length).toFixed(4))
      : null,
    longestIdenticalSequence: computeLongestIdenticalSequence(rawValues),
    longestCorrectSequence,
    longestCorrectWindowMs,
    repeatability: correct >= 2 ? 'REPEATABLE' : correct === 1 ? 'SINGLE' : 'NONE',
    status: 'NO_DETECTION',
    orderIndex: config.orderIndex,
  }

  base.status = determineConfigStatus(base)

  return base
}

export function buildReliabilityConclusion(
  results: ReliabilityConfigurationResult[],
  physicalConfirmed: boolean,
): string {
  const totalCorrect = results.reduce((sum, item) => sum + item.correct, 0)
  const repeatable = results.filter((item) => item.status === 'REPEATABLE_CORRECT')
  const lines = ['=== BENCHMARK CONCLUSION ===', '']

  if (!physicalConfirmed) {
    lines.push(
      'PHYSICAL BARCODE NOT CONFIRMED',
      '',
      'The benchmark cannot conclude that a read is incorrect',
      'until the physical barcode is confirmed.',
      '',
    )
  }

  if (repeatable.length > 0) {
    const best = repeatable.sort((a, b) => b.correct - a.correct)[0]!
    lines.push(
      'REPEATABLE CORRECT',
      '',
      'Multiple correct reads of the expected barcode were observed.',
      '',
      'Candidate configuration (experimental only):',
      `Resolution: ${best.resolutionLabel}`,
      `Target size: ${best.sizeTargetLabel}`,
      `Correct reads: ${best.correct}`,
      `Correct rate: ${best.correctRate}`,
      '',
      'Experimental candidate — not automatically integrated into production.',
    )
  } else if (totalCorrect === 1) {
    lines.push(
      'CORRECT BUT NOT REPEATABLE',
      '',
      'The expected barcode was read correctly once, but not repeatedly.',
      'Do not integrate automatically.',
    )
  } else if (totalCorrect === 0 && results.some((item) => item.detections > 0)) {
    lines.push(
      'NO CORRECT READ',
      '',
      'No correct read of 6043000070493 was observed.',
      '',
      'The detector produced barcode-like decodes, but never the expected value.',
      'Do not recommend a focus value.',
    )
  } else {
    lines.push(
      'NO CORRECT READ',
      '',
      'No correct read of 6043000070493 was observed.',
    )
  }

  lines.push(
    '',
    `Experimental focus used: ${EXPERIMENTAL_FOCUS} — NON VALIDÉ`,
    '',
    'EXPERIMENTAL RESULT ONLY',
    'DO NOT INTEGRATE AUTOMATICALLY INTO THE PRODUCTION SCANNER.',
  )

  return lines.join('\n')
}

export function buildReliabilityReport(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  expectedBarcode: string
  expectedFormat: string
  inputMode: InputMode
  durationSeconds: number
  settleMs: number
  orderMode: OrderMode
  randomSeed: number | null
  physicalConfirmed: boolean
  configurationOrder: ReliabilityConfiguration[]
  results: ReliabilityConfigurationResult[]
  rawDetections: ReliabilityRawDetection[]
  decodePatterns: DecodePatternRow[]
  multiFrameAnalysis: MultiFrameStrategyResult[]
  capturedFrames: CapturedFrameRecord[]
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE DECODE RELIABILITY EXPERIMENT ===',
    '',
    `Date: ${new Date().toISOString()}`,
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    '',
    `Expected: ${options.expectedBarcode}`,
    `Expected format: ${options.expectedFormat}`,
    `Physical barcode confirmed: ${options.physicalConfirmed ? 'YES' : 'NO'}`,
    `Experimental focus: ${EXPERIMENTAL_FOCUS} — NON VALIDÉ`,
    `Input mode: ${options.inputMode}`,
    `Duration: ${options.durationSeconds}s`,
    `Settle time: ${options.settleMs}ms`,
    `Order mode: ${options.orderMode}`,
    options.randomSeed != null ? `Random seed: ${options.randomSeed}` : '',
    '',
    'Camera:',
    `Resolution actual: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `FPS: ${options.trackSettings.frameRate}`,
    `Facing: ${options.trackSettings.facingMode}`,
    '',
    'Configuration order:',
    ...options.configurationOrder.map(
      (item, index) => `${index + 1}. ${item.resolutionPreset.label} / ${item.sizeTarget.label}`,
    ),
    '',
    'RESULTS',
    '',
  ]

  for (const result of [...options.results].sort((a, b) => a.orderIndex - b.orderIndex)) {
    lines.push(
      `${result.resolutionLabel} / ${result.sizeTargetLabel}`,
      `Requested: ${result.requestedWidth}×${result.requestedHeight}`,
      `Actual: ${result.actualWidth ?? '—'}×${result.actualHeight ?? '—'} @ ${result.actualFrameRate} fps`,
      `Focus requested/actual: ${result.focusRequested} / ${result.focusActual}`,
      `Zoom actual: ${result.zoomActual}×`,
      `Frames: ${result.frames}`,
      `Detections: ${result.detections}`,
      `Correct: ${result.correct}`,
      `Incorrect: ${result.incorrect}`,
      `Correct rate: ${result.correctRate}`,
      `Detection rate: ${result.detectionRate}`,
      `Distinct values: ${result.distinctValues}`,
      `Most frequent: ${result.mostFrequentValue ?? '—'} (${result.mostFrequentOccurrences})`,
      `Temporal stability: ${result.temporalStability ?? '—'}`,
      `Correct stability: ${result.correctStability ?? '—'}`,
      `Check-digit-valid detections: ${result.checkDigitValidDetections}`,
      `Average sharpness: ${result.averageSharpness ?? '—'}`,
      `Average width ratio: ${result.averageBarcodeWidthRatio != null ? `${(result.averageBarcodeWidthRatio * 100).toFixed(1)}%` : '—'}`,
      `Status: ${result.status}`,
      '',
    )
  }

  lines.push('DECODE PATTERN ANALYSIS', 'Value | Occurrences | Format | Check digit valid', '')

  for (const row of options.decodePatterns) {
    lines.push(`${row.rawValue} | ${row.occurrences} | ${row.format} | ${row.checkDigitValid ? 'YES' : 'NO'}`)
  }

  lines.push('', 'MULTI-FRAME ANALYSIS', '')

  for (const row of options.multiFrameAnalysis) {
    lines.push(
      `${row.strategy}: confirmations ${row.confirmations}, correct ${row.correctConfirmations}, incorrect ${row.incorrectConfirmations}`,
    )
  }

  lines.push('', 'RAW DETECTIONS', '')

  for (const entry of options.rawDetections.slice(0, 200)) {
    lines.push(
      `${entry.timestamp} — ${entry.resolutionLabel} / ${entry.sizeTargetLabel}`,
      `rawValue: ${entry.rawValue} (${entry.classification})`,
      `format: ${entry.format} | check digit: ${entry.checkDigitValid ? 'VALID' : 'INVALID'}`,
      `widthRatio: ${entry.widthRatio != null ? `${(entry.widthRatio * 100).toFixed(1)}%` : '—'} (video ${entry.videoWidth}×${entry.videoHeight})`,
      `sharpness: ${entry.sharpness ?? '—'}`,
      '',
    )
  }

  lines.push('', options.conclusion, '')

  return lines.join('\n')
}

export function buildReliabilityCsv(
  results: ReliabilityConfigurationResult[],
): string {
  const header = [
    'resolution',
    'target_size',
    'focus_requested',
    'focus_actual',
    'zoom_actual',
    'frames',
    'detections',
    'correct',
    'incorrect',
    'detection_rate',
    'correct_rate',
    'distinct_values',
    'most_frequent',
    'temporal_stability',
    'correct_stability',
    'avg_sharpness',
    'avg_width_ratio',
    'check_digit_valid_detections',
    'status',
  ].join(',')

  const rows = results.map((item) => [
    item.resolutionLabel,
    item.sizeTargetLabel,
    item.focusRequested,
    item.focusActual,
    item.zoomActual,
    item.frames,
    item.detections,
    item.correct,
    item.incorrect,
    item.detectionRate,
    item.correctRate,
    item.distinctValues,
    item.mostFrequentValue ?? '',
    item.temporalStability ?? '',
    item.correctStability ?? '',
    item.averageSharpness ?? '',
    item.averageBarcodeWidthRatio ?? '',
    item.checkDigitValidDetections,
    item.status,
  ].join(','))

  return [header, ...rows].join('\n')
}

export function captureFrameWithCrop(
  video: HTMLVideoElement,
  boundingBox: { x: number; y: number; width: number; height: number } | null,
  marginRatio = CROP_MARGIN_RATIO,
): { fullFrameDataUrl: string; cropDataUrl: string | null } {
  const fullCanvas = document.createElement('canvas')
  fullCanvas.width = video.videoWidth
  fullCanvas.height = video.videoHeight
  const fullContext = fullCanvas.getContext('2d')

  if (!fullContext || video.videoWidth <= 0) {
    return { fullFrameDataUrl: '', cropDataUrl: null }
  }

  fullContext.drawImage(video, 0, 0, video.videoWidth, video.videoHeight)
  const fullFrameDataUrl = fullCanvas.toDataURL('image/jpeg', 0.92)

  if (!boundingBox) {
    return { fullFrameDataUrl, cropDataUrl: null }
  }

  const marginX = boundingBox.width * marginRatio
  const marginY = boundingBox.height * marginRatio
  const x = Math.max(0, Math.floor(boundingBox.x - marginX))
  const y = Math.max(0, Math.floor(boundingBox.y - marginY))
  const width = Math.min(video.videoWidth - x, Math.ceil(boundingBox.width + marginX * 2))
  const height = Math.min(video.videoHeight - y, Math.ceil(boundingBox.height + marginY * 2))

  const cropCanvas = document.createElement('canvas')
  cropCanvas.width = width
  cropCanvas.height = height
  const cropContext = cropCanvas.getContext('2d')

  if (!cropContext) {
    return { fullFrameDataUrl, cropDataUrl: null }
  }

  cropContext.drawImage(fullCanvas, x, y, width, height, 0, 0, width, height)

  return {
    fullFrameDataUrl,
    cropDataUrl: cropCanvas.toDataURL('image/jpeg', 0.92),
  }
}

export function buildReliabilityExportJson(options: Record<string, unknown>): string {
  return JSON.stringify(options, null, 2)
}
