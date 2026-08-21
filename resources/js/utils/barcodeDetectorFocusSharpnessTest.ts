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

export const REFERENCE_EAN_VALUE = '6202312030117'
export const ALT_REFERENCE_EAN_VALUE = '6043000070493'
export const REFERENCE_EAN_FORMAT = 'EAN-13'
export const EXPECTED_FORMAT = 'ean_13'
export const SMALL_EAN_SUBJECT = 'Small EAN-13'
export const STANDARD_EAN_SUBJECT = 'Standard EAN-13'
export const CUSTOM_SUBJECT = 'Custom'
export const DETECTION_INTERVAL_MS = 150
export const CAMERA_SETTLING_MS = 1000
export const SHARPNESS_SAMPLE_INTERVAL_MS = 500
export const MAX_READ_HISTORY = 30
export const MAX_CONSTRAINT_LOG = 20
export const DUPLICATE_CORRECT_WINDOW_MS = 800
export const ZOOM_CANDIDATES = [1, 2, 3, 4, 6, 8] as const

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

export type TestSubjectId = 'small-ean' | 'standard-ean' | 'custom'
export type FocusProfileId =
  | 'default'
  | 'manual'
  | 'continuous'
  | 'single-shot'
  | 'manual-min'
  | 'manual-mid'
  | 'manual-max'

export type ReadClassification =
  | 'CORRECT_READ'
  | 'INCORRECT_READ'
  | 'EXPECTED_FORMAT_BUT_WRONG_VALUE'
  | 'UNEXPECTED_FORMAT'
  | 'NOT_FOUND'
  | 'ERROR'
  | 'DUPLICATE_CORRECT_READ'

export type FocusStabilityLabel = 'Stable' | 'Variable' | 'Très variable' | 'Données insuffisantes'

export interface FocusProfileDefinition {
  id: FocusProfileId
  label: string
  constraintFocusMode: string | null
  constraintFocusDistance: number | null
  requiresFocusDistance: boolean
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

export interface FocusCapabilities {
  modes: string[]
  distance: FocusDistanceCapabilities
}

export interface TrackCapabilitiesDetails {
  focus: FocusCapabilities
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

export interface RequestedControls {
  focus: string
  zoom: string
  focusDistance: string
}

export interface ConstraintLogEntry {
  id: string
  timestamp: string
  requestedFocus: string
  requestedZoom: string
  requestedFocusDistance: string
  success: boolean
  errorName: string
  errorMessage: string
  actualFocus: string
  actualZoom: string
  actualFocusDistance: string
}

export interface SharpnessStats {
  current: number | null
  average: number | null
  min: number | null
  max: number | null
  samples: number
  stability: FocusStabilityLabel
  standardDeviation: number | null
  significantVariations: number
  timeToStableMs: number | null
}

export interface BoundingBoxDetails {
  x: number
  y: number
  width: number
  height: number
  area: number
  relativeWidthPercent: number
  relativeHeightPercent: number
  label: string
}

export interface SessionStats {
  attempts: number
  correctReads: number
  incorrectReads: number
  unexpectedFormat: number
  expectedFormatWrongValue: number
  duplicateCorrectReads: number
  notFound: number
  errors: number
  lastDetectionMs: number | null
  averageDetectionMs: number | null
  minDetectionMs: number | null
  maxDetectionMs: number | null
  testStartedAt: number | null
  testDurationMs: number
}

export interface ProfileAggregate extends SessionStats {
  focusProfileId: FocusProfileId
  focusLabel: string
  zoom: number
  zoomLabel: string
  testSubject: string
  requestedFocus: string
  actualFocus: string
  requestedZoom: string
  actualZoom: string
  enabled: boolean
  averageSharpness: number | null
  correctReadAverageSharpness: number | null
  incorrectReadAverageSharpness: number | null
  notFoundAverageSharpness: number | null
}

export interface ReadHistoryEntry {
  id: string
  timestamp: string
  focusLabel: string
  zoomLabel: string
  rawValue: string
  format: string
  classification: ReadClassification
  durationMs: number
  sharpness: number | null
  boundingBox: string
  expectedBarcode: string
}

export interface ComparisonTableRow {
  focusLabel: string
  zoomLabel: string
  testSubject: string
  correct: number
  incorrect: number
  notFound: number
  errors: number
  correctRate: string
  incorrectRate: string
  averageSharpness: string
  enabled: boolean
}

export interface IncorrectValueSummary {
  value: string
  count: number
}

export {
  computeAverageDetectionMs,
  getEnvironmentDiagnostics,
  type EnvironmentDiagnostics,
}

export function isNativeBarcodeDetectorAvailable(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

async function resolveSupportedFormats(
  DetectorClass: BarcodeDetectorConstructorLike,
): Promise<string[]> {
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
    throw new Error('BarcodeDetector natif non disponible sur ce navigateur.')
  }

  const DetectorClass = window.BarcodeDetector as BarcodeDetectorConstructorLike

  try {
    const formats = await resolveSupportedFormats(DetectorClass)

    return {
      detector: new DetectorClass({ formats }),
      formatsUsed: formats,
      creationNote: null,
    }
  } catch (error) {
    try {
      return {
        detector: new DetectorClass(),
        formatsUsed: [],
        creationNote: error instanceof Error ? error.message : String(error),
      }
    } catch (fallbackError) {
      if (fallbackError instanceof Error) {
        throw fallbackError
      }

      throw new Error(String(fallbackError))
    }
  }
}

function display(value: unknown): string {
  if (value == null || value === '') {
    return '—'
  }

  return String(value)
}

function readNumericCapability(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value
  }

