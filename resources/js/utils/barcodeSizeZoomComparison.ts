import {
  createNativeBarcodeDetector,
  isNativeBarcodeDetectorAvailable,
  pickBestNativeBarcode,
  type BarcodeDetectorLike,
} from '@/utils/nativeBarcodeScannerEngine'
import { getEnvironmentDiagnostics, type EnvironmentDiagnostics } from '@/utils/nativeBarcodeDetectorLiveTest'

export const DEFAULT_EXPECTED_BARCODE = '6202312030117'
export const DEFAULT_FOCUS_DISTANCE = 0.39
export const STABILIZATION_MS = 1000
export const DETECTION_INTERVAL_MS = 150
export const MAX_RAW_DETECTIONS = 10
export const ZOOM_LEVELS = [1, 2, 3, 4] as const
export const DURATION_OPTIONS = [5, 10, 15, 20, 30] as const
export const DEFAULT_DURATION_SECONDS = 15

export const FIXED_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
    frameRate: { ideal: 30 },
  },
  audio: false,
}

export type BarcodeTarget = 'A' | 'B'
export type ReadResultType = 'CORRECT' | 'INCORRECT' | 'NOT_FOUND' | 'ERROR'
export type ConstraintMatchStatus = 'MATCH' | 'MISMATCH' | 'UNKNOWN' | 'APPLY_ERROR'
export type ConfigurationStatus = 'VALID' | 'NOT_APPLIED' | 'APPLY_ERROR' | 'NO_DETECTION'
export type DurationSeconds = (typeof DURATION_OPTIONS)[number]

export interface DetectedBarcodeWithGeometry {
  rawValue?: string
  format?: string
  boundingBox?: { x: number; y: number; width: number; height: number }
  cornerPoints?: Array<{ x: number; y: number }>
}

export interface FocusDistanceCapabilities {
  supported: boolean
  min: number | null
  max: number | null
  step: number | null
}

export interface ZoomCapabilities {
  supported: boolean
  min: number | null
  max: number | null
  step: number | null
}

export interface TrackCapabilitiesSnapshot {
  focusModes: string[]
  focusDistance: FocusDistanceCapabilities
  zoom: ZoomCapabilities
}

export interface TrackSettingsSnapshot {
  width: number | null
  height: number | null
  frameRate: string
  facingMode: string
  focusMode: string
  focusDistance: string
  zoom: string
}

export interface ComparisonConfiguration {
  id: string
  label: string
  barcodeTarget: BarcodeTarget
  expectedBarcode: string
  requestedZoom: number
  orderIndex: number
}

export interface AppliedConfigurationSnapshot {
  requestedFocusMode: string
  actualFocusMode: string
  requestedFocusDistance: number
  actualFocusDistance: string
  requestedZoom: number
  actualZoom: string
  focusModeStatus: ConstraintMatchStatus
  focusDistanceStatus: ConstraintMatchStatus
  zoomStatus: ConstraintMatchStatus
  configurationStatus: ConfigurationStatus
  applyErrorName: string | null
  applyErrorMessage: string | null
  constraintsJson: string
}

export interface RawDetectionEntry {
  id: string
  timestamp: string
  barcodeTarget: BarcodeTarget
  zoom: string
  format: string
  rawValue: string
  classification: ReadResultType
  boundingBoxWidth: number | null
  boundingBoxHeight: number | null
  widthRatio: number | null
}

export interface ConfigurationResult {
  configId: string
  label: string
  barcodeTarget: BarcodeTarget
  requestedZoom: number
  requestedFocusDistance: number
  applied: AppliedConfigurationSnapshot | null
  framesAnalyzed: number
  detections: number
  correct: number
  incorrect: number
  notFound: number
  correctRate: string
  detectionRate: string
  averageDetectionLatencyMs: number | null
  timeToFirstCorrectMs: number | null
  averageSharpness: number | null
  averageBarcodeWidth: number | null
  averageBarcodeHeight: number | null
  averageWidthRatio: number | null
  configurationStatus: ConfigurationStatus
}

export interface BestZoomResult {
  zoom: number
  correctRate: string
  detectionRate: string
  label: string
}

