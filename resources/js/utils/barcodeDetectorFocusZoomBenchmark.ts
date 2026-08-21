import {
  createNativeBarcodeDetector,
  isNativeBarcodeDetectorAvailable,
  NATIVE_BARCODE_FORMATS,
  pickBestNativeBarcode,
  type BarcodeDetectorLike,
} from '@/utils/nativeBarcodeScannerEngine'
import { getEnvironmentDiagnostics, type EnvironmentDiagnostics } from '@/utils/nativeBarcodeDetectorLiveTest'

export const DEFAULT_EXPECTED_BARCODE = '6202312030117'
export const STABILIZATION_MS = 1000
export const DETECTION_INTERVAL_MS = 150
export const MAX_HISTORY = 200
export const ZOOM_CANDIDATES = [1, 2, 3, 4, 6, 8] as const
export const FOCUS_PERCENTAGES = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1] as const
export const FAST_FOCUS_PERCENTAGES = [0.3, 0.5, 0.7, 0.9] as const
export const DURATION_OPTIONS = [5, 10, 15, 30] as const
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

export type ReadResultType = 'CORRECT' | 'INCORRECT' | 'NOT_FOUND' | 'ERROR'
export type ConstraintMatchStatus = 'MATCH' | 'MISMATCH' | 'UNKNOWN' | 'APPLY_ERROR'
export type ConfigurationStatus = 'VALID' | 'NOT_APPLIED' | 'APPLY_ERROR' | 'VALID_BUT_NO_READ'
export type BenchmarkPreset = 'FULL' | 'FAST'
export type ConfidenceLevel = 'LOW SAMPLE' | 'PRELIMINARY' | 'GOOD SAMPLE' | 'STRONG SAMPLE'
export type DurationSeconds = (typeof DURATION_OPTIONS)[number]

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

