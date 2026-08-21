import {
  applyExperimentConfiguration,
  clampFocusForExperiment,
  classifyReadResult,
  createComparisonBarcodeDetector,
  extractBarcodeGeometry,
  EXPECTED_BARCODE,
  FIXED_CAMERA_CONSTRAINTS,
  getSizeTarget,
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

export const FOCUS_SWEEP_LEVELS = [0.1, 0.15, 0.18, 0.2, 0.22, 0.25, 0.28, 0.3] as const
export const FIXED_ZOOM = 1
export const DURATION_SECONDS = 30
export const DETECTION_INTERVAL_MS = 150
export const MEDIUM_GUIDE_WIDTH_RATIO = 0.45
export const MEDIUM_SIZE_TARGET = '40–50% de la largeur de l\'image'
export const MEDIUM_INSTRUCTION = 'Positionnez le téléphone afin que le code occupe\nenviron 40–50 % de la largeur de l\'image.\n\nMaintenez ensuite le téléphone immobile\npendant toute la mesure.'

export {
  EXPECTED_BARCODE,
  FIXED_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  createComparisonBarcodeDetector,
  pickBestNativeBarcode,
  applyExperimentConfiguration,
  classifyReadResult,
  extractBarcodeGeometry,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  getSizeTarget,
}
export type { EnvironmentDiagnostics, AppliedExperimentSnapshot, ValidationStatus }

export type StabilityCategory = 'STABLE' | 'PROMISING' | 'FAILED'

export interface FineFocusConfiguration {
  id: string
  label: string
  requestedFocusDistance: number
  requestedZoom: number
  expectedBarcode: string
  orderIndex: number
}

export interface FineFocusRawDetection {
  id: string
  timestamp: string
  focusRequested: number
  focusActual: string
  format: string
  rawValue: string
  classification: ReadResultType
  boundingBox: { x: number; y: number; width: number; height: number } | null
  boundingBoxWidth: number | null
  boundingBoxHeight: number | null
  widthRatio: number | null
  sharpness: number | null
}

export interface FineFocusConfigurationResult {
  configId: string
  focusRequested: number
  focusActual: string
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
  correctPerFrameRate: string
  timeToFirstCorrectMs: number | null
  averageSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  averageBarcodeWidth: number | null
  averageBarcodeHeight: number | null
  averageBarcodeWidthRatio: number | null
  correctTimestamps: string[]
  firstCorrectTimestamp: string | null
  lastCorrectTimestamp: string | null
  correctDetectionIntervalsMs: number[]
  stability: StabilityCategory
  configurationStatus: ConfigurationStatus
  orderIndex: number
}

export interface ObservedFocusWindow {
  rangeLabel: string | null
  bestFocus: number | null
  bestCorrect: number
}

export function resolveSweepFocusLevels(capabilities: FocusDistanceCapabilities): number[] {
  return FOCUS_SWEEP_LEVELS
    .map((value) => clampFocusForExperiment(value, capabilities))
    .filter((value): value is number => value != null)
}

export function buildFineFocusConfigurations(options: {
  expectedBarcode: string
  focusLevels: number[]
  preserveOrder?: FineFocusConfiguration[]
}): FineFocusConfiguration[] {
  const base = options.focusLevels.map((focus) => ({
    id: `focus-${focus}`,
    label: `focus ${focus}`,
    requestedFocusDistance: focus,
    requestedZoom: FIXED_ZOOM,
    expectedBarcode: options.expectedBarcode,
    orderIndex: 0,
  }))

  if (options.preserveOrder && options.preserveOrder.length === base.length) {
    return options.preserveOrder.map((item, index) => ({ ...item, orderIndex: index }))
  }

  return base.map((item, index) => ({ ...item, orderIndex: index }))
}

export function randomizeFineFocusOrder(configs: FineFocusConfiguration[]): FineFocusConfiguration[] {
  const shuffled = [...configs]

  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(Math.random() * (index + 1))
    ;[shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex]!, shuffled[index]!]
  }

  return shuffled.map((item, orderIndex) => ({ ...item, orderIndex }))
}