export { getEnvironmentDiagnostics, isNativeBarcodeDetectorAvailable, type EnvironmentDiagnostics }

function readNumeric(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function readTrackCapabilitiesSnapshot(track: MediaStreamTrack | null): TrackCapabilitiesSnapshot {
  const raw = track?.getCapabilities?.() as Record<string, unknown> | undefined

  if (!raw) {
    return {
      focusModes: [],
      focusDistance: { supported: false, min: null, max: null, step: null },
      zoom: { supported: false, min: null, max: null, step: null },
    }
  }

  const focusModes = Array.isArray(raw.focusMode)
    ? raw.focusMode.map(String)
    : raw.focusMode != null ? [String(raw.focusMode)] : []

  const focusDistanceRaw = raw.focusDistance
  const focusDistanceSupported = focusDistanceRaw != null && typeof focusDistanceRaw === 'object'
  const focusDistanceRecord = focusDistanceSupported
    ? focusDistanceRaw as { min?: number; max?: number; step?: number }
    : null

  const zoomRaw = raw.zoom
  const zoomSupported = zoomRaw != null && typeof zoomRaw === 'object'
  const zoomRecord = zoomSupported ? zoomRaw as { min?: number; max?: number; step?: number } : null

  return {
    focusModes,
    focusDistance: {
      supported: focusDistanceSupported,
      min: readNumeric(focusDistanceRecord?.min),
      max: readNumeric(focusDistanceRecord?.max),
      step: readNumeric(focusDistanceRecord?.step),
    },
    zoom: {
      supported: zoomSupported,
      min: readNumeric(zoomRecord?.min),
      max: readNumeric(zoomRecord?.max),
      step: readNumeric(zoomRecord?.step),
    },
  }
}

export function readTrackSettingsSnapshot(track: MediaStreamTrack | null): TrackSettingsSnapshot {
  const settings = track?.getSettings?.() as (MediaTrackSettings & {
    zoom?: number
    focusMode?: string
    focusDistance?: number
  }) | undefined

  if (!settings) {
    return {
      width: null,
      height: null,
      frameRate: '—',
      facingMode: '—',
      focusMode: '—',
      focusDistance: '—',
      zoom: '—',
    }
  }

  return {
    width: settings.width ?? null,
    height: settings.height ?? null,
    frameRate: settings.frameRate != null ? String(settings.frameRate) : '—',
    facingMode: settings.facingMode ? String(settings.facingMode) : '—',
    focusMode: settings.focusMode != null ? String(settings.focusMode) : '—',
    focusDistance: settings.focusDistance != null ? String(settings.focusDistance) : '—',
    zoom: settings.zoom != null ? String(settings.zoom) : '—',
  }
}

export function roundToStep(value: number, min: number, max: number, step: number | null): number {
  const clamped = Math.min(Math.max(value, min), max)

  if (step == null || step <= 0) {
    return Number(clamped.toFixed(6))
  }

  const steps = Math.round((clamped - min) / step)

  return Number((min + steps * step).toFixed(6))
}

export function clampFocusDistance(value: number, capabilities: FocusDistanceCapabilities): number | null {
  if (!capabilities.supported || capabilities.min == null || capabilities.max == null || !Number.isFinite(value)) {
    return null
  }

  return roundToStep(value, capabilities.min, capabilities.max, capabilities.step)
}

export function resolveAvailableZoomLevels(capabilities: ZoomCapabilities): number[] {
  if (!capabilities.supported || capabilities.max == null) {
    return [1]
  }

  return ZOOM_LEVELS.filter((level) => level <= capabilities.max!)
}

export function clampZoomValue(value: number, capabilities: ZoomCapabilities): number {
  if (!capabilities.supported || capabilities.min == null || capabilities.max == null) {
    return 1
  }

  return roundToStep(value, capabilities.min, capabilities.max, capabilities.step)
}

export function computeTolerance(step: number | null, minimum = 0.01): number {
  if (step == null || step <= 0) {
    return minimum
  }

  return Math.max(step * 1.5, minimum)
}

export function buildCameraConfigurationConstraints(options: {
  focusDistance: number
  zoom: number
  useAdvancedArray?: boolean
}): MediaTrackConstraints {
  const constraintSet: MediaTrackConstraintSet = {
    focusMode: 'manual',
    focusDistance: options.focusDistance,
    zoom: options.zoom,
  }

  if (options.useAdvancedArray) {
    return { advanced: [constraintSet] }
  }

  return constraintSet
}

export function assertConstraintsStructure(constraints: MediaTrackConstraints): void {
  if ('advanced' in constraints && constraints.advanced !== undefined && !Array.isArray(constraints.advanced)) {
    throw new Error('Internal DEV error: MediaTrackConstraints.advanced must be an array')
  }
}

export function evaluateMatchStatus(
  requested: number | string,
  actual: string,
  step: number | null,
  minimumTolerance = 0.01,
): ConstraintMatchStatus {
  if (actual === '—') {
    return 'UNKNOWN'
  }

  if (typeof requested === 'string') {
    return actual === requested ? 'MATCH' : 'MISMATCH'
  }

  const actualValue = Number.parseFloat(actual)

  if (!Number.isFinite(actualValue)) {
    return 'UNKNOWN'
  }

  return Math.abs(actualValue - requested) <= computeTolerance(step, minimumTolerance) ? 'MATCH' : 'MISMATCH'
}

export async function applyCameraConfiguration(
  track: MediaStreamTrack,
  options: {
    requestedFocusDistance: number
    requestedZoom: number
    focusDistanceStep: number | null
    zoomStep: number | null
  },
): Promise<AppliedConfigurationSnapshot> {
  let constraints = buildCameraConfigurationConstraints({
    focusDistance: options.requestedFocusDistance,
    zoom: options.requestedZoom,
  })

  assertConstraintsStructure(constraints)

  let constraintsJson = JSON.stringify(constraints, null, 2)
  let applyError: Error | null = null

  console.info('[DEV SIZE×ZOOM] Applying constraints', constraints)

  try {
    await track.applyConstraints(constraints)
  } catch {
    const advancedConstraints = buildCameraConfigurationConstraints({
      focusDistance: options.requestedFocusDistance,
      zoom: options.requestedZoom,
      useAdvancedArray: true,
    })

    assertConstraintsStructure(advancedConstraints)

    try {
      await track.applyConstraints(advancedConstraints)
      constraints = advancedConstraints
      constraintsJson = JSON.stringify(advancedConstraints, null, 2)
    } catch (secondError) {
      applyError = secondError instanceof Error ? secondError : new Error(String(secondError))
    }
  }

  await new Promise((resolve) => window.setTimeout(resolve, STABILIZATION_MS))

  const settings = readTrackSettingsSnapshot(track)

  if (applyError) {
    return {
      requestedFocusMode: 'manual',
      actualFocusMode: settings.focusMode,
      requestedFocusDistance: options.requestedFocusDistance,
      actualFocusDistance: settings.focusDistance,
      requestedZoom: options.requestedZoom,
      actualZoom: settings.zoom,
      focusModeStatus: 'APPLY_ERROR',
      focusDistanceStatus: 'APPLY_ERROR',
      zoomStatus: 'APPLY_ERROR',
      configurationStatus: 'APPLY_ERROR',
      applyErrorName: applyError.name,
      applyErrorMessage: applyError.message,
      constraintsJson,
    }
  }

  const focusModeStatus = evaluateMatchStatus('manual', settings.focusMode, null)
  const focusDistanceStatus = evaluateMatchStatus(
    options.requestedFocusDistance,
    settings.focusDistance,
    options.focusDistanceStep,
  )
  const zoomStatus = evaluateMatchStatus(options.requestedZoom, settings.zoom, options.zoomStep, 0.05)

  const valid =
    focusModeStatus === 'MATCH'
    && focusDistanceStatus === 'MATCH'
    && zoomStatus === 'MATCH'

  return {
    requestedFocusMode: 'manual',
    actualFocusMode: settings.focusMode,
    requestedFocusDistance: options.requestedFocusDistance,
    actualFocusDistance: settings.focusDistance,
    requestedZoom: options.requestedZoom,
    actualZoom: settings.zoom,
    focusModeStatus,
    focusDistanceStatus,
    zoomStatus,
    configurationStatus: valid ? 'VALID' : 'NOT_APPLIED',
    applyErrorName: null,
    applyErrorMessage: null,
    constraintsJson,
  }
}

export function buildComparisonConfigurations(options: {
  barcodeAExpected: string
  barcodeBExpected: string
  zoomLevels: number[]
  order: ComparisonConfiguration[]
}): ComparisonConfiguration[] {
  const base: ComparisonConfiguration[] = []

  for (const barcodeTarget of ['A', 'B'] as const) {
    for (const zoom of options.zoomLevels) {
      base.push({
        id: `${barcodeTarget}-${zoom}`,
        label: `Barcode ${barcodeTarget} × ${zoom}×`,
        barcodeTarget,
        expectedBarcode: barcodeTarget === 'A' ? options.barcodeAExpected : options.barcodeBExpected,
        requestedZoom: zoom,
        orderIndex: 0,
      })
    }
  }

  if (options.order.length === base.length) {
    return options.order.map((item, index) => ({ ...item, orderIndex: index }))
  }

  return base.map((item, index) => ({ ...item, orderIndex: index }))
}

export function randomizeConfigurationOrder(configs: ComparisonConfiguration[]): ComparisonConfiguration[] {
  const shuffled = [...configs]

  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(Math.random() * (index + 1))
    ;[shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex]!, shuffled[index]!]
  }

  return shuffled.map((item, orderIndex) => ({ ...item, orderIndex }))
}