  return null
}

export function readTrackCapabilitiesDetails(track: MediaStreamTrack | null): TrackCapabilitiesDetails {
  const capabilities = track?.getCapabilities?.() as Record<string, unknown> | undefined

  if (!capabilities) {
    return {
      focus: { modes: [], distance: { supported: false, min: null, max: null, step: null } },
      zoom: { supported: false, min: null, max: null, step: null },
      summary: '—',
    }
  }

  const focusModes = Array.isArray(capabilities.focusMode)
    ? capabilities.focusMode.map(String)
    : capabilities.focusMode != null
      ? [String(capabilities.focusMode)]
      : []

  const focusDistanceRaw = capabilities.focusDistance
  const focusDistanceSupported = focusDistanceRaw != null && typeof focusDistanceRaw === 'object'
  const focusDistanceRecord = focusDistanceSupported
    ? focusDistanceRaw as { min?: number; max?: number; step?: number }
    : null

  const zoomRaw = capabilities.zoom
  const zoomSupported = zoomRaw != null && typeof zoomRaw === 'object'
  const zoomRecord = zoomSupported ? zoomRaw as { min?: number; max?: number; step?: number } : null

  const focus: FocusCapabilities = {
    modes: focusModes,
    distance: {
      supported: focusDistanceSupported,
      min: readNumericCapability(focusDistanceRecord?.min),
      max: readNumericCapability(focusDistanceRecord?.max),
      step: readNumericCapability(focusDistanceRecord?.step),
    },
  }

  const zoom: ZoomCapabilities = {
    supported: zoomSupported,
    min: readNumericCapability(zoomRecord?.min),
    max: readNumericCapability(zoomRecord?.max),
    step: readNumericCapability(zoomRecord?.step),
  }

  return {
    focus,
    zoom,
    summary: [
      focusModes.length > 0 ? `focusMode=${focusModes.join('|')}` : 'focusMode=unavailable',
      focusDistanceSupported ? `focusDistance=${focus.distance.min}..${focus.distance.max}` : 'focusDistance=unavailable',
      zoomSupported ? `zoom=${zoom.min}..${zoom.max}` : 'zoom=unavailable',
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
  const aspectRatio = width && height ? (width / height).toFixed(2) : '—'

  return {
    width,
    height,
    frameRate: settings.frameRate != null ? String(settings.frameRate) : '—',
    facingMode: settings.facingMode ? String(settings.facingMode) : '—',
    aspectRatio: String(aspectRatio),
    deviceId: settings.deviceId ? `${settings.deviceId.slice(0, 8)}…` : '—',
    zoom: settings.zoom != null ? String(settings.zoom) : '—',
    focusMode: settings.focusMode != null ? String(settings.focusMode) : '—',
    focusDistance: settings.focusDistance != null ? String(settings.focusDistance) : '—',
    summary: [
      width != null ? `width=${width}` : null,
      height != null ? `height=${height}` : null,
      settings.focusMode != null ? `focusMode=${settings.focusMode}` : null,
      settings.zoom != null ? `zoom=${settings.zoom}` : null,
      settings.focusDistance != null ? `focusDistance=${settings.focusDistance}` : null,
    ].filter(Boolean).join(', ') || '—',
  }
}

export function getVideoOrientationLabel(width: number, height: number): string {
  if (width <= 0 || height <= 0) {
    return '—'
  }

  if (width === height) {
    return 'square'
  }

  return width > height ? 'landscape' : 'portrait'
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

  const clamped = Math.min(Math.max(value, capabilities.zoom.min), capabilities.zoom.max)

  if (capabilities.zoom.step != null && capabilities.zoom.step > 0) {
    const steps = Math.round((clamped - capabilities.zoom.min) / capabilities.zoom.step)

    return Number((capabilities.zoom.min + steps * capabilities.zoom.step).toFixed(2))
  }

  return Number(clamped.toFixed(2))
}

export function buildAvailableFocusProfiles(capabilities: TrackCapabilitiesDetails): FocusProfileDefinition[] {
  const profiles: FocusProfileDefinition[] = [
    { id: 'default', label: 'DEFAULT', constraintFocusMode: null, constraintFocusDistance: null, requiresFocusDistance: false },
  ]

  if (capabilities.focus.modes.includes('manual')) {
    profiles.push({
      id: 'manual',
      label: 'MANUAL',
      constraintFocusMode: 'manual',
      constraintFocusDistance: null,
      requiresFocusDistance: false,
    })
  }

  if (capabilities.focus.modes.includes('continuous')) {
    profiles.push({
      id: 'continuous',
      label: 'CONTINUOUS',
      constraintFocusMode: 'continuous',
      constraintFocusDistance: null,
      requiresFocusDistance: false,
    })
  }

  if (capabilities.focus.modes.includes('single-shot')) {
    profiles.push({
      id: 'single-shot',
      label: 'SINGLE-SHOT',
      constraintFocusMode: 'single-shot',
      constraintFocusDistance: null,
      requiresFocusDistance: false,
    })
  }

  if (capabilities.focus.distance.supported && capabilities.focus.distance.min != null && capabilities.focus.distance.max != null) {
    const min = capabilities.focus.distance.min
    const max = capabilities.focus.distance.max
    const mid = Number(((min + max) / 2).toFixed(2))

    profiles.push(
      {
        id: 'manual-min',
        label: 'MANUAL MIN',
        constraintFocusMode: 'manual',
        constraintFocusDistance: min,
        requiresFocusDistance: true,
      },
      {
        id: 'manual-mid',
        label: 'MANUAL MID',
        constraintFocusMode: 'manual',
        constraintFocusDistance: mid,
        requiresFocusDistance: true,
      },
      {
        id: 'manual-max',
        label: 'MANUAL MAX',
        constraintFocusMode: 'manual',
        constraintFocusDistance: max,
        requiresFocusDistance: true,
      },
    )
  }

  return profiles
}

export function isFocusProfileAvailable(
  profile: FocusProfileDefinition,
  capabilities: TrackCapabilitiesDetails,
): boolean {
  if (profile.id === 'default') {
    return true
  }

  if (profile.constraintFocusMode && !capabilities.focus.modes.includes(profile.constraintFocusMode)) {
    return false
  }

  if (profile.requiresFocusDistance && !capabilities.focus.distance.supported) {
    return false
  }

  return true
}

export function getExpectedBarcodeForSubject(subject: TestSubjectId, customValue: string): string {
  if (subject === 'standard-ean') {
    return REFERENCE_EAN_VALUE
  }

  if (subject === 'small-ean') {
    return customValue.trim()
  }

  return customValue.trim()
}

export function getSubjectLabel(subject: TestSubjectId): string {
  if (subject === 'small-ean') {
    return SMALL_EAN_SUBJECT
  }

  if (subject === 'standard-ean') {
    return STANDARD_EAN_SUBJECT
  }

  return CUSTOM_SUBJECT
}

export function createEmptySessionStats(): SessionStats {
  return {
    attempts: 0,
    correctReads: 0,
    incorrectReads: 0,
    unexpectedFormat: 0,
    expectedFormatWrongValue: 0,
    duplicateCorrectReads: 0,
    notFound: 0,
    errors: 0,
    lastDetectionMs: null,
    averageDetectionMs: null,
    minDetectionMs: null,
    maxDetectionMs: null,
    testStartedAt: null,
    testDurationMs: 0,
  }
}

export function buildProfileKey(
  focusProfileId: FocusProfileId,
  zoom: number,
  testSubject: TestSubjectId,
): string {
  return `${focusProfileId}:${zoom}:${testSubject}`
}

export function createInitialProfileAggregates(
  capabilities: TrackCapabilitiesDetails,
  testSubject: TestSubjectId,
): ProfileAggregate[] {
  const profiles = buildAvailableFocusProfiles(capabilities)
  const zoomLevels = resolveZoomLevels(capabilities)
  const rows: ProfileAggregate[] = []

  for (const profile of profiles) {
    for (const zoom of zoomLevels) {
      const enabled = isFocusProfileAvailable(profile, capabilities)

      rows.push({
        ...createEmptySessionStats(),
        focusProfileId: profile.id,
        focusLabel: profile.label,
        zoom,
        zoomLabel: `${zoom}×`,
        testSubject: getSubjectLabel(testSubject),
        requestedFocus: profile.constraintFocusMode ?? 'none',
        actualFocus: '—',
        requestedZoom: `${zoom}`,
        actualZoom: '—',
        enabled,
        averageSharpness: null,
        correctReadAverageSharpness: null,
        incorrectReadAverageSharpness: null,
        notFoundAverageSharpness: null,
      })
    }
  }

  return rows
}

export function computeRate(numerator: number, denominator: number): string {
  if (denominator <= 0) {
    return '—'
  }

  const rate = Math.min(100, (numerator / denominator) * 100)

  return `${rate.toFixed(1)}%`
}

export function computeCorrectRate(stats: SessionStats): string {
  return computeRate(stats.correctReads, stats.attempts)
}

export function computeIncorrectRate(stats: SessionStats): string {
  return computeRate(stats.incorrectReads + stats.expectedFormatWrongValue + stats.unexpectedFormat, stats.attempts)
}

export function computeNotFoundRate(stats: SessionStats): string {
  return computeRate(stats.notFound, stats.attempts)
}

export function formatDurationMs(value: number | null): string {
  if (value == null) {
    return '—'
  }

  return `${Math.round(value)} ms`
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
      const laplacian =
        -gray[(y - 1) * width + x]!
        - gray[y * width + (x - 1)]!
        + 4 * center
        - gray[y * width + (x + 1)]!
        - gray[(y + 1) * width + x]!

      sum += laplacian
      sumSq += laplacian * laplacian
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

  const imageData = context.getImageData(0, 0, width, height)

  return Math.round(computeLaplacianVariance(imageData))
}

export function computeSharpnessStats(values: number[]): SharpnessStats {
  if (values.length === 0) {
    return {
      current: null,
      average: null,
      min: null,
      max: null,
      samples: 0,
      stability: 'Données insuffisantes',
      standardDeviation: null,
      significantVariations: 0,
      timeToStableMs: null,
    }
  }

  const average = values.reduce((sum, value) => sum + value, 0) / values.length
  const min = Math.min(...values)
  const max = Math.max(...values)
  const variance = values.reduce((sum, value) => sum + (value - average) ** 2, 0) / values.length
  const standardDeviation = Math.sqrt(variance)
  const coefficient = average > 0 ? standardDeviation / average : 1

  let significantVariations = 0

  for (let index = 1; index < values.length; index++) {
    const previous = values[index - 1]!
    const current = values[index]!

    if (Math.abs(current - previous) / Math.max(previous, 1) >= 0.15) {
      significantVariations += 1
    }
  }

  let stability: FocusStabilityLabel = 'Données insuffisantes'

  if (values.length >= 3) {
    if (coefficient < 0.08) {
      stability = 'Stable'
    } else if (coefficient < 0.18) {
      stability = 'Variable'
    } else {
      stability = 'Très variable'
    }
  }

  let timeToStableMs: number | null = null

  if (values.length >= 4) {
    const target = average
    const stableIndex = values.findIndex((value, index) => {
      if (index < 2) {
        return false
      }

      const windowValues = values.slice(Math.max(0, index - 2), index + 1)
      const windowAverage = windowValues.reduce((sum, item) => sum + item, 0) / windowValues.length

      return Math.abs(windowAverage - target) / Math.max(target, 1) <= 0.1
    })

    if (stableIndex >= 0) {
      timeToStableMs = stableIndex * SHARPNESS_SAMPLE_INTERVAL_MS
    }
  }

  return {
    current: values[values.length - 1] ?? null,
    average: Math.round(average),
    min: Math.round(min),
    max: Math.round(max),
    samples: values.length,
    stability,
    standardDeviation: Math.round(standardDeviation),
    significantVariations,
    timeToStableMs,
  }
}

export function extractBoundingBoxDetails(
  barcode: DetectedBarcodeLike,
  videoWidth: number,
  videoHeight: number,
): BoundingBoxDetails | null {
  const record = barcode as DetectedBarcodeLike & {
    boundingBox?: { x: number; y: number; width: number; height: number }
  }

  const box = record.boundingBox

  if (!box || videoWidth <= 0 || videoHeight <= 0) {
    return null
  }

  const relativeWidthPercent = (box.width / videoWidth) * 100
  const relativeHeightPercent = (box.height / videoHeight) * 100

  return {
    x: Math.round(box.x),
    y: Math.round(box.y),
    width: Math.round(box.width),
    height: Math.round(box.height),
    area: Math.round(box.width * box.height),
    relativeWidthPercent: Number(relativeWidthPercent.toFixed(1)),
    relativeHeightPercent: Number(relativeHeightPercent.toFixed(1)),
    label: `${Math.round(box.width)} × ${Math.round(box.height)} px`,
  }
}

export function classifyDetectionResult(options: {
  barcode: DetectedBarcodeLike | null
  expectedBarcode: string
  expectedFormat: string
  lastCorrectValue: string | null
  lastCorrectAt: number | null
  now: number
}): ReadClassification {
  const { barcode, expectedBarcode, expectedFormat, lastCorrectValue, lastCorrectAt, now } = options

  if (!barcode?.rawValue) {
    return 'NOT_FOUND'
  }

  const format = barcode.format ?? ''

  if (expectedFormat && format && format !== expectedFormat) {
    return 'UNEXPECTED_FORMAT'
  }

  if (expectedBarcode && barcode.rawValue === expectedBarcode) {
    if (
      lastCorrectValue === barcode.rawValue
      && lastCorrectAt != null
      && now - lastCorrectAt <= DUPLICATE_CORRECT_WINDOW_MS
    ) {
      return 'DUPLICATE_CORRECT_READ'
    }

    return 'CORRECT_READ'
  }

  if (expectedFormat && format === expectedFormat) {
    return 'EXPECTED_FORMAT_BUT_WRONG_VALUE'
  }

  return 'INCORRECT_READ'
}

export async function applyFocusProfileToTrack(
  track: MediaStreamTrack,
  profile: FocusProfileDefinition,
): Promise<ConstraintLogEntry> {
  const timestamp = new Date().toLocaleTimeString('fr-FR')
  const settingsBefore = readTrackSettingsDetails(track)

  if (profile.id === 'default') {
    return {
      id: `${Date.now()}-default`,
      timestamp,
      requestedFocus: 'none',
      requestedZoom: settingsBefore.zoom,
      requestedFocusDistance: '—',
      success: true,
      errorName: '—',
      errorMessage: 'Aucune contrainte focus appliquée (DEFAULT).',
      actualFocus: settingsBefore.focusMode,
      actualZoom: settingsBefore.zoom,
      actualFocusDistance: settingsBefore.focusDistance,
    }
  }

  const advanced: MediaTrackConstraintSet[] = []

  if (profile.constraintFocusMode) {
    advanced.push({ focusMode: profile.constraintFocusMode as ConstrainDOMString })
  }

  if (profile.constraintFocusDistance != null) {
    advanced.push({ focusDistance: profile.constraintFocusDistance })
  }

  try {
    await track.applyConstraints({ advanced })
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      id: `${Date.now()}-focus`,
      timestamp,
      requestedFocus: profile.constraintFocusMode ?? 'none',
      requestedZoom: settingsAfter.zoom,
      requestedFocusDistance: profile.constraintFocusDistance != null ? String(profile.constraintFocusDistance) : '—',
      success: true,
      errorName: '—',
      errorMessage: 'Constraint application: SUCCESS',
      actualFocus: settingsAfter.focusMode,
      actualZoom: settingsAfter.zoom,
      actualFocusDistance: settingsAfter.focusDistance,
    }
  } catch (error) {
    const serialized = serializeConstraintError(error)
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      id: `${Date.now()}-focus-error`,
      timestamp,
      requestedFocus: profile.constraintFocusMode ?? 'none',
      requestedZoom: settingsBefore.zoom,
      requestedFocusDistance: profile.constraintFocusDistance != null ? String(profile.constraintFocusDistance) : '—',
      success: false,
      errorName: serialized.name,
      errorMessage: serialized.message,
      actualFocus: settingsAfter.focusMode,
      actualZoom: settingsAfter.zoom,
      actualFocusDistance: settingsAfter.focusDistance,
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
      requestedFocus: settingsBefore.focusMode,
      requestedZoom: `${requestedZoom}`,
      requestedFocusDistance: settingsBefore.focusDistance,
      success: false,
      errorName: 'ZoomUnavailable',
      errorMessage: 'Zoom matériel non disponible sur ce track.',
      actualFocus: settingsBefore.focusMode,
      actualZoom: settingsBefore.zoom,
      actualFocusDistance: settingsBefore.focusDistance,
    }
  }

  try {
    await track.applyConstraints({ advanced: [{ zoom: clampedZoom }] })
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      id: `${Date.now()}-zoom`,
      timestamp,
      requestedFocus: settingsBefore.focusMode,
      requestedZoom: `${requestedZoom}`,
      requestedFocusDistance: settingsBefore.focusDistance,
      success: true,
      errorName: '—',
      errorMessage: 'Constraint application: SUCCESS',
      actualFocus: settingsAfter.focusMode,
      actualZoom: settingsAfter.zoom,
      actualFocusDistance: settingsAfter.focusDistance,
    }
  } catch (error) {
    const serialized = serializeConstraintError(error)
    const settingsAfter = readTrackSettingsDetails(track)

    return {
      id: `${Date.now()}-zoom-error`,
      timestamp,
      requestedFocus: settingsBefore.focusMode,
      requestedZoom: `${requestedZoom}`,
      requestedFocusDistance: settingsBefore.focusDistance,
      success: false,
      errorName: serialized.name,
      errorMessage: serialized.message,
      actualFocus: settingsAfter.focusMode,
      actualZoom: settingsAfter.zoom,
      actualFocusDistance: settingsAfter.focusDistance,
    }
  }
}

