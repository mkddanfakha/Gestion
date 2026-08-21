import {
  formatNativeBarcodeFormat,
  pickBestNativeBarcode,
  type BarcodeDetectorLike,
  type DetectedBarcodeLike,
} from '@/utils/nativeBarcodeScannerEngine'
import {
  computeAverageDetectionMs,
  getEnvironmentDiagnostics,
  readCameraTrackDiagnostics,
  type CameraTrackDiagnostics,
  type EnvironmentDiagnostics,
} from '@/utils/nativeBarcodeDetectorLiveTest'

export {
  createNativeBarcodeDetector,
  formatNativeBarcodeFormat,
  pickBestNativeBarcode,
  type BarcodeDetectorLike,
  type DetectedBarcodeLike,
} from '@/utils/nativeBarcodeScannerEngine'

export {
  computeAverageDetectionMs,
  getEnvironmentDiagnostics,
  readCameraTrackDiagnostics,
  type CameraTrackDiagnostics,
  type EnvironmentDiagnostics,
} from '@/utils/nativeBarcodeDetectorLiveTest'

export const REFERENCE_EAN_VALUE = '6202312030117'
export const REFERENCE_EAN_FORMAT = 'EAN-13'
export const SMALL_EAN_TEST_LABEL = 'Petit EAN-13'
export const STANDARD_EAN_TEST_LABEL = 'EAN-13 standard'
export const DETECTION_INTERVAL_MS = 150
export const MAX_SUCCESS_HISTORY = 20
export const MIN_ATTEMPTS_FOR_CONCLUSION = 100
export const MIN_TEST_DURATION_MS = 20_000
export const TARGET_ATTEMPTS = 200

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

export type RoiVariantId =
  | 'full'
  | 'center-80'
  | 'center-60'
  | 'center-40'
  | 'zoom-2x'
  | 'zoom-3x'

export type CodeTestCategory = 'small-ean' | 'standard-ean'

export interface RoiVariantDefinition {
  id: RoiVariantId
  label: string
  shortLabel: string
  zonePercent: number
  zoomFactor: number
  usesCanvas: boolean
}

export const ROI_VARIANTS: RoiVariantDefinition[] = [
  { id: 'full', label: 'FULL', shortLabel: 'FULL', zonePercent: 100, zoomFactor: 1, usesCanvas: false },
  { id: 'center-80', label: 'CENTER 80%', shortLabel: '80%', zonePercent: 80, zoomFactor: 1, usesCanvas: true },
  { id: 'center-60', label: 'CENTER 60%', shortLabel: '60%', zonePercent: 60, zoomFactor: 1, usesCanvas: true },
  { id: 'center-40', label: 'CENTER 40%', shortLabel: '40%', zonePercent: 40, zoomFactor: 1, usesCanvas: true },
  { id: 'zoom-2x', label: 'ZOOM 2×', shortLabel: '2×', zonePercent: 50, zoomFactor: 2, usesCanvas: true },
  { id: 'zoom-3x', label: 'ZOOM 3×', shortLabel: '3×', zonePercent: 33.33, zoomFactor: 3, usesCanvas: true },
]

export interface DetectionStats {
  framesSeen: number
  detectionAttempts: number
  successfulDetections: number
  notFound: number
  errors: number
  lastDetectionMs: number | null
  averageDetectionMs: number | null
  minDetectionMs: number | null
  maxDetectionMs: number | null
  testStartedAt: number | null
  testDurationMs: number
}

export interface VariantCodeAggregate extends DetectionStats {
  variantId: RoiVariantId
  variantLabel: string
  zoneLabel: string
  zoomLabel: string
  codeCategory: CodeTestCategory
  codeLabel: string
}

export interface SuccessHistoryEntry {
  id: string
  timestamp: string
  variantLabel: string
  rawValue: string
  format: string
  durationMs: number
  codeCategory: CodeTestCategory
}

export interface ActualTrackDetails {
  width: number | null
  height: number | null
  facingMode: string
  frameRate: string
  aspectRatio: string
  deviceId: string
  summary: string
}

export interface TrackCapabilitiesSummary {
  focusMode: string
  zoom: string
  torch: string
  summary: string
}

export interface OverlayRectPercent {
  left: number
  top: number
  width: number
  height: number
}

export interface CropRect {
  sx: number
  sy: number
  sw: number
  sh: number
  dw: number
  dh: number
}

export interface AnalyzedSourceMeta {
  sourceLabel: string
  width: number
  height: number
  zoomLabel: string
}