export function classifyReadResult(rawValue: string | undefined, expectedBarcode: string): ReadResultType {
  if (!rawValue) {
    return 'NOT_FOUND'
  }

  return rawValue === expectedBarcode ? 'CORRECT' : 'INCORRECT'
}

export function computeRate(numerator: number, denominator: number): string {
  if (denominator <= 0) {
    return '—'
  }

  return `${Math.min(100, (numerator / denominator) * 100).toFixed(1)}%`
}

export function average(values: number[]): number | null {
  if (values.length === 0) {
    return null
  }

  return Math.round(values.reduce((sum, value) => sum + value, 0) / values.length)
}

export function extractBarcodeGeometry(
  barcode: DetectedBarcodeWithGeometry | null,
  videoWidth: number,
): {
  width: number | null
  height: number | null
  widthRatio: number | null
} {
  if (!barcode?.boundingBox) {
    return { width: null, height: null, widthRatio: null }
  }

  const width = Math.round(barcode.boundingBox.width)
  const height = Math.round(barcode.boundingBox.height)
  const widthRatio = videoWidth > 0 ? Number((width / videoWidth).toFixed(4)) : null

  return { width, height, widthRatio }
}

export function normalizeDetections(results: unknown[]): DetectedBarcodeWithGeometry[] {
  return results.map((item) => {
    const record = item as DetectedBarcodeWithGeometry
    const boundingBox = record.boundingBox

    return {
      rawValue: record.rawValue,
      format: record.format,
      boundingBox: boundingBox
        ? {
          x: readNumeric(boundingBox.x) ?? 0,
          y: readNumeric(boundingBox.y) ?? 0,
          width: readNumeric(boundingBox.width) ?? 0,
          height: readNumeric(boundingBox.height) ?? 0,
        }
        : undefined,
      cornerPoints: record.cornerPoints,
    }
  })
}

