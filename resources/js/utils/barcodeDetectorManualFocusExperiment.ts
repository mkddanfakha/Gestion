import {
  NATIVE_BARCODE_FORMATS,
  pickBestNativeBarcode,
  type BarcodeDetectorConstructorLike,
  type BarcodeDetectorLike,
  type DetectedBarcodeLike,
} from '@/utils/nativeBarcodeScannerEngine'
import {
  computeAverageDetectionMs,
  getEnvironmentDiagnostics,
  type EnvironmentDiagnostics,
} from '@/utils/nativeBarcodeDetectorLiveTest'

export const DEFAULT_EXPECTED_BARCODE = '6202312030117'
export const DEFAULT_SUBJECT = 'Small EAN-13'
export const DETECTION_INTERVAL_MS = 150
export const CAMERA_SETTLING_MS = 1000
export const MAX_EVENT_HISTORY = 100
export const MAX_CONSTRAINT_LOG = 40
export const MIN_ATTEMPTS_PRELIMINARY = 100
export const MIN_ATTEMPTS_SUFFICIENT = 300
export const ZOOM_CANDIDATES = [1, 2, 3, 4, 6, 8] as const
export const TEST_DURATION_OPTIONS = [10, 20, 30, 60] as const
export const MANUAL_DISTANCE_PRESETS = ['min', '25', '50', '75', 'max'] as const

export const FIXED_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
    frameRate: { ideal: 30 },
  },
  audio: false,
}

export type FocusModeType = 'continuous' | 'manual'
export type ManualDistancePreset = (typeof MANUAL_DISTANCE_PRESETS)[number]
export type ReadResultType = 'CORRECT' | 'INCORRECT' | 'NOT_FOUND' | 'ERROR'
export type FocusValidationStatus = 'VALID' | 'INVALID' | 'UNKNOWN'
export type ConstraintStatus = 'MATCH' | 'MISMATCH' | 'UNKNOWN' | 'NOT_APPLIED' | 'APPLY_ERROR'
export type ConstraintApplicationStatus = 'SUCCESS' | 'ERROR'
export type DataReliabilityStatus = 'Data insufficient' | 'Preliminary' | 'Data sufficient'
export type ComparisonVerdict = 'YES' | 'NO' | 'UNCLEAR'
export type TestDurationSeconds = (typeof TEST_DURATION_OPTIONS)[number]

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

export interface TrackCapabilitiesDetails {
  focusModes: string[]
  focusDistance: FocusDistanceCapabilities
  zoom: ZoomCapabilities
}

export interface TrackSettingsDetails {
  width: number | null
  height: number | null
  frameRate: string
  facingMode: string
  deviceId: string
  zoom: string
  focusMode: string
  focusDistance: string
}

export interface AppliedConfiguration {
  focusMode: FocusModeType
  requestedFocusMode: string
  actualFocusMode: string
  requestedFocusDistance: number | null
  actualFocusDistance: string
  requestedZoom: number
  actualZoom: string
  focusValidation: FocusValidationStatus
  focusValidationMessage: string
  configurationValid: boolean
  distancePreset: ManualDistancePreset | 'custom' | null
  configurationLabel: string
  constraintApplication: ConstraintApplicationStatus
  focusModeStatus: ConstraintStatus
  focusDistanceStatus: ConstraintStatus
  zoomStatus: ConstraintStatus
  applyErrorName: string | null
  applyErrorMessage: string | null
  constraintsJson: string
}

export interface ConstraintLogEntry {
  id: string
  timestamp: string
  requestedFocusMode: string
  actualFocusMode: string
  requestedFocusDistance: string
  actualFocusDistance: string
  requestedZoom: string
  actualZoom: string
  success: boolean
  focusValidation: FocusValidationStatus
  focusModeStatus: ConstraintStatus
  focusDistanceStatus: ConstraintStatus
  zoomStatus: ConstraintStatus
  constraintsJson: string
  errorName: string
  errorMessage: string
}

export interface SessionStats {
  attempts: number
  correct: number
  incorrect: number
  notFound: number
  errors: number
  averageDetectionMs: number | null
  minDetectionMs: number | null
  maxDetectionMs: number | null
  averageSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  correctAverageSharpness: number | null
  incorrectAverageSharpness: number | null
  notFoundAverageSharpness: number | null
}

export interface ConfigurationAggregate extends SessionStats {
  configKey: string
  configurationLabel: string
  actualFocus: string
  actualDistance: string
  actualZoom: string
  focusValidation: FocusValidationStatus
  configurationValid: boolean
  dataReliability: DataReliabilityStatus
}

export interface EventHistoryEntry {
  id: string
  timestamp: string
  configuration: string
  focusMode: string
  focusDistance: string
  zoom: string
  sharpness: number | null
  resultType: ReadResultType
  rawValue: string
  expectedValue: string
  durationMs: number
}

