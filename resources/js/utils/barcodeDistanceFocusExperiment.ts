import {
  applyCameraConfiguration,
  average,
  classifyReadResult,
  computeRate,
  createComparisonBarcodeDetector,
  extractBarcodeGeometry,
  FIXED_CAMERA_CONSTRAINTS,
  measureVideoSharpness,
  normalizeDetections,
  pickBestNativeBarcode,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  type AppliedConfigurationSnapshot,
  type ConfigurationStatus,
  type EnvironmentDiagnostics,
  type FocusDistanceCapabilities,
  type ReadResultType,
  type TrackCapabilitiesSnapshot,
  type TrackSettingsSnapshot,
} from '@/utils/barcodeSizeZoomComparison'

export const EXPECTED_BARCODE = '6043000070493'
export const FOCUS_LEVELS = [0.2, 0.3, 0.39, 0.5] as const
export const FIXED_ZOOM = 1
export const DURATION_SECONDS = 15
export const DETECTION_INTERVAL_MS = 150
export const MAX_RAW_DETECTIONS = 50
export const STABILIZATION_MS = 1000

export { FIXED_CAMERA_CONSTRAINTS, getEnvironmentDiagnostics, isNativeBarcodeDetectorAvailable, createComparisonBarcodeDetector, pickBestNativeBarcode }
export type { EnvironmentDiagnostics }

export type ApparentSize = 'SMALL' | 'MEDIUM' | 'LARGE'
export type ValidationStatus = 'MATCH' | 'MISMATCH' | 'UNSUPPORTED' | 'APPLY_ERROR'

export interface SizeTargetSpec {
  size: ApparentSize
  label: string
  minWidthRatio: number
  maxWidthRatio: number
  guideWidthRatio: number
  targetLabel: string
  instruction: string
}

export const SIZE_TARGETS: SizeTargetSpec[] = [
  {
    size: 'SMALL',
    label: 'SMALL',
    minWidthRatio: 0.25,
    maxWidthRatio: 0.35,
    guideWidthRatio: 0.3,
    targetLabel: '25–35% de la largeur de l\'image',
    instruction: 'Placez le téléphone afin que le code occupe\nenviron 25–35 % de la largeur de l\'image.\n\nMaintenez ensuite le téléphone immobile.',
  },
  {
    size: 'MEDIUM',
    label: 'MEDIUM',
    minWidthRatio: 0.4,
    maxWidthRatio: 0.5,
    guideWidthRatio: 0.45,
    targetLabel: '40–50% de la largeur de l\'image',
    instruction: 'Placez le téléphone afin que le code occupe\nenviron 40–50 % de la largeur de l\'image.\n\nMaintenez ensuite le téléphone immobile.',
  },
  {
    size: 'LARGE',
    label: 'LARGE',
    minWidthRatio: 0.6,
    maxWidthRatio: 0.7,
    guideWidthRatio: 0.65,
    targetLabel: '60–70% de la largeur de l\'image',
    instruction: 'Placez le téléphone afin que le code occupe\nenviron 60–70 % de la largeur de l\'image.\n\nMaintenez ensuite le téléphone immobile.',
  },
]

export interface DistanceFocusConfiguration {
  id: string
  label: string
  size: ApparentSize
  sizeTarget: string
  sizeInstruction: string
  guideWidthRatio: number
  requestedFocusDistance: number
  requestedZoom: number
  expectedBarcode: string
  orderIndex: number
}

export interface AppliedExperimentSnapshot extends AppliedConfigurationSnapshot {
  focusModeValidation: ValidationStatus
  focusDistanceValidation: ValidationStatus
  zoomValidation: ValidationStatus
  focusDistanceSupported: boolean
}

export interface DistanceFocusRawDetection {
  id: string
  timestamp: string
  configuration: string
  size: ApparentSize
  focus: string
  zoom: string
  format: string
  rawValue: string
  classification: ReadResultType
  boundingBox: { x: number; y: number; width: number; height: number } | null
  boundingBoxWidth: number | null
  boundingBoxHeight: number | null
  boundingBoxWidthRatio: number | null
  sharpness: number | null
}

export interface DistanceFocusConfigurationResult {
  configId: string
  configuration: string
  size: ApparentSize
  sizeTarget: string
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
  timeToFirstCorrectMs: number | null
  averageSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  sharpnessAtDetection: number | null
  sharpnessAtCorrectDetection: number | null
  averageBarcodeWidth: number | null
  averageBarcodeHeight: number | null
  averageBarcodeWidthRatio: number | null
  configurationStatus: ConfigurationStatus
  orderIndex: number
}

