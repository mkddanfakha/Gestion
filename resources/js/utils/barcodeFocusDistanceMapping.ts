import { getEnvironmentDiagnostics, type EnvironmentDiagnostics } from '@/utils/nativeBarcodeDetectorLiveTest'

export const STABILIZATION_MS = 1000
export const MAPPING_PERCENTAGES = [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1] as const
export const OPTIONAL_ZOOM_LEVELS = [1, 2, 4, 8] as const

export const FIXED_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
    frameRate: { ideal: 30 },
  },
  audio: false,
}

export type MappingStatus = 'MATCH' | 'MISMATCH' | 'UNKNOWN' | 'APPLY_ERROR'
export type OverallMappingStatus = 'VALID' | 'INVALID' | 'ERROR' | 'UNKNOWN'
export type ApplyStatus = 'SUCCESS' | 'ERROR'

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
  raw: Record<string, unknown>
}

export interface TrackSettingsSnapshot {
  width: number | null
  height: number | null
  frameRate: string
  facingMode: string
  focusMode: string
  focusDistance: string
  zoom: string
  raw: MediaTrackSettings & Record<string, unknown>
}

export interface MappingMeasurement {
  id: string
  label: string
  requestedFocusDistance: number
  actualFocusDistance: number | null
  difference: number | null
  tolerance: number
  requestedFocusMode: string
  actualFocusMode: string
  focusModeStatus: MappingStatus
  focusDistanceStatus: MappingStatus
  zoomStatus: MappingStatus
  overallStatus: OverallMappingStatus
  applyStatus: ApplyStatus
  requestedZoom: number
  actualZoom: string
  applyErrorName: string | null
  applyErrorMessage: string | null
  constraintsJson: string
  timestamp: string
}

export interface MappingSummary {
  total: number
  match: number
  mismatch: number
  applyError: number
  unknown: number
  matchRate: string
}

export interface MappingGraphPoint {
  requested: number
  actual: number
  status: MappingStatus
}

export { getEnvironmentDiagnostics, type EnvironmentDiagnostics }

function readNumeric(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function readTrackCapabilitiesSnapshot(track: MediaStreamTrack | null): TrackCapabilitiesSnapshot {
  const raw = (track?.getCapabilities?.() ?? {}) as Record<string, unknown>

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
    raw,
  }
}