export function computeStability(correct: number, frames: number): StabilityCategory {
  const correctRate = frames > 0 ? correct / frames : 0

  if (correct >= 3 && correctRate >= 0.3) {
    return 'STABLE'
  }

  if (correct >= 1 && correct <= 2) {
    return 'PROMISING'
  }

  return 'FAILED'
}

export function computeCorrectDetectionIntervals(timestampsMs: number[]): number[] {
  if (timestampsMs.length < 2) {
    return []
  }

  const intervals: number[] = []

  for (let index = 1; index < timestampsMs.length; index += 1) {
    intervals.push(Math.round(timestampsMs[index]! - timestampsMs[index - 1]!))
  }

  return intervals
}

export function createEmptyFineFocusResult(config: FineFocusConfiguration): FineFocusConfigurationResult {
  return {
    configId: config.id,
    focusRequested: config.requestedFocusDistance,
    focusActual: '—',
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
    correctPerFrameRate: '—',
    timeToFirstCorrectMs: null,
    averageSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    averageBarcodeWidth: null,
    averageBarcodeHeight: null,
    averageBarcodeWidthRatio: null,
    correctTimestamps: [],
    firstCorrectTimestamp: null,
    lastCorrectTimestamp: null,
    correctDetectionIntervalsMs: [],
    stability: 'FAILED',
    configurationStatus: 'NOT_APPLIED',
    orderIndex: config.orderIndex,
  }
}

export function finalizeFineFocusResult(
  config: FineFocusConfiguration,
  applied: AppliedExperimentSnapshot,
  stats: {
    frames: number
    detections: number
    correct: number
    incorrect: number
    notFound: number
    timeToFirstCorrectMs: number | null
    sharpnessValues: number[]
    barcodeWidths: number[]
    barcodeHeights: number[]
    widthRatios: number[]
    correctTimestamps: string[]
    correctTimestampsMs: number[]
  },
): FineFocusConfigurationResult {
  const configurationStatus: ConfigurationStatus =
    applied.configurationStatus !== 'VALID'
      ? applied.configurationStatus === 'APPLY_ERROR' ? 'APPLY_ERROR' : 'NOT_APPLIED'
      : stats.correct === 0 && stats.detections === 0 && stats.frames > 0
        ? 'NO_DETECTION'
        : 'VALID'

  const minSharpness = stats.sharpnessValues.length ? Math.min(...stats.sharpnessValues) : null
  const maxSharpness = stats.sharpnessValues.length ? Math.max(...stats.sharpnessValues) : null
  const correctRate = computeRate(stats.correct, stats.frames)

  return {
    configId: config.id,
    focusRequested: config.requestedFocusDistance,
    focusActual: applied.actualFocusDistance,
    zoomRequested: config.requestedZoom,
    zoomActual: applied.actualZoom,
    applied,
    frames: stats.frames,
    detections: stats.detections,
    correct: stats.correct,
    incorrect: stats.incorrect,
    notFound: stats.notFound,
    detectionRate: computeRate(stats.detections, stats.frames),
    correctRate,
    correctPerFrameRate: correctRate,
    timeToFirstCorrectMs: stats.timeToFirstCorrectMs,
    averageSharpness: average(stats.sharpnessValues),
    minSharpness,
    maxSharpness,
    averageBarcodeWidth: average(stats.barcodeWidths),
    averageBarcodeHeight: average(stats.barcodeHeights),
    averageBarcodeWidthRatio: stats.widthRatios.length
      ? Number((stats.widthRatios.reduce((sum, value) => sum + value, 0) / stats.widthRatios.length).toFixed(4))
      : null,
    correctTimestamps: stats.correctTimestamps,
    firstCorrectTimestamp: stats.correctTimestamps[0] ?? null,
    lastCorrectTimestamp: stats.correctTimestamps.at(-1) ?? null,
    correctDetectionIntervalsMs: computeCorrectDetectionIntervals(stats.correctTimestampsMs),
    stability: computeStability(stats.correct, stats.frames),
    configurationStatus,
    orderIndex: config.orderIndex,
  }
}

