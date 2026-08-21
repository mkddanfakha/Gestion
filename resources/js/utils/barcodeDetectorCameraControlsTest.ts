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
  inferBrowserLabel,
  type EnvironmentDiagnostics,
} from '@/utils/nativeBarcodeDetectorLiveTest'

export const REFERENCE_EAN_VALUE = '6202312030117'
export const REFERENCE_EAN_FORMAT = 'EAN-13'
export const SMALL_EAN_TEST_LABEL = 'Small EAN-13'
export const STANDARD_EAN_TEST_LABEL = 'Standard EAN-13'
export const DETECTION_INTERVAL_MS = 150
export const CAMERA_SETTLING_MS = 750
export const MAX_SUCCESS_HISTORY = 20
export const MIN_ATTEMPTS_PRELIMINARY = 20
export const MIN_ATTEMPTS_SUFFICIENT = 50

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
export const ZOOM_LEVELS = [1, 2, 3, 4] as const

export type FocusProfileId = 'default' | 'continuous' | 'single-shot'
export type CodeTestCategory = 'small-ean' | 'standard-ean'
export type DataSufficiencyStatus = 'insufficient' | 'preliminary' | 'sufficient'

export interface FocusProfileDefinition {
  id: FocusProfileId
  label: string
  constraintValue: string | null
}

export const FOCUS_PROFILES: FocusProfileDefinition[] = [
  { id: 'default', label: 'DEFAULT', constraintValue: null },
  { id: 'continuous', label: 'CONTINUOUS', constraintValue: 'continuous' },
  { id: 'single-shot', label: 'SINGLE-SHOT', constraintValue: 'single-shot' },
]

export interface ZoomCapabilities {
  supported: boolean
  min: number | null
  max: number | null
  step: number | null
  label: string
}

export interface FocusCapabilities {
  supported: boolean
  modes: string[]
  label: string
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
  summary: string
}

export interface ConstraintApplicationResult {
  success: boolean
  name: string
  message: string
  constraint: string
  requestedFocus: string
  requestedZoom: string | null
  actualFocus: string
  actualZoom: string
}

export interface DetectionStats {
  detectionAttempts: number
  successfulDetections: number
  notFound: number
  errors: number
  lastDetectionMs: number | null
  averageDetectionMs: number | null
  minDetectionMs: number | null
  maxDetectionMs: number | null
  averageSuccessIntervalMs: number | null
  minSuccessIntervalMs: number | null
  maxSuccessIntervalMs: number | null
  testStartedAt: number | null
  testDurationMs: number
}

export interface ProfileCodeAggregate extends DetectionStats {
  focusProfileId: FocusProfileId
  focusLabel: string
  requestedFocusMode: string
  actualFocusMode: string
  requestedZoom: number | null
  actualZoom: string
  zoomLabel: string
  codeCategory: CodeTestCategory
  codeLabel: string
  enabled: boolean
  skipReason: string | null
}

export interface SuccessHistoryEntry {
  id: string
  timestamp: string
  focusLabel: string
  zoomLabel: string
  rawValue: string
  format: string
  durationMs: number
  codeCategory: CodeTestCategory
  boundingBox: string
  cornerPoints: string
}

export interface ComparisonTableRow {
  focusLabel: string
  zoomLabel: string
  actualZoom: string
  actualFocus: string
  codeLabel: string
  codeCategory: CodeTestCategory
  attempts: number
  success: number
  notFound: number
  errors: number
  successRate: string
  errorRate: string
  averageMs: string
  dataStatus: string
  enabled: boolean
}