export function serializeConstraintError(error: unknown): {
  name: string
  message: string
} {
  if (error instanceof DOMException || error instanceof Error) {
    return {
      name: error.name || '—',
      message: error.message || '—',
    }
  }

  return {
    name: '—',
    message: String(error),
  }
}

export function buildComparisonTableRows(aggregates: ProfileAggregate[]): ComparisonTableRow[] {
  return aggregates.map((aggregate) => ({
    focusLabel: aggregate.focusLabel,
    zoomLabel: aggregate.zoomLabel,
    testSubject: aggregate.testSubject,
    correct: aggregate.correctReads,
    incorrect: aggregate.incorrectReads + aggregate.expectedFormatWrongValue + aggregate.unexpectedFormat,
    notFound: aggregate.notFound,
    errors: aggregate.errors,
    correctRate: computeCorrectRate(aggregate),
    incorrectRate: computeIncorrectRate(aggregate),
    averageSharpness: aggregate.averageSharpness != null ? String(Math.round(aggregate.averageSharpness)) : '—',
    enabled: aggregate.enabled,
  }))
}

export function summarizeIncorrectValues(history: ReadHistoryEntry[]): IncorrectValueSummary[] {
  const counts = new Map<string, number>()

  for (const entry of history) {
    if (
      entry.classification === 'INCORRECT_READ'
      || entry.classification === 'EXPECTED_FORMAT_BUT_WRONG_VALUE'
      || entry.classification === 'UNEXPECTED_FORMAT'
    ) {
      counts.set(entry.rawValue, (counts.get(entry.rawValue) ?? 0) + 1)
    }
  }

  return [...counts.entries()]
    .map(([value, count]) => ({ value, count }))
    .sort((left, right) => right.count - left.count)
    .slice(0, 10)
}

