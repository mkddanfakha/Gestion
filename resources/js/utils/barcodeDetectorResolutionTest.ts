import {
  formatNativeBarcodeFormat,
  NATIVE_BARCODE_FORMATS,
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

export const REFERENCE_EAN_VALUE = '6202312030117'
export const REFERENCE_EAN_FORMAT = 'EAN-13'
export const SMALL_EAN_TEST_LABEL = 'Petit EAN-13'
export const STANDARD_EAN_TEST_LABEL = 'EAN-13 standard'
export const DETECTION_INTERVAL_MS = 150
export const MAX_SUCCESS_HISTORY = 20
export const MIN_ATTEMPTS_FOR_CONCLUSION = 100
export const MIN_TEST_DURATION_MS = 20_000

export type ResolutionProfileId = '640x480' | '1280x720' | '1920x1080' | 'maximum'
export type CodeTestCategory = 'small-ean' | 'standard-ean'

export interface ResolutionProfileDefinition {
  id: ResolutionProfileId
  label: string
  requestedWidth: number
  requestedHeight: number
}

export const RESOLUTION_PROFILES: ResolutionProfileDefinition[] = [
  { id: '640x480', label: '640×480', requestedWidth: 640, requestedHeight: 480 },
  { id: '1280x720', label: '1280×720', requestedWidth: 1280, requestedHeight: 720 },
  { id: '1920x1080', label: '1920×1080', requestedWidth: 1920, requestedHeight: 1080 },
  { id: 'maximum', label: 'Maximum', requestedWidth: 3840, requestedHeight: 2160 },
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
  averageSuccessIntervalMs: number | null
  minSuccessIntervalMs: number | null
  maxSuccessIntervalMs: number | null
}

export interface ProfileCodeAggregate extends DetectionStats {
  profileId: ResolutionProfileId
  profileLabel: string
  codeCategory: CodeTestCategory
  codeLabel: string
  actualResolution: string
  requestedResolution: string
}

export interface SuccessHistoryEntry {
  id: string
  timestamp: string
  profileLabel: string
  rawValue: string
  format: string
  resolution: string
  durationMs: number
  codeCategory: CodeTestCategory
}

export interface RequestedConstraintsSummary {
  facingMode: string
  width: number
  height: number
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

export interface ComparisonTableRow {
  profileLabel: string
  actualResolution: string
  codeLabel: string
  codeCategory: CodeTestCategory
  attempts: number
  success: number
  notFound: number
  errors: number
  successRate: string
  notFoundRate: string
  errorRate: string
  averageMs: string
  minMs: string
  maxMs: string
  dataSufficient: boolean
}

export interface ProfileComparisonEntry {
  profileLabel: string
  actualResolution: string
  smallSuccessRate: string
  standardSuccessRate: string
  differencePoints: string
  dataSufficient: boolean
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
    averageSuccessIntervalMs: null,
    minSuccessIntervalMs: null,
    maxSuccessIntervalMs: null,
  }
}

export function createEmptyProfileCodeAggregate(
  profile: ResolutionProfileDefinition,
  codeCategory: CodeTestCategory,
): ProfileCodeAggregate {
  return {
    ...createEmptyDetectionStats(),
    profileId: profile.id,
    profileLabel: profile.label,
    codeCategory,
    codeLabel: codeCategory === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL,
    actualResolution: '—',
    requestedResolution: `${profile.requestedWidth}×${profile.requestedHeight}`,
  }
}

export function createInitialProfileCodeAggregates(): ProfileCodeAggregate[] {
  const rows: ProfileCodeAggregate[] = []

  for (const profile of RESOLUTION_PROFILES) {
    rows.push(createEmptyProfileCodeAggregate(profile, 'small-ean'))
    rows.push(createEmptyProfileCodeAggregate(profile, 'standard-ean'))
  }

  return rows
}

export function getProfileDefinition(profileId: ResolutionProfileId): ResolutionProfileDefinition {
  const profile = RESOLUTION_PROFILES.find((item) => item.id === profileId)

  if (!profile) {
    throw new Error(`Profil inconnu: ${profileId}`)
  }

  return profile
}

export function buildProfileConstraints(profileId: ResolutionProfileId): MediaStreamConstraints {
  const profile = getProfileDefinition(profileId)

  return {
    video: {
      facingMode: { ideal: 'environment' },
      width: { ideal: profile.requestedWidth },
      height: { ideal: profile.requestedHeight },
    },
    audio: false,
  }
}

export function summarizeRequestedConstraints(profileId: ResolutionProfileId): RequestedConstraintsSummary {
  const profile = getProfileDefinition(profileId)

  return {
    facingMode: 'environment (ideal)',
    width: profile.requestedWidth,
    height: profile.requestedHeight,
  }
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
    settings.aspectRatio != null ? `aspectRatio=${settings.aspectRatio}` : null,
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

export function formatActualTrackSettings(stream: MediaStream | null): string {
  return readActualTrackDetails(stream).summary
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

export function updateDetectionDurationStats(
  stats: DetectionStats,
  durations: number[],
  durationMs: number,
): DetectionStats {
  const nextDurations = [...durations, durationMs]

  return {
    ...stats,
    lastDetectionMs: durationMs,
    averageDetectionMs: computeAverageDetectionMs(nextDurations),
    minDetectionMs: Math.min(...nextDurations),
    maxDetectionMs: Math.max(...nextDurations),
  }
}

export function updateSuccessIntervalStats(
  stats: DetectionStats,
  intervals: number[],
  intervalMs: number,
): DetectionStats {
  const nextIntervals = [...intervals, intervalMs]

  return {
    ...stats,
    averageSuccessIntervalMs: computeAverageDetectionMs(nextIntervals),
    minSuccessIntervalMs: Math.min(...nextIntervals),
    maxSuccessIntervalMs: Math.max(...nextIntervals),
  }
}

export function computeRateDifferencePoints(
  leftRate: number,
  rightRate: number,
): string {
  const diff = rightRate - leftRate
  const sign = diff > 0 ? '+' : ''

  return `${sign}${diff.toFixed(1)} pts`
}

export function parseRatePercent(value: string): number | null {
  if (value === '—') {
    return null
  }

  const parsed = Number.parseFloat(value.replace('%', '').replace(',', '.'))

  return Number.isFinite(parsed) ? parsed : null
}

export function describeImprovementMagnitude(differencePoints: number): string {
  const absolute = Math.abs(differencePoints)

  if (absolute < 10) {
    return 'amélioration faible / non concluante'
  }

  if (absolute <= 25) {
    return 'amélioration notable'
  }

  return 'amélioration importante'
}

export function buildComparisonTableRows(aggregates: ProfileCodeAggregate[]): ComparisonTableRow[] {
  return aggregates.map((aggregate) => ({
    profileLabel: aggregate.profileLabel,
    actualResolution: aggregate.actualResolution,
    codeLabel: aggregate.codeLabel,
    codeCategory: aggregate.codeCategory,
    attempts: aggregate.detectionAttempts,
    success: aggregate.successfulDetections,
    notFound: aggregate.notFound,
    errors: aggregate.errors,
    successRate: computeSuccessRate(aggregate),
    notFoundRate: computeNotFoundRate(aggregate),
    errorRate: computeErrorRate(aggregate),
    averageMs: aggregate.averageDetectionMs != null ? String(aggregate.averageDetectionMs) : '—',
    minMs: aggregate.minDetectionMs != null ? String(aggregate.minDetectionMs) : '—',
    maxMs: aggregate.maxDetectionMs != null ? String(aggregate.maxDetectionMs) : '—',
    dataSufficient: hasSufficientTestData(aggregate),
  }))
}

export function buildProfileComparisonEntries(aggregates: ProfileCodeAggregate[]): ProfileComparisonEntry[] {
  return RESOLUTION_PROFILES.map((profile) => {
    const small = aggregates.find((item) => item.profileId === profile.id && item.codeCategory === 'small-ean')
    const standard = aggregates.find((item) => item.profileId === profile.id && item.codeCategory === 'standard-ean')
    const smallRate = parseRatePercent(computeSuccessRate(small ?? createEmptyDetectionStats()))
    const standardRate = parseRatePercent(computeSuccessRate(standard ?? createEmptyDetectionStats()))
    const difference = smallRate != null && standardRate != null
      ? computeRateDifferencePoints(smallRate, standardRate)
      : '—'

    return {
      profileLabel: profile.label,
      actualResolution: small?.actualResolution !== '—' ? small?.actualResolution ?? standard?.actualResolution ?? '—' : standard?.actualResolution ?? '—',
      smallSuccessRate: computeSuccessRate(small ?? createEmptyDetectionStats()),
      standardSuccessRate: computeSuccessRate(standard ?? createEmptyDetectionStats()),
      differencePoints: difference,
      dataSufficient: hasSufficientTestData(small ?? createEmptyDetectionStats())
        || hasSufficientTestData(standard ?? createEmptyDetectionStats()),
    }
  })
}

export function formatHistoryTimestamp(date = new Date()): string {
  return date.toLocaleTimeString('fr-FR', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

function getAggregate(
  aggregates: ProfileCodeAggregate[],
  profileId: ResolutionProfileId,
  codeCategory: CodeTestCategory,
): ProfileCodeAggregate | undefined {
  return aggregates.find((item) => item.profileId === profileId && item.codeCategory === codeCategory)
}

function getSuccessRateNumber(aggregate: ProfileCodeAggregate | undefined): number | null {
  if (!aggregate || aggregate.detectionAttempts <= 0) {
    return null
  }

  return (aggregate.successfulDetections / aggregate.detectionAttempts) * 100
}

export function buildResolutionConclusion(input: {
  aggregates: ProfileCodeAggregate[]
  activeActualResolution: string
  activeRequestedResolution: string
}): string {
  const lines = ['=== CONCLUSION ===', '']
  const tested = input.aggregates.filter((item) => item.detectionAttempts > 0)

  if (tested.length === 0) {
    lines.push('Aucune tentative enregistrée pour le moment.')
    lines.push('Sélectionnez un type de test, démarrez un profil, lancez la détection pendant au moins 20 secondes, puis comparez.')
    return lines.join('\n')
  }

  const actualResolutions = new Set(
    tested
      .map((item) => item.actualResolution)
      .filter((value) => value !== '—'),
  )

  if (actualResolutions.size <= 1 && tested.length > 1) {
    lines.push('Les différents profils aboutissent à une résolution réelle similaire sur cet appareil.')
    lines.push(`Résolution réelle observée : ${[...actualResolutions][0] ?? input.activeActualResolution}.`)
    lines.push('La résolution ne peut donc pas être comparée correctement avec ce test.')
    lines.push('')
  }

  const smallByProfile = RESOLUTION_PROFILES
    .map((profile) => ({
      profile,
      aggregate: getAggregate(input.aggregates, profile.id, 'small-ean'),
      rate: getSuccessRateNumber(getAggregate(input.aggregates, profile.id, 'small-ean')),
    }))
    .filter((item) => item.aggregate && item.aggregate.detectionAttempts > 0 && hasSufficientTestData(item.aggregate))

  const standardByProfile = RESOLUTION_PROFILES
    .map((profile) => ({
      profile,
      aggregate: getAggregate(input.aggregates, profile.id, 'standard-ean'),
      rate: getSuccessRateNumber(getAggregate(input.aggregates, profile.id, 'standard-ean')),
    }))
    .filter((item) => item.aggregate && item.aggregate.detectionAttempts > 0 && hasSufficientTestData(item.aggregate))

  if (smallByProfile.length >= 2) {
    const sorted = [...smallByProfile].sort((left, right) => (right.rate ?? 0) - (left.rate ?? 0))
    const best = sorted[0]
    const worst = sorted[sorted.length - 1]

    if (best && worst && best.profile.id !== worst.profile.id && best.rate != null && worst.rate != null) {
      const diff = best.rate - worst.rate
      const magnitude = describeImprovementMagnitude(diff)

      if (diff >= 10) {
        lines.push(`La détection du petit EAN-13 semble s'améliorer avec l'augmentation de la résolution (${magnitude}).`)
        lines.push(`Meilleur profil petit EAN : ${best.profile.label} (${best.rate.toFixed(1)} %).`)
        lines.push(`Profil le plus faible : ${worst.profile.label} (${worst.rate.toFixed(1)} %).`)
        lines.push('Cela suggère que la résolution effective de l\'image peut être un facteur important.')
      } else {
        lines.push('L\'augmentation de la résolution ne semble pas améliorer significativement la détection du petit EAN-13.')
        lines.push('Il faudra probablement examiner d\'autres facteurs : distance, mise au point, taille apparente du code, éclairage, mouvement ou traitement du flux.')
      }
      lines.push('')
    }
  } else {
    lines.push('Données insuffisantes pour conclure sur l\'impact de la résolution pour le petit EAN-13.')
    lines.push(`Minimum recommandé : ${MIN_ATTEMPTS_FOR_CONCLUSION} tentatives ou ${MIN_TEST_DURATION_MS / 1000} secondes par profil.`)
    lines.push('')
  }

  const standardRates = standardByProfile.map((item) => item.rate).filter((rate): rate is number => rate != null)
  const smallRates = smallByProfile.map((item) => item.rate).filter((rate): rate is number => rate != null)

  if (standardRates.some((rate) => rate >= 20) && smallRates.every((rate) => rate < 10)) {
    lines.push('L\'EAN-13 standard est détecté correctement tandis que le petit EAN-13 reste difficile à détecter.')
    lines.push('Cela confirme une différence de difficulté entre les deux codes, mais ne permet pas encore d\'identifier la cause exacte.')
    lines.push('')
  }

  if (input.activeRequestedResolution !== '—' && input.activeActualResolution !== '—') {
    lines.push(`Profil actif — demandé : ${input.activeRequestedResolution} ; réel : ${input.activeActualResolution}.`)
  }

  lines.push('')
  lines.push('Indice empirique : ces mesures ne constituent pas une preuve scientifique. Utilisez-les pour orienter la prochaine étape.')

  return lines.join('\n')
}

function formatAggregateBlock(title: string, aggregate: ProfileCodeAggregate | undefined, referenceCode?: string): string[] {
  if (!aggregate || aggregate.detectionAttempts <= 0) {
    return [
      `=== ${title} ===`,
      'Attempts: —',
      'Success: —',
      'Not found: —',
      'Errors: —',
      'Success rate: —',
      'NOT FOUND rate: —',
      'Error rate: —',
      'Average detection: —',
      'Min: —',
      'Max: —',
      'Average success interval: —',
      'Data sufficient: no',
      '',
    ]
  }

  return [
    `=== ${title} ===`,
    referenceCode ? `Code: ${referenceCode}` : undefined,
    `Attempts: ${aggregate.detectionAttempts}`,
    `Success: ${aggregate.successfulDetections}`,
    `Not found: ${aggregate.notFound}`,
    `Errors: ${aggregate.errors}`,
    `Success rate: ${computeSuccessRate(aggregate)}`,
    `NOT FOUND rate: ${computeNotFoundRate(aggregate)}`,
    `Error rate: ${computeErrorRate(aggregate)}`,
    `Average detection: ${aggregate.averageDetectionMs ?? '—'} ms`,
    `Min: ${aggregate.minDetectionMs ?? '—'} ms`,
    `Max: ${aggregate.maxDetectionMs ?? '—'} ms`,
    `Average success interval: ${aggregate.averageSuccessIntervalMs ?? '—'} ms`,
    `Min success interval: ${aggregate.minSuccessIntervalMs ?? '—'} ms`,
    `Max success interval: ${aggregate.maxSuccessIntervalMs ?? '—'} ms`,
    `Data sufficient: ${hasSufficientTestData(aggregate) ? 'yes' : 'no'}`,
    '',
  ].filter((line): line is string => line != null)
}

export function buildResolutionDiagnosticClipboard(input: {
  environment: EnvironmentDiagnostics
  supportedFormats: string[]
  activeProfileLabel: string
  activeTestCategory: CodeTestCategory
  requestedConstraints: RequestedConstraintsSummary
  actualTrackDetails: ActualTrackDetails
  trackDiagnostics: CameraTrackDiagnostics
  cameraState: string
  streamActive: boolean
  videoWidth: number
  videoHeight: number
  readyState: number
  currentTime: number
  stats: DetectionStats
  aggregates: ProfileCodeAggregate[]
  comparisonEntries: ProfileComparisonEntry[]
  successHistory: SuccessHistoryEntry[]
  conclusion: string
  videoFlowWarning: string | null
}): string {
  const comparisonLines = input.aggregates.flatMap((aggregate) => [
    `${aggregate.profileLabel} / ${aggregate.codeLabel}:`,
    `  Requested: ${aggregate.requestedResolution}`,
    `  Actual: ${aggregate.actualResolution}`,
    `  Attempts: ${aggregate.detectionAttempts}`,
    `  Success: ${aggregate.successfulDetections}`,
    `  Not found: ${aggregate.notFound}`,
    `  Errors: ${aggregate.errors}`,
    `  Success rate: ${computeSuccessRate(aggregate)}`,
    `  NOT FOUND rate: ${computeNotFoundRate(aggregate)}`,
    `  Error rate: ${computeErrorRate(aggregate)}`,
    `  Average detection: ${aggregate.averageDetectionMs ?? '—'} ms`,
    `  Data sufficient: ${hasSufficientTestData(aggregate) ? 'yes' : 'no'}`,
    '',
  ])

  const profileComparisonLines = input.comparisonEntries.map((entry) => [
    `${entry.profileLabel} (${entry.actualResolution}):`,
    `  Petit EAN-13: ${entry.smallSuccessRate}`,
    `  EAN-13 standard: ${entry.standardSuccessRate}`,
    `  Écart: ${entry.differencePoints}`,
  ].join('\n'))

  const historyLines = input.successHistory.length > 0
    ? input.successHistory.map((entry) => `${entry.timestamp} | ${entry.profileLabel} | ${entry.resolution} | ${entry.rawValue} | ${entry.format} | ${entry.durationMs} ms`)
    : ['—']

  const activeSmall = input.aggregates.find((item) => item.codeCategory === 'small-ean' && item.profileLabel === input.activeProfileLabel)
  const activeStandard = input.aggregates.find((item) => item.codeCategory === 'standard-ean' && item.profileLabel === input.activeProfileLabel)

  return [
    '=== BARCODE DETECTOR RESOLUTION DIAGNOSTIC ===',
    '',
    `Browser: ${input.environment.browserLabel}`,
    `User agent: ${input.environment.userAgent}`,
    `Secure context: ${input.environment.secureContext}`,
    `Platform: ${input.environment.platform}`,
    '',
    'BarcodeDetector:',
    `Available: ${input.environment.barcodeDetectorAvailable}`,
    `Formats: ${input.supportedFormats.length > 0 ? input.supportedFormats.join(', ') : NATIVE_BARCODE_FORMATS.join(', ')}`,
    '',
    '=== CAMERA ===',
    '',
    `Profile: ${input.activeProfileLabel}`,
    `Active test: ${input.activeTestCategory === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL}`,
    `Requested resolution: ${input.requestedConstraints.width}×${input.requestedConstraints.height}`,
    `Actual resolution: ${input.trackDiagnostics.resolution}`,
    `Facing mode: ${input.actualTrackDetails.facingMode}`,
    `Frame rate: ${input.actualTrackDetails.frameRate}`,
    `Aspect ratio: ${input.actualTrackDetails.aspectRatio}`,
    `DeviceId: ${input.actualTrackDetails.deviceId}`,
    `Requested constraints: facingMode=${input.requestedConstraints.facingMode}, width=${input.requestedConstraints.width}, height=${input.requestedConstraints.height}`,
    `Actual track settings: ${input.actualTrackDetails.summary}`,
    '',
    `Camera: ${input.cameraState}`,
    `Stream: ${input.streamActive ? 'active' : 'stopped'}`,
    `Video readyState: ${input.readyState}`,
    `Video width: ${input.videoWidth}`,
    `Video height: ${input.videoHeight}`,
    `Current time: ${input.currentTime}`,
    input.videoFlowWarning ? `Video flow warning: ${input.videoFlowWarning}` : undefined,
    '',
    '=== SESSION ACTIVE ===',
    `Frames: ${input.stats.framesSeen}`,
    `Attempts: ${input.stats.detectionAttempts}`,
    `Success: ${input.stats.successfulDetections}`,
    `Not found: ${input.stats.notFound}`,
    `Errors: ${input.stats.errors}`,
    `Average detection: ${input.stats.averageDetectionMs ?? '—'} ms`,
    `Min: ${input.stats.minDetectionMs ?? '—'} ms`,
    `Max: ${input.stats.maxDetectionMs ?? '—'} ms`,
    '',
    ...formatAggregateBlock('SMALL EAN-13 / ACTIVE PROFILE', activeSmall),
    ...formatAggregateBlock('STANDARD EAN-13 / ACTIVE PROFILE', activeStandard, REFERENCE_EAN_VALUE),
    '=== COMPARISON ===',
    ...comparisonLines,
    '=== PROFILE COMPARISON ===',
    ...profileComparisonLines,
    '',
    '=== HISTORY ===',
    ...historyLines,
    '',
    input.conclusion,
  ].filter((line): line is string => line != null).join('\n')
}

export {
  formatNativeBarcodeFormat,
  getEnvironmentDiagnostics,
  pickBestNativeBarcode,
  readCameraTrackDiagnostics,
  type BarcodeDetectorLike,
  type CameraTrackDiagnostics,
  type DetectedBarcodeLike,
  type EnvironmentDiagnostics,
}

export { computeAverageDetectionMs } from '@/utils/nativeBarcodeDetectorLiveTest'
export { createNativeBarcodeDetector } from '@/utils/nativeBarcodeScannerEngine'