export interface BestConfigurationResult {
  size: ApparentSize
  focus: number
  correct: number
  correctRate: string
  detectionRate: string
  timeToFirstCorrectMs: number | null
  label: string
}

export function getSizeTarget(size: ApparentSize): SizeTargetSpec {
  return SIZE_TARGETS.find((item) => item.size === size) ?? SIZE_TARGETS[1]!
}

export function resolveFocusLevels(capabilities: FocusDistanceCapabilities): number[] {
  return FOCUS_LEVELS
    .map((value) => clampFocusForExperiment(value, capabilities))
    .filter((value): value is number => value != null)
}

export function clampFocusForExperiment(value: number, capabilities: FocusDistanceCapabilities): number | null {
  if (!capabilities.supported || capabilities.min == null || capabilities.max == null) {
    return null
  }

  const clamped = Math.min(capabilities.max, Math.max(capabilities.min, value))

  if (capabilities.step != null && capabilities.step > 0) {
    const steps = Math.round((clamped - capabilities.min) / capabilities.step)
    return Number((capabilities.min + steps * capabilities.step).toFixed(4))
  }

  return Number(clamped.toFixed(4))
}

export function buildDistanceFocusConfigurations(options: {
  expectedBarcode: string
  focusLevels: number[]
  preserveOrder?: DistanceFocusConfiguration[]
}): DistanceFocusConfiguration[] {
  const base: DistanceFocusConfiguration[] = []

  for (const sizeSpec of SIZE_TARGETS) {
    for (const focus of options.focusLevels) {
      base.push({
        id: `${sizeSpec.size}-${focus}`,
        label: `${sizeSpec.label} × focus ${focus}`,
        size: sizeSpec.size,
        sizeTarget: sizeSpec.targetLabel,
        sizeInstruction: sizeSpec.instruction,
        guideWidthRatio: sizeSpec.guideWidthRatio,
        requestedFocusDistance: focus,
        requestedZoom: FIXED_ZOOM,
        expectedBarcode: options.expectedBarcode,
        orderIndex: 0,
      })
    }
  }

  if (options.preserveOrder && options.preserveOrder.length === base.length) {
    return options.preserveOrder.map((item, index) => ({ ...item, orderIndex: index }))
  }

  return base.map((item, index) => ({ ...item, orderIndex: index }))
}

export function randomizeDistanceFocusOrder(configs: DistanceFocusConfiguration[]): DistanceFocusConfiguration[] {
  const shuffled = [...configs]

  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(Math.random() * (index + 1))
    ;[shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex]!, shuffled[index]!]
  }

  return shuffled.map((item, orderIndex) => ({ ...item, orderIndex }))
}

function mapValidationStatus(
  status: AppliedConfigurationSnapshot['focusModeStatus'],
  supported: boolean,
): ValidationStatus {
  if (!supported) {
    return 'UNSUPPORTED'
  }

  if (status === 'APPLY_ERROR') {
    return 'APPLY_ERROR'
  }

  if (status === 'MATCH') {
    return 'MATCH'
  }

  if (status === 'MISMATCH') {
    return 'MISMATCH'
  }

  return 'UNSUPPORTED'
}

export async function applyExperimentConfiguration(
  track: MediaStreamTrack,
  options: {
    requestedFocusDistance: number
    requestedZoom: number
    focusDistanceCapabilities: FocusDistanceCapabilities
    zoomStep: number | null
  },
): Promise<AppliedExperimentSnapshot> {
  const focusSupported = options.focusDistanceCapabilities.supported

  if (!focusSupported) {
    const settings = readTrackSettingsSnapshot(track)

    return {
      requestedFocusMode: 'manual',
      actualFocusMode: settings.focusMode,
      requestedFocusDistance: options.requestedFocusDistance,
      actualFocusDistance: settings.focusDistance,
      requestedZoom: options.requestedZoom,
      actualZoom: settings.zoom,
      focusModeStatus: 'UNKNOWN',
      focusDistanceStatus: 'UNKNOWN',
      zoomStatus: 'UNKNOWN',
      configurationStatus: 'NOT_APPLIED',
      applyErrorName: null,
      applyErrorMessage: 'focusDistance not supported by camera',
      constraintsJson: '{}',
      focusModeValidation: 'UNSUPPORTED',
      focusDistanceValidation: 'UNSUPPORTED',
      zoomValidation: evaluateZoomValidation(options.requestedZoom, settings.zoom, options.zoomStep),
      focusDistanceSupported: false,
    }
  }

  const applied = await applyCameraConfiguration(track, {
    requestedFocusDistance: options.requestedFocusDistance,
    requestedZoom: options.requestedZoom,
    focusDistanceStep: options.focusDistanceCapabilities.step,
    zoomStep: options.zoomStep,
  })

  return {
    ...applied,
    focusModeValidation: mapValidationStatus(applied.focusModeStatus, focusSupported),
    focusDistanceValidation: mapValidationStatus(applied.focusDistanceStatus, focusSupported),
    zoomValidation: applied.zoomStatus === 'MATCH'
      ? 'MATCH'
      : applied.zoomStatus === 'MISMATCH'
        ? 'MISMATCH'
        : applied.zoomStatus === 'APPLY_ERROR'
          ? 'APPLY_ERROR'
          : 'UNSUPPORTED',
    focusDistanceSupported: focusSupported,
  }
}