export interface FocusZoomComparisonEntry {
  focusLabel: string
  zoomLabel: string
  smallSuccessRate: string
  standardSuccessRate: string
  differencePoints: string
  dataStatus: string
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

export function readTrackCapabilitiesDetails(track: MediaStreamTrack | null): TrackCapabilitiesDetails {
  const capabilities = track?.getCapabilities?.() as Record<string, unknown> | undefined

  if (!capabilities) {
    return {
      focus: { supported: false, modes: [], label: 'unavailable' },
      zoom: { supported: false, min: null, max: null, step: null, label: 'unavailable' },
      summary: '—',
    }
  }

  const focusModes = Array.isArray(capabilities.focusMode)
    ? capabilities.focusMode.map(String)
    : capabilities.focusMode != null
      ? [String(capabilities.focusMode)]
      : []

  const zoomCapability = capabilities.zoom
  const zoomSupported = zoomCapability != null && typeof zoomCapability === 'object'
  const zoomRecord = zoomSupported ? zoomCapability as { min?: number; max?: number; step?: number } : null

  const focus: FocusCapabilities = {
    supported: focusModes.length > 0,
    modes: focusModes,
    label: focusModes.length > 0 ? 'supported' : 'unavailable',
  }

  const zoom: ZoomCapabilities = {
    supported: zoomSupported,
    min: zoomRecord?.min ?? null,
    max: zoomRecord?.max ?? null,
    step: zoomRecord?.step ?? null,
    label: zoomSupported ? 'supported' : 'unavailable',
  }

  const summary = [
    focus.supported ? `focusMode=${focusModes.join('|')}` : 'focusMode=unavailable',
    zoom.supported ? `zoom=${zoom.min ?? '?'}..${zoom.max ?? '?'} step=${zoom.step ?? '?'}` : 'zoom=unavailable',
  ].join(', ')

  return { focus, zoom, summary }
}

export function readTrackSettingsDetails(track: MediaStreamTrack | null): TrackSettingsDetails {
  const settings = track?.getSettings?.()

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
      summary: '—',
    }
  }

  const width = settings.width ?? null
  const height = settings.height ?? null
  const aspectRatio = width && height ? (width / height).toFixed(2) : '—'
  const deviceId = settings.deviceId ? `${settings.deviceId.slice(0, 8)}…` : '—'
  const zoom = (settings as MediaTrackSettings & { zoom?: number }).zoom
  const focusMode = (settings as MediaTrackSettings & { focusMode?: string }).focusMode

  return {
    width,
    height,
    frameRate: settings.frameRate != null ? String(settings.frameRate) : '—',
    facingMode: settings.facingMode ? String(settings.facingMode) : '—',
    aspectRatio: String(aspectRatio),
    deviceId,
    zoom: zoom != null ? `${zoom}` : '—',
    focusMode: focusMode != null ? String(focusMode) : '—',
    summary: [
      width != null ? `width=${width}` : null,
      height != null ? `height=${height}` : null,
      settings.frameRate != null ? `frameRate=${settings.frameRate}` : null,
      focusMode != null ? `focusMode=${focusMode}` : null,
      zoom != null ? `zoom=${zoom}` : null,
    ].filter(Boolean).join(', ') || '—',
  }
}

export function readCameraTrackDiagnostics(stream: MediaStream | null): {
  trackCount: number
  videoTrackCount: number
  trackState: string
  facingMode: string
  resolution: string
  frameRate: string
} {
  const tracks = stream?.getTracks() ?? []
  const videoTracks = stream?.getVideoTracks() ?? []
  const track = videoTracks[0]
  const settings = track?.getSettings?.()

  return {
    trackCount: tracks.length,
    videoTrackCount: videoTracks.length,
    trackState: display(track?.readyState),
    facingMode: display(settings?.facingMode),
    resolution: settings?.width && settings?.height
      ? `${settings.width} × ${settings.height}`
      : '—',
    frameRate: settings?.frameRate ? `${settings.frameRate}` : '—',
  }
}

export function isFocusProfileSupported(
  profile: FocusProfileDefinition,
  capabilities: TrackCapabilitiesDetails,
): boolean {
  if (profile.id === 'default') {
    return true
  }

  if (!capabilities.focus.supported || !profile.constraintValue) {
    return false
  }

  return capabilities.focus.modes.includes(profile.constraintValue)
}