export function buildSummaryTableRows(results: FineFocusConfigurationResult[]): Array<{
  focus: number
  actual: string
  frames: number
  detections: number
  correct: number
  incorrect: number
  detectionRate: string
  correctRate: string
  avgWidth: string
  sharpness: string
  stability: StabilityCategory
}> {
  return [...results]
    .sort((left, right) => left.focusRequested - right.focusRequested)
    .map((item) => ({
      focus: item.focusRequested,
      actual: item.focusActual,
      frames: item.frames,
      detections: item.detections,
      correct: item.correct,
      incorrect: item.incorrect,
      detectionRate: item.detectionRate,
      correctRate: item.correctRate,
      avgWidth: item.averageBarcodeWidthRatio != null
        ? `${(item.averageBarcodeWidthRatio * 100).toFixed(1)}%`
        : '—',
      sharpness: item.averageSharpness != null ? String(Math.round(item.averageSharpness)) : '—',
      stability: item.stability,
    }))
}

export function buildBestFocusRanking(results: FineFocusConfigurationResult[]): FineFocusConfigurationResult[] {
  return [...results].sort((left, right) => {
    if (right.correct !== left.correct) {
      return right.correct - left.correct
    }

    const leftCorrectRate = left.correct / Math.max(left.frames, 1)
    const rightCorrectRate = right.correct / Math.max(right.frames, 1)

    if (rightCorrectRate !== leftCorrectRate) {
      return rightCorrectRate - leftCorrectRate
    }

    const leftRepeatable = left.correct >= 2 ? 1 : 0
    const rightRepeatable = right.correct >= 2 ? 1 : 0

    if (rightRepeatable !== leftRepeatable) {
      return rightRepeatable - leftRepeatable
    }

    const leftDetectionRate = left.detections / Math.max(left.frames, 1)
    const rightDetectionRate = right.detections / Math.max(right.frames, 1)

    if (rightDetectionRate !== leftDetectionRate) {
      return rightDetectionRate - leftDetectionRate
    }

    return (left.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER) - (right.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER)
  })
}

export function buildObservedFocusWindow(results: FineFocusConfigurationResult[]): ObservedFocusWindow {
  const sortedByFocus = [...results].sort((left, right) => left.focusRequested - right.focusRequested)
  const best = buildBestFocusRanking(results)[0]
  const bestCorrect = best?.correct ?? 0

  if (bestCorrect === 0) {
    return { rangeLabel: null, bestFocus: null, bestCorrect: 0 }
  }

  const levelRows = FOCUS_SWEEP_LEVELS.map((focus) => {
    const match = sortedByFocus.find((item) => Math.abs(item.focusRequested - focus) < 0.001)
    return { focus, correct: match?.correct ?? 0 }
  })

  let bestRange: { start: number; end: number; total: number } | null = null
  let rangeStart = -1
  let rangeTotal = 0

  for (let index = 0; index < levelRows.length; index += 1) {
    const row = levelRows[index]!

    if (row.correct > 0) {
      if (rangeStart === -1) {
        rangeStart = index
        rangeTotal = 0
      }

      rangeTotal += row.correct
    } else if (rangeStart !== -1) {
      const start = levelRows[rangeStart]!.focus
      const end = levelRows[index - 1]!.focus

      if (!bestRange || rangeTotal > bestRange.total) {
        bestRange = { start, end, total: rangeTotal }
      }

      rangeStart = -1
      rangeTotal = 0
    }
  }

  if (rangeStart !== -1) {
    const start = levelRows[rangeStart]!.focus
    const end = levelRows[levelRows.length - 1]!.focus

    if (!bestRange || rangeTotal > bestRange.total) {
      bestRange = { start, end, total: rangeTotal }
    }
  }

  return {
    rangeLabel: bestRange ? `${bestRange.start.toFixed(2)}–${bestRange.end.toFixed(2)}` : null,
    bestFocus: best?.focusRequested ?? null,
    bestCorrect,
  }
}