export interface ComparisonTableRow {
  configuration: string
  actualFocus: string
  distance: string
  zoom: string
  correct: number
  incorrect: number
  notFound: number
  correctRate: string
  sharpness: string
  focusValidation: FocusValidationStatus
  dataReliability: DataReliabilityStatus
}

export interface RecommendedConfiguration {
  id: string
  label: string
  focusMode: FocusModeType
  distancePreset: ManualDistancePreset | null
  zoom: number
}

export {
  computeAverageDetectionMs,
  getEnvironmentDiagnostics,
  type EnvironmentDiagnostics,
}

export function isNativeBarcodeDetectorAvailable(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

async function resolveSupportedFormats(DetectorClass: BarcodeDetectorConstructorLike): Promise<string[]> {
  if (typeof DetectorClass.getSupportedFormats !== 'function') {
    return [...NATIVE_BARCODE_FORMATS]
  }

  try {
    const supported = await DetectorClass.getSupportedFormats()
    const filtered = NATIVE_BARCODE_FORMATS.filter((format) => supported.includes(format))

    return filtered.length > 0 ? filtered : [...NATIVE_BARCODE_FORMATS]
  } catch {
    return [...NATIVE_BARCODE_FORMATS]
  }
}

export async function createExperimentBarcodeDetector(): Promise<{
  detector: BarcodeDetectorLike
  formatsUsed: string[]
}> {
  if (!isNativeBarcodeDetectorAvailable()) {
    throw new Error('BarcodeDetector indisponible.')
  }

  const DetectorClass = window.BarcodeDetector as BarcodeDetectorConstructorLike
  const formats = await resolveSupportedFormats(DetectorClass)

  try {
    return { detector: new DetectorClass({ formats }), formatsUsed: formats }
  } catch {
    return { detector: new DetectorClass(), formatsUsed: formats }
  }
}

function readNumeric(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function readTrackCapabilitiesDetails(track: MediaStreamTrack | null): TrackCapabilitiesDetails {
  const capabilities = track?.getCapabilities?.() as Record<string, unknown> | undefined

  if (!capabilities) {
    return {
      focusModes: [],
      focusDistance: { supported: false, min: null, max: null, step: null },
      zoom: { supported: false, min: null, max: null, step: null },
    }
  }

  const focusModes = Array.isArray(capabilities.focusMode)
    ? capabilities.focusMode.map(String)
    : capabilities.focusMode != null ? [String(capabilities.focusMode)] : []

  const focusDistanceRaw = capabilities.focusDistance
  const focusDistanceSupported = focusDistanceRaw != null && typeof focusDistanceRaw === 'object'
  const focusDistanceRecord = focusDistanceSupported
    ? focusDistanceRaw as { min?: number; max?: number; step?: number }
    : null

  const zoomRaw = capabilities.zoom
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

export function readTrackSettingsDetails(track: MediaStreamTrack | null): TrackSettingsDetails {
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
      deviceId: '—',
      zoom: '—',
      focusMode: '—',
      focusDistance: '—',
    }
  }

  return {
    width: settings.width ?? null,
    height: settings.height ?? null,
    frameRate: settings.frameRate != null ? String(settings.frameRate) : '—',
    facingMode: settings.facingMode ? String(settings.facingMode) : '—',
    deviceId: settings.deviceId ? `${settings.deviceId.slice(0, 8)}…` : '—',
    zoom: settings.zoom != null ? String(settings.zoom) : '—',
    focusMode: settings.focusMode != null ? String(settings.focusMode) : '—',
    focusDistance: settings.focusDistance != null ? String(settings.focusDistance) : '—',
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

export function computeDistanceForPreset(
  capabilities: TrackCapabilitiesDetails,
  preset: ManualDistancePreset,
): number | null {
  const { min, max, step } = capabilities.focusDistance

  if (!capabilities.focusDistance.supported || min == null || max == null) {
    return null
  }

  const percentMap: Record<ManualDistancePreset, number> = {
    min: 0,
    '25': 0.25,
    '50': 0.5,
    '75': 0.75,
    max: 1,
  }

  return roundToStep(min + (max - min) * percentMap[preset], min, max, step)
}

export function resolveZoomLevels(capabilities: TrackCapabilitiesDetails): number[] {
  if (!capabilities.zoom.supported || capabilities.zoom.max == null) {
    return [1]
  }

  return ZOOM_CANDIDATES.filter((level) => level <= capabilities.zoom.max!)
}

export function clampZoomValue(value: number, capabilities: TrackCapabilitiesDetails): number {
  if (!capabilities.zoom.supported || capabilities.zoom.min == null || capabilities.zoom.max == null) {
    return 1
  }

  return roundToStep(value, capabilities.zoom.min, capabilities.zoom.max, capabilities.zoom.step)
}

export function buildCameraConstraints(options: {
  focusMode?: FocusModeType
  focusDistance?: number | null
  zoom?: number | null
  capabilities: TrackCapabilitiesDetails
  useAdvancedArray?: boolean
}): MediaTrackConstraints {
  const constraintSet: MediaTrackConstraintSet = {}

  if (options.focusMode) {
    constraintSet.focusMode = options.focusMode
  }

  if (
    options.focusMode === 'manual'
    && options.focusDistance != null
    && Number.isFinite(options.focusDistance)
    && options.capabilities.focusDistance.supported
  ) {
    constraintSet.focusDistance = options.focusDistance
  }

  if (
    options.capabilities.zoom.supported
    && options.zoom != null
    && Number.isFinite(options.zoom)
  ) {
    constraintSet.zoom = options.zoom
  }

  if (Object.keys(constraintSet).length === 0) {
    return {}
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

export function evaluateNumericConstraintStatus(
  requested: number,
  actual: string,
  step: number | null,
  minimumTolerance = 0.01,
): ConstraintStatus {
  if (actual === '—') {
    return 'UNKNOWN'
  }

  const actualValue = Number.parseFloat(actual)

  if (!Number.isFinite(actualValue)) {
    return 'UNKNOWN'
  }

  const tolerance = step != null && step > 0
    ? Math.max(step * 1.5, minimumTolerance)
    : minimumTolerance

  return Math.abs(actualValue - requested) <= tolerance ? 'MATCH' : 'MISMATCH'
}

export function evaluateAppliedConfiguration(options: {
  focusMode: FocusModeType
  requestedFocusMode: string
  requestedFocusDistance: number | null
  requestedZoom: number
  zoomRequested: boolean
  actualFocusMode: string
  actualFocusDistance: string
  actualZoom: string
  focusDistanceStep: number | null
  zoomStep: number | null
  applyError: Error | null
}): {
  constraintApplication: ConstraintApplicationStatus
  focusModeStatus: ConstraintStatus
  focusDistanceStatus: ConstraintStatus
  zoomStatus: ConstraintStatus
  focusValidation: FocusValidationStatus
  configurationValid: boolean
  message: string
} {
  if (options.applyError) {
    return {
      constraintApplication: 'ERROR',
      focusModeStatus: 'APPLY_ERROR',
      focusDistanceStatus: 'NOT_APPLIED',
      zoomStatus: options.zoomRequested ? 'NOT_APPLIED' : 'NOT_APPLIED',
      focusValidation: 'INVALID',
      configurationValid: false,
      message: [
        'Constraint application: ERROR',
        '',
        `applyConstraints FAILED`,
        `Name: ${options.applyError.name}`,
        `Message: ${options.applyError.message}`,
      ].join('\n'),
    }
  }

  const focusModeStatus: ConstraintStatus = options.actualFocusMode === '—'
    ? 'UNKNOWN'
    : options.actualFocusMode === options.requestedFocusMode
      ? 'MATCH'
      : 'MISMATCH'

  let focusDistanceStatus: ConstraintStatus = 'NOT_APPLIED'

  if (options.focusMode === 'manual' && options.requestedFocusDistance != null) {
    focusDistanceStatus = evaluateNumericConstraintStatus(
      options.requestedFocusDistance,
      options.actualFocusDistance,
      options.focusDistanceStep,
    )
  }

  let zoomStatus: ConstraintStatus = 'NOT_APPLIED'

  if (options.zoomRequested) {
    zoomStatus = evaluateNumericConstraintStatus(
      options.requestedZoom,
      options.actualZoom,
      options.zoomStep,
      0.05,
    )
  }

  const configurationValid =
    focusModeStatus === 'MATCH'
    && (focusDistanceStatus === 'MATCH' || focusDistanceStatus === 'NOT_APPLIED')
    && (zoomStatus === 'MATCH' || zoomStatus === 'NOT_APPLIED')

  const lines = [
    'Constraint application: SUCCESS',
    '',
    'REQUESTED',
    `Focus mode: ${options.requestedFocusMode}`,
    `Focus distance: ${options.requestedFocusDistance ?? '—'}`,
    `Zoom: ${options.requestedZoom}`,
    '',
    'ACTUAL',
    `Focus mode: ${options.actualFocusMode}`,
    `Focus distance: ${options.actualFocusDistance}`,
    `Zoom: ${options.actualZoom}`,
    '',
    'VALIDATION',
    `Focus mode: ${focusModeStatus}`,
    `Focus distance: ${focusDistanceStatus}`,
    `Zoom: ${zoomStatus}`,
    '',
    `Experiment: ${configurationValid ? 'VALID' : 'INVALID'}`,
  ]

  if (focusModeStatus === 'MISMATCH') {
    lines.push('', 'The device/browser did not apply the requested focus mode.')
  }

  if (focusDistanceStatus === 'MISMATCH') {
    lines.push('', 'WARNING — requested focus distance differs from actual focus distance (DEVICE-ADJUSTED).')
  }

  if (zoomStatus === 'MISMATCH') {
    lines.push('', 'WARNING — requested zoom differs from actual zoom (DEVICE-ADJUSTED).')
  }

  const focusValidation: FocusValidationStatus = configurationValid
    ? 'VALID'
    : focusModeStatus === 'APPLY_ERROR'
      ? 'INVALID'
      : focusModeStatus === 'MISMATCH' || focusDistanceStatus === 'MISMATCH' || zoomStatus === 'MISMATCH'
        ? 'INVALID'
        : 'UNKNOWN'

  return {
    constraintApplication: 'SUCCESS',
    focusModeStatus,
    focusDistanceStatus,
    zoomStatus,
    focusValidation,
    configurationValid,
    message: lines.join('\n'),
  }
}

export function buildConfigurationLabel(
  focusMode: FocusModeType,
  distancePreset: ManualDistancePreset | 'custom' | null,
  requestedDistance: number | null,
  zoom: number,
): string {
  if (focusMode === 'continuous') {
    return `CONTINUOUS + ${zoom}×`
  }

  if (distancePreset && distancePreset !== 'custom') {
    const presetLabel = distancePreset === 'min'
      ? 'MIN'
      : distancePreset === 'max'
        ? 'MAX'
        : `${distancePreset}%`

    return `MANUAL ${presetLabel} + ${zoom}×`
  }

  return `MANUAL ${requestedDistance ?? '—'} + ${zoom}×`
}

export function buildConfigKey(
  focusMode: FocusModeType,
  requestedDistance: number | null,
  zoom: number,
): string {
  return `${focusMode}:${requestedDistance ?? 'none'}:${zoom}`
}

export function computeFocusTolerance(step: number | null): number {
  if (step == null || step <= 0) {
    return 0.01
  }

  return Math.max(step * 1.5, 0.01)
}

export function validateAppliedZoom(options: {
  requestedZoom: number
  actualZoom: string
  step: number | null
}): boolean {
  if (options.actualZoom === '—') {
    return false
  }

  const actual = Number.parseFloat(options.actualZoom)

  if (!Number.isFinite(actual)) {
    return false
  }

  const tolerance = options.step != null && options.step > 0
    ? Math.max(options.step * 1.5, 0.05)
    : 0.05

  return Math.abs(actual - options.requestedZoom) <= tolerance
}

export function validateAppliedFocus(options: {
  focusMode: FocusModeType
  requestedFocusMode: string
  actualFocusMode: string
  requestedFocusDistance: number | null
  actualFocusDistance: string
  step: number | null
}): { status: FocusValidationStatus; message: string; configurationValid: boolean } {
  const { focusMode, requestedFocusMode, actualFocusMode, requestedFocusDistance, actualFocusDistance, step } = options

  if (focusMode === 'continuous') {
    const valid = actualFocusMode === 'continuous'

    return {
      status: valid ? 'VALID' : 'INVALID',
      message: valid
        ? 'Focus validation: VALID — continuous applied'
        : `Focus validation: INVALID — requested continuous, actual ${actualFocusMode}`,
      configurationValid: valid,
    }
  }

  if (actualFocusMode !== 'manual') {
    return {
      status: 'INVALID',
      message: `Focus validation: INVALID — requested manual, actual ${actualFocusMode}`,
      configurationValid: false,
    }
  }

  if (requestedFocusDistance == null || actualFocusDistance === '—') {
    return { status: 'UNKNOWN', message: 'Focus validation: UNKNOWN — focusDistance unavailable', configurationValid: false }
  }

  const actualDistance = Number.parseFloat(actualFocusDistance)

  if (!Number.isFinite(actualDistance)) {
    return { status: 'INVALID', message: 'Focus validation: INVALID — actual focusDistance unreadable', configurationValid: false }
  }

  const tolerance = computeFocusTolerance(step)
  const delta = Math.abs(actualDistance - requestedFocusDistance)

  if (delta <= tolerance) {
    return {
      status: 'VALID',
      message: `Focus validation: VALID — manual ${requestedFocusDistance} ≈ actual ${actualFocusDistance}`,
      configurationValid: true,
    }
  }

  return {
    status: 'INVALID',
    message: `WARNING — requested focus distance ${requestedFocusDistance} differs from actual ${actualFocusDistance} (INVALID / DEVICE-ADJUSTED)`,
    configurationValid: false,
  }
}

export function createEmptySessionStats(): SessionStats {
  return {
    attempts: 0,
    correct: 0,
    incorrect: 0,
    notFound: 0,
    errors: 0,
    averageDetectionMs: null,
    minDetectionMs: null,
    maxDetectionMs: null,
    averageSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    correctAverageSharpness: null,
    incorrectAverageSharpness: null,
    notFoundAverageSharpness: null,
  }
}

export function getDataReliabilityStatus(attempts: number): DataReliabilityStatus {
  if (attempts < MIN_ATTEMPTS_PRELIMINARY) {
    return 'Data insufficient'
  }

  if (attempts < MIN_ATTEMPTS_SUFFICIENT) {
    return 'Preliminary'
  }

  return 'Data sufficient'
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

export function formatDurationMs(value: number | null): string {
  return value == null ? '—' : `${Math.round(value)} ms`
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

export async function applyCameraConfiguration(
  track: MediaStreamTrack,
  options: {
    focusMode: FocusModeType
    requestedFocusDistance: number | null
    requestedZoom: number
    capabilities: TrackCapabilitiesDetails
  },
): Promise<{ applied: AppliedConfiguration; log: ConstraintLogEntry }> {
  const timestamp = new Date().toLocaleTimeString('fr-FR')
  const clampedZoom = clampZoomValue(options.requestedZoom, options.capabilities)
  const requestedFocusDistance = options.focusMode === 'manual' ? options.requestedFocusDistance : null
  const zoomRequested = options.capabilities.zoom.supported && Number.isFinite(clampedZoom)

  const constraints = buildCameraConstraints({
    focusMode: options.focusMode,
    focusDistance: requestedFocusDistance,
    zoom: zoomRequested ? clampedZoom : null,
    capabilities: options.capabilities,
  })

  assertConstraintsStructure(constraints)

  let constraintsJson = JSON.stringify(constraints, null, 2)
  console.info('[DEV CAMERA] Applying constraints', constraints)

  let applyError: Error | null = null

  try {
    await track.applyConstraints(constraints)
  } catch (firstError) {
    const advancedConstraints = buildCameraConstraints({
      focusMode: options.focusMode,
      focusDistance: requestedFocusDistance,
      zoom: zoomRequested ? clampedZoom : null,
      capabilities: options.capabilities,
      useAdvancedArray: true,
    })

    assertConstraintsStructure(advancedConstraints)

    try {
      console.info('[DEV CAMERA] Retrying with advanced array', advancedConstraints)
      await track.applyConstraints(advancedConstraints)
      constraintsJson = JSON.stringify(advancedConstraints, null, 2)
    } catch (secondError) {
      applyError = secondError instanceof Error ? secondError : new Error(String(secondError))
      console.error('[DEV CAMERA] applyConstraints FAILED', {
        name: applyError.name,
        message: applyError.message,
        firstError: firstError instanceof Error ? firstError.message : String(firstError),
        constraints: advancedConstraints,
        settings: track.getSettings?.(),
      })
    }
  }

  const settings = readTrackSettingsDetails(track)
  const evaluation = evaluateAppliedConfiguration({
    focusMode: options.focusMode,
    requestedFocusMode: options.focusMode,
    requestedFocusDistance,
    requestedZoom: clampedZoom,
    zoomRequested,
    actualFocusMode: settings.focusMode,
    actualFocusDistance: settings.focusDistance,
    actualZoom: settings.zoom,
    focusDistanceStep: options.capabilities.focusDistance.step,
    zoomStep: options.capabilities.zoom.step,
    applyError,
  })

  const errorMessage = applyError
    ? [
      evaluation.message,
      '',
      'Constraints:',
      constraintsJson,
      '',
      'Current settings:',
      JSON.stringify(settings, null, 2),
    ].join('\n')
    : evaluation.message

  const applied: AppliedConfiguration = {
    focusMode: options.focusMode,
    requestedFocusMode: options.focusMode,
    actualFocusMode: settings.focusMode,
    requestedFocusDistance,
    actualFocusDistance: settings.focusDistance,
    requestedZoom: clampedZoom,
    actualZoom: settings.zoom,
    focusValidation: evaluation.focusValidation,
    focusValidationMessage: errorMessage,
    configurationValid: evaluation.configurationValid,
    distancePreset: null,
    configurationLabel: buildConfigurationLabel(
      options.focusMode,
      null,
      requestedFocusDistance,
      clampedZoom,
    ),
    constraintApplication: evaluation.constraintApplication,
    focusModeStatus: evaluation.focusModeStatus,
    focusDistanceStatus: evaluation.focusDistanceStatus,
    zoomStatus: evaluation.zoomStatus,
    applyErrorName: applyError?.name ?? null,
    applyErrorMessage: applyError?.message ?? null,
    constraintsJson,
  }

  return {
    applied,
    log: {
      id: `${Date.now()}-apply${applyError ? '-error' : ''}`,
      timestamp,
      requestedFocusMode: options.focusMode,
      actualFocusMode: settings.focusMode,
      requestedFocusDistance: requestedFocusDistance != null ? String(requestedFocusDistance) : '—',
      actualFocusDistance: settings.focusDistance,
      requestedZoom: String(clampedZoom),
      actualZoom: settings.zoom,
      success: applyError == null,
      focusValidation: evaluation.focusValidation,
      focusModeStatus: evaluation.focusModeStatus,
      focusDistanceStatus: evaluation.focusDistanceStatus,
      zoomStatus: evaluation.zoomStatus,
      constraintsJson,
      errorName: applyError?.name ?? '—',
      errorMessage: applyError?.message ?? evaluation.message,
    },
  }
}

export async function applyExperimentConfiguration(
  track: MediaStreamTrack,
  options: {
    focusMode: FocusModeType
    requestedFocusDistance: number | null
    requestedZoom: number
    capabilities: TrackCapabilitiesDetails
  },
): Promise<{ applied: AppliedConfiguration; log: ConstraintLogEntry }> {
  return applyCameraConfiguration(track, options)
}

export function buildRecommendedConfigurations(capabilities: TrackCapabilitiesDetails): RecommendedConfiguration[] {
  const configs: RecommendedConfiguration[] = []
  const zoomLevels = [1, 4, 8].filter((zoom) => resolveZoomLevels(capabilities).includes(zoom))
  const manualPresets: ManualDistancePreset[] = ['min', '25', '50', '75', 'max']

  if (capabilities.focusModes.includes('continuous')) {
    for (const zoom of zoomLevels) {
      configs.push({
        id: `continuous-${zoom}`,
        label: `CONTINUOUS + ${zoom}×`,
        focusMode: 'continuous',
        distancePreset: null,
        zoom,
      })
    }
  }

  if (capabilities.focusModes.includes('manual') && capabilities.focusDistance.supported) {
    for (const preset of manualPresets) {
      for (const zoom of zoomLevels) {
        configs.push({
          id: `manual-${preset}-${zoom}`,
          label: `MANUAL ${preset === 'min' ? 'MIN' : preset === 'max' ? 'MAX' : `${preset}%`} + ${zoom}×`,
          focusMode: 'manual',
          distancePreset: preset,
          zoom,
        })
      }
    }
  }

  return configs
}

export function buildInitialAggregates(capabilities: TrackCapabilitiesDetails): ConfigurationAggregate[] {
  const rows: ConfigurationAggregate[] = []
  const zoomLevels = resolveZoomLevels(capabilities)

  if (capabilities.focusModes.includes('continuous')) {
    for (const zoom of zoomLevels) {
      const label = buildConfigurationLabel('continuous', null, null, zoom)
      rows.push({
        ...createEmptySessionStats(),
        configKey: buildConfigKey('continuous', null, zoom),
        configurationLabel: label,
        actualFocus: '—',
        actualDistance: '—',
        actualZoom: '—',
        focusValidation: 'UNKNOWN',
        configurationValid: false,
        dataReliability: 'Data insufficient',
      })
    }
  }

  if (capabilities.focusModes.includes('manual') && capabilities.focusDistance.supported) {
    for (const preset of MANUAL_DISTANCE_PRESETS) {
      const distance = computeDistanceForPreset(capabilities, preset)

      for (const zoom of zoomLevels) {
        const label = buildConfigurationLabel('manual', preset, distance, zoom)
        rows.push({
          ...createEmptySessionStats(),
          configKey: buildConfigKey('manual', distance, zoom),
          configurationLabel: label,
          actualFocus: '—',
          actualDistance: '—',
          actualZoom: '—',
          focusValidation: 'UNKNOWN',
          configurationValid: false,
          dataReliability: 'Data insufficient',
        })
      }
    }
  }

  return rows
}

export function buildComparisonTableRows(aggregates: ConfigurationAggregate[]): ComparisonTableRow[] {
  return aggregates.map((aggregate) => ({
    configuration: aggregate.configurationLabel,
    actualFocus: aggregate.actualFocus,
    distance: aggregate.actualDistance,
    zoom: aggregate.actualZoom,
    correct: aggregate.correct,
    incorrect: aggregate.incorrect,
    notFound: aggregate.notFound,
    correctRate: computeRate(aggregate.correct, aggregate.attempts),
    sharpness: aggregate.averageSharpness != null ? String(Math.round(aggregate.averageSharpness)) : '—',
    focusValidation: aggregate.focusValidation,
    dataReliability: aggregate.dataReliability,
  }))
}

export function findBestConfiguration(aggregates: ConfigurationAggregate[]): {
  label: string
  reason: string
} {
  const candidates = aggregates.filter(
    (item) => item.configurationValid && item.attempts >= MIN_ATTEMPTS_PRELIMINARY,
  )

  if (candidates.length === 0) {
    return { label: '—', reason: 'INSUFFICIENT DATA — pas assez de tentatives valides.' }
  }

  const best = [...candidates].sort((left, right) => {
    const leftCorrectRate = left.correct / Math.max(left.attempts, 1)
    const rightCorrectRate = right.correct / Math.max(right.attempts, 1)

    if (rightCorrectRate !== leftCorrectRate) {
      return rightCorrectRate - leftCorrectRate
    }

    const leftIncorrectRate = left.incorrect / Math.max(left.attempts, 1)
    const rightIncorrectRate = right.incorrect / Math.max(right.attempts, 1)

    if (leftIncorrectRate !== rightIncorrectRate) {
      return leftIncorrectRate - rightIncorrectRate
    }

    return (right.averageSharpness ?? 0) - (left.averageSharpness ?? 0)
  })[0]!

  return {
    label: best.configurationLabel,
    reason: `Correct ${computeRate(best.correct, best.attempts)}, incorrect ${computeRate(best.incorrect, best.attempts)}, not found ${computeRate(best.notFound, best.attempts)}, sharpness ${best.averageSharpness ?? '—'}`,
  }
}

function compareCorrectRates(rows: ConfigurationAggregate[]): ComparisonVerdict {
  if (rows.length < 2 || rows.every((row) => row.attempts < MIN_ATTEMPTS_PRELIMINARY)) {
    return 'UNCLEAR'
  }

  const rates = rows.map((row) => row.correct / Math.max(row.attempts, 1))
  const max = Math.max(...rates)
  const min = Math.min(...rates)

  if (max - min < 0.1) {
    return 'NO'
  }

  if (max - min >= 0.2) {
    return 'YES'
  }

  return 'UNCLEAR'
}

export function analyzeZoomEffect(
  aggregates: ConfigurationAggregate[],
  focusMode: FocusModeType,
  requestedDistance: number | null,
): ComparisonVerdict {
  const rows = aggregates.filter((row) => {
    const keyParts = row.configKey.split(':')

    return keyParts[0] === focusMode && (requestedDistance == null ? keyParts[1] === 'none' : keyParts[1] === String(requestedDistance))
  })

  return compareCorrectRates(rows)
}

export function analyzeManualFocusEffect(
  aggregates: ConfigurationAggregate[],
  zoom: number,
): ComparisonVerdict {
  const manualRows = aggregates.filter((row) => row.configKey.startsWith(`manual:`) && row.configKey.endsWith(`:${zoom}`))
  const continuousRows = aggregates.filter((row) => row.configKey.startsWith(`continuous:none:${zoom}`))

  return compareCorrectRates([...continuousRows, ...manualRows])
}

export function buildExperimentConclusion(options: {
  stats: SessionStats
  bestConfiguration: { label: string; reason: string }
  zoomEffect: ComparisonVerdict
  manualFocusEffect: ComparisonVerdict
  appliedControls: AppliedConfiguration | null
}): string {
  const lines: string[] = []

  if (options.stats.attempts < MIN_ATTEMPTS_PRELIMINARY) {
    return 'INSUFFICIENT DATA\n\nPas assez de tentatives pour comparer correctement les configurations.'
  }

  if (options.appliedControls?.constraintApplication === 'ERROR') {
    return [
      'APPLY ERROR',
      '',
      options.appliedControls.applyErrorMessage ?? 'applyConstraints() a échoué.',
      '',
      'La configuration n\'a pas pu être appliquée — résultats non exploitables.',
    ].join('\n')
  }

  if (options.appliedControls && !options.appliedControls.configurationValid) {
    lines.push('NON TESTABLE', '', options.appliedControls.focusValidationMessage, '')
  }

  const correctRate = options.stats.correct / Math.max(options.stats.attempts, 1)

  if (correctRate >= 0.5 && options.stats.incorrect === 0) {
    lines.push(
      'STRONG INDICATION',
      '',
      `${options.bestConfiguration.label} produit un taux de lectures correctes nettement supérieur aux configurations comparables.`,
      options.bestConfiguration.reason,
      '',
      `Does zoom improve detection? ${options.zoomEffect}`,
      `Does manual focus improve detection? ${options.manualFocusEffect}`,
      '',
      'Résultat expérimental uniquement.',
    )
  } else if (correctRate >= 0.15) {
    lines.push('NO CLEAR ADVANTAGE', '', 'Les configurations testées ne montrent pas d\'amélioration suffisamment stable.', options.bestConfiguration.reason)
  } else {
    lines.push('NO CLEAR ADVANTAGE', '', 'Aucun gain clair observé dans cette session.', options.bestConfiguration.reason)
  }

  return lines.join('\n')
}

export function buildExperimentDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  supportedFormats: string[]
  trackSettings: TrackSettingsDetails
  capabilities: TrackCapabilitiesDetails
  appliedControls: AppliedConfiguration | null
  expectedBarcode: string
  subject: string
  stats: SessionStats
  aggregates: ConfigurationAggregate[]
  constraintLog: ConstraintLogEntry[]
  history: EventHistoryEntry[]
  bestConfiguration: { label: string; reason: string }
  zoomEffect: ComparisonVerdict
  manualFocusEffect: ComparisonVerdict
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE DETECTOR MANUAL FOCUS EXPERIMENT ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    '',
    'BarcodeDetector:',
    `Available: ${options.environment.barcodeDetectorAvailable ? 'YES' : 'NO'}`,
    `Formats: ${options.supportedFormats.join(', ') || 'default'}`,
    '',
    'CAMERA',
    `Requested resolution: 1280×720 @ 30fps`,
    `Actual resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    'CAPABILITIES',
    `Focus modes: ${options.capabilities.focusModes.join(', ') || 'NON DISPONIBLE'}`,
    `Focus distance min/max/step: ${options.capabilities.focusDistance.min ?? '—'} / ${options.capabilities.focusDistance.max ?? '—'} / ${options.capabilities.focusDistance.step ?? '—'}`,
    `Zoom min/max/step: ${options.capabilities.zoom.min ?? '—'} / ${options.capabilities.zoom.max ?? '—'} / ${options.capabilities.zoom.step ?? '—'}`,
    '',
    'CURRENT SETTINGS',
    `Focus mode: ${options.trackSettings.focusMode}`,
    `Focus distance: ${options.trackSettings.focusDistance}`,
    `Zoom: ${options.trackSettings.zoom}`,
    '',
    'TEST SUBJECT',
    options.subject,
    '',
    'EXPECTED BARCODE',
    options.expectedBarcode,
    '',
    'RESULTS',
    `Attempts: ${options.stats.attempts}`,
    `Correct: ${options.stats.correct}`,
    `Incorrect: ${options.stats.incorrect}`,
    `Not found: ${options.stats.notFound}`,
    `Errors: ${options.stats.errors}`,
    `Correct rate: ${computeRate(options.stats.correct, options.stats.attempts)}`,
    `Incorrect rate: ${computeRate(options.stats.incorrect, options.stats.attempts)}`,
    `Not found rate: ${computeRate(options.stats.notFound, options.stats.attempts)}`,
    `Correct avg sharpness: ${options.stats.correctAverageSharpness ?? '—'}`,
    `Incorrect avg sharpness: ${options.stats.incorrectAverageSharpness ?? '—'}`,
    `Not found avg sharpness: ${options.stats.notFoundAverageSharpness ?? '—'}`,
    '',
    'CONFIGURATION COMPARISON',
    '',
  ]

  for (const row of buildComparisonTableRows(options.aggregates)) {
    lines.push(
      row.configuration,
      `actual focus: ${row.actualFocus}`,
      `distance: ${row.distance}`,
      `zoom: ${row.zoom}`,
      `correct: ${row.correct}`,
      `incorrect: ${row.incorrect}`,
      `not found: ${row.notFound}`,
      `correct %: ${row.correctRate}`,
      `sharpness: ${row.sharpness}`,
      `focus validation: ${row.focusValidation}`,
      `data: ${row.dataReliability}`,
      '',
    )
  }

  lines.push(
    'FOCUS DISTANCE COMPARISON',
    `Does manual focus improve detection? ${options.manualFocusEffect}`,
    '',
    'ZOOM COMPARISON',
    `Does zoom improve detection? ${options.zoomEffect}`,
    '',
  )

  if (options.constraintLog.length > 0) {
    lines.push('CONSTRAINT LOG', '')

    for (const entry of options.constraintLog) {
      lines.push(
        `${entry.timestamp} — ${entry.success ? 'SUCCESS' : 'CONSTRAINT ERROR'}`,
        `requestedFocusMode: ${entry.requestedFocusMode}`,
        `actualFocusMode: ${entry.actualFocusMode}`,
        `requestedFocusDistance: ${entry.requestedFocusDistance}`,
        `actualFocusDistance: ${entry.actualFocusDistance}`,
        `requestedZoom: ${entry.requestedZoom}`,
        `actualZoom: ${entry.actualZoom}`,
        `focusModeStatus: ${entry.focusModeStatus}`,
        `focusDistanceStatus: ${entry.focusDistanceStatus}`,
        `zoomStatus: ${entry.zoomStatus}`,
        `constraints: ${entry.constraintsJson}`,
        entry.success ? '' : `error: ${entry.errorName} — ${entry.errorMessage}`,
        '',
      )
    }
  }

  if (options.history.length > 0) {
    lines.push('SUCCESS HISTORY', '')

    for (const entry of options.history.slice(0, MAX_EVENT_HISTORY)) {
      lines.push(
        `${entry.timestamp} — ${entry.configuration} — ${entry.focusMode} — focusDistance: ${entry.focusDistance} — zoom: ${entry.zoom} — sharpness: ${entry.sharpness ?? '—'} — ${entry.resultType} — ${entry.rawValue || '—'} — ${entry.durationMs} ms`,
      )
    }

    lines.push('')
  }

  lines.push(
    'BEST CONFIGURATION',
    options.bestConfiguration.label,
    options.bestConfiguration.reason,
    '',
    'CONCLUSION',
    options.conclusion,
  )

  return lines.join('\n')
}

export { pickBestNativeBarcode }

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}
