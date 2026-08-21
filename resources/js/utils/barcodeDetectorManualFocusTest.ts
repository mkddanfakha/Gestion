import {
  formatNativeBarcodeFormat,
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

export const STANDARD_EAN_VALUE = '6202312030117'
export const DEFAULT_SMALL_EAN_VALUE = '6043000070493'
export const DETECTION_INTERVAL_MS = 150
export const CAMERA_SETTLING_MS = 1000
export const MAX_EVENT_HISTORY = 50
export const MAX_CONSTRAINT_LOG = 30
export const MIN_ATTEMPTS_FOR_BEST = 50
export const ZOOM_CANDIDATES = [1, 2, 3, 4, 6, 8] as const
export const TEST_DURATION_OPTIONS = [20, 30, 60] as const

export const FIXED_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
  },
  audio: false,
}

export const REQUESTED_WIDTH = 1280
export const REQUESTED_HEIGHT = 720

export type FocusProfileId =
  | 'default'
  | 'continuous'
  | 'manual-min'
  | 'manual-25'
  | 'manual-50'
  | 'manual-75'
  | 'manual-100'
  | 'manual-max'
  | 'manual-mid'

export type ReadResultType = 'CORRECT' | 'INCORRECT' | 'NOT_FOUND' | 'ERROR'
export type FocusApplicationStatus = 'APPLIED' | 'NOT APPLIED' | 'UNKNOWN'
export type TestDurationSeconds = (typeof TEST_DURATION_OPTIONS)[number]