export function computeLaplacianVariance(imageData: ImageData): number {
  const { data, width, height } = imageData

  if (width < 3 || height < 3) {
    return 0
  }

  const gray = new Float32Array(width * height)

  for (let i = 0; i < width * height; i++) {
    const idx = i * 4
    gray[i] = 0.299 * data[idx]! + 0.587 * data[idx + 1]! + 0.114 * data[idx + 2]!
  }

  let sum = 0
  let sumSq = 0
  let count = 0

  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < width - 1; x++) {
      const center = gray[y * width + x]!
      const lap =
        -gray[(y - 1) * width + x]!
        - gray[y * width + (x - 1)]!
        + 4 * center
        - gray[y * width + (x + 1)]!
        - gray[(y + 1) * width + x]!

      sum += lap
      sumSq += lap * lap
      count += 1
    }
  }

  if (count === 0) {
    return 0
  }

  const mean = sum / count

  return Math.max(0, sumSq / count - mean * mean)
}

export function measureVideoSharpness(
  video: HTMLVideoElement,
  canvas: HTMLCanvasElement,
  maxSampleWidth = 320,
): number | null {
  if (video.videoWidth <= 0 || video.videoHeight <= 0) {
    return null
  }

  const scale = Math.min(1, maxSampleWidth / video.videoWidth)
  const width = Math.max(3, Math.round(video.videoWidth * scale))
  const height = Math.max(3, Math.round(video.videoHeight * scale))
  const context = canvas.getContext('2d', { willReadFrequently: true })

  if (!context) {
    return null
  }

  canvas.width = width
  canvas.height = height
  context.drawImage(video, 0, 0, width, height)

  return Math.round(computeLaplacianVariance(context.getImageData(0, 0, width, height)))
}