function evaluateZoomValidation(requested: number, actual: string, step: number | null): ValidationStatus {
  if (actual === '—') {
    return 'UNSUPPORTED'
  }

  const actualValue = Number.parseFloat(actual)

  if (!Number.isFinite(actualValue)) {
    return 'UNSUPPORTED'
  }

  const tolerance = step != null && step > 0 ? Math.max(step / 2, 0.05) : 0.05
  return Math.abs(actualValue - requested) <= tolerance ? 'MATCH' : 'MISMATCH'
}

export function createEmptyDistanceFocusResult(config: DistanceFocusConfiguration): DistanceFocusConfigurationResult {
  return {
    configId: config.id,
    configuration: config.label,
    size: config.size,
    sizeTarget: config.sizeTarget,
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
    timeToFirstCorrectMs: null,
    averageSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    sharpnessAtDetection: null,
    sharpnessAtCorrectDetection: null,
    averageBarcodeWidth: null,
    averageBarcodeHeight: null,
    averageBarcodeWidthRatio: null,
    configurationStatus: 'NOT_APPLIED',
    orderIndex: config.orderIndex,
  }
}

export function finalizeDistanceFocusResult(
  config: DistanceFocusConfiguration,
  applied: AppliedExperimentSnapshot,
  stats: {
    frames: number
    detections: number
    correct: number
    incorrect: number
    notFound: number
    timeToFirstCorrectMs: number | null
    sharpnessValues: number[]
    sharpnessAtDetections: number[]
    sharpnessAtCorrectDetections: number[]
    barcodeWidths: number[]
    barcodeHeights: number[]
    widthRatios: number[]
  },
): DistanceFocusConfigurationResult {
  const configurationStatus: ConfigurationStatus =
    applied.configurationStatus !== 'VALID'
      ? applied.configurationStatus === 'APPLY_ERROR' ? 'APPLY_ERROR' : 'NOT_APPLIED'
      : stats.correct === 0 && stats.detections === 0 && stats.frames > 0
        ? 'NO_DETECTION'
        : 'VALID'

  const minSharpness = stats.sharpnessValues.length ? Math.min(...stats.sharpnessValues) : null
  const maxSharpness = stats.sharpnessValues.length ? Math.max(...stats.sharpnessValues) : null

  return {
    configId: config.id,
    configuration: config.label,
    size: config.size,
    sizeTarget: config.sizeTarget,
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
    correctRate: computeRate(stats.correct, stats.frames),
    timeToFirstCorrectMs: stats.timeToFirstCorrectMs,
    averageSharpness: average(stats.sharpnessValues),
    minSharpness,
    maxSharpness,
    sharpnessAtDetection: average(stats.sharpnessAtDetections),
    sharpnessAtCorrectDetection: average(stats.sharpnessAtCorrectDetections),
    averageBarcodeWidth: average(stats.barcodeWidths),
    averageBarcodeHeight: average(stats.barcodeHeights),
    averageBarcodeWidthRatio: stats.widthRatios.length
      ? Number((stats.widthRatios.reduce((sum, value) => sum + value, 0) / stats.widthRatios.length).toFixed(4))
      : null,
    configurationStatus,
    orderIndex: config.orderIndex,
  }
}

export function findBestDistanceFocusConfiguration(results: DistanceFocusConfigurationResult[]): BestConfigurationResult | null {
  const candidates = results.filter((item) => item.correct > 0)

  if (candidates.length === 0) {
    return null
  }

  const best = [...candidates].sort((left, right) => {
    if (right.correct !== left.correct) {
      return right.correct - left.correct
    }

    const leftCorrectRate = left.correct / Math.max(left.frames, 1)
    const rightCorrectRate = right.correct / Math.max(right.frames, 1)

    if (rightCorrectRate !== leftCorrectRate) {
      return rightCorrectRate - leftCorrectRate
    }

    const leftDetectionRate = left.detections / Math.max(left.frames, 1)
    const rightDetectionRate = right.detections / Math.max(right.frames, 1)

    if (rightDetectionRate !== leftDetectionRate) {
      return rightDetectionRate - leftDetectionRate
    }

    return (left.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER) - (right.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER)
  })[0]!

  return {
    size: best.size,
    focus: best.focusRequested,
    correct: best.correct,
    correctRate: best.correctRate,
    detectionRate: best.detectionRate,
    timeToFirstCorrectMs: best.timeToFirstCorrectMs,
    label: `${best.size} × focus ${best.focusRequested}`,
  }
}