export type ConclusionKind = 'NO_VALID' | 'SINGLE_SUCCESS' | 'REPEATABLE'

export function determineConclusionKind(results: FineFocusConfigurationResult[]): ConclusionKind {
  const totalCorrect = results.reduce((sum, item) => sum + item.correct, 0)
  const maxCorrect = Math.max(0, ...results.map((item) => item.correct))

  if (totalCorrect === 0) {
    return 'NO_VALID'
  }

  if (totalCorrect === 1 || maxCorrect === 1) {
    return 'SINGLE_SUCCESS'
  }

  return 'REPEATABLE'
}

export function buildSweepConclusion(
  results: FineFocusConfigurationResult[],
  window: ObservedFocusWindow,
): string {
  const kind = determineConclusionKind(results)
  const lines = ['=== BENCHMARK CONCLUSION ===', '']

  if (kind === 'NO_VALID') {
    lines.push(
      'NO VALID FOCUS CONFIGURATION',
      '',
      'No correct barcode detection was recorded.',
      '',
      'The experiment cannot determine a reliable',
      'focus distance for this device.',
    )
  } else if (kind === 'SINGLE_SUCCESS') {
    const focus = window.bestFocus ?? results.find((item) => item.correct > 0)?.focusRequested ?? '—'
    lines.push(
      'SINGLE SUCCESS OBSERVED',
      '',
      `A correct read was observed around focus ${focus},`,
      'but repeatability has not yet been established.',
      '',
      'A correct read was observed, but the sample',
      'is insufficient to establish repeatability.',
      '',
      'Further testing is recommended.',
    )
  } else {
    lines.push(
      'REPEATABLE READS OBSERVED',
      '',
      'The BarcodeDetector successfully decoded the',
      'expected barcode multiple times in a focus range',
      window.rangeLabel
        ? `around ${window.rangeLabel}.`
        : 'around the tested values.',
      '',
      window.bestFocus != null ? `Best observed focus:\n${window.bestFocus.toFixed(2)}` : 'Best observed focus:\n—',
    )

    if (window.rangeLabel) {
      lines.push('', 'Observed promising range:', window.rangeLabel)
    }
  }

  lines.push(
    '',
    'Experimental result only.',
    'Do not integrate automatically into the production scanner.',
    '',
    'EXPERIMENTAL RESULT ONLY',
    'DO NOT INTEGRATE AUTOMATICALLY INTO THE SCANNER.',
  )

  return lines.join('\n')
}