export function createEmptyConfigurationResult(config: ComparisonConfiguration, focusDistance: number): ConfigurationResult {
  return {
    configId: config.id,
    label: config.label,
    barcodeTarget: config.barcodeTarget,
    requestedZoom: config.requestedZoom,
    requestedFocusDistance: focusDistance,
    applied: null,
    framesAnalyzed: 0,
    detections: 0,
    correct: 0,
    incorrect: 0,
    notFound: 0,
    correctRate: '—',
    detectionRate: '—',
    averageDetectionLatencyMs: null,
    timeToFirstCorrectMs: null,
    averageSharpness: null,
    averageBarcodeWidth: null,
    averageBarcodeHeight: null,
    averageWidthRatio: null,
    configurationStatus: 'NOT_APPLIED',
  }
}

export function finalizeConfigurationResult(
  config: ComparisonConfiguration,
  focusDistance: number,
  applied: AppliedConfigurationSnapshot,
  stats: {
    framesAnalyzed: number
    detections: number
    correct: number
    incorrect: number
    notFound: number
    correctLatencies: number[]
    timeToFirstCorrectMs: number | null
    sharpnessValues: number[]
    barcodeWidths: number[]
    barcodeHeights: number[]
    widthRatios: number[]
  },
): ConfigurationResult {
  const configurationStatus: ConfigurationStatus =
    applied.configurationStatus !== 'VALID'
      ? applied.configurationStatus === 'APPLY_ERROR' ? 'APPLY_ERROR' : 'NO_DETECTION'
      : stats.correct === 0 && stats.detections === 0 && stats.framesAnalyzed > 0
        ? 'NO_DETECTION'
        : 'VALID'

  return {
    configId: config.id,
    label: config.label,
    barcodeTarget: config.barcodeTarget,
    requestedZoom: config.requestedZoom,
    requestedFocusDistance: focusDistance,
    applied,
    framesAnalyzed: stats.framesAnalyzed,
    detections: stats.detections,
    correct: stats.correct,
    incorrect: stats.incorrect,
    notFound: stats.notFound,
    correctRate: computeRate(stats.correct, stats.framesAnalyzed),
    detectionRate: computeRate(stats.detections, stats.framesAnalyzed),
    averageDetectionLatencyMs: average(stats.correctLatencies),
    timeToFirstCorrectMs: stats.timeToFirstCorrectMs,
    averageSharpness: average(stats.sharpnessValues),
    averageBarcodeWidth: average(stats.barcodeWidths),
    averageBarcodeHeight: average(stats.barcodeHeights),
    averageWidthRatio: stats.widthRatios.length
      ? Number((stats.widthRatios.reduce((sum, value) => sum + value, 0) / stats.widthRatios.length).toFixed(4))
      : null,
    configurationStatus,
  }
}

