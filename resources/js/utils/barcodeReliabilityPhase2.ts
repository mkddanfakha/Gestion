/**
 * BARCODE DECODE RELIABILITY BENCHMARK — PHASE 2
 * Laboratoire DEV isolé — jamais utilisé en production.
 */

import {
  applyExperimentConfiguration,
  buildResolutionConstraints,
  calculateBarcodeSizeRatio,
  computeEan13CheckDigit,
  isValidEan13,
  RESOLUTION_PRESETS,
  type ResolutionPreset,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import { getEffectiveVideoDimensions, isValidUpcA } from '@/utils/barcodeDecodeReliabilityMatrix'
import {
  computeDistinctValues,
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

export const DEFAULT_EXPECTED_BARCODES = [{ value: EXPECTED_BARCODE, format: 'ean_13' }] as const
export const DEFAULT_FOCUS_LEVELS = [0.10, 0.15, 0.20, 0.22, 0.25, 0.30, 0.35] as const
export const DEFAULT_ZOOM_LEVELS = [1.0, 1.2, 1.5, 2.0] as const
export const DEFAULT_DURATION_SECONDS = 15
export const DEFAULT_SETTLE_MS = 1500
export const DETECTION_INTERVAL_MS = 150
export const MAX_LIVE_EVENTS = 20

export const BARCODE_SCORE_WEIGHTS = {
  checksum: 20,
  format: 12,
  expectedValue: 28,
  repetition: 12,
  stability: 12,
  sharpness: 8,
  widthRatio: 8,
} as const

export const DEFAULT_VALIDATION_POLICY = {
  minimumConfirmations: 3,
  minimumStability: 60,
  minimumScore: 80,
  minimumTimeBetweenConfirmationsMs: 100,
} as const

export const OVERALL_SCORE_WEIGHTS = {
  expectedCorrectness: 0.40,
  validationRate: 0.20,
  temporalStability: 0.15,
  falsePositiveResistance: 0.10,
  detectionRate: 0.10,
  speed: 0.05,
} as const

export const EXPERIMENTAL_BEST_MINIMUMS = {
  minimumExpectedReads: 2,
  minimumValidationCount: 1,
  minimumOverallScore: 50,
  maximumFalsePositiveRate: 60,
} as const

export const DEFAULT_TARGET_SIZES = [
  { id: '20-30', label: '20–30 %', min: 0.20, max: 0.30, guideWidthRatio: 0.25 },
  { id: '30-40', label: '30–40 %', min: 0.30, max: 0.40, guideWidthRatio: 0.35 },
  { id: '40-50', label: '40–50 %', min: 0.40, max: 0.50, guideWidthRatio: 0.45 },
  { id: '50-60', label: '50–60 %', min: 0.50, max: 0.60, guideWidthRatio: 0.55 },
  { id: '60-70', label: '60–70 %', min: 0.60, max: 0.70, guideWidthRatio: 0.65 },
  { id: '70-80', label: '70–80 %', min: 0.70, max: 0.80, guideWidthRatio: 0.75 },
  { id: '80-90', label: '80–90 %', min: 0.80, max: 0.90, guideWidthRatio: 0.85 },
] as const

export const QUICK_MODE = {
  resolutionId: '640x480',
  targetId: '70-80',
  focusLevels: [0.20, 0.22, 0.25],
  zoomLevels: [1.0],
} as const

export {
  RESOLUTION_PRESETS,
  buildResolutionConstraints,
  calculateBarcodeSizeRatio,
  createComparisonBarcodeDetector,
  applyExperimentConfiguration,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  isManualFocusSupported,
  getEffectiveVideoDimensions,
  isValidEan13,
  computeEan13CheckDigit,
}

export { isValidUpcA } from '@/utils/barcodeDecodeReliabilityMatrix'

export type { EnvironmentDiagnostics, AppliedExperimentSnapshot, ResolutionPreset, TrackCapabilitiesSnapshot, TrackSettingsSnapshot }

export type BenchmarkMode = 'QUICK' | 'FULL'
export type OrderMode = 'ORDERED' | 'RANDOMIZED'
export type PhysicalConfirmationMethod = 'manual_inspection' | 'hardware_scanner' | 'packaging' | 'other' | null

export type ValidationState =
  | 'NO_DETECTION'
  | 'INVALID_FORMAT'
  | 'INVALID_CHECKSUM'
  | 'WRONG_VALID_CHECKSUM'
  | 'EXPECTED_SINGLE'
  | 'EXPECTED_CANDIDATE'
  | 'EXPECTED_VALIDATED'
  | 'FALSE_POSITIVE'
  | 'UNSTABLE'

export type ConfigStatus =
  | 'NO_DETECTION'
  | 'DETECTION_ONLY'
  | 'WRONG_DECODING'
  | 'VALID_CHECKSUM_WRONG_VALUE'
  | 'EXPECTED_ONCE'
  | 'REPEATABLE_CORRECT'
  | 'VALIDATED'
  | 'EXPERIMENTAL_BEST'
  | 'INSUFFICIENT_DATA'
  | 'CONFIGURATION_ERROR'

export interface ExpectedBarcodeSpec {
  value: string
  format: string
}

export interface TargetSizeSpec {
  id: string
  label: string
  min: number
  max: number
  guideWidthRatio: number
}

export interface ValidationPolicy {
  minimumConfirmations: number
  minimumStability: number
  minimumScore: number
  minimumTimeBetweenConfirmationsMs: number
}

export interface Phase2RunConfiguration {
  id: string
  orderIndex: number
  resolution: ResolutionPreset
  targetSize: TargetSizeSpec
  focusRequested: number
  zoomRequested: number
  label: string
}

export interface Phase2RawDetection {
  id: string
  timestamp: string
  elapsedMs: number
  configurationId: string
  frameIndex: number
  detectionIndex: number
  rawValue: string
  format: string
  checkDigitValid: boolean
  isExpected: boolean
  videoWidth: number
  videoHeight: number
  requestedResolution: string
  actualResolution: string
  requestedFocus: number
  actualFocus: string
  requestedZoom: number
  actualZoom: string
  sharpness: number | null
  widthRatio: number | null
  confidenceScore: number
  validationState: ValidationState
}

export interface OverallScoreBreakdown {
  overall: number
  expectedCorrectness: number
  validationRate: number
  temporalStability: number
  falsePositiveResistance: number
  detectionRate: number
  speed: number
}

export interface Phase2ConfigurationResult {
  configurationId: string
  label: string
  resolutionLabel: string
  requestedResolution: string
  actualResolution: string
  targetSizeLabel: string
  focusRequested: number
  focusActual: string
  focusApplied: boolean
  zoomRequested: number
  zoomActual: string
  zoomApplied: boolean
  frames: number
  detections: number
  expectedDetections: number
  wrongDetections: number
  validChecksumDetections: number
  wrongValidChecksumDetections: number
  invalidChecksumDetections: number
  noDetectionFrames: number
  falsePositiveRate: string
  detectionRate: string
  expectedRate: string
  checksumValidRate: string
  validationRate: string
  validationCount: number
  distinctValues: number
  mostFrequentValue: string | null
  mostFrequentValueCount: number
  temporalStability: string
  correctStability: string
  wrongValueStability: string
  averageSharpness: number | null
  medianSharpness: number | null
  minSharpness: number | null
  maxSharpness: number | null
  averageWidthRatio: number | null
  medianWidthRatio: number | null
  timeToFirstDetectionMs: number | null
  timeToFirstValidChecksumMs: number | null
  timeToFirstCorrectMs: number | null
  timeToValidationMs: number | null
  confidenceScore: number
  overallScore: OverallScoreBreakdown
  status: ConfigStatus
  highFalsePositiveRisk: boolean
  errorMessage: string | null
  orderIndex: number
}

export interface Phase2BenchmarkMetadata {
  benchmarkId: string
  date: string
  browser: string
  userAgent: string
  devicePixelRatio: number
  facingMode: string | null
  actualResolution: string | null
  frameRate: number | null
  randomSeed: number | null
  orderMode: OrderMode
  mode: BenchmarkMode
  validationPolicy: ValidationPolicy
  scoreWeights: typeof BARCODE_SCORE_WEIGHTS
  overallScoreWeights: typeof OVERALL_SCORE_WEIGHTS
  expectedBarcodes: ExpectedBarcodeSpec[]
  physicalBarcodeConfirmed: boolean
  physicalConfirmationMethod: PhysicalConfirmationMethod
  durationSeconds: number
  settleMs: number
  focusSupported: boolean
  zoomSupported: boolean
}

function computeMedian(values: number[]): number | null {
  if (values.length === 0) {
    return null
  }

  const sorted = [...values].sort((a, b) => a - b)
  const middle = Math.floor(sorted.length / 2)

  return sorted.length % 2 === 0
    ? (sorted[middle - 1]! + sorted[middle]!) / 2
    : sorted[middle]!
}

export function computeEan8CheckDigit(digits7: string): number | null {
  if (!/^\d{7}$/.test(digits7)) {
    return null
  }

  let sum = 0

  for (let index = 0; index < 7; index += 1) {
    const digit = Number.parseInt(digits7[index]!, 10)
    sum += digit * (index % 2 === 0 ? 3 : 1)
  }

  return (10 - (sum % 10)) % 10
}

export function isValidEan8(value: string): boolean {
  if (!/^\d{8}$/.test(value)) {
    return false
  }

  const expected = computeEan8CheckDigit(value.slice(0, 7))

  return expected != null && expected === Number.parseInt(value[7]!, 10)
}

export function isCheckDigitValid(format: string, rawValue: string): boolean {
  const normalized = format.toLowerCase().replace('-', '_')

  if (normalized === 'ean_13') {
    return isValidEan13(rawValue)
  }

  if (normalized === 'upc_a') {
    return isValidUpcA(rawValue)
  }

  if (normalized === 'ean_8') {
    return isValidEan8(rawValue)
  }

  if (/^\d{13}$/.test(rawValue)) {
    return isValidEan13(rawValue)
  }

  if (/^\d{12}$/.test(rawValue)) {
    return isValidUpcA(rawValue)
  }

  if (/^\d{8}$/.test(rawValue)) {
    return isValidEan8(rawValue)
  }

  return false
}

export function matchesExpectedBarcode(
  rawValue: string,
  format: string,
  expectedBarcodes: ExpectedBarcodeSpec[],
): boolean {
  return expectedBarcodes.some(
    (item) => item.value === rawValue && normalizeFormat(format) === normalizeFormat(item.format),
  )
}

export function matchesExpectedValue(rawValue: string, expectedBarcodes: ExpectedBarcodeSpec[]): boolean {
  return expectedBarcodes.some((item) => item.value === rawValue)
}

function normalizeFormat(format: string): string {
  return format.toLowerCase().replace('-', '_')
}

export function isZoomSupported(capabilities: TrackCapabilitiesSnapshot): boolean {
  return capabilities.zoom.supported && capabilities.zoom.max != null && capabilities.zoom.min != null
}

export function resolveZoomLevels(capabilities: TrackCapabilitiesSnapshot): number[] {
  if (!isZoomSupported(capabilities)) {
    return [1.0]
  }

  const max = capabilities.zoom.max ?? 2.0

  return DEFAULT_ZOOM_LEVELS.filter((value) => value >= (capabilities.zoom.min ?? 1) && value <= max)
}

export function buildPhase2Configurations(options: {
  mode: BenchmarkMode
  expectedBarcodes: ExpectedBarcodeSpec[]
  focusLevels: number[]
  resolutions: ResolutionPreset[]
  targetSizes: TargetSizeSpec[]
  zoomLevels: number[]
  orderMode: OrderMode
  randomSeed?: number
}): Phase2RunConfiguration[] {
  let resolutions = options.resolutions
  let targetSizes = options.targetSizes
  let focusLevels = options.focusLevels
  let zoomLevels = options.zoomLevels

  if (options.mode === 'QUICK') {
    resolutions = options.resolutions.filter((item) => item.id === QUICK_MODE.resolutionId)
    targetSizes = options.targetSizes.filter((item) => item.id === QUICK_MODE.targetId)
    focusLevels = [...QUICK_MODE.focusLevels]
    zoomLevels = [...QUICK_MODE.zoomLevels]
  }

  const base: Phase2RunConfiguration[] = []

  for (const resolution of resolutions) {
    for (const targetSize of targetSizes) {
      for (const focus of focusLevels) {
        for (const zoom of zoomLevels) {
          base.push({
            id: `${resolution.id}-${targetSize.id}-f${focus}-z${zoom}`,
            orderIndex: 0,
            resolution,
            targetSize,
            focusRequested: focus,
            zoomRequested: zoom,
            label: `${resolution.label} / ${targetSize.label} / focus ${focus} / zoom ${zoom}×`,
          })
        }
      }
    }
  }

  const ordered = options.orderMode === 'RANDOMIZED'
    ? seededShuffle(base, options.randomSeed ?? Date.now())
    : base

  return ordered.map((item, index) => ({ ...item, orderIndex: index }))
}

export function countPhase2Configurations(options: Parameters<typeof buildPhase2Configurations>[0]): number {
  return buildPhase2Configurations(options).length
}

function repetitionScore(count: number): number {
  if (count >= 4) {
    return BARCODE_SCORE_WEIGHTS.repetition
  }

  if (count === 3) {
    return BARCODE_SCORE_WEIGHTS.repetition * 0.83
  }

  if (count === 2) {
    return BARCODE_SCORE_WEIGHTS.repetition * 0.42
  }

  return 0
}

function stabilityScore(stabilityPercent: number): number {
  if (stabilityPercent >= 90) {
    return BARCODE_SCORE_WEIGHTS.stability
  }

  if (stabilityPercent >= 75) {
    return BARCODE_SCORE_WEIGHTS.stability * 0.67
  }

  if (stabilityPercent >= 50) {
    return BARCODE_SCORE_WEIGHTS.stability * 0.33
  }

  return 0
}

function normalizedSharpnessScore(sharpness: number | null, referenceMax = 1200): number {
  if (sharpness == null || !Number.isFinite(sharpness) || sharpness <= 0) {
    return 0
  }

  return Number(((Math.min(sharpness, referenceMax) / referenceMax) * BARCODE_SCORE_WEIGHTS.sharpness).toFixed(2))
}

function normalizedWidthRatioScore(widthRatio: number | null, target?: TargetSizeSpec): number {
  if (widthRatio == null || !Number.isFinite(widthRatio)) {
    return 0
  }

  if (target) {
    const center = (target.min + target.max) / 2
    const halfRange = Math.max((target.max - target.min) / 2, 0.05)
    const distance = Math.abs(widthRatio - center)

    return Number((Math.max(0, 1 - distance / halfRange) * BARCODE_SCORE_WEIGHTS.widthRatio).toFixed(2))
  }

  return Number((Math.min(widthRatio, 0.9) / 0.9 * BARCODE_SCORE_WEIGHTS.widthRatio).toFixed(2))
}

export function calculateBarcodeConfidenceScore(input: {
  checkDigitValid: boolean
  formatMatches: boolean
  isExpected: boolean
  sameValueCount: number
  temporalStabilityPercent: number
  sharpness: number | null
  widthRatio: number | null
  targetSize?: TargetSizeSpec
}): number {
  const score =
    (input.checkDigitValid ? BARCODE_SCORE_WEIGHTS.checksum : 0)
    + (input.formatMatches ? BARCODE_SCORE_WEIGHTS.format : 0)
    + (input.isExpected ? BARCODE_SCORE_WEIGHTS.expectedValue : 0)
    + repetitionScore(input.sameValueCount)
    + stabilityScore(input.temporalStabilityPercent)
    + normalizedSharpnessScore(input.sharpness)
    + normalizedWidthRatioScore(input.widthRatio, input.targetSize)

  return Number(Math.min(100, score).toFixed(2))
}

export function classifyPhase2Detection(input: {
  rawValue: string
  format: string
  expectedBarcodes: ExpectedBarcodeSpec[]
  checkDigitValid: boolean
  sameValueDistinctFrameCount: number
  validated: boolean
  hasAnyDetection: boolean
}): ValidationState {
  if (!input.hasAnyDetection) {
    return 'NO_DETECTION'
  }

  if (!/^\d+$/.test(input.rawValue)) {
    return 'INVALID_FORMAT'
  }

  const isExpected = matchesExpectedBarcode(input.rawValue, input.format, input.expectedBarcodes)
  const valueMatch = matchesExpectedValue(input.rawValue, input.expectedBarcodes)

  if (input.validated && isExpected) {
    return 'EXPECTED_VALIDATED'
  }

  if (isExpected && input.sameValueDistinctFrameCount >= 2) {
    return 'EXPECTED_CANDIDATE'
  }

  if (isExpected) {
    return 'EXPECTED_SINGLE'
  }

  if (input.checkDigitValid && !valueMatch) {
    return 'WRONG_VALID_CHECKSUM'
  }

  if (!input.checkDigitValid) {
    return input.rawValue.length >= 8 ? 'INVALID_CHECKSUM' : 'FALSE_POSITIVE'
  }

  return 'UNSTABLE'
}

export function computeTemporalStability(values: string[]): number {
  if (values.length === 0) {
    return 0
  }

  const mostFrequent = computeMostFrequent(values)

  return (mostFrequent.count / values.length) * 100
}

export function computeCorrectStability(values: string[], expectedBarcodes: ExpectedBarcodeSpec[]): number {
  const expectedValues = new Set(expectedBarcodes.map((item) => item.value))
  const correct = values.filter((value) => expectedValues.has(value))

  if (correct.length === 0) {
    return 0
  }

  return (computeMostFrequent(correct).count / values.length) * 100
}

export function evaluateValidationPolicy(
  detections: Phase2RawDetection[],
  expectedBarcodes: ExpectedBarcodeSpec[],
  policy: ValidationPolicy,
): {
  validated: boolean
  validatedAtMs: number | null
  validationCount: number
} {
  const expectedDetections = detections
    .filter((item) => matchesExpectedBarcode(item.rawValue, item.format, expectedBarcodes) && item.checkDigitValid)
    .sort((a, b) => a.elapsedMs - b.elapsedMs)

  const distinctFrameConfirmations: Phase2RawDetection[] = []
  const seenFrames = new Set<number>()

  for (const detection of expectedDetections) {
    if (seenFrames.has(detection.frameIndex)) {
      continue
    }

    const previous = distinctFrameConfirmations.at(-1)

    if (previous && detection.elapsedMs - previous.elapsedMs < policy.minimumTimeBetweenConfirmationsMs) {
      continue
    }

    distinctFrameConfirmations.push(detection)
    seenFrames.add(detection.frameIndex)
  }

  const stability = computeTemporalStability(detections.map((item) => item.rawValue))
  const lastConfirmation = distinctFrameConfirmations.at(-1)
  const score = lastConfirmation?.confidenceScore ?? 0
  const validated =
    distinctFrameConfirmations.length >= policy.minimumConfirmations
    && stability >= policy.minimumStability
    && score >= policy.minimumScore

  return {
    validated,
    validatedAtMs: validated ? lastConfirmation?.elapsedMs ?? null : null,
    validationCount: distinctFrameConfirmations.length,
  }
}

export function computeFalsePositiveRate(input: {
  detections: number
  expectedDetections: number
}): number {
  if (input.detections === 0) {
    return 0
  }

  return ((input.detections - input.expectedDetections) / input.detections) * 100
}

export function isHighFalsePositiveRisk(result: Pick<
  Phase2ConfigurationResult,
  'detections' | 'expectedDetections' | 'wrongValidChecksumDetections' | 'detectionRate' | 'expectedRate'
>): boolean {
  const detectionRate = Number.parseFloat(result.detectionRate)
  const expectedRate = Number.parseFloat(result.expectedRate)

  if (!Number.isFinite(detectionRate) || detectionRate <= 0) {
    return false
  }

  if (expectedRate < detectionRate * 0.5) {
    return true
  }

  return result.wrongValidChecksumDetections > result.expectedDetections
}

export function computeOverallScore(input: {
  expectedRate: number
  validationRate: number
  temporalStability: number
  falsePositiveRate: number
  detectionRate: number
  timeToValidationMs: number | null
  durationMs: number
}): OverallScoreBreakdown {
  const expectedCorrectness = Math.min(100, input.expectedRate)
  const validationRate = Math.min(100, input.validationRate)
  const temporalStability = Math.min(100, input.temporalStability)
  const falsePositiveResistance = Math.max(0, 100 - input.falsePositiveRate)
  const detectionRate = Math.min(100, input.detectionRate)
  let speed = 0

  if (input.timeToValidationMs != null && input.durationMs > 0) {
    speed = Math.max(0, 100 - (input.timeToValidationMs / input.durationMs) * 100)
  }

  return {
    overall: Number((
      expectedCorrectness * OVERALL_SCORE_WEIGHTS.expectedCorrectness
      + validationRate * OVERALL_SCORE_WEIGHTS.validationRate
      + temporalStability * OVERALL_SCORE_WEIGHTS.temporalStability
      + falsePositiveResistance * OVERALL_SCORE_WEIGHTS.falsePositiveResistance
      + detectionRate * OVERALL_SCORE_WEIGHTS.detectionRate
      + speed * OVERALL_SCORE_WEIGHTS.speed
    ).toFixed(2)),
    expectedCorrectness: Number(expectedCorrectness.toFixed(2)),
    validationRate: Number(validationRate.toFixed(2)),
    temporalStability: Number(temporalStability.toFixed(2)),
    falsePositiveResistance: Number(falsePositiveResistance.toFixed(2)),
    detectionRate: Number(detectionRate.toFixed(2)),
    speed: Number(speed.toFixed(2)),
  }
}

export function determineConfigStatus(input: {
  frames: number
  detections: number
  expectedDetections: number
  validationCount: number
  validated: boolean
  errorMessage: string | null
}): ConfigStatus {
  if (input.errorMessage) {
    return 'CONFIGURATION_ERROR'
  }

  if (input.frames < 5) {
    return 'INSUFFICIENT_DATA'
  }

  if (input.detections === 0) {
    return 'NO_DETECTION'
  }

  if (input.validated) {
    return 'VALIDATED'
  }

  if (input.expectedDetections >= 2) {
    return 'REPEATABLE_CORRECT'
  }

  if (input.expectedDetections === 1) {
    return 'EXPECTED_ONCE'
  }

  if (input.detections > 0 && input.expectedDetections === 0) {
    return 'VALID_CHECKSUM_WRONG_VALUE'
  }

  return 'DETECTION_ONLY'
}

export function createEmptyPhase2Result(config: Phase2RunConfiguration): Phase2ConfigurationResult {
  return {
    configurationId: config.id,
    label: config.label,
    resolutionLabel: config.resolution.label,
    requestedResolution: `${config.resolution.width}×${config.resolution.height}`,
    actualResolution: '—',
    targetSizeLabel: config.targetSize.label,
    focusRequested: config.focusRequested,
    focusActual: '—',
    focusApplied: false,
    zoomRequested: config.zoomRequested,
    zoomActual: '—',
    zoomApplied: false,
    frames: 0,
    detections: 0,
    expectedDetections: 0,
    wrongDetections: 0,
    validChecksumDetections: 0,
    wrongValidChecksumDetections: 0,
    invalidChecksumDetections: 0,
    noDetectionFrames: 0,
    falsePositiveRate: '—',
    detectionRate: '—',
    expectedRate: '—',
    checksumValidRate: '—',
    validationRate: '—',
    validationCount: 0,
    distinctValues: 0,
    mostFrequentValue: null,
    mostFrequentValueCount: 0,
    temporalStability: '—',
    correctStability: '—',
    wrongValueStability: '—',
    averageSharpness: null,
    medianSharpness: null,
    minSharpness: null,
    maxSharpness: null,
    averageWidthRatio: null,
    medianWidthRatio: null,
    timeToFirstDetectionMs: null,
    timeToFirstValidChecksumMs: null,
    timeToFirstCorrectMs: null,
    timeToValidationMs: null,
    confidenceScore: 0,
    overallScore: computeOverallScore({
      expectedRate: 0,
      validationRate: 0,
      temporalStability: 0,
      falsePositiveRate: 0,
      detectionRate: 0,
      timeToValidationMs: null,
      durationMs: DEFAULT_DURATION_SECONDS * 1000,
    }),
    status: 'INSUFFICIENT_DATA',
    highFalsePositiveRisk: false,
    errorMessage: null,
    orderIndex: config.orderIndex,
  }
}

export function finalizePhase2Result(
  config: Phase2RunConfiguration,
  applied: AppliedExperimentSnapshot,
  options: {
    dims: ReturnType<typeof getEffectiveVideoDimensions>
    frames: number
    detections: Phase2RawDetection[]
    expectedBarcodes: ExpectedBarcodeSpec[]
    validationPolicy: ValidationPolicy
    durationMs: number
    focusSupported: boolean
    zoomSupported: boolean
    errorMessage?: string | null
  },
): Phase2ConfigurationResult {
  const base = createEmptyPhase2Result(config)
  const values = options.detections.map((item) => item.rawValue)
  const mostFrequent = computeMostFrequent(values)
  const expectedDetections = options.detections.filter((item) => item.isExpected).length
  const validChecksumDetections = options.detections.filter((item) => item.checkDigitValid).length
  const wrongValidChecksumDetections = options.detections.filter((item) => item.validationState === 'WRONG_VALID_CHECKSUM').length
  const invalidChecksumDetections = options.detections.filter((item) =>
    item.validationState === 'INVALID_CHECKSUM' || item.validationState === 'FALSE_POSITIVE',
  ).length
  const sharpnessValues = options.detections.map((item) => item.sharpness).filter((value): value is number => value != null)
  const widthRatios = options.detections.map((item) => item.widthRatio).filter((value): value is number => value != null)
  const validation = evaluateValidationPolicy(options.detections, options.expectedBarcodes, options.validationPolicy)
  const temporalStability = computeTemporalStability(values)
  const correctStability = computeCorrectStability(values, options.expectedBarcodes)
  const wrongValues = values.filter((value) => !matchesExpectedValue(value, options.expectedBarcodes))
  const wrongStability = wrongValues.length ? computeTemporalStability(wrongValues) : 0
  const falsePositiveRateValue = computeFalsePositiveRate({
    detections: options.detections.length,
    expectedDetections,
  })
  const detectionRateValue = options.frames > 0 ? (options.detections.length / options.frames) * 100 : 0
  const expectedRateValue = options.frames > 0 ? (expectedDetections / options.frames) * 100 : 0
  const checksumValidRateValue = options.detections.length > 0 ? (validChecksumDetections / options.detections.length) * 100 : 0
  const validationRateValue = options.detections.length > 0 ? (validation.validationCount / options.detections.length) * 100 : 0
  const confidenceScores = options.detections.map((item) => item.confidenceScore)
  const firstDetection = options.detections[0] ?? null
  const firstValidChecksum = options.detections.find((item) => item.checkDigitValid) ?? null
  const firstCorrect = options.detections.find((item) => item.isExpected) ?? null

  const result: Phase2ConfigurationResult = {
    ...base,
    actualResolution: `${options.dims.nativeWidth}×${options.dims.nativeHeight}`,
    focusActual: applied.actualFocusDistance,
    focusApplied: options.focusSupported && applied.configurationStatus === 'VALID',
    zoomActual: applied.actualZoom,
    zoomApplied: options.zoomSupported && applied.zoomValidation === 'MATCH',
    frames: options.frames,
    detections: options.detections.length,
    expectedDetections,
    wrongDetections: options.detections.length - expectedDetections,
    validChecksumDetections,
    wrongValidChecksumDetections,
    invalidChecksumDetections,
    noDetectionFrames: options.frames - options.detections.length,
    falsePositiveRate: `${falsePositiveRateValue.toFixed(1)}%`,
    detectionRate: `${detectionRateValue.toFixed(1)}%`,
    expectedRate: `${expectedRateValue.toFixed(1)}%`,
    checksumValidRate: `${checksumValidRateValue.toFixed(1)}%`,
    validationRate: `${validationRateValue.toFixed(1)}%`,
    validationCount: validation.validationCount,
    distinctValues: computeDistinctValues(values),
    mostFrequentValue: mostFrequent.value,
    mostFrequentValueCount: mostFrequent.count,
    temporalStability: `${temporalStability.toFixed(1)}%`,
    correctStability: `${correctStability.toFixed(1)}%`,
    wrongValueStability: `${wrongStability.toFixed(1)}%`,
    averageSharpness: average(sharpnessValues),
    medianSharpness: computeMedian(sharpnessValues),
    minSharpness: sharpnessValues.length ? Math.min(...sharpnessValues) : null,
    maxSharpness: sharpnessValues.length ? Math.max(...sharpnessValues) : null,
    averageWidthRatio: widthRatios.length ? Number(average(widthRatios)!.toFixed(4)) : null,
    medianWidthRatio: computeMedian(widthRatios),
    timeToFirstDetectionMs: firstDetection?.elapsedMs ?? null,
    timeToFirstValidChecksumMs: firstValidChecksum?.elapsedMs ?? null,
    timeToFirstCorrectMs: firstCorrect?.elapsedMs ?? null,
    timeToValidationMs: validation.validatedAtMs,
    confidenceScore: confidenceScores.length ? Number(average(confidenceScores)!.toFixed(2)) : 0,
    overallScore: computeOverallScore({
      expectedRate: expectedRateValue,
      validationRate: validationRateValue,
      temporalStability,
      falsePositiveRate: falsePositiveRateValue,
      detectionRate: detectionRateValue,
      timeToValidationMs: validation.validatedAtMs,
      durationMs: options.durationMs,
    }),
    status: 'INSUFFICIENT_DATA',
    highFalsePositiveRisk: false,
    errorMessage: options.errorMessage ?? null,
  }

  result.status = determineConfigStatus({
    frames: result.frames,
    detections: result.detections,
    expectedDetections: result.expectedDetections,
    validationCount: result.validationCount,
    validated: validation.validated,
    errorMessage: result.errorMessage,
  })
  result.highFalsePositiveRisk = isHighFalsePositiveRisk(result)

  return result
}

export function markExperimentalBest(results: Phase2ConfigurationResult[]): Phase2ConfigurationResult[] {
  const eligible = results.filter((item) =>
    item.expectedDetections >= EXPERIMENTAL_BEST_MINIMUMS.minimumExpectedReads
    && item.validationCount >= EXPERIMENTAL_BEST_MINIMUMS.minimumValidationCount
    && item.overallScore.overall >= EXPERIMENTAL_BEST_MINIMUMS.minimumOverallScore
    && Number.parseFloat(item.falsePositiveRate) <= EXPERIMENTAL_BEST_MINIMUMS.maximumFalsePositiveRate,
  )

  if (eligible.length === 0) {
    return results
  }

  const best = [...eligible].sort((a, b) => b.overallScore.overall - a.overallScore.overall)[0]!

  return results.map((item) =>
    item.configurationId === best.configurationId ? { ...item, status: 'EXPERIMENTAL_BEST' } : item,
  )
}

export function pickTopResults(results: Phase2ConfigurationResult[], limit: number | 'all'): Phase2ConfigurationResult[] {
  const sorted = [...results].sort((a, b) => b.overallScore.overall - a.overallScore.overall)

  return limit === 'all' ? sorted : sorted.slice(0, limit)
}

export function buildWhyConfigurationWon(result: Phase2ConfigurationResult): string[] {
  const lines = ['Pourquoi cette configuration est classée #1 :']

  if (Number.parseFloat(result.expectedRate) >= 10) {
    lines.push('✓ meilleure expected-rate observée')
  }

  if (Number.parseFloat(result.temporalStability) >= 50) {
    lines.push('✓ bonne stabilité temporelle')
  }

  if (!result.highFalsePositiveRisk) {
    lines.push('✓ faible taux de faux positifs relatif')
  }

  if (Number.parseFloat(result.checksumValidRate) >= 50) {
    lines.push('✓ checksum généralement valide')
  }

  if (result.timeToValidationMs != null) {
    lines.push('✓ temps de validation acceptable')
  }

  lines.push('', 'Limites :', `⚠️ ${result.validationCount} confirmations`, `⚠️ ${result.detections} détections`, '⚠️ barcode unique', '⚠️ DEV only')

  return lines
}

export function buildHeatmapData(
  results: Phase2ConfigurationResult[],
  xKey: 'targetSizeLabel' | 'resolutionLabel',
  yKey: 'focusRequested' | 'resolutionLabel',
  valueKey: 'expectedRate' | 'overallScore',
): Array<{ x: string; y: string; value: number; label: string }> {
  return results.map((item) => ({
    x: xKey === 'targetSizeLabel' ? item.targetSizeLabel : item.resolutionLabel,
    y: yKey === 'focusRequested' ? String(item.focusRequested) : item.resolutionLabel,
    value: valueKey === 'expectedRate' ? Number.parseFloat(item.expectedRate) : item.overallScore.overall,
    label: item.label,
  }))
}

export function buildPhase2Warnings(options: {
  physicalBarcodeConfirmed: boolean
  expectedBarcodes: ExpectedBarcodeSpec[]
  focusSupported: boolean
  zoomSupported: boolean
  results: Phase2ConfigurationResult[]
}): string[] {
  const warnings: string[] = []

  if (!options.physicalBarcodeConfirmed) {
    warnings.push('⚠️ Physical barcode not confirmed')
  }

  if (options.expectedBarcodes.length <= 1) {
    warnings.push('⚠️ Single barcode benchmark')
  }

  if (!options.focusSupported) {
    warnings.push('⚠️ Focus not actually supported')
  }

  if (!options.zoomSupported) {
    warnings.push('⚠️ Zoom not actually supported')
  }

  if (options.results.some((item) => item.detections < 3 && item.frames > 0)) {
    warnings.push('⚠️ Sample size too small')
  }

  if (options.results.some((item) => item.highFalsePositiveRisk)) {
    warnings.push('⚠️ False-positive risk')
  }

  return warnings
}

export function buildPhase2Report(options: {
  metadata: Phase2BenchmarkMetadata
  configurations: Phase2RunConfiguration[]
  results: Phase2ConfigurationResult[]
  warnings: string[]
  bestConfiguration: Phase2ConfigurationResult | null
}): string {
  const completed = options.results.filter((item) => item.frames > 0 && !item.errorMessage).length
  const skipped = options.results.filter((item) => item.errorMessage).length
  const totals = options.results.reduce(
    (acc, item) => {
      acc.frames += item.frames
      acc.detections += item.detections
      acc.expected += item.expectedDetections
      acc.wrong += item.wrongDetections
      acc.validChecksum += item.validChecksumDetections
      acc.wrongValidChecksum += item.wrongValidChecksumDetections
      acc.validated += item.status === 'VALIDATED' || item.status === 'EXPERIMENTAL_BEST' ? 1 : 0
      return acc
    },
    { frames: 0, detections: 0, expected: 0, wrong: 0, validChecksum: 0, wrongValidChecksum: 0, validated: 0 },
  )

  return [
    '=== PHASE 2 BENCHMARK REPORT ===',
    '',
    `Benchmark ID: ${options.metadata.benchmarkId}`,
    `Date: ${options.metadata.date}`,
    `Browser: ${options.metadata.browser}`,
    `Mode: ${options.metadata.mode}`,
    options.metadata.randomSeed != null ? `Random seed: ${options.metadata.randomSeed}` : '',
    '',
    `Total configurations: ${options.configurations.length}`,
    `Completed: ${completed}`,
    `Skipped/errors: ${skipped}`,
    `Total frames: ${totals.frames}`,
    `Total detections: ${totals.detections}`,
    `Expected detections: ${totals.expected}`,
    `Wrong detections: ${totals.wrong}`,
    `Valid checksum: ${totals.validChecksum}`,
    `Wrong valid checksum: ${totals.wrongValidChecksum}`,
    `Validated configs: ${totals.validated}`,
    '',
    options.bestConfiguration ? `Best configuration: ${options.bestConfiguration.label}` : 'Best configuration: —',
    '',
    ...options.warnings,
    '',
    'EXPERIMENTAL CANDIDATE — NOT AUTOMATICALLY APPLIED TO PRODUCTION',
  ].filter(Boolean).join('\n')
}

export function buildPhase2Csv(results: Phase2ConfigurationResult[]): string {
  const header = [
    'configurationId', 'resolution', 'target', 'focus', 'zoom', 'frames', 'detections', 'expectedReads',
    'detectionRate', 'expectedRate', 'checksumValidRate', 'falsePositiveRate', 'temporalStability',
    'correctStability', 'medianSharpness', 'medianWidthRatio', 'timeToFirstDetection', 'timeToFirstCorrect',
    'timeToValidation', 'confidenceScore', 'overallScore', 'status',
  ].join(',')

  const rows = results.map((item) => [
    item.configurationId, item.resolutionLabel, item.targetSizeLabel, item.focusRequested, item.zoomRequested,
    item.frames, item.detections, item.expectedDetections, item.detectionRate, item.expectedRate,
    item.checksumValidRate, item.falsePositiveRate, item.temporalStability, item.correctStability,
    item.medianSharpness ?? '', item.medianWidthRatio ?? '', item.timeToFirstDetectionMs ?? '',
    item.timeToFirstCorrectMs ?? '', item.timeToValidationMs ?? '', item.confidenceScore,
    item.overallScore.overall, item.status,
  ].join(','))

  return [header, ...rows].join('\n')
}

export function buildPhase2ExportJson(payload: Record<string, unknown>): string {
  return JSON.stringify(payload, null, 2)
}

export function createBenchmarkMetadata(options: {
  environment: EnvironmentDiagnostics
  trackSettings: TrackSettingsSnapshot
  capabilities: TrackCapabilitiesSnapshot
  mode: BenchmarkMode
  orderMode: OrderMode
  randomSeed: number | null
  expectedBarcodes: ExpectedBarcodeSpec[]
  physicalBarcodeConfirmed: boolean
  physicalConfirmationMethod: PhysicalConfirmationMethod
  durationSeconds: number
  settleMs: number
}): Phase2BenchmarkMetadata {
  return {
    benchmarkId: `phase2-${Date.now()}`,
    date: new Date().toISOString(),
    browser: options.environment.browserLabel,
    userAgent: options.environment.userAgent,
    devicePixelRatio: typeof window !== 'undefined' ? window.devicePixelRatio : 1,
    facingMode: options.trackSettings.facingMode,
    actualResolution: options.trackSettings.width && options.trackSettings.height
      ? `${options.trackSettings.width}×${options.trackSettings.height}`
      : null,
    frameRate: options.trackSettings.frameRate,
    randomSeed: options.randomSeed,
    orderMode: options.orderMode,
    mode: options.mode,
    validationPolicy: { ...DEFAULT_VALIDATION_POLICY },
    scoreWeights: { ...BARCODE_SCORE_WEIGHTS },
    overallScoreWeights: { ...OVERALL_SCORE_WEIGHTS },
    expectedBarcodes: options.expectedBarcodes,
    physicalBarcodeConfirmed: options.physicalBarcodeConfirmed,
    physicalConfirmationMethod: options.physicalConfirmationMethod,
    durationSeconds: options.durationSeconds,
    settleMs: options.settleMs,
    focusSupported: isManualFocusSupported(options.capabilities),
    zoomSupported: isZoomSupported(options.capabilities),
  }
}

export function filterPhase2Results(
  results: Phase2ConfigurationResult[],
  filters: {
    resolution?: string
    target?: string
    focus?: string
    status?: string
    minScore?: number
    validatedOnly?: boolean
    expectedOnly?: boolean
  },
): Phase2ConfigurationResult[] {
  return results.filter((item) => {
    if (filters.resolution && item.resolutionLabel !== filters.resolution) {
      return false
    }

    if (filters.target && item.targetSizeLabel !== filters.target) {
      return false
    }

    if (filters.focus && String(item.focusRequested) !== filters.focus) {
      return false
    }

    if (filters.status && item.status !== filters.status) {
      return false
    }

    if (filters.minScore != null && item.overallScore.overall < filters.minScore) {
      return false
    }

    if (filters.validatedOnly && item.status !== 'VALIDATED' && item.status !== 'EXPERIMENTAL_BEST') {
      return false
    }

    if (filters.expectedOnly && item.expectedDetections === 0) {
      return false
    }

    return true
  })
}

export function sortPhase2Results(
  results: Phase2ConfigurationResult[],
  sortKey: 'confidenceScore' | 'overallScore' | 'expectedRate' | 'detectionRate' | 'validationRate' | 'falsePositiveRate' | 'temporalStability' | 'correctStability' | 'medianSharpness' | 'medianWidthRatio' | 'timeToValidationMs',
): Phase2ConfigurationResult[] {
  const rate = (value: string) => {
    const parsed = Number.parseFloat(value)

    return Number.isFinite(parsed) ? parsed : 0
  }

  return [...results].sort((a, b) => {
    switch (sortKey) {
      case 'confidenceScore':
        return b.confidenceScore - a.confidenceScore
      case 'expectedRate':
        return rate(b.expectedRate) - rate(a.expectedRate)
      case 'detectionRate':
        return rate(b.detectionRate) - rate(a.detectionRate)
      case 'validationRate':
        return rate(b.validationRate) - rate(a.validationRate)
      case 'falsePositiveRate':
        return rate(a.falsePositiveRate) - rate(b.falsePositiveRate)
      case 'temporalStability':
        return rate(b.temporalStability) - rate(a.temporalStability)
      case 'correctStability':
        return rate(b.correctStability) - rate(a.correctStability)
      case 'medianSharpness':
        return (b.medianSharpness ?? 0) - (a.medianSharpness ?? 0)
      case 'medianWidthRatio':
        return (b.medianWidthRatio ?? 0) - (a.medianWidthRatio ?? 0)
      case 'timeToValidationMs':
        return (a.timeToValidationMs ?? Number.MAX_SAFE_INTEGER) - (b.timeToValidationMs ?? Number.MAX_SAFE_INTEGER)
      default:
        return b.overallScore.overall - a.overallScore.overall
    }
  })
}