export function buildFocusSharpnessConclusion(options: {
  stats: SessionStats
  sharpness: SharpnessStats
  incorrectValues: IncorrectValueSummary[]
  aggregates: ProfileAggregate[]
}): string {
  const { stats, sharpness, incorrectValues, aggregates } = options
  const lines: string[] = []

  if (stats.attempts < 20) {
    lines.push('Données insuffisantes pour conclure (< 20 tentatives).')
    return lines.join('\n')
  }

  const best = aggregates
    .filter((item) => item.enabled && item.attempts >= 20)
    .sort((left, right) => right.correctReads / Math.max(left.attempts, 1) - left.correctReads / Math.max(right.attempts, 1))[0]

  if (incorrectValues.length > 0) {
    lines.push(
      'Cas D — Des lectures incorrectes apparaissent.',
      'Il faudra être particulièrement prudent avant d\'intégrer une stratégie de détection plus agressive dans le scanner principal.',
      '',
    )
  }

  if (best && best.correctReads > 0 && stats.correctReads > 0) {
    lines.push(
      'Cas A — Le zoom/focus testé semble améliorer simultanément la netteté et le taux de lectures correctes dans certaines configurations.',
      `Meilleure configuration observée : ${best.focusLabel} + ${best.zoomLabel}.`,
      'Cette configuration semble améliorer la détectabilité dans cette session.',
      '',
    )
  } else if (sharpness.max != null && sharpness.average != null && stats.correctReads === 0) {
    lines.push(
      'Cas B — Le score de netteté varie mais le taux de lectures correctes ne progresse pas de manière évidente.',
      '',
    )
  } else if (sharpness.stability === 'Stable' && stats.correctReads === 0) {
    lines.push(
      'Cas C — Le focus semble relativement stable, mais les données ne suffisent pas à établir un gain de détection.',
      '',
    )
  } else {
    lines.push('Résultats mitigés — interprétation prudente recommandée.', '')
  }

  lines.push('Note : indicateurs empiriques — pas une preuve scientifique.')

  return lines.join('\n')
}