export function findBestObservedZoom(
  results: ConfigurationResult[],
  barcodeTarget: BarcodeTarget,
): BestZoomResult | null {
  const candidates = results.filter(
    (item) =>
      item.barcodeTarget === barcodeTarget
      && item.configurationStatus === 'VALID'
      && item.correct > 0,
  )

  if (candidates.length === 0) {
    return null
  }

  const best = [...candidates].sort((left, right) => {
    const leftCorrect = left.correct / Math.max(left.framesAnalyzed, 1)
    const rightCorrect = right.correct / Math.max(right.framesAnalyzed, 1)

    if (rightCorrect !== leftCorrect) {
      return rightCorrect - leftCorrect
    }

    const leftDetection = left.detections / Math.max(left.framesAnalyzed, 1)
    const rightDetection = right.detections / Math.max(right.framesAnalyzed, 1)

    if (rightDetection !== leftDetection) {
      return rightDetection - leftDetection
    }

    return (left.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER) - (right.timeToFirstCorrectMs ?? Number.MAX_SAFE_INTEGER)
  })[0]!

  return {
    zoom: best.requestedZoom,
    correctRate: best.correctRate,
    detectionRate: best.detectionRate,
    label: `${best.requestedZoom}×`,
  }
}

export function buildZoomEffectRows(results: ConfigurationResult[], barcodeTarget: BarcodeTarget): Array<{
  zoom: number
  correctRate: string
  detectionRate: string
  sharpness: string
}> {
  return results
    .filter((item) => item.barcodeTarget === barcodeTarget)
    .sort((left, right) => left.requestedZoom - right.requestedZoom)
    .map((item) => ({
      zoom: item.requestedZoom,
      correctRate: item.correctRate,
      detectionRate: item.detectionRate,
      sharpness: item.averageSharpness != null ? String(item.averageSharpness) : '—',
    }))
}

export function buildObservation(results: ConfigurationResult[]): string | null {
  const aHasCorrect = results.some((item) => item.barcodeTarget === 'A' && item.correct > 0)
  const bHasCorrect = results.some((item) => item.barcodeTarget === 'B' && item.correct > 0)

  if (aHasCorrect && !bHasCorrect) {
    return [
      'OBSERVATION',
      '',
      'Barcode A is detectable under the tested conditions.',
      '',
      'Barcode B was not reliably detectable.',
      '',
      'The experiment does NOT prove that zoom is the cause.',
      '',
      'Possible limiting factors include:',
      '- apparent barcode size',
      '- camera resolution',
      '- focus',
      '- lighting',
      '- barcode print quality',
      '- distance',
    ].join('\n')
  }

  if (!aHasCorrect && bHasCorrect) {
    return [
      'OBSERVATION',
      '',
      'Barcode B is detectable under the tested conditions.',
      '',
      'Barcode A was not reliably detectable.',
      '',
      'The experiment does NOT prove that zoom is the cause.',
    ].join('\n')
  }

  return null
}

export function buildComparisonConclusion(options: {
  bestZoomA: BestZoomResult | null
  bestZoomB: BestZoomResult | null
  results: ConfigurationResult[]
}): string {
  const lines = ['=== BENCHMARK CONCLUSION ===', '']

  if (options.bestZoomA) {
    lines.push(
      'BEST OBSERVED CONFIGURATION — Barcode A',
      `Zoom: ${options.bestZoomA.zoom}×`,
      `Correct rate: ${options.bestZoomA.correctRate}`,
      `Detection rate: ${options.bestZoomA.detectionRate}`,
      'Based on correct rate first, then detection rate, then time-to-first-correct.',
      '',
    )
  } else {
    lines.push(
      'NO VALID BEST CONFIGURATION — Barcode A',
      'No correct barcode detection was recorded.',
      'The experiment cannot determine an optimal zoom.',
      '',
    )
  }

  if (options.bestZoomB) {
    lines.push(
      'BEST OBSERVED CONFIGURATION — Barcode B',
      `Zoom: ${options.bestZoomB.zoom}×`,
      `Correct rate: ${options.bestZoomB.correctRate}`,
      `Detection rate: ${options.bestZoomB.detectionRate}`,
      '',
    )
  } else {
    lines.push(
      'NO VALID BEST CONFIGURATION — Barcode B',
      'No correct barcode detection was recorded.',
      'The experiment cannot determine an optimal zoom.',
      '',
    )
  }

  const observation = buildObservation(options.results)

  if (observation) {
    lines.push(observation, '')
  }

  lines.push('Experimental result only. Do not integrate automatically into the scanner.')

  return lines.join('\n')
}