export interface FocusProfileDefinition {
  id: FocusProfileId
  label: string
  requestedFocusLabel: string
  constraintFocusMode: string | null
  focusDistancePercent: number | null
  requiresManual: boolean
  unavailableReason: string | null
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

export interface TrackCapabilitiesDetails {
  focusModes: string[]
  focusDistance: FocusDistanceCapabilities
  zoom: ZoomCapabilities
  summary: string
}

export interface TrackSettingsDetails {
  width: number | null
  height: number | null
  frameRate: string
  facingMode: string
  aspectRatio: string
  deviceId: string
  zoom: string
  focusMode: string
  focusDistance: string
  summary: string
}

export interface AppliedControlsSnapshot {
  requestedFocusMode: string
  actualFocusMode: string
  requestedFocusDistance: string
  actualFocusDistance: string
  requestedZoom: string
  actualZoom: string
  focusStatus: FocusApplicationStatus
  focusStatusMessage: string
  validManualTest: boolean
  capabilityMismatchWarning: string | null
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
  focusStatus: FocusApplicationStatus
  errorName: string
  errorMessage: string
  errorStack: string
}

export interface SessionStats {
  attempts: number
  correct: number
  incorrect: number
  notFound: number
  errors: number
  lastDetectionMs: number | null
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
  focusLabel: string
  zoomLabel: string
  requestedFocus: string
  actualFocus: string
  requestedDistance: string
  actualDistance: string
  requestedZoom: string
  actualZoom: string
  focusStatus: FocusApplicationStatus
  validManualTest: boolean
  enabled: boolean
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
  requestedFocus: string
  actualFocus: string
  distance: string
  zoom: string
  correct: number
  incorrect: number
  notFound: number
  sharpness: string
  focusStatus: FocusApplicationStatus
  validManualTest: boolean
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

export async function createTestBarcodeDetector(): Promise<{
  detector: BarcodeDetectorLike
  formatsUsed: string[]
  creationNote: string | null
}> {
  if (!isNativeBarcodeDetectorAvailable()) {
    throw new Error('BarcodeDetector natif non disponible.')
  }

  const DetectorClass = window.BarcodeDetector as BarcodeDetectorConstructorLike

  try {
    const formats = await resolveSupportedFormats(DetectorClass)

    return { detector: new DetectorClass({ formats }), formatsUsed: formats, creationNote: null }
  } catch (error) {
    try {
      return {
        detector: new DetectorClass(),
        formatsUsed: [],
        creationNote: error instanceof Error ? error.message : String(error),
      }
    } catch (fallbackError) {
      throw fallbackError instanceof Error ? fallbackError : new Error(String(fallbackError))
    }
  }
}

function readNumericCapability(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function readTrackCapabilitiesDetails(track: MediaStreamTrack | null): TrackCapabilitiesDetails {
  const capabilities = track?.getCapabilities?.() as Record<string, unknown> | undefined

  if (!capabilities) {
    return {
      focusModes: [],
      focusDistance: { supported: false, min: null, max: null, step: null },
      zoom: { supported: false, min: null, max: null, step: null },
      summary: 'NON DISPONIBLE',
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
      min: readNumericCapability(focusDistanceRecord?.min),
      max: readNumericCapability(focusDistanceRecord?.max),
      step: readNumericCapability(focusDistanceRecord?.step),
    },
    zoom: {
      supported: zoomSupported,
      min: readNumericCapability(zoomRecord?.min),
      max: readNumericCapability(zoomRecord?.max),
      step: readNumericCapability(zoomRecord?.step),
    },
    summary: [
      focusModes.length > 0 ? `focusMode=${focusModes.join('|')}` : 'focusMode=NON DISPONIBLE',
      focusDistanceSupported ? `focusDistance=${focusDistanceRecord?.min}..${focusDistanceRecord?.max}` : 'focusDistance=NON DISPONIBLE',
      zoomSupported ? `zoom=${zoomRecord?.min}..${zoomRecord?.max}` : 'zoom=NON DISPONIBLE',
    ].join(', '),
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
      aspectRatio: '—',
      deviceId: '—',
      zoom: '—',
      focusMode: '—',
      focusDistance: '—',
      summary: '—',
    }
  }

  const width = settings.width ?? null
  const height = settings.height ?? null

  return {
    width,
    height,
    frameRate: settings.frameRate != null ? String(settings.frameRate) : '—',
    facingMode: settings.facingMode ? String(settings.facingMode) : '—',
    aspectRatio: width && height ? (width / height).toFixed(2) : '—',
    deviceId: settings.deviceId ? `${settings.deviceId.slice(0, 8)}…` : '—',
    zoom: settings.zoom != null ? String(settings.zoom) : '—',
    focusMode: settings.focusMode != null ? String(settings.focusMode) : '—',
    focusDistance: settings.focusDistance != null ? String(settings.focusDistance) : '—',
    summary: [
      width != null ? `width=${width}` : null,
      settings.focusMode != null ? `focusMode=${settings.focusMode}` : null,
      settings.focusDistance != null ? `focusDistance=${settings.focusDistance}` : null,
      settings.zoom != null ? `zoom=${settings.zoom}` : null,
    ].filter(Boolean).join(', ') || '—',
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

export function computeFocusDistanceValue(
  capabilities: TrackCapabilitiesDetails,
  percent: number,
): number | null {
  const { min, max, step } = capabilities.focusDistance

  if (!capabilities.focusDistance.supported || min == null || max == null) {
    return null
  }

  return roundToStep(min + (max - min) * percent, min, max, step)
}

export function resolveZoomLevels(capabilities: TrackCapabilitiesDetails): number[] {
  if (!capabilities.zoom.supported || capabilities.zoom.max == null) {
    return [1]
  }

  return ZOOM_CANDIDATES.filter((level) => level <= capabilities.zoom.max!)
}

export function clampZoomValue(value: number, capabilities: TrackCapabilitiesDetails): number {
  if (!capabilities.zoom.supported || capabilities.zoom.max == null || capabilities.zoom.min == null) {
    return 1
  }

  return roundToStep(value, capabilities.zoom.min, capabilities.zoom.max, capabilities.zoom.step)
}

export function buildFocusProfiles(capabilities: TrackCapabilitiesDetails): FocusProfileDefinition[] {
  const profiles: FocusProfileDefinition[] = [
    {
      id: 'default',
      label: 'DEFAULT',
      requestedFocusLabel: 'default',
      constraintFocusMode: null,
      focusDistancePercent: null,
      requiresManual: false,
      unavailableReason: null,
    },
  ]

  if (capabilities.focusModes.includes('continuous')) {
    profiles.push({
      id: 'continuous',
      label: 'CONTINUOUS',
      requestedFocusLabel: 'continuous',
      constraintFocusMode: 'continuous',
      focusDistancePercent: null,
      requiresManual: false,
      unavailableReason: null,
    })
  } else {
    profiles.push({
      id: 'continuous',
      label: 'CONTINUOUS',
      requestedFocusLabel: 'continuous',
      constraintFocusMode: 'continuous',
      focusDistancePercent: null,
      requiresManual: false,
      unavailableReason: `Unavailable — camera reports only: ${capabilities.focusModes.join(', ') || 'none'}`,
    })
  }

  const manualItems: Array<{ id: FocusProfileId; label: string; percent: number }> = [
    { id: 'manual-min', label: 'MANUAL MIN', percent: 0 },
    { id: 'manual-25', label: 'MANUAL 25%', percent: 0.25 },
    { id: 'manual-50', label: 'MANUAL 50%', percent: 0.5 },
    { id: 'manual-mid', label: 'MANUAL MID', percent: 0.5 },
    { id: 'manual-75', label: 'MANUAL 75%', percent: 0.75 },
    { id: 'manual-100', label: 'MANUAL 100%', percent: 1 },
    { id: 'manual-max', label: 'MANUAL MAX', percent: 1 },
  ]

  const manualSupported = capabilities.focusModes.includes('manual')
  const distanceSupported = capabilities.focusDistance.supported

  for (const item of manualItems) {
    profiles.push({
      id: item.id,
      label: item.label,
      requestedFocusLabel: 'manual',
      constraintFocusMode: 'manual',
      focusDistancePercent: item.percent,
      requiresManual: true,
      unavailableReason: !manualSupported
        ? 'Unavailable — manual focusMode not reported in capabilities'
        : !distanceSupported
          ? 'Unavailable — focusDistance not exposed by this device'
          : null,
    })
  }

  return profiles
}

export function getFocusProfile(
  profiles: FocusProfileDefinition[],
  profileId: FocusProfileId,
): FocusProfileDefinition {
  const profile = profiles.find((item) => item.id === profileId)

  if (!profile) {
    throw new Error(`Profil inconnu: ${profileId}`)
  }

  return profile
}

export function isFocusProfileSelectable(
  profile: FocusProfileDefinition,
  capabilities: TrackCapabilitiesDetails,
): boolean {
  if (profile.unavailableReason) {
    return false
  }

  if (profile.id === 'default') {
    return true
  }

  if (profile.id === 'continuous') {
    return capabilities.focusModes.includes('continuous')
  }

  if (profile.requiresManual) {
    return capabilities.focusModes.includes('manual') && capabilities.focusDistance.supported
  }

  return false
}

export function buildCapabilityMismatchWarning(capabilities: TrackCapabilitiesDetails, settings: TrackSettingsDetails): string | null {
  if (
    capabilities.focusModes.length === 1
    && capabilities.focusModes[0] === 'manual'
    && settings.focusMode === 'continuous'
  ) {
    return [
      'WARNING — Capabilities report: manual',
      'Current settings report: continuous',
      'The browser/device is exposing inconsistent focus information.',
    ].join('\n')
  }

  return null
}

export function evaluateFocusApplicationStatus(options: {
  profile: FocusProfileDefinition
  requestedFocusMode: string
  actualFocusMode: string
  requestedFocusDistance: number | null
  actualFocusDistance: string
}): { status: FocusApplicationStatus; message: string; validManualTest: boolean } {
  const { profile, requestedFocusMode, actualFocusMode, requestedFocusDistance, actualFocusDistance } = options

  if (profile.id === 'default') {
    return {
      status: 'UNKNOWN',
      message: `DEFAULT → actual focus: ${actualFocusMode}`,
      validManualTest: true,
    }
  }

  if (profile.id === 'continuous') {
    const applied = actualFocusMode === 'continuous'

    return {
      status: applied ? 'APPLIED' : 'NOT APPLIED',
      message: applied
        ? 'Requested: continuous — Actual: continuous — Status: APPLIED'
        : `Requested: continuous — Actual: ${actualFocusMode} — Status: NOT APPLIED / FAILED TO APPLY`,
      validManualTest: applied,
    }
  }

  if (profile.requiresManual) {
    const manualApplied = actualFocusMode === 'manual'

    if (!manualApplied) {
      return {
        status: 'NOT APPLIED',
        message: `Requested: manual — Actual: ${actualFocusMode} — Status: NOT APPLIED — NOT A VALID MANUAL TEST`,
        validManualTest: false,
      }
    }

    if (requestedFocusDistance != null && actualFocusDistance !== '—') {
      const actualDistance = Number.parseFloat(actualFocusDistance)
      const delta = Math.abs(actualDistance - requestedFocusDistance)

      if (Number.isFinite(actualDistance) && delta > 0.05) {
        return {
          status: 'NOT APPLIED',
          message: `Focus manual applied but focusDistance mismatch (requested ${requestedFocusDistance}, actual ${actualFocusDistance})`,
          validManualTest: false,
        }
      }
    }

    return {
      status: 'APPLIED',
      message: 'Requested: manual — Actual: manual — ✓ Focus manuel réellement appliqué',
      validManualTest: true,
    }
  }

  return { status: 'UNKNOWN', message: 'Impossible de vérifier', validManualTest: true }
}

export function createEmptySessionStats(): SessionStats {
  return {
    attempts: 0,
    correct: 0,
    incorrect: 0,
    notFound: 0,
    errors: 0,
    lastDetectionMs: null,
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

export function buildConfigKey(profileId: FocusProfileId, zoom: number): string {
  return `${profileId}:${zoom}`
}

export function buildConfigurationLabel(profile: FocusProfileDefinition, zoom: number): string {
  return `${profile.label} + ${zoom}×`
}

export function createInitialAggregates(
  profiles: FocusProfileDefinition[],
  capabilities: TrackCapabilitiesDetails,
): ConfigurationAggregate[] {
  const rows: ConfigurationAggregate[] = []
  const zoomLevels = resolveZoomLevels(capabilities)

  for (const profile of profiles) {
    for (const zoom of zoomLevels) {
      rows.push({
        ...createEmptySessionStats(),
        configKey: buildConfigKey(profile.id, zoom),
        focusLabel: profile.label,
        zoomLabel: `${zoom}×`,
        requestedFocus: profile.requestedFocusLabel,
        actualFocus: '—',
        requestedDistance: '—',
        actualDistance: '—',
        requestedZoom: `${zoom}`,
        actualZoom: '—',
        focusStatus: 'UNKNOWN',
        validManualTest: true,
        enabled: isFocusProfileSelectable(profile, capabilities),
      })
    }
  }

  return rows
}

export function classifyReadResult(rawValue: string | undefined, expectedBarcode: string): ReadResultType {
  if (!rawValue) {
    return 'NOT_FOUND'
  }

  return rawValue === expectedBarcode ? 'CORRECT' : 'INCORRECT'
}

export function formatDurationMs(value: number | null): string {
  if (value == null) {
    return '—'
  }

  return `${Math.round(value)} ms`
}

export function computeRate(numerator: number, denominator: number): string {
  if (denominator <= 0) {
    return '—'
  }

  return `${Math.min(100, (numerator / denominator) * 100).toFixed(1)}%`
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

export function average(values: number[]): number | null {
  if (values.length === 0) {
    return null
  }

  return Math.round(values.reduce((sum, value) => sum + value, 0) / values.length)
}

export async function applyFocusProfileToTrack(
  track: MediaStreamTrack,
  profile: FocusProfileDefinition,
  capabilities: TrackCapabilitiesDetails,
): Promise<{ focusDistance: number | null; log: ConstraintLogEntry }> {
  const timestamp = new Date().toLocaleTimeString('fr-FR')
  const settingsBefore = readTrackSettingsDetails(track)
  let focusDistance: number | null = null

  if (profile.id === 'default') {
    const settingsAfter = readTrackSettingsDetails(track)
    const evaluation = evaluateFocusApplicationStatus({
      profile,
      requestedFocusMode: 'default',
      actualFocusMode: settingsAfter.focusMode,
      requestedFocusDistance: null,
      actualFocusDistance: settingsAfter.focusDistance,
    })

    return {
      focusDistance: null,
      log: {
        id: `${Date.now()}-default`,
        timestamp,
        requestedFocusMode: 'default',
        actualFocusMode: settingsAfter.focusMode,
        requestedFocusDistance: '—',
        actualFocusDistance: settingsAfter.focusDistance,
        requestedZoom: settingsBefore.zoom,
        actualZoom: settingsAfter.zoom,
        success: true,
        focusStatus: evaluation.status,
        errorName: '—',
        errorMessage: 'No focus constraint applied (DEFAULT).',
        errorStack: '—',
      },
    }
  }

  const advanced: MediaTrackConstraintSet[] = []

  if (profile.constraintFocusMode) {
    advanced.push({ focusMode: profile.constraintFocusMode as ConstrainDOMString })
  }

  if (profile.focusDistancePercent != null) {
    focusDistance = computeFocusDistanceValue(capabilities, profile.focusDistancePercent)

    if (focusDistance != null) {
      advanced.push({ focusDistance })
    }
  }

  try {
    await track.applyConstraints({ advanced })
    const settingsAfter = readTrackSettingsDetails(track)
    const evaluation = evaluateFocusApplicationStatus({
      profile,
      requestedFocusMode: profile.constraintFocusMode ?? 'default',
      actualFocusMode: settingsAfter.focusMode,
      requestedFocusDistance: focusDistance,
      actualFocusDistance: settingsAfter.focusDistance,
    })

    return {
      focusDistance,
      log: {
        id: `${Date.now()}-focus`,
        timestamp,
        requestedFocusMode: profile.constraintFocusMode ?? 'default',
        actualFocusMode: settingsAfter.focusMode,
        requestedFocusDistance: focusDistance != null ? String(focusDistance) : '—',
        actualFocusDistance: settingsAfter.focusDistance,
        requestedZoom: settingsBefore.zoom,
        actualZoom: settingsAfter.zoom,
        success: true,
        focusStatus: evaluation.status,
        errorName: '—',
        errorMessage: evaluation.message,
        errorStack: '—',
      },
    }
  } catch (error) {
    const serialized = serializeConstraintError(error)
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      focusDistance,
      log: {
        id: `${Date.now()}-focus-error`,
        timestamp,
        requestedFocusMode: profile.constraintFocusMode ?? 'default',
        actualFocusMode: settingsAfter.focusMode,
        requestedFocusDistance: focusDistance != null ? String(focusDistance) : '—',
        actualFocusDistance: settingsAfter.focusDistance,
        requestedZoom: settingsBefore.zoom,
        actualZoom: settingsAfter.zoom,
        success: false,
        focusStatus: 'NOT APPLIED',
        errorName: serialized.name,
        errorMessage: serialized.message,
        errorStack: serialized.stack,
      },
    }
  }
}

export async function applyZoomToTrack(
  track: MediaStreamTrack,
  requestedZoom: number,
  capabilities: TrackCapabilitiesDetails,
): Promise<ConstraintLogEntry> {
  const timestamp = new Date().toLocaleTimeString('fr-FR')
  const clampedZoom = clampZoomValue(requestedZoom, capabilities)
  const settingsBefore = readTrackSettingsDetails(track)

  if (!capabilities.zoom.supported) {
    return {
      id: `${Date.now()}-zoom-unavailable`,
      timestamp,
      requestedFocusMode: settingsBefore.focusMode,
      actualFocusMode: settingsBefore.focusMode,
      requestedFocusDistance: settingsBefore.focusDistance,
      actualFocusDistance: settingsBefore.focusDistance,
      requestedZoom: `${requestedZoom}`,
      actualZoom: settingsBefore.zoom,
      success: false,
      focusStatus: 'UNKNOWN',
      errorName: 'ZoomUnavailable',
      errorMessage: 'Zoom matériel NON DISPONIBLE',
      errorStack: '—',
    }
  }

  try {
    await track.applyConstraints({ advanced: [{ zoom: clampedZoom }] })
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      id: `${Date.now()}-zoom`,
      timestamp,
      requestedFocusMode: settingsBefore.focusMode,
      actualFocusMode: settingsAfter.focusMode,
      requestedFocusDistance: settingsBefore.focusDistance,
      actualFocusDistance: settingsAfter.focusDistance,
      requestedZoom: `${requestedZoom}`,
      actualZoom: settingsAfter.zoom,
      success: true,
      focusStatus: 'UNKNOWN',
      errorName: '—',
      errorMessage: 'Constraint application: SUCCESS',
      errorStack: '—',
    }
  } catch (error) {
    const serialized = serializeConstraintError(error)
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      id: `${Date.now()}-zoom-error`,
      timestamp,
      requestedFocusMode: settingsBefore.focusMode,
      actualFocusMode: settingsAfter.focusMode,
      requestedFocusDistance: settingsBefore.focusDistance,
      actualFocusDistance: settingsAfter.focusDistance,
      requestedZoom: `${requestedZoom}`,
      actualZoom: settingsAfter.zoom,
      success: false,
      focusStatus: 'UNKNOWN',
      errorName: serialized.name,
      errorMessage: serialized.message,
      errorStack: serialized.stack,
    }
  }
}

export function serializeConstraintError(error: unknown): { name: string; message: string; stack: string } {
  if (error instanceof Error) {
    return { name: error.name || '—', message: error.message || '—', stack: error.stack || '—' }
  }

  return { name: '—', message: String(error), stack: '—' }
}

export function buildComparisonTableRows(aggregates: ConfigurationAggregate[]): ComparisonTableRow[] {
  return aggregates.map((aggregate) => ({
    configuration: `${aggregate.focusLabel} + ${aggregate.zoomLabel}`,
    requestedFocus: aggregate.requestedFocus,
    actualFocus: aggregate.actualFocus,
    distance: aggregate.actualDistance !== '—' ? aggregate.actualDistance : aggregate.requestedDistance,
    zoom: aggregate.actualZoom,
    correct: aggregate.correct,
    incorrect: aggregate.incorrect,
    notFound: aggregate.notFound,
    sharpness: aggregate.averageSharpness != null ? String(Math.round(aggregate.averageSharpness)) : '—',
    focusStatus: aggregate.focusStatus,
    validManualTest: aggregate.validManualTest,
  }))
}

export function findBestConfiguration(aggregates: ConfigurationAggregate[]): {
  label: string
  reason: string
} {
  const candidates = aggregates.filter((item) => item.enabled && item.validManualTest && item.attempts >= MIN_ATTEMPTS_FOR_BEST)

  if (candidates.length === 0) {
    return { label: '—', reason: 'Data insufficient (< 50 attempts per configuration)' }
  }

  const best = candidates.sort((left, right) => {
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
    label: `${best.focusLabel} + ${best.zoomLabel}`,
    reason: `Correct ${computeRate(best.correct, best.attempts)}, incorrect ${computeRate(best.incorrect, best.attempts)}, sharpness ${best.averageSharpness ?? '—'}`,
  }
}

export function buildManualFocusConclusion(options: {
  stats: SessionStats
  appliedControls: AppliedControlsSnapshot | null
  bestConfiguration: { label: string; reason: string }
  capabilityMismatchWarning: string | null
}): string {
  const lines: string[] = []

  if (options.capabilityMismatchWarning) {
    lines.push('NON TESTABLE', '', options.capabilityMismatchWarning, '')
  }

  if (options.appliedControls && !options.appliedControls.validManualTest && options.appliedControls.requestedFocusMode === 'manual') {
    lines.push(
      'NON TESTABLE',
      '',
      'Le focus manuel demandé n\'a pas été réellement appliqué.',
      options.appliedControls.focusStatusMessage,
      '',
    )
  }

  if (options.stats.attempts < MIN_ATTEMPTS_FOR_BEST) {
    lines.push('Données insuffisantes pour conclure (< 50 tentatives).')
    return lines.join('\n')
  }

  if (options.stats.correct === 0) {
    lines.push('AUCUN GAIN OBSERVÉ — aucune lecture correcte dans cette session.')
  } else if (options.stats.correct / options.stats.attempts >= 0.5) {
    lines.push(
      'INDICE FORT',
      '',
      `La configuration ${options.bestConfiguration.label} présente, dans cette session, un taux de lectures correctes supérieur aux autres configurations comparables.`,
      options.bestConfiguration.reason,
      '',
      'Résultat expérimental uniquement.',
    )
  } else if (options.stats.correct / options.stats.attempts >= 0.15) {
    lines.push('INDICE MODÉRÉ — amélioration partielle observée.', options.bestConfiguration.reason)
  } else {
    lines.push('INDICE FAIBLE — gain limité observé.', options.bestConfiguration.reason)
  }

  if (options.stats.incorrect > 0) {
    lines.push('', 'Des lectures incorrectes ont été observées — prudence recommandée avant toute intégration scanner.')
  }

  return lines.join('\n')
}

export function buildManualFocusDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  supportedFormats: string[]
  detectorCreationNote: string | null
  trackSettings: TrackSettingsDetails
  capabilities: TrackCapabilitiesDetails
  appliedControls: AppliedControlsSnapshot | null
  expectedBarcode: string
  testDurationSeconds: number
  stats: SessionStats
  aggregates: ConfigurationAggregate[]
  constraintLog: ConstraintLogEntry[]
  history: EventHistoryEntry[]
  capabilityMismatchWarning: string | null
  bestConfiguration: { label: string; reason: string }
  conclusion: string
}): string {
  const lines = [
    '=== BARCODE DETECTOR MANUAL FOCUS DIAGNOSTIC ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    '',
    'BarcodeDetector:',
    `Available: ${options.environment.barcodeDetectorAvailable ? 'YES' : 'NO'}`,
    `Formats: ${options.supportedFormats.join(', ') || 'default constructor'}`,
    ...(options.detectorCreationNote ? [`Creation note: ${options.detectorCreationNote}`] : []),
    '',
    'CAMERA',
    `Requested resolution: ${REQUESTED_WIDTH}×${REQUESTED_HEIGHT}`,
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
    `Focus: ${options.trackSettings.focusMode}`,
    `Focus distance: ${options.trackSettings.focusDistance}`,
    `Zoom: ${options.trackSettings.zoom}`,
    '',
  ]

  if (options.appliedControls) {
    lines.push(
      'APPLIED CONTROLS',
      `Requested focus: ${options.appliedControls.requestedFocusMode}`,
      `Actual focus: ${options.appliedControls.actualFocusMode}`,
      `Requested distance: ${options.appliedControls.requestedFocusDistance}`,
      `Actual distance: ${options.appliedControls.actualFocusDistance}`,
      `Requested zoom: ${options.appliedControls.requestedZoom}`,
      `Actual zoom: ${options.appliedControls.actualZoom}`,
      `Focus status: ${options.appliedControls.focusStatus}`,
      `Valid manual test: ${options.appliedControls.validManualTest ? 'YES' : 'NO — NOT A VALID MANUAL TEST'}`,
      options.appliedControls.focusStatusMessage,
      '',
    )
  }

  if (options.capabilityMismatchWarning) {
    lines.push('CAPABILITY MISMATCH', options.capabilityMismatchWarning, '')
  }

  lines.push(
    'TEST SUBJECT',
    `Expected barcode: ${options.expectedBarcode}`,
    `Duration: ${options.testDurationSeconds}s`,
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
    '',
    'SHARPNESS ANALYSIS',
    `Average: ${options.stats.averageSharpness ?? '—'}`,
    `Min: ${options.stats.minSharpness ?? '—'}`,
    `Max: ${options.stats.maxSharpness ?? '—'}`,
    `Correct reads average sharpness: ${options.stats.correctAverageSharpness ?? '—'}`,
    `Incorrect reads average sharpness: ${options.stats.incorrectAverageSharpness ?? '—'}`,
    `Not found average sharpness: ${options.stats.notFoundAverageSharpness ?? '—'}`,
    '',
    'CONFIGURATION COMPARISON',
    '',
  )

  for (const row of buildComparisonTableRows(options.aggregates)) {
    lines.push(
      row.configuration,
      `requested focus: ${row.requestedFocus}`,
      `actual focus: ${row.actualFocus}`,
      `distance: ${row.distance}`,
      `zoom: ${row.zoom}`,
      `correct: ${row.correct}`,
      `incorrect: ${row.incorrect}`,
      `not found: ${row.notFound}`,
      `sharpness: ${row.sharpness}`,
      `focus status: ${row.focusStatus}`,
      `valid manual test: ${row.validManualTest ? 'YES' : 'NO'}`,
      '',
    )
  }

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
        entry.success ? '' : `name: ${entry.errorName}`,
        entry.success ? '' : `message: ${entry.errorMessage}`,
        '',
      )
    }
  }

  if (options.history.length > 0) {
    lines.push('HISTORY', '')

    for (const entry of options.history.slice(0, MAX_EVENT_HISTORY)) {
      lines.push(
        `${entry.timestamp} — ${entry.configuration} — focusDistance: ${entry.focusDistance} — zoom: ${entry.zoom} — sharpness: ${entry.sharpness ?? '—'} — ${entry.resultType} — ${entry.rawValue || '—'} — ${entry.durationMs} ms`,
      )
    }

    lines.push('')
  }

  lines.push(
    'BEST CONFIGURATION',
    `${options.bestConfiguration.label}`,
    options.bestConfiguration.reason,
    '',
    'CONCLUSION',
    options.conclusion,
  )

  return lines.join('\n')
}

export { formatNativeBarcodeFormat, pickBestNativeBarcode }

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}