export function readTrackSettingsSnapshot(track: MediaStreamTrack | null): TrackSettingsSnapshot {
  const raw = (track?.getSettings?.() ?? {}) as MediaTrackSettings & {
    zoom?: number
    focusMode?: string
    focusDistance?: number
  }

  return {
    width: raw.width ?? null,
    height: raw.height ?? null,
    frameRate: raw.frameRate != null ? String(raw.frameRate) : '—',
    facingMode: raw.facingMode ? String(raw.facingMode) : '—',
    focusMode: raw.focusMode != null ? String(raw.focusMode) : '—',
    focusDistance: raw.focusDistance != null ? String(raw.focusDistance) : '—',
    zoom: raw.zoom != null ? String(raw.zoom) : '—',
    raw,
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

export function computeFocusTolerance(step: number | null): number {
  if (step == null || step <= 0) {
    return 0.01
  }

  return Math.max(step * 1.5, 0.01)
}

export function buildRequestedFocusDistanceValues(capabilities: FocusDistanceCapabilities): number[] {
  const { min, max, step } = capabilities

  if (!capabilities.supported || min == null || max == null) {
    return []
  }

  const range = max - min
  const labels = ['MIN', '10%', '20%', '30%', '40%', '50%', '60%', '70%', '80%', '90%', 'MAX']

  return MAPPING_PERCENTAGES.map((percentage, index) => {
    const value = index === 0
      ? min
      : index === MAPPING_PERCENTAGES.length - 1
        ? max
        : min + range * percentage

    return roundToStep(value, min, max, step)
  }).filter((value, index, array) => array.indexOf(value) === index)
}

export function buildRequestedFocusDistanceLabels(values: number[], capabilities: FocusDistanceCapabilities): string[] {
  const { min, max } = capabilities
  const labelByValue = new Map<number, string>()

  if (min != null) {
    labelByValue.set(min, 'MIN')
  }

  if (max != null) {
    labelByValue.set(max, 'MAX')
  }

  MAPPING_PERCENTAGES.forEach((percentage) => {
    if (percentage > 0 && percentage < 1 && min != null && max != null) {
      const value = roundToStep(min + (max - min) * percentage, min, max, capabilities.step)
      labelByValue.set(value, `${Math.round(percentage * 100)}%`)
    }
  })

  return values.map((value) => labelByValue.get(value) ?? value.toFixed(4))
}

export function clampZoomValue(value: number, capabilities: ZoomCapabilities): number {
  if (!capabilities.supported || capabilities.min == null || capabilities.max == null) {
    return 1
  }

  return roundToStep(value, capabilities.min, capabilities.max, capabilities.step)
}

export function resolveAvailableZoomLevels(capabilities: ZoomCapabilities): number[] {
  if (!capabilities.supported || capabilities.max == null) {
    return [1]
  }

  return OPTIONAL_ZOOM_LEVELS.filter((level) => level <= capabilities.max!)
}

export function buildFocusMappingConstraints(options: {
  focusDistance: number
  zoom?: number | null
  useAdvancedArray?: boolean
}): MediaTrackConstraints {
  if (!Number.isFinite(options.focusDistance)) {
    throw new Error('Internal DEV error: focusDistance must be finite')
  }

  const constraintSet: MediaTrackConstraintSet = {
    focusMode: 'manual',
    focusDistance: options.focusDistance,
  }

  if (options.zoom != null && Number.isFinite(options.zoom)) {
    constraintSet.zoom = options.zoom
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

export function evaluateFocusModeStatus(requested: string, actual: string): MappingStatus {
  if (actual === '—') {
    return 'UNKNOWN'
  }

  return actual === requested ? 'MATCH' : 'MISMATCH'
}

export function evaluateFocusDistanceStatus(
  requested: number,
  actual: number | null,
  step: number | null,
): { status: MappingStatus; difference: number | null; tolerance: number } {
  const tolerance = computeFocusTolerance(step)

  if (actual == null || !Number.isFinite(actual)) {
    return { status: 'UNKNOWN', difference: null, tolerance }
  }

  const difference = actual - requested

  return {
    status: Math.abs(difference) <= tolerance ? 'MATCH' : 'MISMATCH',
    difference,
    tolerance,
  }
}

export function evaluateZoomStatus(
  requested: number,
  actual: string,
  step: number | null,
): MappingStatus {
  if (actual === '—') {
    return 'UNKNOWN'
  }

  const actualValue = Number.parseFloat(actual)

  if (!Number.isFinite(actualValue)) {
    return 'UNKNOWN'
  }

  const tolerance = step != null && step > 0 ? Math.max(step * 1.5, 0.05) : 0.05

  return Math.abs(actualValue - requested) <= tolerance ? 'MATCH' : 'MISMATCH'
}

export function computeOverallStatus(options: {
  applyStatus: ApplyStatus
  focusModeStatus: MappingStatus
  focusDistanceStatus: MappingStatus
}): OverallMappingStatus {
  if (options.applyStatus === 'ERROR') {
    return 'ERROR'
  }

  if (options.focusModeStatus === 'UNKNOWN' || options.focusDistanceStatus === 'UNKNOWN') {
    return 'UNKNOWN'
  }

  if (options.focusModeStatus === 'MATCH' && options.focusDistanceStatus === 'MATCH') {
    return 'VALID'
  }

  return 'INVALID'
}

export async function applyFocusDistance(
  track: MediaStreamTrack,
  options: {
    requestedFocusDistance: number
    requestedZoom: number
    zoomSupported: boolean
    focusDistanceStep: number | null
    zoomStep: number | null
    label: string
  },
): Promise<MappingMeasurement> {
  const timestamp = new Date().toLocaleTimeString('fr-FR')
  const requestedZoom = options.zoomSupported ? options.requestedZoom : 1

  let constraints = buildFocusMappingConstraints({
    focusDistance: options.requestedFocusDistance,
    zoom: options.zoomSupported ? requestedZoom : null,
  })

  assertConstraintsStructure(constraints)

  let constraintsJson = JSON.stringify(constraints, null, 2)
  let applyError: Error | null = null

  console.info('[DEV FOCUS MAPPING] Applying constraints', constraints)

  try {
    await track.applyConstraints(constraints)
  } catch (firstError) {
    const advancedConstraints = buildFocusMappingConstraints({
      focusDistance: options.requestedFocusDistance,
      zoom: options.zoomSupported ? requestedZoom : null,
      useAdvancedArray: true,
    })

    assertConstraintsStructure(advancedConstraints)

    try {
      console.info('[DEV FOCUS MAPPING] Retrying with advanced array', advancedConstraints)
      await track.applyConstraints(advancedConstraints)
      constraints = advancedConstraints
      constraintsJson = JSON.stringify(advancedConstraints, null, 2)
    } catch (secondError) {
      applyError = secondError instanceof Error ? secondError : new Error(String(secondError))
      console.error('[DEV FOCUS MAPPING] applyConstraints FAILED', applyError)
    }
  }

  await new Promise((resolve) => window.setTimeout(resolve, STABILIZATION_MS))

  const settings = readTrackSettingsSnapshot(track)
  const actualFocusDistance = settings.focusDistance === '—'
    ? null
    : Number.parseFloat(settings.focusDistance)

  const focusModeStatus = applyError
    ? 'APPLY_ERROR'
    : evaluateFocusModeStatus('manual', settings.focusMode)

  const distanceEvaluation = applyError
    ? { status: 'APPLY_ERROR' as MappingStatus, difference: null, tolerance: computeFocusTolerance(options.focusDistanceStep) }
    : evaluateFocusDistanceStatus(options.requestedFocusDistance, actualFocusDistance, options.focusDistanceStep)

  const zoomStatus = applyError
    ? 'APPLY_ERROR'
    : options.zoomSupported
      ? evaluateZoomStatus(requestedZoom, settings.zoom, options.zoomStep)
      : 'UNKNOWN'

  const applyStatus: ApplyStatus = applyError ? 'ERROR' : 'SUCCESS'
  const overallStatus = computeOverallStatus({
    applyStatus,
    focusModeStatus,
    focusDistanceStatus: distanceEvaluation.status,
  })

  return {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    label: options.label,
    requestedFocusDistance: options.requestedFocusDistance,
    actualFocusDistance,
    difference: distanceEvaluation.difference,
    tolerance: distanceEvaluation.tolerance,
    requestedFocusMode: 'manual',
    actualFocusMode: settings.focusMode,
    focusModeStatus,
    focusDistanceStatus: distanceEvaluation.status,
    zoomStatus,
    overallStatus,
    applyStatus,
    requestedZoom,
    actualZoom: settings.zoom,
    applyErrorName: applyError?.name ?? null,
    applyErrorMessage: applyError?.message ?? null,
    constraintsJson,
    timestamp,
  }
}

export function buildMappingSummary(measurements: MappingMeasurement[]): MappingSummary {
  const total = measurements.length
  const match = measurements.filter((item) => item.focusDistanceStatus === 'MATCH').length
  const mismatch = measurements.filter((item) => item.focusDistanceStatus === 'MISMATCH').length
  const applyError = measurements.filter((item) => item.focusDistanceStatus === 'APPLY_ERROR').length
  const unknown = measurements.filter((item) => item.focusDistanceStatus === 'UNKNOWN').length
  const evaluated = match + mismatch
  const matchRate = evaluated > 0 ? `${((match / evaluated) * 100).toFixed(1)}%` : '—'

  return { total, match, mismatch, applyError, unknown, matchRate }
}

export function buildMappingGraphPoints(measurements: MappingMeasurement[]): MappingGraphPoint[] {
  return measurements
    .filter((item) => item.actualFocusDistance != null && Number.isFinite(item.actualFocusDistance))
    .map((item) => ({
      requested: item.requestedFocusDistance,
      actual: item.actualFocusDistance!,
      status: item.focusDistanceStatus,
    }))
}

export function buildMappingConclusion(summary: MappingSummary): string {
  if (summary.total === 0) {
    return 'Experimental observation\n\nNo mapping data collected yet.'
  }

  const evaluated = summary.match + summary.mismatch

  if (evaluated === 0) {
    return 'Experimental observation\n\nNo valid focus distance measurements were recorded.'
  }

  const matchRatio = summary.match / evaluated

  if (matchRatio >= 0.85) {
    return [
      'FOCUS DISTANCE RESULT',
      '',
      'Strong agreement',
      '',
      'Most requested values were applied within tolerance.',
      `Match rate: ${summary.matchRate}`,
      '',
      'Experimental observation only.',
    ].join('\n')
  }

  if (matchRatio >= 0.4) {
    return [
      'FOCUS DISTANCE RESULT',
      '',
      'Partial agreement',
      '',
      'Some requested values were applied correctly,',
      'while others produced significantly different actual values.',
      `Match rate: ${summary.matchRate}`,
      '',
      'Experimental observation only.',
    ].join('\n')
  }

  return [
    'FOCUS DISTANCE RESULT',
    '',
    'Strong mismatch',
    '',
    'The camera exposes focusDistance but frequently applies',
    'values significantly different from the requested values.',
    `Match rate: ${summary.matchRate}`,
    '',
    'Experimental observation only.',
  ].join('\n')
}

export function clampCustomFocusDistance(
  value: number,
  capabilities: FocusDistanceCapabilities,
): number | null {
  if (!capabilities.supported || capabilities.min == null || capabilities.max == null || !Number.isFinite(value)) {
    return null
  }

  return roundToStep(value, capabilities.min, capabilities.max, capabilities.step)
}

export function buildMappingDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  mappingZoom: number
  stabilizationMs: number
  measurements: MappingMeasurement[]
  summary: MappingSummary
  conclusion: string
  mappingStoppedByUser: boolean
}): string {
  const lines = [
    '=== BARCODE DETECTOR FOCUS DISTANCE MAPPING ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    '',
    'Camera:',
    'Requested resolution: 1280×720',
    `Actual resolution: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `Facing: ${options.trackSettings.facingMode}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    'CAPABILITIES',
    `Focus modes: ${options.capabilities.focusModes.join(', ') || '—'}`,
    `Focus distance min: ${options.capabilities.focusDistance.min ?? '—'}`,
    `Focus distance max: ${options.capabilities.focusDistance.max ?? '—'}`,
    `Focus distance step: ${options.capabilities.focusDistance.step ?? '—'}`,
    `Zoom min: ${options.capabilities.zoom.min ?? '—'}`,
    `Zoom max: ${options.capabilities.zoom.max ?? '—'}`,
    `Zoom step: ${options.capabilities.zoom.step ?? '—'}`,
    '',
    'MAPPING CONFIGURATION',
    `Zoom: ${options.mappingZoom}×`,
    `Number of requested values: ${options.measurements.length}`,
    `Stabilization delay: ${options.stabilizationMs} ms`,
    options.mappingStoppedByUser ? 'Mapping stopped by user.' : '',
    '',
    'RESULTS',
    '',
  ]

  for (const item of options.measurements) {
    lines.push(
      `Requested: ${item.requestedFocusDistance}`,
      `Actual: ${item.actualFocusDistance ?? '—'}`,
      `Difference: ${item.difference ?? '—'}`,
      `Tolerance: ${item.tolerance}`,
      `Focus mode requested/actual: ${item.requestedFocusMode} / ${item.actualFocusMode} (${item.focusModeStatus})`,
      `Zoom requested/actual: ${item.requestedZoom}× / ${item.actualZoom}× (${item.zoomStatus})`,
      `Status: ${item.overallStatus} / focusDistance=${item.focusDistanceStatus}`,
      item.applyErrorName ? `applyConstraints error name: ${item.applyErrorName}` : '',
      item.applyErrorMessage ? `applyConstraints error message: ${item.applyErrorMessage}` : '',
      `Constraints: ${item.constraintsJson}`,
      '',
    )
  }

  lines.push(
    'SUMMARY',
    `Total: ${options.summary.total}`,
    `MATCH: ${options.summary.match}`,
    `MISMATCH: ${options.summary.mismatch}`,
    `APPLY_ERROR: ${options.summary.applyError}`,
    `UNKNOWN: ${options.summary.unknown}`,
    `Match rate: ${options.summary.matchRate}`,
    '',
    'CONCLUSION',
    options.conclusion,
  )

  return lines.filter((line, index, array) => !(line === '' && array[index - 1] === '')).join('\n')
}