export function buildComparisonDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  barcodeAExpected: string
  barcodeBExpected: string
  focusDistance: number
  appliedFocus: AppliedConfigurationSnapshot | null
  durationSeconds: number
  results: ConfigurationResult[]
  rawDetections: RawDetectionEntry[]
  bestZoomA: BestZoomResult | null
  bestZoomB: BestZoomResult | null
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE SIZE × ZOOM COMPARISON ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    '',
    'Camera:',
    `Resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    `Barcode A expected value: ${options.barcodeAExpected}`,
    `Barcode B expected value: ${options.barcodeBExpected}`,
    '',
    'Focus:',
    `Requested: ${options.focusDistance}`,
    `Actual: ${options.appliedFocus?.actualFocusDistance ?? options.trackSettings.focusDistance}`,
    '',
    'Zoom capabilities:',
    `Min: ${options.capabilities.zoom.min ?? '—'}`,
    `Max: ${options.capabilities.zoom.max ?? '—'}`,
    `Step: ${options.capabilities.zoom.step ?? '—'}`,
    '',
    `Duration: ${options.durationSeconds}s`,
    '',
    'RESULTS',
    '',
  ]

  for (const result of options.results) {
    lines.push(
      `Configuration: ${result.label}`,
      `Barcode: ${result.barcodeTarget}`,
      `Zoom requested: ${result.requestedZoom}×`,
      `Zoom actual: ${result.applied?.actualZoom ?? '—'}×`,
      `Focus requested: ${result.requestedFocusDistance}`,
      `Focus actual: ${result.applied?.actualFocusDistance ?? '—'}`,
      `Validation focus mode: ${result.applied?.focusModeStatus ?? '—'}`,
      `Validation focus distance: ${result.applied?.focusDistanceStatus ?? '—'}`,
      `Validation zoom: ${result.applied?.zoomStatus ?? '—'}`,
      `Frames: ${result.framesAnalyzed}`,
      `Detections: ${result.detections}`,
      `Correct: ${result.correct}`,
      `Incorrect: ${result.incorrect}`,
      `Not found: ${result.notFound}`,
      `Correct rate: ${result.correctRate}`,
      `Detection rate: ${result.detectionRate}`,
      `Time to first correct: ${result.timeToFirstCorrectMs ?? '—'} ms`,
      `Average sharpness: ${result.averageSharpness ?? '—'}`,
      `Average barcode width: ${result.averageBarcodeWidth ?? '—'}`,
      `Average barcode height: ${result.averageBarcodeHeight ?? '—'}`,
      '',
    )
  }

  lines.push('RAW DETECTIONS', '')

  for (const entry of options.rawDetections) {
    lines.push(
      `${entry.timestamp} — Barcode ${entry.barcodeTarget} — zoom ${entry.zoom}×`,
      `format: ${entry.format}`,
      `rawValue: ${entry.rawValue}`,
      `classification: ${entry.classification}`,
      `boundingBox width/height: ${entry.boundingBoxWidth ?? '—'} / ${entry.boundingBoxHeight ?? '—'}`,
      '',
    )
  }

  lines.push(
    'SUMMARY',
    `Barcode A best observed zoom: ${options.bestZoomA ? `${options.bestZoomA.zoom}× (${options.bestZoomA.correctRate})` : 'NO VALID BEST CONFIGURATION'}`,
    `Barcode B best observed zoom: ${options.bestZoomB ? `${options.bestZoomB.zoom}× (${options.bestZoomB.correctRate})` : 'NO VALID BEST CONFIGURATION'}`,
    '',
    'Conclusion:',
    options.conclusion,
    '',
    'SCANNER BUSINESS LOGIC MODIFIED: NO',
    'ZXING MODIFIED: NO',
    'CANVAS DECODING MODIFIED: NO',
    'LOOKUP MODIFIED: NO',
    'PANIER MODIFIED: NO',
    'STOCK MODIFIED: NO',
  )

  return lines.join('\n')
}

export async function createComparisonBarcodeDetector(): Promise<{
  detector: BarcodeDetectorLike
  formatsUsed: string[]
}> {
  return createNativeBarcodeDetector()
}

export { pickBestNativeBarcode }
