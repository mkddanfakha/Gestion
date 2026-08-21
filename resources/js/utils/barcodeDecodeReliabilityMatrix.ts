/**
 * BARCODE DECODE RELIABILITY MATRIX — utilitaires DEV isolés.
 *
 * Score expérimental (DEV uniquement, jamais production) :
 *   score =
 *     correctReads * 100
 *     + correctRepetitions * 40
 *     + multiFrameCorrectConfirmations * 25
 *     + checkDigitValidCorrect * 5
 *     - incorrectReads * 15
 *     - invalidReads * 10
 *     - distinctWrongValues * 8
 *     - (1 - repeatabilityRatio) * 30
 *
 * repeatabilityRatio = repetitionsWithCorrect / totalRepetitions (0..1)
 */

import {
  applyExperimentConfiguration,
  buildResolutionConstraints,
  calculateBarcodeSizeRatio,
  computeEan13CheckDigit,
  computeHammingDistance,
  computeMatchingDigits,
  isValidEan13,
  RESOLUTION_PRESETS,
  type ResolutionPreset,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import {
  computeDistinctValues,
  computeLongestIdenticalSequence,
  computeMostFrequent,
  isManualFocusSupported,
  seededShuffle,
} from '@/utils/barcodeStabilityFocusRepeatability'
import { average, computeRate } from '@/utils/barcodeSizeZoomComparison'
import {
  createComparisonBarcodeDetector,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  EXPECTED_BARCODE,
  type AppliedExperimentSnapshot,
  type EnvironmentDiagnostics,
  type TrackCapabilitiesSnapshot,
  type TrackSettingsSnapshot,
} from '@/utils/barcodeDistanceFocusExperiment'

export const DEFAULT_EXPECTED_BARCODE = EXPECTED_BARCODE
export const DEFAULT_EXPECTED_FORMAT = 'ean_13'
export const FIXED_ZOOM = 1
export const DETECTION_INTERVAL_MS = 150

export const PHASE_A_FOCUS_LEVELS = [0.18, 0.2, 0.22, 0.24, 0.26] as const
export const FINE_FOCUS_LEVELS = [0.19, 0.2, 0.21, 0.22, 0.23, 0.24, 0.25] as const

export const PLACEMENT_GUIDES = [
  { id: 'free', label: 'Position libre (mesure widthRatio réel)' },
  { id: '10-15', label: 'Viser ~10–15 % largeur' },
  { id: '15-20', label: 'Viser ~15–20 % largeur' },
  { id: '20-25', label: 'Viser ~20–25 % largeur' },
  { id: '25-30', label: 'Viser ~25–30 % largeur' },
  { id: '30-40', label: 'Viser ~30–40 % largeur' },
  { id: '40-50', label: 'Viser ~40–50 % largeur (hypothèse ancienne)' },
  { id: '50-60', label: 'Viser ~50–60 % largeur' },
] as const

export const WIDTH_RATIO_BUCKETS = [
  { id: 'lt10', label: '< 10 %', min: 0, max: 0.1 },
  { id: '10-12', label: '10–12 %', min: 0.1, max: 0.12 },
  { id: '12-14', label: '12–14 %', min: 0.12, max: 0.14 },
  { id: '14-16', label: '14–16 %', min: 0.14, max: 0.16 },
  { id: '16-18', label: '16–18 %', min: 0.16, max: 0.18 },
  { id: '18-20', label: '18–20 %', min: 0.18, max: 0.2 },
  { id: '20-22', label: '20–22 %', min: 0.2, max: 0.22 },
  { id: '22-25', label: '22–25 %', min: 0.22, max: 0.25 },
  { id: '25-30', label: '25–30 %', min: 0.25, max: 0.3 },
  { id: '30-35', label: '30–35 %', min: 0.3, max: 0.35 },
  { id: '35-40', label: '35–40 %', min: 0.35, max: 0.4 },
  { id: 'gt40', label: '> 40 %', min: 0.4, max: Infinity },
] as const

export const SHARPNESS_BUCKETS = [
  { id: 'lt200', label: '< 200', min: 0, max: 200 },
  { id: '200-400', label: '200–400', min: 200, max: 400 },
  { id: '400-600', label: '400–600', min: 400, max: 600 },
  { id: '600-800', label: '600–800', min: 600, max: 800 },
  { id: '800-1000', label: '800–1000', min: 800, max: 1000 },
  { id: 'gt1000', label: '> 1000', min: 1000, max: Infinity },
] as const

export const MULTI_FRAME_LEVELS = [1, 2, 3, 4, 5] as const

export {
  RESOLUTION_PRESETS,
  buildResolutionConstraints,
  calculateBarcodeSizeRatio,
  isValidEan13,
  computeEan13CheckDigit,
  createComparisonBarcodeDetector,
  applyExperimentConfiguration,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  isManualFocusSupported,
  seededShuffle,
  computeHammingDistance,
  computeMatchingDigits,
}
export type { EnvironmentDiagnostics, AppliedExperimentSnapshot, ResolutionPreset }

export type DetectionClassification = 'EXPECTED' | 'CORRECT_VALUE' | 'VALID_WRONG' | 'INVALID' | 'NOISE'
export type OrderMode = 'FIXED' | 'RANDOMIZED'
export type ExperimentPhase = 'A' | 'B' | 'FINE_FOCUS'
export type MatrixConfigStatus =
  | 'NO_DETECTION'
  | 'INCORRECT_DECODING'
  | 'UNSTABLE_DECODING'
  | 'CORRECT_ONCE'
  | 'REPEATABLE_CORRECT'
  | 'STABLE_CORRECT'

export type ConclusionKind =
  | 'NO_RELIABLE_CONFIGURATION'
  | 'PROMISING_EXPERIMENTAL_CONFIGURATION'
  | 'REPEATABLE_EXPERIMENTAL_CONFIGURATION'
  | 'PRODUCTION_CANDIDATE'

export interface EffectiveVideoDimensions {
  nativeWidth: number
  nativeHeight: number
  logicalWidth: number
  logicalHeight: number
  orientationSwapped: boolean
}

export interface MatrixRunConfiguration {
  id: string
  phase: ExperimentPhase
  resolutionPreset: ResolutionPreset
  focusRequested: number
  repetition: number
  placementGuideLabel: string
  expectedBarcode: string
  expectedFormat: string
  requestedZoom: number
  orderIndex: number
}

export interface MatrixRawDetection {
  id: string
  timestamp: string
  elapsedMs: number
  configId: string
  phase: ExperimentPhase
  repetition: number
  resolutionLabel: string
  focusRequested: number
  focusActual: string
  zoomActual: string
  placementGuideLabel: string
  format: string
  rawValue: string
  classification: DetectionClassification
  checkDigitValid: boolean
  hammingDistance: number | null
  matchingDigits: number
  boundingBoxWidth: number | null
  boundingBoxHeight: number | null
  widthRatio: number | null
  heightRatio: number | null
  nativeVideoWidth: number
  nativeVideoHeight: number
  logicalVideoWidth: number
  logicalVideoHeight: number
  sharpness: number | null
}

export interface MatrixConfigurationResult {
  configId: string
  phase: ExperimentPhase
  repetition: number
  resolutionLabel: string
  requestedWidth: number
  requestedHeight: number
  actualNativeWidth: number | null
  actualNativeHeight: number | null
  actualLogicalWidth: number | null
  actualLogicalHeight: number | null
  orientationSwapped: boolean
  focusRequested: number
  focusActual: string
  zoomRequested: number
  zoomActual: string
  placementGuideLabel: string
  frames: number
  detections: number
  expectedReads: number
  correctValueReads: number
  validWrongReads: number
  invalidReads: number
  noiseReads: number
  detectionRate: string
  correctRate: string
  distinctValues: number
  mostFrequentValue: string | null
  temporalStability: string | null
  repeatabilityKey: string
  averageWidthRatio: number | null
  averageCorrectWidthRatio: number | null
  averageSharpness: number | null
  bestSharpness: number | null
  multiFrameCorrectConfirmations: number
  experimentalScore: number
  status: MatrixConfigStatus
  orderIndex: number
  startedAt: string | null
  finishedAt: string | null
}

export interface WidthRatioBucketRow {
  bucketLabel: string
  detections: number
  expectedReads: number
  correctRate: string
  checkDigitValidCount: number
  validWrongCount: number
  invalidCount: number
  averageSharpness: string
  bestSharpness: string
  temporalStability: string
  mostFrequentValue: string | null
}

export interface SharpnessBucketRow {
  bucketLabel: string
  detections: number
  expectedReads: number
  incorrect: number
  correctRate: string
  averageWidthRatio: string
  formatDistribution: string
}

export interface MultiFrameLevelRow {
  level: number
  confirmations: number
  correctConfirmations: number
  incorrectConfirmations: number
  averageDelayMs: string
  minDelayMs: string
  maxDelayMs: string
}

export interface RepeatabilityGroupRow {
  groupKey: string
  resolutionLabel: string
  focusRequested: number
  repetitions: number
  repetitionsWithCorrect: number
  totalCorrect: number
  repeatabilityRatio: string
  experimentalScore: number
}

export interface ProductionCriteriaEvaluation {
  meetsCriteria: boolean
  details: string[]
}

/**
 * Normalise les dimensions vidéo lorsque la caméra retourne width/height inversés
 * par rapport à la résolution demandée (ex. demandé 1280×720, obtenu 720×1280).
 */
export function getEffectiveVideoDimensions(
  nativeWidth: number,
  nativeHeight: number,
  requestedWidth: number,
  requestedHeight: number,
): EffectiveVideoDimensions {
  const directMatch =
    (nativeWidth === requestedWidth && nativeHeight === requestedHeight)
    || (nativeWidth === requestedHeight && nativeHeight === requestedWidth)

  const orientationSwapped = nativeWidth === requestedHeight && nativeHeight === requestedWidth

  const logicalWidth = orientationSwapped ? Math.max(nativeWidth, nativeHeight) : nativeWidth
  const logicalHeight = orientationSwapped ? Math.min(nativeWidth, nativeHeight) : nativeHeight

  return {
    nativeWidth,
    nativeHeight,
    logicalWidth: directMatch || orientationSwapped ? logicalWidth : nativeWidth,
    logicalHeight: directMatch || orientationSwapped ? logicalHeight : nativeHeight,
    orientationSwapped,
  }
}

export function resolutionMatchesRequested(
  nativeWidth: number,
  nativeHeight: number,
  requestedWidth: number,
  requestedHeight: number,
): boolean {
  return (
    (nativeWidth === requestedWidth && nativeHeight === requestedHeight)
    || (nativeWidth === requestedHeight && nativeHeight === requestedWidth)
  )
}

export function isValidUpcA(value: string): boolean {
  if (!/^\d{12}$/.test(value)) {
    return false
  }

  let sum = 0

  for (let index = 0; index < 12; index += 1) {
    const digit = Number.parseInt(value[index]!, 10)
    sum += digit * (index % 2 === 0 ? 3 : 1)
  }

  return (10 - (sum % 10)) % 10 === 0
}

export function isCheckDigitValid(format: string, rawValue: string): boolean {
  const normalized = format.toLowerCase()

  if (normalized === 'ean_13' || normalized === 'ean-13') {
    return isValidEan13(rawValue)
  }

  if (normalized === 'upc_a' || normalized === 'upc-a') {
    return isValidUpcA(rawValue)
  }

  if (/^\d{13}$/.test(rawValue)) {
    return isValidEan13(rawValue)
  }

  if (/^\d{12}$/.test(rawValue)) {
    return isValidUpcA(rawValue)
  }

  return false
}

export function isExpectedRead(rawValue: string, format: string, expectedBarcode: string, expectedFormat: string): boolean {
  return format === expectedFormat && rawValue === expectedBarcode && isValidEan13(rawValue)
}

export function classifyDetection(
  rawValue: string,
  format: string,
  expectedBarcode: string,
  expectedFormat: string,
): DetectionClassification {
  if (!/^\d+$/.test(rawValue) || rawValue.length < 8) {
    return 'NOISE'
  }

  if (isExpectedRead(rawValue, format, expectedBarcode, expectedFormat)) {
    return 'EXPECTED'
  }

  if (rawValue === expectedBarcode) {
    return 'CORRECT_VALUE'
  }

  if (isCheckDigitValid(format, rawValue)) {
    return 'VALID_WRONG'
  }

  return 'INVALID'
}

export function assignWidthRatioBucket(widthRatio: number | null): string {
  if (widthRatio == null) {
    return '—'
  }

  for (const bucket of WIDTH_RATIO_BUCKETS) {
    if (widthRatio >= bucket.min && widthRatio < bucket.max) {
      return bucket.label
    }
  }

  return '—'
}

export function assignSharpnessBucket(sharpness: number | null): string {
  if (sharpness == null) {
    return '—'
  }

  for (const bucket of SHARPNESS_BUCKETS) {
    if (sharpness >= bucket.min && sharpness < bucket.max) {
      return bucket.label
    }
  }

  return '—'
}

export function buildMatrixConfigurations(options: {
  phase: ExperimentPhase
  resolutionPresets: ResolutionPreset[]
  focusLevels: number[]
  repetitions: number
  placementGuideLabel: string
  expectedBarcode: string
  expectedFormat: string
  orderMode: OrderMode
  randomSeed?: number
  preserveOrder?: MatrixRunConfiguration[]
}): MatrixRunConfiguration[] {
  const base: MatrixRunConfiguration[] = []

  for (let repetition = 1; repetition <= options.repetitions; repetition += 1) {
    for (const resolutionPreset of options.resolutionPresets) {
      for (const focus of options.focusLevels) {
        base.push({
          id: `${options.phase}-${resolutionPreset.id}-f${focus}-r${repetition}`,
          phase: options.phase,
          resolutionPreset,
          focusRequested: focus,
          repetition,
          placementGuideLabel: options.placementGuideLabel,
          expectedBarcode: options.expectedBarcode,
          expectedFormat: options.expectedFormat,
          requestedZoom: FIXED_ZOOM,
          orderIndex: 0,
        })
      }
    }
  }

  if (options.preserveOrder && options.preserveOrder.length === base.length && options.orderMode === 'FIXED') {
    return options.preserveOrder.map((item, index) => ({ ...item, orderIndex: index }))
  }

  const ordered = options.orderMode === 'RANDOMIZED'
    ? seededShuffle(base, options.randomSeed ?? Date.now())
    : base

  return ordered.map((item, index) => ({ ...item, orderIndex: index }))
}

export function buildPhaseBConfigurations(
  topGroups: RepeatabilityGroupRow[],
  options: {
    repetitions: number
    placementGuideLabel: string
    expectedBarcode: string
    expectedFormat: string
    orderMode: OrderMode
    randomSeed?: number
  },
): MatrixRunConfiguration[] {
  const resolutionByLabel = Object.fromEntries(RESOLUTION_PRESETS.map((item) => [item.label, item]))
  const base: MatrixRunConfiguration[] = []

  for (let repetition = 1; repetition <= options.repetitions; repetition += 1) {
    for (const group of topGroups) {
      const preset = resolutionByLabel[group.resolutionLabel]

      if (!preset) {
        continue
      }

      base.push({
        id: `B-${preset.id}-f${group.focusRequested}-r${repetition}`,
        phase: 'B',
        resolutionPreset: preset,
        focusRequested: group.focusRequested,
        repetition,
        placementGuideLabel: options.placementGuideLabel,
        expectedBarcode: options.expectedBarcode,
        expectedFormat: options.expectedFormat,
        requestedZoom: FIXED_ZOOM,
        orderIndex: 0,
      })
    }
  }

  const ordered = options.orderMode === 'RANDOMIZED'
    ? seededShuffle(base, options.randomSeed ?? Date.now())
    : base

  return ordered.map((item, index) => ({ ...item, orderIndex: index }))
}

export function analyzeWidthRatioBuckets(detections: MatrixRawDetection[]): WidthRatioBucketRow[] {
  return WIDTH_RATIO_BUCKETS.map((bucket) => {
    const items = detections.filter(
      (item) => item.widthRatio != null && item.widthRatio >= bucket.min && item.widthRatio < bucket.max,
    )
    const expectedReads = items.filter((item) => item.classification === 'EXPECTED').length
    const mostFrequent = computeMostFrequent(items.map((item) => item.rawValue))
    const temporalRatio = items.length ? mostFrequent.count / items.length : 0

    return {
      bucketLabel: bucket.label,
      detections: items.length,
      expectedReads,
      correctRate: computeRate(expectedReads, items.length),
      checkDigitValidCount: items.filter((item) => item.checkDigitValid).length,
      validWrongCount: items.filter((item) => item.classification === 'VALID_WRONG').length,
      invalidCount: items.filter((item) => item.classification === 'INVALID').length,
      averageSharpness: average(items.map((item) => item.sharpness).filter((v): v is number => v != null)) != null
        ? String(Math.round(average(items.map((item) => item.sharpness).filter((v): v is number => v != null))!))
        : '—',
      bestSharpness: items.length
        ? String(Math.max(...items.map((item) => item.sharpness ?? 0)))
        : '—',
      temporalStability: items.length ? `${(temporalRatio * 100).toFixed(1)}%` : '—',
      mostFrequentValue: mostFrequent.value,
    }
  })
}

export function analyzeSharpnessBuckets(detections: MatrixRawDetection[]): SharpnessBucketRow[] {
  return SHARPNESS_BUCKETS.map((bucket) => {
    const items = detections.filter(
      (item) => item.sharpness != null && item.sharpness >= bucket.min && item.sharpness < bucket.max,
    )
    const expectedReads = items.filter((item) => item.classification === 'EXPECTED').length
    const formats = new Map<string, number>()

    for (const item of items) {
      formats.set(item.format, (formats.get(item.format) ?? 0) + 1)
    }

    return {
      bucketLabel: bucket.label,
      detections: items.length,
      expectedReads,
      incorrect: items.length - expectedReads,
      correctRate: computeRate(expectedReads, items.length),
      averageWidthRatio: average(items.map((item) => item.widthRatio).filter((v): v is number => v != null)) != null
        ? `${((average(items.map((item) => item.widthRatio).filter((v): v is number => v != null))!) * 100).toFixed(1)}%`
        : '—',
      formatDistribution: [...formats.entries()].map(([format, count]) => `${format}:${count}`).join(', ') || '—',
    }
  })
}

export function analyzeMultiFrameLevels(
  detections: MatrixRawDetection[],
  expectedBarcode: string,
): MultiFrameLevelRow[] {
  const sorted = [...detections].sort((a, b) => a.elapsedMs - b.elapsedMs)

  return MULTI_FRAME_LEVELS.map((level) => {
    let confirmations = 0
    let correctConfirmations = 0
    let incorrectConfirmations = 0
    const delays: number[] = []
    let streakValue: string | null = null
    let streakLength = 0
    let streakStartMs = 0

    for (const detection of sorted) {
      if (detection.rawValue === streakValue) {
        streakLength += 1
      } else {
        streakValue = detection.rawValue
        streakLength = 1
        streakStartMs = detection.elapsedMs
      }

      if (streakLength >= level) {
        confirmations += 1
        delays.push(detection.elapsedMs - streakStartMs)

        if (detection.rawValue === expectedBarcode) {
          correctConfirmations += 1
        } else {
          incorrectConfirmations += 1
        }

        streakLength = 0
        streakValue = null
      }
    }

    return {
      level,
      confirmations,
      correctConfirmations,
      incorrectConfirmations,
      averageDelayMs: delays.length ? String(Math.round(average(delays)!)) : '—',
      minDelayMs: delays.length ? String(Math.min(...delays)) : '—',
      maxDelayMs: delays.length ? String(Math.max(...delays)) : '—',
    }
  })
}

export function computeExperimentalScore(input: {
  expectedReads: number
  repetitionsWithCorrect: number
  multiFrameCorrectConfirmations: number
  checkDigitValidCorrect: number
  incorrectReads: number
  invalidReads: number
  distinctWrongValues: number
  repeatabilityRatio: number
}): number {
  return Number((
    input.expectedReads * 100
    + input.repetitionsWithCorrect * 40
    + input.multiFrameCorrectConfirmations * 25
    + input.checkDigitValidCorrect * 5
    - input.incorrectReads * 15
    - input.invalidReads * 10
    - input.distinctWrongValues * 8
    - (1 - input.repeatabilityRatio) * 30
  ).toFixed(2))
}

export function computeRepeatabilityGroups(results: MatrixConfigurationResult[]): RepeatabilityGroupRow[] {
  const groups = new Map<string, MatrixConfigurationResult[]>()

  for (const result of results) {
    const key = `${result.resolutionLabel}|${result.focusRequested}`
    const list = groups.get(key) ?? []
    list.push(result)
    groups.set(key, list)
  }

  return [...groups.entries()].map(([groupKey, items]) => {
    const repetitionsWithCorrect = items.filter((item) => item.expectedReads > 0).length
    const repeatabilityRatio = items.length ? repetitionsWithCorrect / items.length : 0

    return {
      groupKey,
      resolutionLabel: items[0]!.resolutionLabel,
      focusRequested: items[0]!.focusRequested,
      repetitions: items.length,
      repetitionsWithCorrect,
      totalCorrect: items.reduce((sum, item) => sum + item.expectedReads, 0),
      repeatabilityRatio: `${(repeatabilityRatio * 100).toFixed(1)}%`,
      experimentalScore: average(items.map((item) => item.experimentalScore)) ?? 0,
    }
  }).sort((a, b) => b.experimentalScore - a.experimentalScore)
}

export function pickTopConfigurations(
  results: MatrixConfigurationResult[],
  limit = 5,
): MatrixConfigurationResult[] {
  return [...results]
    .sort((a, b) => b.experimentalScore - a.experimentalScore)
    .slice(0, limit)
}

export function determineMatrixConfigStatus(result: {
  detections: number
  expectedReads: number
  longestCorrectSequence: number
  temporalStability: string | null
}): MatrixConfigStatus {
  if (result.detections === 0) {
    return 'NO_DETECTION'
  }

  if (result.expectedReads >= 3 && result.longestCorrectSequence >= 2) {
    return 'STABLE_CORRECT'
  }

  if (result.expectedReads >= 2) {
    return 'REPEATABLE_CORRECT'
  }

  if (result.expectedReads === 1) {
    return 'CORRECT_ONCE'
  }

  const temporal = result.temporalStability != null ? Number.parseFloat(result.temporalStability) : 0

  if (Number.isFinite(temporal) && temporal >= 50) {
    return 'UNSTABLE_DECODING'
  }

  return 'INCORRECT_DECODING'
}

export function evaluateProductionCriteria(results: MatrixConfigurationResult[]): ProductionCriteriaEvaluation {
  const best = pickTopConfigurations(results, 1)[0]
  const details: string[] = []

  if (!best) {
    return { meetsCriteria: false, details: ['Aucune configuration testée.'] }
  }

  const checks = [
    { ok: best.expectedReads >= 3, label: 'Au moins 3 lectures EXPECTED dans une configuration' },
    { ok: best.status === 'STABLE_CORRECT' || best.status === 'REPEATABLE_CORRECT', label: 'Status REPEATABLE_CORRECT ou STABLE_CORRECT' },
    { ok: best.multiFrameCorrectConfirmations >= 2, label: 'Au moins 2 confirmations multi-frame correctes' },
    { ok: best.validWrongReads + best.invalidReads < best.expectedReads, label: 'Faux décodages inférieurs aux lectures correctes' },
    { ok: best.distinctValues <= 5, label: 'Diversité de valeurs limitée (≤ 5)' },
  ]

  for (const check of checks) {
    details.push(`${check.ok ? 'OK' : 'FAIL'} — ${check.label}`)
  }

  return {
    meetsCriteria: checks.every((item) => item.ok),
    details,
  }
}

export function determineConclusion(
  results: MatrixConfigurationResult[],
  productionCriteria: ProductionCriteriaEvaluation,
): ConclusionKind {
  const totalExpected = results.reduce((sum, item) => sum + item.expectedReads, 0)
  const repeatableGroups = computeRepeatabilityGroups(results).filter(
    (item) => item.repetitionsWithCorrect >= 2 && item.totalCorrect >= 2,
  )

  if (productionCriteria.meetsCriteria) {
    return 'PRODUCTION_CANDIDATE'
  }

  if (repeatableGroups.length > 0 || results.some((item) => item.status === 'REPEATABLE_CORRECT' || item.status === 'STABLE_CORRECT')) {
    return 'REPEATABLE_EXPERIMENTAL_CONFIGURATION'
  }

  if (totalExpected > 0) {
    return 'PROMISING_EXPERIMENTAL_CONFIGURATION'
  }

  return 'NO_RELIABLE_CONFIGURATION'
}

export function buildMatrixConclusion(
  results: MatrixConfigurationResult[],
  productionCriteria: ProductionCriteriaEvaluation,
): string {
  const kind = determineConclusion(results, productionCriteria)
  const top = pickTopConfigurations(results, 5)
  const lines = ['=== BENCHMARK CONCLUSION ===', '']

  if (kind === 'PRODUCTION_CANDIDATE') {
    lines.push(
      'PRODUCTION_CANDIDATE (informational only — do NOT auto-integrate)',
      '',
      ...productionCriteria.details,
      '',
      'Experimental candidate only. Manual validation required before any production change.',
    )
  } else if (kind === 'REPEATABLE_EXPERIMENTAL_CONFIGURATION') {
    lines.push('REPEATABLE_EXPERIMENTAL_CONFIGURATION', '', 'Several correct reads observed with repeatability signals.')
  } else if (kind === 'PROMISING_EXPERIMENTAL_CONFIGURATION') {
    lines.push('PROMISING_EXPERIMENTAL_CONFIGURATION', '', 'Correct reads observed but confirmation required.')
  } else {
    lines.push('NO_RELIABLE_CONFIGURATION', '', 'No sufficiently reliable configuration identified.')
  }

  lines.push('', 'TOP CONFIGURATIONS (experimental ranking only)', '')

  for (const [index, item] of top.entries()) {
    lines.push(
      `${index + 1}. ${item.resolutionLabel} / focus ${item.focusRequested} / rep ${item.repetition}`,
      `   expected=${item.expectedReads} score=${item.experimentalScore} avgWidth=${item.averageCorrectWidthRatio != null ? `${(item.averageCorrectWidthRatio * 100).toFixed(1)}%` : '—'}`,
    )
  }

  lines.push(
    '',
    'Previous benchmark context:',
    '- Correct reads observed around widthRatio 16–20 %, not 40–50 %.',
    '- Focus 0.22 is NOT validated as optimal.',
    '- High sharpness does not imply correct decoding.',
    '',
    'EXPERIMENTAL RESULT ONLY',
    'DO NOT INTEGRATE AUTOMATICALLY INTO THE PRODUCTION SCANNER.',
  )

  return lines.join('\n')
}

export function createEmptyMatrixResult(config: MatrixRunConfiguration): MatrixConfigurationResult {
  return {
    configId: config.id,
    phase: config.phase,
    repetition: config.repetition,
    resolutionLabel: config.resolutionPreset.label,
    requestedWidth: config.resolutionPreset.width,
    requestedHeight: config.resolutionPreset.height,
    actualNativeWidth: null,
    actualNativeHeight: null,
    actualLogicalWidth: null,
    actualLogicalHeight: null,
    orientationSwapped: false,
    focusRequested: config.focusRequested,
    focusActual: '—',
    zoomRequested: config.requestedZoom,
    zoomActual: '—',
    placementGuideLabel: config.placementGuideLabel,
    frames: 0,
    detections: 0,
    expectedReads: 0,
    correctValueReads: 0,
    validWrongReads: 0,
    invalidReads: 0,
    noiseReads: 0,
    detectionRate: '—',
    correctRate: '—',
    distinctValues: 0,
    mostFrequentValue: null,
    temporalStability: null,
    repeatabilityKey: `${config.resolutionPreset.label}|${config.focusRequested}`,
    averageWidthRatio: null,
    averageCorrectWidthRatio: null,
    averageSharpness: null,
    bestSharpness: null,
    multiFrameCorrectConfirmations: 0,
    experimentalScore: 0,
    status: 'NO_DETECTION',
    orderIndex: config.orderIndex,
    startedAt: null,
    finishedAt: null,
  }
}

export function finalizeMatrixResult(
  config: MatrixRunConfiguration,
  applied: AppliedExperimentSnapshot,
  options: {
    dims: EffectiveVideoDimensions
    frames: number
    detections: MatrixRawDetection[]
    sharpnessValues: number[]
    startedAt: string
    finishedAt: string
    repetitionsWithCorrectInGroup: number
    totalRepetitionsInGroup: number
  },
): MatrixConfigurationResult {
  const rawValues = options.detections.map((item) => item.rawValue)
  const expectedReads = options.detections.filter((item) => item.classification === 'EXPECTED').length
  const mostFrequent = computeMostFrequent(rawValues)
  const temporalRatio = options.detections.length ? mostFrequent.count / options.detections.length : null
  const wrongValues = new Set(
    options.detections
      .filter((item) => item.classification !== 'EXPECTED')
      .map((item) => item.rawValue),
  )
  const multiFrame = analyzeMultiFrameLevels(options.detections, config.expectedBarcode)
  const longestCorrectSequence = computeLongestIdenticalSequence(
    options.detections.filter((item) => item.classification === 'EXPECTED').map((item) => item.rawValue),
  )
  const repeatabilityRatio = options.totalRepetitionsInGroup > 0
    ? options.repetitionsWithCorrectInGroup / options.totalRepetitionsInGroup
    : 0
  const widthRatios = options.detections.map((item) => item.widthRatio).filter((v): v is number => v != null)
  const correctWidthRatios = options.detections
    .filter((item) => item.classification === 'EXPECTED')
    .map((item) => item.widthRatio)
    .filter((v): v is number => v != null)

  const base = createEmptyMatrixResult(config)

  const result: MatrixConfigurationResult = {
    ...base,
    actualNativeWidth: options.dims.nativeWidth,
    actualNativeHeight: options.dims.nativeHeight,
    actualLogicalWidth: options.dims.logicalWidth,
    actualLogicalHeight: options.dims.logicalHeight,
    orientationSwapped: options.dims.orientationSwapped,
    focusActual: applied.actualFocusDistance,
    zoomActual: applied.actualZoom,
    frames: options.frames,
    detections: options.detections.length,
    expectedReads,
    correctValueReads: options.detections.filter((item) => item.classification === 'CORRECT_VALUE').length,
    validWrongReads: options.detections.filter((item) => item.classification === 'VALID_WRONG').length,
    invalidReads: options.detections.filter((item) => item.classification === 'INVALID').length,
    noiseReads: options.detections.filter((item) => item.classification === 'NOISE').length,
    detectionRate: computeRate(options.detections.length, options.frames),
    correctRate: computeRate(expectedReads, options.frames),
    distinctValues: computeDistinctValues(rawValues),
    mostFrequentValue: mostFrequent.value,
    temporalStability: formatPercent(temporalRatio),
    averageWidthRatio: widthRatios.length
      ? Number((widthRatios.reduce((sum, value) => sum + value, 0) / widthRatios.length).toFixed(4))
      : null,
    averageCorrectWidthRatio: correctWidthRatios.length
      ? Number((correctWidthRatios.reduce((sum, value) => sum + value, 0) / correctWidthRatios.length).toFixed(4))
      : null,
    averageSharpness: average(options.sharpnessValues),
    bestSharpness: options.sharpnessValues.length ? Math.max(...options.sharpnessValues) : null,
    multiFrameCorrectConfirmations: multiFrame.reduce((sum, item) => sum + item.correctConfirmations, 0),
    experimentalScore: computeExperimentalScore({
      expectedReads,
      repetitionsWithCorrect: options.repetitionsWithCorrectInGroup,
      multiFrameCorrectConfirmations: multiFrame.reduce((sum, item) => sum + item.correctConfirmations, 0),
      checkDigitValidCorrect: options.detections.filter((item) => item.classification === 'EXPECTED' && item.checkDigitValid).length,
      incorrectReads: options.detections.length - expectedReads,
      invalidReads: options.detections.filter((item) => item.classification === 'INVALID').length,
      distinctWrongValues: wrongValues.size,
      repeatabilityRatio,
    }),
    startedAt: options.startedAt,
    finishedAt: options.finishedAt,
    status: 'NO_DETECTION',
  }

  result.status = determineMatrixConfigStatus({
    detections: result.detections,
    expectedReads: result.expectedReads,
    longestCorrectSequence,
    temporalStability: result.temporalStability,
  })

  return result
}

function formatPercent(ratio: number | null): string | null {
  if (ratio == null || !Number.isFinite(ratio)) {
    return null
  }

  return `${(ratio * 100).toFixed(1)}%`
}

export function buildMatrixReport(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  phase: ExperimentPhase
  orderMode: OrderMode
  randomSeed: number | null
  durationSeconds: number
  settleMs: number
  repetitions: number
  results: MatrixConfigurationResult[]
  rawDetections: MatrixRawDetection[]
  widthBuckets: WidthRatioBucketRow[]
  sharpnessBuckets: SharpnessBucketRow[]
  multiFrameLevels: MultiFrameLevelRow[]
  repeatabilityGroups: RepeatabilityGroupRow[]
  topConfigurations: MatrixConfigurationResult[]
  productionCriteria: ProductionCriteriaEvaluation
  conclusion: string
}): string {
  return [
    '=== BARCODE DECODE RELIABILITY MATRIX ===',
    '',
    `Date: ${new Date().toISOString()}`,
    `Browser: ${options.environment.browserLabel}`,
    `User agent: ${options.environment.userAgent}`,
    '',
    'Camera:',
    `Actual: ${options.trackSettings.width ?? '—'}×${options.trackSettings.height ?? '—'}`,
    `FPS: ${options.trackSettings.frameRate}`,
    '',
    `Phase: ${options.phase}`,
    `Order: ${options.orderMode}`,
    options.randomSeed != null ? `Seed: ${options.randomSeed}` : '',
    `Duration: ${options.durationSeconds}s`,
    `Settle: ${options.settleMs}ms`,
    `Repetitions: ${options.repetitions}`,
    '',
    'Best configurations (experimental ranking only):',
    ...options.topConfigurations.map(
      (item, index) => `${index + 1}. ${item.resolutionLabel} focus ${item.focusRequested} — expected ${item.expectedReads} — score ${item.experimentalScore}`,
    ),
    '',
    'Width ratio analysis:',
    ...options.widthBuckets.filter((item) => item.detections > 0).map(
      (item) => `${item.bucketLabel}: detections ${item.detections}, expected ${item.expectedReads}, correct rate ${item.correctRate}`,
    ),
    '',
    'Sharpness analysis:',
    ...options.sharpnessBuckets.filter((item) => item.detections > 0).map(
      (item) => `${item.bucketLabel}: detections ${item.detections}, expected ${item.expectedReads}, avg width ${item.averageWidthRatio}`,
    ),
    '',
    'Multi-frame analysis:',
    ...options.multiFrameLevels.map(
      (item) => `Level ${item.level}: confirmations ${item.confirmations}, correct ${item.correctConfirmations}, incorrect ${item.incorrectConfirmations}`,
    ),
    '',
    options.conclusion,
    '',
  ].join('\n')
}