export function buildSizeComparisonSummary(results: DistanceFocusConfigurationResult[]): Array<{
  size: ApparentSize
  correctReads: number
  detectionRate: string
}> {
  return SIZE_TARGETS.map((spec) => {
    const items = results.filter((item) => item.size === spec.size)
    const correctReads = items.reduce((sum, item) => sum + item.correct, 0)
    const frames = items.reduce((sum, item) => sum + item.frames, 0)
    const detections = items.reduce((sum, item) => sum + item.detections, 0)

    return {
      size: spec.size,
      correctReads,
      detectionRate: computeRate(detections, frames),
    }
  })
}

export function buildFocusComparisonSummary(results: DistanceFocusConfigurationResult[]): Array<{
  focus: number
  correctReads: number
}> {
  const focusLevels = [...new Set(results.map((item) => item.focusRequested))].sort((a, b) => a - b)

  return focusLevels.map((focus) => ({
    focus,
    correctReads: results
      .filter((item) => item.focusRequested === focus)
      .reduce((sum, item) => sum + item.correct, 0),
  }))
}

export function buildSummaryTableRows(results: DistanceFocusConfigurationResult[]): Array<{
  size: ApparentSize
  focus: number
  detectionRate: string
  correctRate: string
  correct: number
  incorrect: number
  avgWidth: string
  avgSharpness: string
}> {
  return [...results]
    .sort((left, right) => left.orderIndex - right.orderIndex)
    .map((item) => ({
      size: item.size,
      focus: item.focusRequested,
      detectionRate: item.detectionRate,
      correctRate: item.correctRate,
      correct: item.correct,
      incorrect: item.incorrect,
      avgWidth: item.averageBarcodeWidthRatio != null
        ? `${(item.averageBarcodeWidthRatio * 100).toFixed(1)}%`
        : '—',
      avgSharpness: item.averageSharpness != null ? String(Math.round(item.averageSharpness)) : '—',
    }))
}

export function buildBestConfigurationsRanking(results: DistanceFocusConfigurationResult[]): DistanceFocusConfigurationResult[] {
  return [...results].sort((left, right) => {
    if (right.correct !== left.correct) {
      return right.correct - left.correct
    }

    const leftCorrectRate = left.correct / Math.max(left.frames, 1)
    const rightCorrectRate = right.correct / Math.max(right.frames, 1)

    if (rightCorrectRate !== leftCorrectRate) {
      return rightCorrectRate - leftCorrectRate
    }

    const leftDetectionRate = left.detections / Math.max(left.frames, 1)
    const rightDetectionRate = right.detections / Math.max(right.frames, 1)

    if (rightDetectionRate !== leftDetectionRate) {
      return rightDetectionRate - leftDetectionRate
    }

    return (left.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER) - (right.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER)
  })
}

export function buildExperimentConclusion(best: BestConfigurationResult | null): string {
  const lines = ['=== BENCHMARK CONCLUSION ===', '']

  if (best) {
    lines.push(
      'BEST OBSERVED CONFIGURATION',
      '',
      `Size: ${best.size}`,
      `Focus: ${best.focus}`,
      `Correct reads: ${best.correct}`,
      `Correct rate: ${best.correctRate}`,
      `Detection rate: ${best.detectionRate}`,
      '',
      'Experimental result only — not an automatic recommendation for the production scanner.',
    )
  } else {
    lines.push(
      'NO VALID BEST CONFIGURATION',
      '',
      'No correct barcode detection was recorded.',
      'The experiment cannot determine an optimal',
      'distance/focus configuration.',
    )
  }

  lines.push(
    '',
    'EXPERIMENTAL RESULT ONLY',
    'DO NOT INTEGRATE AUTOMATICALLY INTO THE SCANNER.',
  )

  return lines.join('\n')
}