export function buildSweepReport(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  expectedBarcode: string
  randomized: boolean
  configurationOrder: FineFocusConfiguration[]
  results: FineFocusConfigurationResult[]
  rawDetections: FineFocusRawDetection[]
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE FINE FOCUS SWEEP ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    '',
    'Camera:',
    `Resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    `Barcode expected: ${options.expectedBarcode}`,
    '',
    'Focus capabilities:',
    `Min: ${options.capabilities.focusDistance.min ?? '—'}`,
    `Max: ${options.capabilities.focusDistance.max ?? '—'}`,
    `Step: ${options.capabilities.focusDistance.step ?? '—'}`,
    `Supported: ${options.capabilities.focusDistance.supported ? 'yes' : 'no'}`,
    '',
    'Size: MEDIUM',
    `Target: ${MEDIUM_SIZE_TARGET}`,
    '',
    'Zoom:',
    `${FIXED_ZOOM}×`,
    '',
    `Duration: ${DURATION_SECONDS}s`,
    '',
    `Order mode: ${options.randomized ? 'RANDOMIZED' : 'FIXED'}`,
    '',
    'Configuration order used:',
    ...options.configurationOrder.map((item, index) => `${index + 1}. focus ${item.requestedFocusDistance}`),
    '',
    'RESULTS',
    '',
  ]

  for (const result of [...options.results].sort((left, right) => left.focusRequested - right.focusRequested)) {
    lines.push(
      `Focus requested: ${result.focusRequested}`,
      `Focus actual: ${result.focusActual}`,
      `Zoom requested: ${result.zoomRequested}×`,
      `Zoom actual: ${result.zoomActual}×`,
      `Validation focus mode: ${result.applied?.focusModeValidation ?? '—'}`,
      `Validation focus distance: ${result.applied?.focusDistanceValidation ?? '—'}`,
      `Validation zoom: ${result.applied?.zoomValidation ?? '—'}`,
      `Stability: ${result.stability}`,
      `Frames: ${result.frames}`,
      `Detections: ${result.detections}`,
      `Correct: ${result.correct}`,
      `Incorrect: ${result.incorrect}`,
      `Not found: ${result.notFound}`,
      `Detection rate: ${result.detectionRate}`,
      `Correct rate: ${result.correctRate}`,
      `Correct per frame rate: ${result.correctPerFrameRate}`,
      `Time to first correct: ${result.timeToFirstCorrectMs ?? '—'} ms`,
      `Average sharpness: ${result.averageSharpness ?? '—'}`,
      `Min sharpness: ${result.minSharpness ?? '—'}`,
      `Max sharpness: ${result.maxSharpness ?? '—'}`,
      `Average barcode width: ${result.averageBarcodeWidth ?? '—'}`,
      `Average barcode height: ${result.averageBarcodeHeight ?? '—'}`,
      `Average barcode width ratio: ${result.averageBarcodeWidthRatio != null ? `${(result.averageBarcodeWidthRatio * 100).toFixed(1)}%` : '—'}`,
      `First correct: ${result.firstCorrectTimestamp ?? '—'}`,
      `Last correct: ${result.lastCorrectTimestamp ?? '—'}`,
      `Correct detection intervals (ms): ${result.correctDetectionIntervalsMs.length ? result.correctDetectionIntervalsMs.join(', ') : '—'}`,
      '',
      'Correct detections:',
      `${result.correct}`,
      '',
      'Timestamps:',
      ...(result.correctTimestamps.length ? result.correctTimestamps : ['—']),
      '',
    )
  }

  lines.push('RAW DETECTIONS', '')

  for (const entry of options.rawDetections) {
    lines.push(
      `${entry.timestamp} — focus ${entry.focusRequested} (actual ${entry.focusActual})`,
      `format: ${entry.format}`,
      `rawValue: ${entry.rawValue}`,
      `classification: ${entry.classification}`,
      `boundingBox: ${entry.boundingBoxWidth ?? '—'} × ${entry.boundingBoxHeight ?? '—'}`,
      `widthRatio: ${entry.widthRatio != null ? `${(entry.widthRatio * 100).toFixed(1)}%` : '—'}`,
      `sharpness: ${entry.sharpness ?? '—'}`,
      '',
    )
  }

  lines.push('SUMMARY', '')

  for (const row of buildSummaryTableRows(options.results)) {
    lines.push(
      `Focus ${row.focus} | actual ${row.actual} | frames ${row.frames} | detections ${row.detections} | correct ${row.correct} | incorrect ${row.incorrect} | detection ${row.detectionRate} | correct ${row.correctRate} | avg width ${row.avgWidth} | sharpness ${row.sharpness} | stability ${row.stability}`,
    )
  }

  lines.push('', 'BEST FOCUS CONFIGURATIONS', '')

  for (const [index, item] of buildBestFocusRanking(options.results).entries()) {
    lines.push(
      `${index + 1}. focus ${item.focusRequested} — correct ${item.correct} — correct rate ${item.correctRate} — stability ${item.stability}`,
    )
  }

  const window = buildObservedFocusWindow(options.results)

  if (window.rangeLabel && window.bestFocus != null) {
    lines.push('', 'OBSERVED FOCUS WINDOW', '', `Approximate promising range:\n${window.rangeLabel}`, '', `Best observed:\n${window.bestFocus.toFixed(2)}`)
  }

  lines.push('', options.conclusion, '')

  return lines.join('\n')
}