export function buildMatrixCsv(results: MatrixConfigurationResult[]): string {
  const header = [
    'resolution', 'focus', 'repetition', 'phase', 'requested_resolution', 'actual_native_resolution',
    'detections', 'expected', 'correct_rate', 'detection_rate', 'avg_width_ratio', 'avg_correct_width_ratio',
    'avg_sharpness', 'distinct_values', 'temporal_stability', 'experimental_score', 'status',
  ].join(',')

  const rows = results.map((item) => [
    item.resolutionLabel,
    item.focusRequested,
    item.repetition,
    item.phase,
    `${item.requestedWidth}x${item.requestedHeight}`,
    `${item.actualNativeWidth ?? '—'}x${item.actualNativeHeight ?? '—'}`,
    item.detections,
    item.expectedReads,
    item.correctRate,
    item.detectionRate,
    item.averageWidthRatio ?? '',
    item.averageCorrectWidthRatio ?? '',
    item.averageSharpness ?? '',
    item.distinctValues,
    item.temporalStability ?? '',
    item.experimentalScore,
    item.status,
  ].join(','))

  return [header, ...rows].join('\n')
}

export function buildMatrixRawCsv(detections: MatrixRawDetection[]): string {
  const header = [
    'timestamp', 'resolution', 'focus', 'zoom', 'placement_guide', 'repetition', 'phase',
    'raw_value', 'format', 'classification', 'check_digit_valid', 'bounding_box_width',
    'bounding_box_height', 'width_ratio', 'height_ratio', 'sharpness', 'elapsed_ms',
  ].join(',')

  const rows = detections.map((item) => [
    item.timestamp,
    item.resolutionLabel,
    item.focusRequested,
    item.zoomActual,
    item.placementGuideLabel,
    item.repetition,
    item.phase,
    item.rawValue,
    item.format,
    item.classification,
    item.checkDigitValid,
    item.boundingBoxWidth ?? '',
    item.boundingBoxHeight ?? '',
    item.widthRatio ?? '',
    item.heightRatio ?? '',
    item.sharpness ?? '',
    item.elapsedMs,
  ].join(','))

  return [header, ...rows].join('\n')
}

export function buildMatrixExportJson(payload: Record<string, unknown>): string {
  return JSON.stringify(payload, null, 2)
}

export function computeGroupRepeatability(
  config: MatrixRunConfiguration,
  currentExpectedReads: number,
  completedResults: MatrixConfigurationResult[],
): { repetitionsWithCorrect: number; totalRepetitionsInGroup: number } {
  const groupKey = `${config.resolutionPreset.label}|${config.focusRequested}`
  const sameGroup = completedResults.filter((item) => item.repeatabilityKey === groupKey)
  const repetitionsWithCorrect = sameGroup.filter((item) => item.expectedReads > 0).length
    + (currentExpectedReads > 0 ? 1 : 0)
  const totalRepetitionsInGroup = sameGroup.length + 1

  return { repetitionsWithCorrect, totalRepetitionsInGroup }
}