export function buildExperimentReport(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  expectedBarcode: string
  randomized: boolean
  configurationOrder: DistanceFocusConfiguration[]
  results: DistanceFocusConfigurationResult[]
  rawDetections: DistanceFocusRawDetection[]
  best: BestConfigurationResult | null
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE DISTANCE × FOCUS EXPERIMENT ===',
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
    'Zoom:',
    `${FIXED_ZOOM}×`,
    '',
    `Duration: ${DURATION_SECONDS}s`,
    '',
    `Order mode: ${options.randomized ? 'RANDOMIZED' : 'FIXED'}`,
    '',
    'Configuration order used:',
    ...options.configurationOrder.map((item, index) => `${index + 1}. ${item.label}`),
    '',
    'RESULTS',
    '',
  ]

  for (const result of options.results) {
    lines.push(
      `Configuration: ${result.configuration}`,
      `Size target: ${result.sizeTarget}`,
      `Focus requested: ${result.focusRequested}`,
      `Focus actual: ${result.focusActual}`,
      `Zoom requested: ${result.zoomRequested}×`,
      `Zoom actual: ${result.zoomActual}×`,
      `Validation focus mode: ${result.applied?.focusModeValidation ?? '—'}`,
      `Validation focus distance: ${result.applied?.focusDistanceValidation ?? '—'}`,
      `Validation zoom: ${result.applied?.zoomValidation ?? '—'}`,
      `Frames: ${result.frames}`,
      `Detections: ${result.detections}`,
      `Correct: ${result.correct}`,
      `Incorrect: ${result.incorrect}`,
      `Not found: ${result.notFound}`,
      `Detection rate: ${result.detectionRate}`,
      `Correct rate: ${result.correctRate}`,
      `Time to first correct: ${result.timeToFirstCorrectMs ?? '—'} ms`,
      `Average sharpness: ${result.averageSharpness ?? '—'}`,
      `Min sharpness: ${result.minSharpness ?? '—'}`,
      `Max sharpness: ${result.maxSharpness ?? '—'}`,
      `Sharpness at detection: ${result.sharpnessAtDetection ?? '—'}`,
      `Sharpness at correct detection: ${result.sharpnessAtCorrectDetection ?? '—'}`,
      `Average barcode width: ${result.averageBarcodeWidth ?? '—'}`,
      `Average barcode height: ${result.averageBarcodeHeight ?? '—'}`,
      `Average barcode width ratio: ${result.averageBarcodeWidthRatio != null ? `${(result.averageBarcodeWidthRatio * 100).toFixed(1)}%` : '—'}`,
      '',
    )
  }

  lines.push('RAW DETECTIONS', '')

  for (const entry of options.rawDetections) {
    lines.push(
      `${entry.timestamp} — ${entry.size} — focus ${entry.focus}`,
      `format: ${entry.format}`,
      `rawValue: ${entry.rawValue}`,
      `classification: ${entry.classification}`,
      `boundingBox: ${entry.boundingBoxWidth ?? '—'} × ${entry.boundingBoxHeight ?? '—'}`,
      entry.boundingBox
        ? `bbox x/y: ${entry.boundingBox.x} / ${entry.boundingBox.y}`
        : 'bbox x/y: —',
      `widthRatio: ${entry.boundingBoxWidthRatio != null ? `${(entry.boundingBoxWidthRatio * 100).toFixed(1)}%` : '—'}`,
      `sharpness: ${entry.sharpness ?? '—'}`,
      '',
    )
  }

  lines.push('SUMMARY', '')

  for (const row of buildSummaryTableRows(options.results)) {
    lines.push(
      `${row.size} | focus ${row.focus} | detection ${row.detectionRate} | correct ${row.correctRate} | correct ${row.correct} | incorrect ${row.incorrect} | avg width ${row.avgWidth} | avg sharpness ${row.avgSharpness}`,
    )
  }

  lines.push('', 'Best configurations by CORRECT READS', '')

  for (const [index, item] of buildBestConfigurationsRanking(options.results).entries()) {
    lines.push(
      `${index + 1}. ${item.configuration} — correct ${item.correct} — correct rate ${item.correctRate} — detection rate ${item.detectionRate}`,
    )
  }

  lines.push('', 'Comparison by apparent size', '')

  for (const item of buildSizeComparisonSummary(options.results)) {
    lines.push(`${item.size}`, `correct reads: ${item.correctReads}`, `detection rate: ${item.detectionRate}`, '')
  }

  lines.push('Comparison by focus', '')

  for (const item of buildFocusComparisonSummary(options.results)) {
    lines.push(`FOCUS ${item.focus.toFixed(2)}`, `correct reads: ${item.correctReads}`, '')
  }

  lines.push('', options.conclusion, '')

  return lines.join('\n')
}

export {
  classifyReadResult,
  extractBarcodeGeometry,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
}