export interface BenchmarkConfiguration {
  id: string
  label: string
  requestedFocusDistance: number
  requestedZoom: number
  enabled: boolean
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

export interface AttemptHistoryEntry {
  id: string
  timestamp: string
  focusDistance: string
  zoom: string
  sharpness: number | null
  result: ReadResultType
  decodedValue: string
  latencyMs: number
}

export interface ConfigurationResult {
  configId: string
  label: string
  requestedFocusDistance: number
  requestedZoom: number
  applied: AppliedConfigurationSnapshot | null
  attempts: number
  correct: number
  incorrect: number
  notFound: number
  errors: number
  correctRate: string
  incorrectRate: string
  notFoundRate: string
  errorRate: string
  averageSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  correctAverageSharpness: number | null
  notFoundAverageSharpness: number | null
  timeToFirstCorrectMs: number | null
  averageDetectionLatencyMs: number | null
  medianDetectionLatencyMs: number | null
  minDetectionLatencyMs: number | null
  maxDetectionLatencyMs: number | null
  attemptsPerSecond: number | null
  successfulDetectionsPerSecond: number | null
  configurationStatus: ConfigurationStatus
  confidence: ConfidenceLevel
}

export interface ZoomComparisonRow {
  zoom: number
  bestFocus: string
  correctRate: string
  incorrectRate: string
  notFoundRate: string
  latency: string
  sharpness: string
}

export interface FocusComparisonRow {
  focusDistance: string
  bestZoom: string
  correctRate: string
  incorrectRate: string
  notFoundRate: string
  latency: string
  sharpness: string
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

export function computeTolerance(step: number | null, minimum = 0.01): number {
  if (step == null || step <= 0) {
    return minimum
  }

  return Math.max(step * 1.5, minimum)
}

export function buildFocusDistanceValues(
  capabilities: FocusDistanceCapabilities,
  percentages: readonly number[] = FOCUS_PERCENTAGES,
): number[] {
  const { min, max, step } = capabilities

  if (!capabilities.supported || min == null || max == null) {
    return []
  }

  const range = max - min

  return percentages
    .map((percentage) => roundToStep(min + range * percentage, min, max, step))
    .filter((value, index, array) => array.indexOf(value) === index)
}

export function resolveZoomLevels(capabilities: ZoomCapabilities): number[] {
  if (!capabilities.supported || capabilities.max == null) {
    return [1]
  }

  return ZOOM_CANDIDATES.filter((level) => level <= capabilities.max!)
}

export function clampZoomValue(value: number, capabilities: ZoomCapabilities): number {
  if (!capabilities.supported || capabilities.min == null || capabilities.max == null) {
    return 1
  }

  return roundToStep(value, capabilities.min, capabilities.max, capabilities.step)
}

export function buildBenchmarkConfigurations(options: {
  capabilities: TrackCapabilitiesSnapshot
  preset: BenchmarkPreset
  enabledFocusValues?: number[]
  enabledZoomValues?: number[]
}): BenchmarkConfiguration[] {
  const percentages = options.preset === 'FAST' ? FAST_FOCUS_PERCENTAGES : FOCUS_PERCENTAGES
  const focusValues = options.enabledFocusValues ?? buildFocusDistanceValues(options.capabilities.focusDistance, percentages)
  const zoomValues = options.enabledZoomValues ?? resolveZoomLevels(options.capabilities.zoom)
  const configs: BenchmarkConfiguration[] = []

  for (const focusDistance of focusValues) {
    for (const zoom of zoomValues) {
      configs.push({
        id: `${focusDistance}:${zoom}`,
        label: `${focusDistance} × ${zoom}×`,
        requestedFocusDistance: focusDistance,
        requestedZoom: zoom,
        enabled: true,
      })
    }
  }

  return configs
}

export function buildCameraConfigurationConstraints(options: {
  focusDistance: number
  zoom: number
  useAdvancedArray?: boolean
}): MediaTrackConstraints {
  if (!Number.isFinite(options.focusDistance) || !Number.isFinite(options.zoom)) {
    throw new Error('Internal DEV error: focusDistance and zoom must be finite')
  }

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

  const actualValue = typeof requested === 'number' ? Number.parseFloat(actual) : actual
  const requestedValue = typeof requested === 'number' ? requested : String(requested)

  if (typeof requested === 'number' && !Number.isFinite(actualValue as number)) {
    return 'UNKNOWN'
  }

  if (typeof requested === 'string') {
    return actual === requested ? 'MATCH' : 'MISMATCH'
  }

  const tolerance = computeTolerance(step, minimumTolerance)

  return Math.abs((actualValue as number) - requested) <= tolerance ? 'MATCH' : 'MISMATCH'
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

  console.info('[DEV FOCUS×ZOOM BENCHMARK] Applying constraints', constraints)

  try {
    await track.applyConstraints(constraints)
  } catch (firstError) {
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
  const zoomStatus = evaluateMatchStatus(
    options.requestedZoom,
    settings.zoom,
    options.zoomStep,
    0.05,
  )

  const configurationValid =
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
    configurationStatus: configurationValid ? 'VALID' : 'NOT_APPLIED',
    applyErrorName: null,
    applyErrorMessage: null,
    constraintsJson,
  }
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

export function median(values: number[]): number | null {
  if (values.length === 0) {
    return null
  }

  const sorted = [...values].sort((left, right) => left - right)
  const middle = Math.floor(sorted.length / 2)

  return sorted.length % 2 === 0
    ? Math.round((sorted[middle - 1]! + sorted[middle]!) / 2)
    : Math.round(sorted[middle]!)
}

export function getConfidenceLevel(attempts: number): ConfidenceLevel {
  if (attempts < 50) {
    return 'LOW SAMPLE'
  }

  if (attempts < 100) {
    return 'PRELIMINARY'
  }

  if (attempts < 300) {
    return 'GOOD SAMPLE'
  }

  return 'STRONG SAMPLE'
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

export function createEmptyConfigurationResult(config: BenchmarkConfiguration): ConfigurationResult {
  return {
    configId: config.id,
    label: config.label,
    requestedFocusDistance: config.requestedFocusDistance,
    requestedZoom: config.requestedZoom,
    applied: null,
    attempts: 0,
    correct: 0,
    incorrect: 0,
    notFound: 0,
    errors: 0,
    correctRate: '—',
    incorrectRate: '—',
    notFoundRate: '—',
    errorRate: '—',
    averageSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    correctAverageSharpness: null,
    notFoundAverageSharpness: null,
    timeToFirstCorrectMs: null,
    averageDetectionLatencyMs: null,
    medianDetectionLatencyMs: null,
    minDetectionLatencyMs: null,
    maxDetectionLatencyMs: null,
    attemptsPerSecond: null,
    successfulDetectionsPerSecond: null,
    configurationStatus: 'NOT_APPLIED',
    confidence: 'LOW SAMPLE',
  }
}

export function finalizeConfigurationResult(
  config: BenchmarkConfiguration,
  applied: AppliedConfigurationSnapshot,
  stats: {
    attempts: number
    correct: number
    incorrect: number
    notFound: number
    errors: number
    sharpnessValues: number[]
    correctSharpnessValues: number[]
    notFoundSharpnessValues: number[]
    correctLatencies: number[]
    timeToFirstCorrectMs: number | null
    durationSeconds: number
  },
): ConfigurationResult {
  const configurationStatus: ConfigurationStatus =
    applied.configurationStatus !== 'VALID'
      ? applied.configurationStatus
      : stats.correct === 0 && stats.attempts > 0
        ? 'VALID_BUT_NO_READ'
        : 'VALID'

  return {
    configId: config.id,
    label: config.label,
    requestedFocusDistance: config.requestedFocusDistance,
    requestedZoom: config.requestedZoom,
    applied,
    attempts: stats.attempts,
    correct: stats.correct,
    incorrect: stats.incorrect,
    notFound: stats.notFound,
    errors: stats.errors,
    correctRate: computeRate(stats.correct, stats.attempts),
    incorrectRate: computeRate(stats.incorrect, stats.attempts),
    notFoundRate: computeRate(stats.notFound, stats.attempts),
    errorRate: computeRate(stats.errors, stats.attempts),
    averageSharpness: average(stats.sharpnessValues),
    minSharpness: stats.sharpnessValues.length ? Math.min(...stats.sharpnessValues) : null,
    maxSharpness: stats.sharpnessValues.length ? Math.max(...stats.sharpnessValues) : null,
    correctAverageSharpness: average(stats.correctSharpnessValues),
    notFoundAverageSharpness: average(stats.notFoundSharpnessValues),
    timeToFirstCorrectMs: stats.timeToFirstCorrectMs,
    averageDetectionLatencyMs: average(stats.correctLatencies),
    medianDetectionLatencyMs: median(stats.correctLatencies),
    minDetectionLatencyMs: stats.correctLatencies.length ? Math.min(...stats.correctLatencies) : null,
    maxDetectionLatencyMs: stats.correctLatencies.length ? Math.max(...stats.correctLatencies) : null,
    attemptsPerSecond: stats.durationSeconds > 0 ? Number((stats.attempts / stats.durationSeconds).toFixed(2)) : null,
    successfulDetectionsPerSecond: stats.durationSeconds > 0 ? Number((stats.correct / stats.durationSeconds).toFixed(2)) : null,
    configurationStatus,
    confidence: getConfidenceLevel(stats.attempts),
  }
}

export function rankConfigurationResults(results: ConfigurationResult[]): ConfigurationResult[] {
  return [...results]
    .filter((item) => item.configurationStatus === 'VALID' || item.configurationStatus === 'VALID_BUT_NO_READ')
    .sort((left, right) => {
      const leftCorrect = left.correct / Math.max(left.attempts, 1)
      const rightCorrect = right.correct / Math.max(right.attempts, 1)

      if (rightCorrect !== leftCorrect) {
        return rightCorrect - leftCorrect
      }

      const leftIncorrect = left.incorrect / Math.max(left.attempts, 1)
      const rightIncorrect = right.incorrect / Math.max(right.attempts, 1)

      if (leftIncorrect !== rightIncorrect) {
        return leftIncorrect - rightIncorrect
      }

      const leftNotFound = left.notFound / Math.max(left.attempts, 1)
      const rightNotFound = right.notFound / Math.max(right.attempts, 1)

      if (leftNotFound !== rightNotFound) {
        return leftNotFound - rightNotFound
      }

      return (left.averageDetectionLatencyMs ?? Number.MAX_SAFE_INTEGER) - (right.averageDetectionLatencyMs ?? Number.MAX_SAFE_INTEGER)
    })
}

export function buildZoomComparison(results: ConfigurationResult[]): ZoomComparisonRow[] {
  const zoomLevels = [...new Set(results.map((item) => item.requestedZoom))].sort((a, b) => a - b)

  return zoomLevels.map((zoom) => {
    const rows = results.filter((item) => item.requestedZoom === zoom && item.attempts > 0)
    const best = rankConfigurationResults(rows)[0]

    return {
      zoom,
      bestFocus: best ? String(best.requestedFocusDistance) : '—',
      correctRate: best?.correctRate ?? '—',
      incorrectRate: best?.incorrectRate ?? '—',
      notFoundRate: best?.notFoundRate ?? '—',
      latency: best?.averageDetectionLatencyMs != null ? `${best.averageDetectionLatencyMs} ms` : '—',
      sharpness: best?.averageSharpness != null ? String(best.averageSharpness) : '—',
    }
  })
}

export function buildFocusComparison(results: ConfigurationResult[]): FocusComparisonRow[] {
  const focusValues = [...new Set(results.map((item) => item.requestedFocusDistance))].sort((a, b) => a - b)

  return focusValues.map((focusDistance) => {
    const rows = results.filter((item) => item.requestedFocusDistance === focusDistance && item.attempts > 0)
    const best = rankConfigurationResults(rows)[0]

    return {
      focusDistance: String(focusDistance),
      bestZoom: best ? `${best.requestedZoom}×` : '—',
      correctRate: best?.correctRate ?? '—',
      incorrectRate: best?.incorrectRate ?? '—',
      notFoundRate: best?.notFoundRate ?? '—',
      latency: best?.averageDetectionLatencyMs != null ? `${best.averageDetectionLatencyMs} ms` : '—',
      sharpness: best?.averageSharpness != null ? String(best.averageSharpness) : '—',
    }
  })
}

export function findBestZoom(results: ConfigurationResult[]): { zoom: number; correctRate: string } | null {
  const comparison = buildZoomComparison(results).filter((row) => row.correctRate !== '—')

  if (comparison.length === 0) {
    return null
  }

  const best = [...comparison].sort((left, right) =>
    Number.parseFloat(right.correctRate) - Number.parseFloat(left.correctRate),
  )[0]!

  return { zoom: best.zoom, correctRate: best.correctRate }
}

export function findBestFocus(results: ConfigurationResult[]): { focusDistance: number; correctRate: string } | null {
  const comparison = buildFocusComparison(results).filter((row) => row.correctRate !== '—')

  if (comparison.length === 0) {
    return null
  }

  const best = [...comparison].sort((left, right) =>
    Number.parseFloat(right.correctRate) - Number.parseFloat(left.correctRate),
  )[0]!

  return { focusDistance: Number.parseFloat(best.focusDistance), correctRate: best.correctRate }
}

export function buildHeatmapCells(
  results: ConfigurationResult[],
  focusValues: number[],
  zoomValues: number[],
): Array<{ focusDistance: number; zoom: number; correctRate: string; notFoundRate: string; status: ConfigurationStatus }> {
  return focusValues.flatMap((focusDistance) =>
    zoomValues.map((zoom) => {
      const result = results.find((item) =>
        item.requestedFocusDistance === focusDistance && item.requestedZoom === zoom,
      )

      return {
        focusDistance,
        zoom,
        correctRate: result?.correctRate ?? '—',
        notFoundRate: result?.notFoundRate ?? '—',
        status: result?.configurationStatus ?? 'NOT_APPLIED',
      }
    }),
  )
}

export function buildBenchmarkConclusion(best: ConfigurationResult | null, results: ConfigurationResult[]): string {
  if (!best || best.attempts === 0) {
    return '=== BENCHMARK CONCLUSION ===\n\nNo benchmark data collected yet.\n\nExperimental result only.'
  }

  const bestZoom = findBestZoom(results)
  const lines = [
    '=== BENCHMARK CONCLUSION ===',
    '',
    'Best configuration:',
    `Focus distance: ${best.requestedFocusDistance}`,
    `Zoom: ${best.requestedZoom}×`,
    '',
    `Correct rate: ${best.correctRate}`,
    `Incorrect rate: ${best.incorrectRate}`,
    `Not found rate: ${best.notFoundRate}`,
    '',
    `Average sharpness: ${best.averageSharpness ?? '—'}`,
    `Average detection latency: ${best.averageDetectionLatencyMs ?? '—'} ms`,
    `Time to first correct: ${best.timeToFirstCorrectMs ?? '—'} ms`,
    '',
    `Sample size: ${best.attempts} attempts`,
    `Confidence: ${best.confidence}`,
    '',
    bestZoom ? `Best zoom observed: ${bestZoom.zoom}× (${bestZoom.correctRate})` : '',
    '',
    'Experimental result only.',
    'This benchmark identifies the best configuration for the current device, camera, browser and test conditions.',
    'It does not prove that the same configuration is optimal on every Android device.',
  ]

  return lines.filter((line, index, array) => !(line === '' && array[index - 1] === '')).join('\n')
}

export function buildBenchmarkDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  capabilities: TrackCapabilitiesSnapshot
  trackSettings: TrackSettingsSnapshot
  expectedBarcode: string
  durationSeconds: number
  preset: BenchmarkPreset
  totalConfigurations: number
  results: ConfigurationResult[]
  history: AttemptHistoryEntry[]
  best: ConfigurationResult | null
  bestZoom: { zoom: number; correctRate: string } | null
  bestFocus: { focusDistance: number; correctRate: string } | null
  zoomComparison: ZoomComparisonRow[]
  focusComparison: FocusComparisonRow[]
  heatmap: ReturnType<typeof buildHeatmapCells>
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE DETECTOR FOCUS × ZOOM BENCHMARK ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    '',
    'Camera:',
    `Actual resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    'Capabilities:',
    `Focus modes: ${options.capabilities.focusModes.join(', ') || '—'}`,
    `Focus distance min: ${options.capabilities.focusDistance.min ?? '—'}`,
    `Focus distance max: ${options.capabilities.focusDistance.max ?? '—'}`,
    `Focus distance step: ${options.capabilities.focusDistance.step ?? '—'}`,
    `Zoom min: ${options.capabilities.zoom.min ?? '—'}`,
    `Zoom max: ${options.capabilities.zoom.max ?? '—'}`,
    `Zoom step: ${options.capabilities.zoom.step ?? '—'}`,
    '',
    `Expected barcode: ${options.expectedBarcode}`,
    `Duration per configuration: ${options.durationSeconds}s`,
    `Preset: ${options.preset}`,
    `Total configurations: ${options.totalConfigurations}`,
    '',
    'RESULTS',
    '',
  ]

  for (const result of options.results) {
    lines.push(
      `${result.label}`,
      `Status: ${result.configurationStatus}`,
      `Requested focus/zoom: ${result.requestedFocusDistance} / ${result.requestedZoom}×`,
      `Actual focus/zoom: ${result.applied?.actualFocusDistance ?? '—'} / ${result.applied?.actualZoom ?? '—'}×`,
      `Correct: ${result.correct}`,
      `Incorrect: ${result.incorrect}`,
      `Not found: ${result.notFound}`,
      `Correct rate: ${result.correctRate}`,
      `Avg sharpness: ${result.averageSharpness ?? '—'}`,
      `First correct: ${result.timeToFirstCorrectMs ?? '—'} ms`,
      `Confidence: ${result.confidence}`,
      '',
    )
  }

  if (options.best) {
    lines.push(
      'BEST CONFIGURATION',
      `Focus distance: ${options.best.requestedFocusDistance}`,
      `Zoom: ${options.best.requestedZoom}×`,
      `Correct rate: ${options.best.correctRate}`,
      `Incorrect rate: ${options.best.incorrectRate}`,
      `Not found rate: ${options.best.notFoundRate}`,
      '',
    )
  }

  if (options.bestZoom) {
    lines.push('BEST ZOOM', `${options.bestZoom.zoom}× — ${options.bestZoom.correctRate}`, '')
  }

  if (options.bestFocus) {
    lines.push('BEST FOCUS', `${options.bestFocus.focusDistance} — ${options.bestFocus.correctRate}`, '')
  }

  lines.push('HEATMAP', '')

  for (const cell of options.heatmap) {
    lines.push(`${cell.focusDistance} × ${cell.zoom}× — correct ${cell.correctRate} — not found ${cell.notFoundRate} — ${cell.status}`)
  }

  lines.push('', 'CONCLUSION', options.conclusion, '', 'SCANNER BUSINESS LOGIC MODIFIED:', 'NO', 'ZXING MODIFIED:', 'NO', 'CANVAS DECODING MODIFIED:', 'NO', 'LOOKUP MODIFIED:', 'NO', 'PANIER MODIFIED:', 'NO', 'STOCK MODIFIED:', 'NO')

  return lines.join('\n')
}

export async function createBenchmarkBarcodeDetector(): Promise<{
  detector: BarcodeDetectorLike
  formatsUsed: string[]
}> {
  return createNativeBarcodeDetector()
}

export { pickBestNativeBarcode, NATIVE_BARCODE_FORMATS }

declare global {
  interface Window {
    BarcodeDetector?: {
      new (options?: { formats?: string[] }): BarcodeDetectorLike
      getSupportedFormats?: () => Promise<string[]>
    }
  }
}