export function buildFocusSharpnessDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  supportedFormats: string[]
  detectorCreationNote: string | null
  requestedWidth: number
  requestedHeight: number
  trackSettings: TrackSettingsDetails
  trackDiagnostics: {
    trackState: string
    resolution: string
    frameRate: string
    facingMode: string
  }
  capabilities: TrackCapabilitiesDetails
  requestedControls: RequestedControls
  expectedBarcode: string
  testSubject: string
  stats: SessionStats
  sharpness: SharpnessStats
  correctReadAverageSharpness: number | null
  incorrectReadAverageSharpness: number | null
  notFoundAverageSharpness: number | null
  aggregates: ProfileAggregate[]
  history: ReadHistoryEntry[]
  constraintLog: ConstraintLogEntry[]
  incorrectValues: IncorrectValueSummary[]
  conclusion: string
}): string {
  const lines: string[] = [
    '=== BARCODE DETECTOR FOCUS + SHARPNESS DIAGNOSTIC ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    '',
    'BarcodeDetector:',
    `Available: ${options.environment.barcodeDetectorAvailable ? 'YES' : 'NO'}`,
    `Formats: ${options.supportedFormats.length > 0 ? options.supportedFormats.join(', ') : 'default constructor'}`,
  ]

  if (options.detectorCreationNote) {
    lines.push(`Creation note: ${options.detectorCreationNote}`)
  }

  lines.push(
    '',
    'CAMERA',
    `Requested resolution: ${options.requestedWidth}×${options.requestedHeight}`,
    `Actual resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    `Track state: ${options.trackDiagnostics.trackState}`,
    '',
    'CAPABILITIES',
    `Focus mode: ${options.capabilities.focus.modes.length > 0 ? 'supported' : 'unavailable'}`,
    `Focus modes: ${options.capabilities.focus.modes.join(', ') || '—'}`,
    `Focus distance: ${options.capabilities.focus.distance.supported ? `${options.capabilities.focus.distance.min}..${options.capabilities.focus.distance.max}` : 'unavailable'}`,
    `Zoom: ${options.capabilities.zoom.supported ? 'supported' : 'unavailable'}`,
    `Zoom min: ${options.capabilities.zoom.min ?? '—'}`,
    `Zoom max: ${options.capabilities.zoom.max ?? '—'}`,
    `Zoom step: ${options.capabilities.zoom.step ?? '—'}`,
    '',
    'CURRENT SETTINGS',
    `Focus: ${options.trackSettings.focusMode}`,
    `Zoom: ${options.trackSettings.zoom}`,
    `Width: ${options.trackSettings.width ?? '—'}`,
    `Height: ${options.trackSettings.height ?? '—'}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    'REQUESTED',
    `Focus: ${options.requestedControls.focus}`,
    `Zoom: ${options.requestedControls.zoom}`,
    `Focus distance: ${options.requestedControls.focusDistance}`,
    '',
    'TEST',
    `Expected barcode: ${options.expectedBarcode || '—'}`,
    `Subject: ${options.testSubject}`,
    `Duration: ${Math.round(options.stats.testDurationMs / 1000)} s`,
    '',
    'RESULTS',
    `Attempts: ${options.stats.attempts}`,
    `Correct: ${options.stats.correctReads}`,
    `Incorrect: ${options.stats.incorrectReads + options.stats.expectedFormatWrongValue + options.stats.unexpectedFormat}`,
    `Not found: ${options.stats.notFound}`,
    `Errors: ${options.stats.errors}`,
    `Correct rate: ${computeCorrectRate(options.stats)}`,
    `Incorrect rate: ${computeIncorrectRate(options.stats)}`,
    `Not found rate: ${computeNotFoundRate(options.stats)}`,
    '',
    'SHARPNESS',
    `Average: ${options.sharpness.average ?? '—'}`,
    `Min: ${options.sharpness.min ?? '—'}`,
    `Max: ${options.sharpness.max ?? '—'}`,
    `Stability: ${options.sharpness.stability}`,
    '',
    'CORRECT READS',
    `Average sharpness: ${options.correctReadAverageSharpness ?? '—'}`,
    `Average detection: ${formatDurationMs(options.stats.averageDetectionMs)}`,
    '',
    'INCORRECT READS',
    `Average sharpness: ${options.incorrectReadAverageSharpness ?? '—'}`,
    `Values: ${options.incorrectValues.map((item) => `${item.value} (${item.count})`).join(', ') || '—'}`,
    '',
    'NOT FOUND',
    `Average sharpness: ${options.notFoundAverageSharpness ?? '—'}`,
    '',
    'CONFIGURATION COMPARISON',
    '',
  )

  for (const row of buildComparisonTableRows(options.aggregates)) {
    if (!row.enabled) {
      continue
    }

    lines.push(
      `${row.focusLabel} + ${row.zoomLabel} (${row.testSubject})`,
      `Correct: ${row.correct}`,
      `Incorrect: ${row.incorrect}`,
      `Not found: ${row.notFound}`,
      `Correct rate: ${row.correctRate}`,
      `Sharpness: ${row.averageSharpness}`,
      '',
    )
  }

  if (options.constraintLog.length > 0) {
    lines.push('CONSTRAINT LOG', '')

    for (const entry of options.constraintLog.slice(0, MAX_CONSTRAINT_LOG)) {
      lines.push(
        `${entry.timestamp} — ${entry.success ? 'SUCCESS' : 'FAILED'}`,
        `Requested focus: ${entry.requestedFocus}`,
        `Actual focus: ${entry.actualFocus}`,
        `Requested zoom: ${entry.requestedZoom}`,
        `Actual zoom: ${entry.actualZoom}`,
        entry.success ? '' : `Error: ${entry.errorName} — ${entry.errorMessage}`,
        '',
      )
    }
  }

  if (options.history.length > 0) {
    lines.push('HISTORY', '')

    for (const entry of options.history.slice(0, MAX_READ_HISTORY)) {
      lines.push(
        `${entry.timestamp} — ${entry.focusLabel} ${entry.zoomLabel} — ${entry.rawValue || '—'} — ${entry.format} — ${entry.classification} — ${formatDurationMs(entry.durationMs)} — Sharpness: ${entry.sharpness ?? '—'}`,
      )
    }

    lines.push('')
  }

  lines.push('CONCLUSION', '', options.conclusion)

  return lines.join('\n')
}

export { formatNativeBarcodeFormat, pickBestNativeBarcode }

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}