export function resolveZoomLevels(capabilities: TrackCapabilitiesDetails): number[] {
  if (!capabilities.zoom.supported || capabilities.zoom.max == null) {
    return [1]
  }

  return ZOOM_LEVELS.filter((level) => level <= capabilities.zoom.max!)
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

export function createEmptyDetectionStats(): DetectionStats {
  return {
    detectionAttempts: 0,
    successfulDetections: 0,
    notFound: 0,
    errors: 0,
    lastDetectionMs: null,
    averageDetectionMs: null,
    minDetectionMs: null,
    maxDetectionMs: null,
    averageSuccessIntervalMs: null,
    minSuccessIntervalMs: null,
    maxSuccessIntervalMs: null,
    testStartedAt: null,
    testDurationMs: 0,
  }
}

export function buildProfileAggregateKey(
  focusProfileId: FocusProfileId,
  zoom: number,
  codeCategory: CodeTestCategory,
): string {
  return `${focusProfileId}:${zoom}:${codeCategory}`
}

export function createInitialProfileAggregates(
  capabilities: TrackCapabilitiesDetails,
): ProfileCodeAggregate[] {
  const rows: ProfileCodeAggregate[] = []
  const zoomLevels = resolveZoomLevels(capabilities)

  for (const focusProfile of FOCUS_PROFILES) {
    const focusSupported = isFocusProfileSupported(focusProfile, capabilities)

    for (const zoom of zoomLevels) {
      for (const codeCategory of ['small-ean', 'standard-ean'] as const) {
        const enabled = focusSupported && (zoom === 1 || capabilities.zoom.supported)
        const skipReason = !focusSupported
          ? `Focus ${focusProfile.label} non supporté`
          : zoom > 1 && !capabilities.zoom.supported
            ? 'Zoom unavailable'
            : null

        rows.push({
          ...createEmptyDetectionStats(),
          focusProfileId: focusProfile.id,
          focusLabel: focusProfile.label,
          requestedFocusMode: focusProfile.constraintValue ?? 'default',
          actualFocusMode: '—',
          requestedZoom: zoom,
          actualZoom: '—',
          zoomLabel: `${zoom}×`,
          codeCategory,
          codeLabel: codeCategory === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL,
          enabled,
          skipReason,
        })
      }
    }
  }

  return rows
}

export async function applyFocusModeToTrack(
  track: MediaStreamTrack,
  profile: FocusProfileDefinition,
): Promise<ConstraintApplicationResult> {
  const settingsBefore = readTrackSettingsDetails(track)

  if (profile.id === 'default' || !profile.constraintValue) {
    return {
      success: true,
      name: '—',
      message: 'Aucune contrainte focus appliquée (default).',
      constraint: '—',
      requestedFocus: 'default',
      requestedZoom: null,
      actualFocus: settingsBefore.focusMode,
      actualZoom: settingsBefore.zoom,
    }
  }

  try {
    await track.applyConstraints({
      advanced: [{ focusMode: profile.constraintValue as ConstrainDOMString }],
    })

    const settingsAfter = readTrackSettingsDetails(track)

    return {
      success: true,
      name: '—',
      message: 'Constraint application: SUCCESS',
      constraint: `focusMode=${profile.constraintValue}`,
      requestedFocus: profile.constraintValue,
      requestedZoom: null,
      actualFocus: settingsAfter.focusMode,
      actualZoom: settingsAfter.zoom,
    }
  } catch (error) {
    const serialized = serializeConstraintError(error)

    return {
      success: false,
      name: serialized.name,
      message: serialized.message,
      constraint: serialized.constraint,
      requestedFocus: profile.constraintValue,
      requestedZoom: null,
      actualFocus: readTrackSettingsDetails(track).focusMode,
      actualZoom: readTrackSettingsDetails(track).zoom,
    }
  }
}

export async function applyZoomToTrack(
  track: MediaStreamTrack,
  requestedZoom: number,
  capabilities: TrackCapabilitiesDetails,
): Promise<ConstraintApplicationResult> {
  const clampedZoom = clampZoomValue(requestedZoom, capabilities)
  const settingsBefore = readTrackSettingsDetails(track)

  if (!capabilities.zoom.supported) {
    return {
      success: false,
      name: 'ZoomUnavailable',
      message: 'Zoom matériel non disponible sur ce track.',
      constraint: 'zoom',
      requestedFocus: settingsBefore.focusMode,
      requestedZoom: `${requestedZoom}`,
      actualFocus: settingsBefore.focusMode,
      actualZoom: settingsBefore.zoom,
    }
  }

  try {
    await track.applyConstraints({
      advanced: [{ zoom: clampedZoom }],
    })

    const settingsAfter = readTrackSettingsDetails(track)

    return {
      success: true,
      name: '—',
      message: 'Constraint application: SUCCESS',
      constraint: `zoom=${clampedZoom}`,
      requestedFocus: settingsBefore.focusMode,
      requestedZoom: `${requestedZoom}`,
      actualFocus: settingsAfter.focusMode,
      actualZoom: settingsAfter.zoom,
    }
  } catch (error) {
    const serialized = serializeConstraintError(error)

    return {
      success: false,
      name: serialized.name,
      message: serialized.message,
      constraint: serialized.constraint,
      requestedFocus: settingsBefore.focusMode,
      requestedZoom: `${requestedZoom}`,
      actualFocus: readTrackSettingsDetails(track).focusMode,
      actualZoom: readTrackSettingsDetails(track).zoom,
    }
  }
}

export async function applyCameraControlsProfile(
  track: MediaStreamTrack,
  focusProfile: FocusProfileDefinition,
  requestedZoom: number,
  capabilities: TrackCapabilitiesDetails,
): Promise<{
  focusResult: ConstraintApplicationResult
  zoomResult: ConstraintApplicationResult
  actualFocus: string
  actualZoom: string
  skippedZoom: boolean
  skipReason: string | null
}> {
  const focusResult = await applyFocusModeToTrack(track, focusProfile)

  const zoomSupported = capabilities.zoom.supported
  const maxZoom = capabilities.zoom.max
  const skippedZoom = requestedZoom > 1 && (!zoomSupported || (maxZoom != null && requestedZoom > maxZoom))

  let zoomResult: ConstraintApplicationResult

  if (skippedZoom) {
    zoomResult = {
      success: false,
      name: 'ZoomSkipped',
      message: maxZoom != null && requestedZoom > maxZoom
        ? `Requested zoom: ${requestedZoom}× — Actual supported max: ${maxZoom}× → Test skipped`
        : 'Zoom matériel non disponible.',
      constraint: 'zoom',
      requestedFocus: focusResult.actualFocus,
      requestedZoom: `${requestedZoom}`,
      actualFocus: focusResult.actualFocus,
      actualZoom: readTrackSettingsDetails(track).zoom,
    }
  } else {
    zoomResult = await applyZoomToTrack(track, requestedZoom, capabilities)
  }

  const settings = readTrackSettingsDetails(track)

  return {
    focusResult,
    zoomResult,
    actualFocus: settings.focusMode,
    actualZoom: settings.zoom,
    skippedZoom,
    skipReason: skippedZoom ? zoomResult.message : null,
  }
}

export function serializeConstraintError(error: unknown): {
  name: string
  message: string
  constraint: string
} {
  if (error instanceof DOMException || error instanceof Error) {
    const record = error as Error & { constraint?: string }

    return {
      name: record.name || '—',
      message: record.message || '—',
      constraint: record.constraint ? String(record.constraint) : '—',
    }
  }

  return {
    name: '—',
    message: String(error),
    constraint: '—',
  }
}

export function computeRate(numerator: number, denominator: number): string {
  if (denominator <= 0) {
    return '—'
  }

  const rate = Math.min(100, (numerator / denominator) * 100)

  return `${rate.toFixed(1)}%`
}

export function computeSuccessRate(stats: Pick<DetectionStats, 'detectionAttempts' | 'successfulDetections'>): string {
  return computeRate(stats.successfulDetections, stats.detectionAttempts)
}

export function computeErrorRate(stats: Pick<DetectionStats, 'detectionAttempts' | 'errors'>): string {
  return computeRate(stats.errors, stats.detectionAttempts)
}

export function getDataSufficiencyStatus(attempts: number): DataSufficiencyStatus {
  if (attempts < MIN_ATTEMPTS_PRELIMINARY) {
    return 'insufficient'
  }

  if (attempts < MIN_ATTEMPTS_SUFFICIENT) {
    return 'preliminary'
  }

  return 'sufficient'
}

export function formatDataSufficiencyStatus(attempts: number): string {
  const status = getDataSufficiencyStatus(attempts)

  if (status === 'insufficient') {
    return 'Data insufficient'
  }

  if (status === 'preliminary') {
    return 'Preliminary'
  }

  return 'Data sufficient'
}

export function formatDurationMs(value: number | null): string {
  if (value == null) {
    return '—'
  }

  return `${Math.round(value)} ms`
}

export function parseRatePercent(value: string): number | null {
  if (value === '—') {
    return null
  }

  const parsed = Number.parseFloat(value.replace('%', '').replace(',', '.'))

  return Number.isFinite(parsed) ? parsed : null
}

export function computeRateDifferencePoints(leftRate: number, rightRate: number): string {
  const diff = rightRate - leftRate
  const sign = diff > 0 ? '+' : ''

  return `${sign}${diff.toFixed(1)} pts`
}

export function formatDetectionDetails(barcode: DetectedBarcodeLike): {
  boundingBox: string
  cornerPoints: string
} {
  const record = barcode as DetectedBarcodeLike & {
    boundingBox?: { x: number; y: number; width: number; height: number }
    cornerPoints?: Array<{ x: number; y: number }>
  }

  const boundingBox = record.boundingBox
    ? `${Math.round(record.boundingBox.x)},${Math.round(record.boundingBox.y)} ${Math.round(record.boundingBox.width)}×${Math.round(record.boundingBox.height)}`
    : '—'

  const cornerPoints = Array.isArray(record.cornerPoints)
    ? record.cornerPoints.map((point) => `${Math.round(point.x)},${Math.round(point.y)}`).join(' | ')
    : '—'

  return { boundingBox, cornerPoints }
}

export function buildComparisonTableRows(aggregates: ProfileCodeAggregate[]): ComparisonTableRow[] {
  return aggregates.map((aggregate) => ({
    focusLabel: aggregate.focusLabel,
    zoomLabel: aggregate.zoomLabel,
    actualZoom: aggregate.actualZoom,
    actualFocus: aggregate.actualFocusMode,
    codeLabel: aggregate.codeLabel,
    codeCategory: aggregate.codeCategory,
    attempts: aggregate.detectionAttempts,
    success: aggregate.successfulDetections,
    notFound: aggregate.notFound,
    errors: aggregate.errors,
    successRate: computeSuccessRate(aggregate),
    errorRate: computeErrorRate(aggregate),
    averageMs: formatDurationMs(aggregate.averageDetectionMs),
    dataStatus: formatDataSufficiencyStatus(aggregate.detectionAttempts),
    enabled: aggregate.enabled,
  }))
}

export function buildFocusZoomComparisonEntries(aggregates: ProfileCodeAggregate[]): FocusZoomComparisonEntry[] {
  const uniqueKeys = new Map<string, { focusLabel: string; zoomLabel: string }>()

  for (const aggregate of aggregates) {
    uniqueKeys.set(`${aggregate.focusProfileId}:${aggregate.requestedZoom}`, {
      focusLabel: aggregate.focusLabel,
      zoomLabel: aggregate.zoomLabel,
    })
  }

  return [...uniqueKeys.entries()].map(([key]) => {
    const [focusProfileId, zoomValue] = key.split(':')
    const small = aggregates.find(
      (item) => item.focusProfileId === focusProfileId
        && `${item.requestedZoom}` === zoomValue
        && item.codeCategory === 'small-ean',
    )
    const standard = aggregates.find(
      (item) => item.focusProfileId === focusProfileId
        && `${item.requestedZoom}` === zoomValue
        && item.codeCategory === 'standard-ean',
    )
    const smallRate = small ? parseRatePercent(computeSuccessRate(small)) : null
    const standardRate = standard ? parseRatePercent(computeSuccessRate(standard)) : null
    const attempts = Math.max(small?.detectionAttempts ?? 0, standard?.detectionAttempts ?? 0)

    return {
      focusLabel: small?.focusLabel ?? standard?.focusLabel ?? focusProfileId,
      zoomLabel: small?.zoomLabel ?? standard?.zoomLabel ?? `${zoomValue}×`,
      smallSuccessRate: small ? computeSuccessRate(small) : '—',
      standardSuccessRate: standard ? computeSuccessRate(standard) : '—',
      differencePoints: smallRate != null && standardRate != null
        ? computeRateDifferencePoints(smallRate, standardRate)
        : '—',
      dataStatus: formatDataSufficiencyStatus(attempts),
    }
  })
}

export function buildCameraControlsConclusion(options: {
  aggregates: ProfileCodeAggregate[]
  capabilities: TrackCapabilitiesDetails
}): string {
  const { aggregates, capabilities } = options

  if (!capabilities.focus.supported && !capabilities.zoom.supported) {
    return [
      'NON TESTABLE',
      '',
      'Le navigateur/appareil n\'expose pas suffisamment de contrôles caméra pour effectuer cette comparaison.',
    ].join('\n')
  }

  const smallAggregates = aggregates.filter((item) => item.codeCategory === 'small-ean' && item.enabled)
  const sufficientSmall = smallAggregates.filter(
    (item) => getDataSufficiencyStatus(item.detectionAttempts) === 'sufficient',
  )

  if (sufficientSmall.length === 0) {
    return [
      'Données insuffisantes pour conclure.',
      '',
      `Au moins ${MIN_ATTEMPTS_SUFFICIENT} tentatives par configuration sont recommandées.`,
    ].join('\n')
  }

  const baseline = sufficientSmall.find((item) => item.focusProfileId === 'default' && item.requestedZoom === 1)
  const baselineRate = baseline ? parseRatePercent(computeSuccessRate(baseline)) ?? 0 : 0

  const best = sufficientSmall.reduce((currentBest, current) => {
    const currentRate = parseRatePercent(computeSuccessRate(current)) ?? 0
    const bestRate = parseRatePercent(computeSuccessRate(currentBest)) ?? 0

    return currentRate > bestRate ? current : currentBest
  })

  const bestRate = parseRatePercent(computeSuccessRate(best)) ?? 0
  const improvement = bestRate - baselineRate

  if (baselineRate === 0 && bestRate >= 10) {
    return [
      'INDICE FORT',
      '',
      'Le contrôle caméra réel semble améliorer la détectabilité du petit EAN-13.',
      'Un test d\'intégration dans le scanner principal pourrait être envisagé.',
      '',
      `Configuration observée : ${best.focusLabel} + ${best.zoomLabel} (${computeSuccessRate(best)}).`,
      'Cette configuration semble améliorer la détectabilité dans cette session.',
      '',
      'Note empirique — pas une preuve scientifique.',
    ].join('\n')
  }

  if (improvement > 0 && improvement < 10) {
    return [
      'INDICE FAIBLE',
      '',
      'Une amélioration est observée mais les données sont insuffisantes ou trop faibles pour justifier une modification du scanner principal.',
      '',
      `Meilleure configuration : ${best.focusLabel} + ${best.zoomLabel} (${computeSuccessRate(best)}).`,
    ].join('\n')
  }

  if (bestRate === 0) {
    return [
      'AUCUN GAIN OBSERVÉ',
      '',
      'Le focus/zoom matériel testé ne semble pas résoudre le problème du petit EAN-13 sur cet appareil.',
    ].join('\n')
  }

  return [
    'Résultats mitigés.',
    '',
    `Meilleure configuration observée : ${best.focusLabel} + ${best.zoomLabel} (${computeSuccessRate(best)}).`,
    'Cette configuration semble améliorer la détectabilité dans cette session.',
    '',
    'Indicateur empirique — pas une preuve scientifique.',
  ].join('\n')
}

export function buildCameraControlsDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  supportedFormats: string[]
  detectorCreationNote: string | null
  requestedWidth: number
  requestedHeight: number
  trackSettings: TrackSettingsDetails
  trackDiagnostics: ReturnType<typeof readCameraTrackDiagnostics>
  capabilities: TrackCapabilitiesDetails
  currentFocusRequested: string
  currentZoomRequested: string
  activeTestLabel: string
  activeProfileLabel: string
  sessionStats: DetectionStats
  aggregates: ProfileCodeAggregate[]
  successHistory: SuccessHistoryEntry[]
  lastConstraintResults: string[]
  conclusion: string
}): string {
  const lines: string[] = [
    '=== BARCODE DETECTOR CAMERA CONTROLS DIAGNOSTIC ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    `Platform: ${typeof navigator !== 'undefined' ? navigator.platform : '—'}`,
    '',
    `BarcodeDetector:`,
    `Available: ${options.environment.barcodeDetectorAvailable ? 'YES' : 'NO'}`,
    `Formats: ${options.supportedFormats.length > 0 ? options.supportedFormats.join(', ') : 'default constructor'}`,
  ]

  if (options.detectorCreationNote) {
    lines.push(`Creation note: ${options.detectorCreationNote}`)
  }

  lines.push(
    '',
    '=== CAMERA ===',
    '',
    `Requested resolution: ${options.requestedWidth}×${options.requestedHeight}`,
    `Actual resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    `Track state: ${options.trackDiagnostics.trackState}`,
    '',
    '=== CAPABILITIES ===',
    '',
    `Focus mode: ${options.capabilities.focus.label}`,
    `Focus modes: ${options.capabilities.focus.modes.length > 0 ? options.capabilities.focus.modes.join(', ') : '—'}`,
    `Zoom: ${options.capabilities.zoom.label}`,
    `Zoom min: ${options.capabilities.zoom.min ?? '—'}`,
    `Zoom max: ${options.capabilities.zoom.max ?? '—'}`,
    `Zoom step: ${options.capabilities.zoom.step ?? '—'}`,
    '',
    '=== CURRENT SETTINGS ===',
    '',
    `Focus mode: ${options.trackSettings.focusMode}`,
    `Zoom: ${options.trackSettings.zoom}`,
    `Width: ${options.trackSettings.width ?? '—'}`,
    `Height: ${options.trackSettings.height ?? '—'}`,
    `Frame rate: ${options.trackSettings.frameRate}`,
    '',
    '=== TEST PROFILE ===',
    '',
    `Test subject: ${options.activeTestLabel}`,
    `Focus: ${options.activeProfileLabel}`,
    `Focus requested: ${options.currentFocusRequested}`,
    `Zoom requested: ${options.currentZoomRequested}`,
    '',
    '=== RESULTS (session active) ===',
    '',
    `Attempts: ${options.sessionStats.detectionAttempts}`,
    `Success: ${options.sessionStats.successfulDetections}`,
    `Not found: ${options.sessionStats.notFound}`,
    `Errors: ${options.sessionStats.errors}`,
    `Success rate: ${computeSuccessRate(options.sessionStats)}`,
    `Average detection: ${formatDurationMs(options.sessionStats.averageDetectionMs)}`,
    `Min detection: ${formatDurationMs(options.sessionStats.minDetectionMs)}`,
    `Max detection: ${formatDurationMs(options.sessionStats.maxDetectionMs)}`,
    '',
    '=== COMPARISON ===',
    '',
  )

  for (const row of buildComparisonTableRows(options.aggregates)) {
    if (!row.enabled) {
      continue
    }

    lines.push(
      `${row.focusLabel} + ${row.zoomLabel} (${row.codeLabel})`,
      `Actual zoom: ${row.actualZoom}`,
      `Actual focus: ${row.actualFocus}`,
      `Attempts: ${row.attempts}`,
      `Success: ${row.success}`,
      `Not found: ${row.notFound}`,
      `Errors: ${row.errors}`,
      `Success rate: ${row.successRate}`,
      `Average detection: ${row.averageMs}`,
      `Data status: ${row.dataStatus}`,
      '',
    )
  }

  if (options.lastConstraintResults.length > 0) {
    lines.push('=== CONSTRAINT APPLICATION ===', '', ...options.lastConstraintResults, '')
  }

  if (options.successHistory.length > 0) {
    lines.push('=== SUCCESS HISTORY ===', '')

    for (const entry of options.successHistory) {
      lines.push(
        `${entry.timestamp} — ${entry.focusLabel} ${entry.zoomLabel} — ${entry.rawValue} — ${entry.format} — ${formatDurationMs(entry.durationMs)}`,
      )
    }

    lines.push('')
  }

  lines.push('=== CONCLUSION ===', '', options.conclusion)

  return lines.join('\n')
}

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}

export { formatNativeBarcodeFormat, inferBrowserLabel, pickBestNativeBarcode }