export interface ComparisonTableRow {
  variantLabel: string
  zoneLabel: string
  zoomLabel: string
  codeLabel: string
  codeCategory: CodeTestCategory
  attempts: number
  success: number
  notFound: number
  errors: number
  successRate: string
  averageMs: string
  minMs: string
  maxMs: string
  dataSufficient: boolean
}

export interface VariantComparisonEntry {
  variantLabel: string
  smallSuccessRate: string
  standardSuccessRate: string
  differencePoints: string
  dataSufficient: boolean
}

export function getVariantDefinition(variantId: RoiVariantId): RoiVariantDefinition {
  const variant = ROI_VARIANTS.find((item) => item.id === variantId)

  if (!variant) {
    throw new Error(`Variante inconnue: ${variantId}`)
  }

  return variant
}

export function createEmptyDetectionStats(): DetectionStats {
  return {
    framesSeen: 0,
    detectionAttempts: 0,
    successfulDetections: 0,
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

export function createEmptyVariantAggregate(
  variant: RoiVariantDefinition,
  codeCategory: CodeTestCategory,
): VariantCodeAggregate {
  return {
    ...createEmptyDetectionStats(),
    variantId: variant.id,
    variantLabel: variant.label,
    zoneLabel: `${variant.zonePercent}%`,
    zoomLabel: `${variant.zoomFactor}×`,
    codeCategory,
    codeLabel: codeCategory === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL,
  }
}

export function createInitialVariantAggregates(): VariantCodeAggregate[] {
  const rows: VariantCodeAggregate[] = []

  for (const variant of ROI_VARIANTS) {
    rows.push(createEmptyVariantAggregate(variant, 'small-ean'))
    rows.push(createEmptyVariantAggregate(variant, 'standard-ean'))
  }

  return rows
}

export function readActualTrackDetails(stream: MediaStream | null): ActualTrackDetails {
  const track = stream?.getVideoTracks()[0]
  const settings = track?.getSettings?.()

  if (!settings) {
    return {
      width: null,
      height: null,
      facingMode: '—',
      frameRate: '—',
      aspectRatio: '—',
      deviceId: '—',
      summary: '—',
    }
  }

  const width = settings.width ?? null
  const height = settings.height ?? null
  const aspectRatio = width && height ? (width / height).toFixed(2) : '—'
  const deviceId = settings.deviceId ? `${settings.deviceId.slice(0, 8)}…` : '—'

  const summary = [
    width != null ? `width=${width}` : null,
    height != null ? `height=${height}` : null,
    settings.frameRate != null ? `frameRate=${settings.frameRate}` : null,
    settings.facingMode != null ? `facingMode=${settings.facingMode}` : null,
  ].filter(Boolean).join(', ')

  return {
    width,
    height,
    facingMode: settings.facingMode ? String(settings.facingMode) : '—',
    frameRate: settings.frameRate != null ? String(settings.frameRate) : '—',
    aspectRatio: String(aspectRatio),
    deviceId,
    summary: summary || '—',
  }
}

export function readTrackCapabilitiesSummary(stream: MediaStream | null): TrackCapabilitiesSummary {
  const track = stream?.getVideoTracks()[0]
  const capabilities = track?.getCapabilities?.() as Record<string, unknown> | undefined

  if (!capabilities) {
    return {
      focusMode: '—',
      zoom: '—',
      torch: '—',
      summary: '—',
    }
  }

  const focusMode = Array.isArray(capabilities.focusMode)
    ? capabilities.focusMode.join(', ')
    : capabilities.focusMode != null
      ? String(capabilities.focusMode)
      : '—'

  const zoom = capabilities.zoom != null
    ? typeof capabilities.zoom === 'object'
      ? JSON.stringify(capabilities.zoom)
      : String(capabilities.zoom)
    : '—'

  const torch = capabilities.torch != null ? String(capabilities.torch) : '—'

  return {
    focusMode,
    zoom,
    torch,
    summary: [
      focusMode !== '—' ? `focusMode=${focusMode}` : null,
      zoom !== '—' ? `zoom=${zoom}` : null,
      torch !== '—' ? `torch=${torch}` : null,
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

export function computeOverlayRectPercent(variant: RoiVariantDefinition): OverlayRectPercent {
  const zone = variant.zonePercent / 100
  const width = zone * 100
  const height = zone * 100
  const left = (100 - width) / 2
  const top = (100 - height) / 2

  return { left, top, width, height }
}

export function computeCropRect(
  videoWidth: number,
  videoHeight: number,
  variant: RoiVariantDefinition,
): CropRect {
  const zone = variant.zonePercent / 100
  const sw = Math.max(1, Math.round(videoWidth * zone))
  const sh = Math.max(1, Math.round(videoHeight * zone))
  const sx = Math.max(0, Math.round((videoWidth - sw) / 2))
  const sy = Math.max(0, Math.round((videoHeight - sh) / 2))
  const dw = Math.max(1, Math.round(sw * variant.zoomFactor))
  const dh = Math.max(1, Math.round(sh * variant.zoomFactor))

  return { sx, sy, sw, sh, dw, dh }
}

export function renderRoiToCanvas(
  video: HTMLVideoElement,
  canvas: HTMLCanvasElement,
  variant: RoiVariantDefinition,
): AnalyzedSourceMeta {
  const crop = computeCropRect(video.videoWidth, video.videoHeight, variant)
  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Impossible d\'obtenir le contexte 2D du canvas.')
  }

  canvas.width = crop.dw
  canvas.height = crop.dh
  context.drawImage(video, crop.sx, crop.sy, crop.sw, crop.sh, 0, 0, crop.dw, crop.dh)

  return {
    sourceLabel: variant.label,
    width: crop.dw,
    height: crop.dh,
    zoomLabel: `${variant.zoomFactor}×`,
  }
}

export function computeRate(numerator: number, denominator: number): string {
  if (denominator <= 0) {
    return '—'
  }

  return `${((numerator / denominator) * 100).toFixed(1)}%`
}

export function computeSuccessRate(stats: Pick<DetectionStats, 'detectionAttempts' | 'successfulDetections'>): string {
  return computeRate(stats.successfulDetections, stats.detectionAttempts)
}

export function computeNotFoundRate(stats: Pick<DetectionStats, 'detectionAttempts' | 'notFound'>): string {
  return computeRate(stats.notFound, stats.detectionAttempts)
}

export function computeErrorRate(stats: Pick<DetectionStats, 'detectionAttempts' | 'errors'>): string {
  return computeRate(stats.errors, stats.detectionAttempts)
}

export function hasSufficientTestData(stats: Pick<DetectionStats, 'detectionAttempts' | 'testDurationMs'>): boolean {
  return stats.detectionAttempts >= MIN_ATTEMPTS_FOR_CONCLUSION || stats.testDurationMs >= MIN_TEST_DURATION_MS
}

export function formatDataSufficient(stats: Pick<DetectionStats, 'detectionAttempts' | 'testDurationMs'>): string {
  return hasSufficientTestData(stats) ? 'YES' : 'NO'
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

export function describeImprovementMagnitude(differencePoints: number): string {
  const absolute = Math.abs(differencePoints)

  if (absolute < 10) {
    return 'différence faible / non concluante'
  }

  if (absolute <= 25) {
    return 'différence notable'
  }

  return 'différence importante'
}

export function formatDurationMs(value: number | null): string {
  if (value == null) {
    return '—'
  }

  return `${Math.round(value)} ms`
}

export function buildComparisonTableRows(aggregates: VariantCodeAggregate[]): ComparisonTableRow[] {
  return aggregates.map((aggregate) => ({
    variantLabel: aggregate.variantLabel,
    zoneLabel: aggregate.zoneLabel,
    zoomLabel: aggregate.zoomLabel,
    codeLabel: aggregate.codeLabel,
    codeCategory: aggregate.codeCategory,
    attempts: aggregate.detectionAttempts,
    success: aggregate.successfulDetections,
    notFound: aggregate.notFound,
    errors: aggregate.errors,
    successRate: computeSuccessRate(aggregate),
    averageMs: formatDurationMs(aggregate.averageDetectionMs),
    minMs: formatDurationMs(aggregate.minDetectionMs),
    maxMs: formatDurationMs(aggregate.maxDetectionMs),
    dataSufficient: hasSufficientTestData(aggregate),
  }))
}

export function buildVariantComparisonEntries(aggregates: VariantCodeAggregate[]): VariantComparisonEntry[] {
  return ROI_VARIANTS.map((variant) => {
    const small = aggregates.find((item) => item.variantId === variant.id && item.codeCategory === 'small-ean')
    const standard = aggregates.find((item) => item.variantId === variant.id && item.codeCategory === 'standard-ean')
    const smallRate = small ? parseRatePercent(computeSuccessRate(small)) : null
    const standardRate = standard ? parseRatePercent(computeSuccessRate(standard)) : null

    return {
      variantLabel: variant.label,
      smallSuccessRate: small ? computeSuccessRate(small) : '—',
      standardSuccessRate: standard ? computeSuccessRate(standard) : '—',
      differencePoints: smallRate != null && standardRate != null
        ? computeRateDifferencePoints(smallRate, standardRate)
        : '—',
      dataSufficient: Boolean(small && standard && hasSufficientTestData(small) && hasSufficientTestData(standard)),
    }
  })
}

function formatAggregateBlock(aggregate: VariantCodeAggregate | undefined, prefix: string): string[] {
  if (!aggregate) {
    return [`${prefix}:`, 'Attempts: —', 'Success: —', 'Rate: —']
  }

  return [
    `${prefix}:`,
    `Attempts: ${aggregate.detectionAttempts}`,
    `Success: ${aggregate.successfulDetections}`,
    `Not found: ${aggregate.notFound}`,
    `Errors: ${aggregate.errors}`,
    `Success rate: ${computeSuccessRate(aggregate)}`,
    `Average detection: ${formatDurationMs(aggregate.averageDetectionMs)}`,
    `Min: ${formatDurationMs(aggregate.minDetectionMs)}`,
    `Max: ${formatDurationMs(aggregate.maxDetectionMs)}`,
    `Data sufficient: ${formatDataSufficient(aggregate)}`,
  ]
}

export function buildRoiConclusion(aggregates: VariantCodeAggregate[]): string {
  const smallAggregates = ROI_VARIANTS.map((variant) =>
    aggregates.find((item) => item.variantId === variant.id && item.codeCategory === 'small-ean'),
  ).filter(Boolean) as VariantCodeAggregate[]

  const standardAggregates = ROI_VARIANTS.map((variant) =>
    aggregates.find((item) => item.variantId === variant.id && item.codeCategory === 'standard-ean'),
  ).filter(Boolean) as VariantCodeAggregate[]

  const sufficientSmall = smallAggregates.filter(hasSufficientTestData)
  const sufficientStandard = standardAggregates.filter(hasSufficientTestData)

  if (sufficientSmall.length === 0) {
    return [
      'Données insuffisantes pour conclure.',
      '',
      `Chaque variante doit atteindre au moins ${MIN_ATTEMPTS_FOR_CONCLUSION} tentatives`,
      `ou ${MIN_TEST_DURATION_MS / 1000} secondes de test.`,
    ].join('\n')
  }

  const fullSmall = sufficientSmall.find((item) => item.variantId === 'full')
  const fullRate = fullSmall ? parseRatePercent(computeSuccessRate(fullSmall)) ?? 0 : 0

  const bestSmall = sufficientSmall.reduce((best, current) => {
    const currentRate = parseRatePercent(computeSuccessRate(current)) ?? 0
    const bestRate = parseRatePercent(computeSuccessRate(best)) ?? 0

    return currentRate > bestRate ? current : best
  })

  const bestSmallRate = parseRatePercent(computeSuccessRate(bestSmall)) ?? 0
  const improvement = bestSmallRate - fullRate

  const standardFull = sufficientStandard.find((item) => item.variantId === 'full')
  const standardFullRate = standardFull ? parseRatePercent(computeSuccessRate(standardFull)) ?? 0 : null

  const allSmallZero = sufficientSmall.every((item) => (parseRatePercent(computeSuccessRate(item)) ?? 0) === 0)

  const lines: string[] = []

  if (fullRate === 0 && bestSmallRate > 0 && improvement >= 10) {
    lines.push(
      'Le BarcodeDetector semble mieux fonctionner lorsque',
      'le code occupe une portion plus importante de l\'image.',
      '',
      'Cela suggère que la taille apparente du code dans le flux',
      'pourrait être un facteur important.',
      '',
      `Meilleure variante observée (petit EAN) : ${bestSmall.variantLabel} (${computeSuccessRate(bestSmall)}).`,
      `Amélioration vs FULL : ${improvement.toFixed(1)} pts (${describeImprovementMagnitude(improvement)}).`,
      '',
      'Note : le zoom numérique améliore la détectabilité apparente,',
      'pas la qualité réelle de l\'image.',
    )
  } else if (allSmallZero) {
    lines.push(
      'Le recadrage et l\'agrandissement ne semblent pas résoudre',
      'le problème.',
      '',
      'Il faudra probablement examiner :',
      '- autofocus',
      '- netteté',
      '- distance',
      '- éclairage',
      '- qualité d\'impression',
      '- orientation',
      '- caractéristiques physiques du code.',
    )
  } else if (standardFullRate != null && standardFullRate >= 50 && allSmallZero) {
    lines.push(
      'Le problème semble spécifique au petit EAN-13 plutôt qu\'au',
      'fonctionnement général de BarcodeDetector.',
    )
  }

  const center40 = sufficientSmall.find((item) => item.variantId === 'center-40')
  const zoom2x = sufficientSmall.find((item) => item.variantId === 'zoom-2x')
  const center40Rate = center40 ? parseRatePercent(computeSuccessRate(center40)) ?? 0 : 0
  const zoom2xRate = zoom2x ? parseRatePercent(computeSuccessRate(zoom2x)) ?? 0 : 0

  if ((center40Rate >= 10 || zoom2xRate >= 10) && (center40Rate - fullRate >= 10 || zoom2xRate - fullRate >= 10)) {
    lines.push(
      '',
      'Une stratégie de zone d\'intérêt pourrait être étudiée',
      'pour le scanner principal.',
      '',
      'Aucune modification du scanner principal ne doit cependant',
      'être effectuée automatiquement.',
    )
  }

  if (lines.length === 0) {
    lines.push(
      'Les résultats ne montrent pas d\'amélioration nette via ROI/zoom',
      'par rapport au flux complet.',
      '',
      'Indicateur empirique — pas une preuve scientifique.',
    )
  }

  lines.push('', `Seuils empiriques : < 10 pts faible, 10–25 pts notable, > 25 pts important.`)

  return lines.join('\n')
}

export function buildRoiDiagnosticClipboard(options: {
  environment: EnvironmentDiagnostics
  supportedFormats: string[]
  requestedWidth: number
  requestedHeight: number
  actualTrack: ActualTrackDetails
  trackDiagnostics: CameraTrackDiagnostics
  trackCapabilities: TrackCapabilitiesSummary
  videoOrientation: string
  aggregates: VariantCodeAggregate[]
  conclusion: string
}): string {
  const lines: string[] = [
    '=== BARCODE DETECTOR ROI DIAGNOSTIC ===',
    '',
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    `Secure context: ${options.environment.secureContext ? 'yes' : 'no'}`,
    '',
    `BarcodeDetector: ${options.environment.barcodeDetectorAvailable ? 'available' : 'unavailable'}`,
    `Formats: ${options.supportedFormats.length > 0 ? options.supportedFormats.join(', ') : 'default constructor'}`,
    '',
    '=== CAMERA ===',
    '',
    `Requested: ${options.requestedWidth}×${options.requestedHeight}`,
    `Actual: ${options.actualTrack.width ?? '—'}×${options.actualTrack.height ?? '—'}`,
    `Facing: ${options.actualTrack.facingMode}`,
    `FPS: ${options.actualTrack.frameRate}`,
    `Video orientation: ${options.videoOrientation}`,
    `Track state: ${options.trackDiagnostics.trackState}`,
    `Capabilities: ${options.trackCapabilities.summary}`,
    '',
    `Detection interval: ${DETECTION_INTERVAL_MS} ms`,
    'CPU-friendly mode: yes',
    '',
    '=== SMALL EAN-13 ===',
    '',
  ]

  for (const variant of ROI_VARIANTS) {
    const aggregate = options.aggregates.find(
      (item) => item.variantId === variant.id && item.codeCategory === 'small-ean',
    )
    lines.push(...formatAggregateBlock(aggregate, variant.label), '')
  }

  lines.push('=== STANDARD EAN-13 ===', '', `Code: ${REFERENCE_EAN_VALUE}`, '')

  for (const variant of ROI_VARIANTS) {
    const aggregate = options.aggregates.find(
      (item) => item.variantId === variant.id && item.codeCategory === 'standard-ean',
    )
    lines.push(...formatAggregateBlock(aggregate, variant.label), '')
  }

  lines.push('=== COMPARISON ===', '')

  for (const entry of buildVariantComparisonEntries(options.aggregates)) {
    lines.push(
      `${entry.variantLabel}:`,
      `  Petit EAN-13: ${entry.smallSuccessRate}`,
      `  EAN-13 standard: ${entry.standardSuccessRate}`,
      `  Écart: ${entry.differencePoints}`,
      `  Data sufficient: ${entry.dataSufficient ? 'YES' : 'NO'}`,
      '',
    )
  }

  lines.push('=== CONCLUSION ===', '', options.conclusion)

  return lines.join('\n')
}
