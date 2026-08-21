/**
 * Benchmark DEV html5-qrcode — métriques et score expérimental.
 *
 * Score : réutilise calculateBarcodeBenchmarkScore (Quagga2) pour comparabilité.
 *   40 % exactitude · 20 % stabilité · 15 % détection · 15 % anti-FP · 10 % vitesse
 */

import { average, computeRate } from '@/utils/barcodeSizeZoomComparison'
import { computeDistinctValues, computeMostFrequent } from '@/utils/barcodeStabilityFocusRepeatability'
import {
  analyzeMultiFrameConfirmations,
  calculateBarcodeBenchmarkScore,
  DEFAULT_BENCHMARK_DURATION_SECONDS,
  DEFAULT_BENCHMARK_SETTLE_MS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
  type MultiFrameConfirmationLevel,
} from '@/utils/quagga2/quagga2Benchmark'
import {
  classifyQuagga2Detection,
  isCheckDigitValid,
  normalizeQuaggaFormat,
  type Quagga2DetectionKind,
} from '@/utils/quagga2/quagga2Validation'
import {
  Html5Qrcode,
  Html5QrcodeSupportedFormats,
  type Html5QrcodeCameraScanConfig,
  type QrDimensionFunction,
} from 'html5-qrcode'

export {
  DEFAULT_BENCHMARK_DURATION_SECONDS,
  DEFAULT_BENCHMARK_SETTLE_MS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
}

export const HTML5_QRCODE_SCANNER_ELEMENT_ID = 'html5-qrcode-benchmark-scanner'
export const HTML5_QRCODE_DEDUP_WINDOW_MS = 600

export type Html5QrcodeConfigStatus =
  | 'NO_DETECTION'
  | 'CORRECT_ONCE'
  | 'REPEATABLE_CORRECT'
  | 'INCORRECT_DECODING'
  | 'UNSTABLE_DECODING'
  | 'STABLE_INCORRECT'
  | 'INSUFFICIENT_DATA'
  | 'CONFIGURATION_ERROR'

export interface Html5QrcodeDevConfig {
  requestedWidth: number | null
  requestedHeight: number | null
  qrBoxRatio: number
  autoResolution: boolean
  fps: number
  facingMode: 'environment' | 'user'
}

export interface Html5QrcodeBenchmarkConfiguration {
  id: string
  label: string
  config: Html5QrcodeDevConfig
}

export interface Html5QrcodeDetectionPayload {
  rawValue: string
  format: string
}

export interface Html5QrcodeCameraSnapshot {
  requestedWidth: number | null
  requestedHeight: number | null
  actualWidth: number | null
  actualHeight: number | null
  actualFps: number | null
  facingMode: string | null
  qrBoxRatio: number
  html5Config: Html5QrcodeDevConfig
}

export interface Html5QrcodeBenchmarkDetection {
  id: string
  timestamp: string
  elapsedMs: number
  configurationId: string
  configurationLabel: string
  rawValue: string
  format: string
  normalizedFormat: string
  checkDigitValid: boolean
  isExpected: boolean
  classification: Quagga2DetectionKind
  countedForMetrics: boolean
  requestedWidth: number | null
  requestedHeight: number | null
  actualWidth: number | null
  actualHeight: number | null
  actualFps: number | null
  config: Html5QrcodeDevConfig
}

export interface Html5QrcodeBenchmarkScoreBreakdown {
  total: number
  accuracy: number
  stability: number
  detection: number
  falsePositiveResistance: number
  speed: number
}

export interface Html5QrcodeBenchmarkResult {
  configurationId: string
  label: string
  requestedWidth: number | null
  requestedHeight: number | null
  actualWidth: number | null
  actualHeight: number | null
  actualFps: number | null
  qrBoxRatio: number
  frames: number
  detections: number
  rawDetectionEvents: number
  correct: number
  incorrect: number
  invalidCheckDigitDetections: number
  validButWrongEan13Detections: number
  correctRate: string
  detectionRate: string
  distinctValues: number
  mostFrequent: string | null
  checkDigitValidDetections: number
  checkDigitInvalidDetections: number
  correctStability: string
  temporalStability: string
  falsePositiveRate: string
  timeToFirstDetectionMs: number | null
  timeToFirstCorrectMs: number | null
  timeToFirstStableCorrectMs: number | null
  multiFrameLevels: MultiFrameConfirmationLevel[]
  score: Html5QrcodeBenchmarkScoreBreakdown
  status: Html5QrcodeConfigStatus
  errorMessage: string | null
}

export const HTML5_QRCODE_BENCHMARK_CONFIGURATIONS: Html5QrcodeBenchmarkConfiguration[] = [
  {
    id: '1',
    label: '640×480 / qrBox 60%',
    config: { requestedWidth: 640, requestedHeight: 480, qrBoxRatio: 0.6, autoResolution: false, fps: 10, facingMode: 'environment' },
  },
  {
    id: '2',
    label: '640×480 / qrBox 70%',
    config: { requestedWidth: 640, requestedHeight: 480, qrBoxRatio: 0.7, autoResolution: false, fps: 10, facingMode: 'environment' },
  },
  {
    id: '3',
    label: '640×480 / qrBox 80%',
    config: { requestedWidth: 640, requestedHeight: 480, qrBoxRatio: 0.8, autoResolution: false, fps: 10, facingMode: 'environment' },
  },
  {
    id: '4',
    label: '1280×720 / qrBox 60%',
    config: { requestedWidth: 1280, requestedHeight: 720, qrBoxRatio: 0.6, autoResolution: false, fps: 10, facingMode: 'environment' },
  },
  {
    id: '5',
    label: '1280×720 / qrBox 70%',
    config: { requestedWidth: 1280, requestedHeight: 720, qrBoxRatio: 0.7, autoResolution: false, fps: 10, facingMode: 'environment' },
  },
  {
    id: '6',
    label: '1280×720 / qrBox 80%',
    config: { requestedWidth: 1280, requestedHeight: 720, qrBoxRatio: 0.8, autoResolution: false, fps: 10, facingMode: 'environment' },
  },
  {
    id: '7',
    label: 'auto / qrBox 70%',
    config: { requestedWidth: null, requestedHeight: null, qrBoxRatio: 0.7, autoResolution: true, fps: 10, facingMode: 'environment' },
  },
]

let activeScanner: Html5Qrcode | null = null
let activeSessionId = 0

function buildQrBoxFunction(ratio: number): QrDimensionFunction {
  return (viewfinderWidth, viewfinderHeight) => {
    const minEdge = Math.min(viewfinderWidth, viewfinderHeight)
    const size = Math.floor(minEdge * ratio)

    return { width: size, height: size }
  }
}

function buildVideoConstraints(config: Html5QrcodeDevConfig): MediaTrackConstraints {
  if (config.autoResolution) {
    return { facingMode: config.facingMode }
  }

  return {
    facingMode: config.facingMode,
    width: config.requestedWidth != null ? { ideal: config.requestedWidth } : undefined,
    height: config.requestedHeight != null ? { ideal: config.requestedHeight } : undefined,
  }
}

function buildScanConfig(config: Html5QrcodeDevConfig): Html5QrcodeCameraScanConfig {
  return {
    fps: config.fps,
    qrbox: buildQrBoxFunction(config.qrBoxRatio),
    disableFlip: true,
    videoConstraints: buildVideoConstraints(config),
  }
}

function readCameraSnapshot(scanner: Html5Qrcode, config: Html5QrcodeDevConfig): Html5QrcodeCameraSnapshot {
  let settings: MediaTrackSettings | undefined

  try {
    settings = scanner.getRunningTrackSettings()
  } catch {
    settings = undefined
  }

  return {
    requestedWidth: config.requestedWidth,
    requestedHeight: config.requestedHeight,
    actualWidth: settings?.width ?? null,
    actualHeight: settings?.height ?? null,
    actualFps: settings?.frameRate ?? null,
    facingMode: settings?.facingMode ?? config.facingMode,
    qrBoxRatio: config.qrBoxRatio,
    html5Config: config,
  }
}

export async function stopHtml5QrcodeScanner(): Promise<void> {
  if (activeScanner) {
    try {
      if (activeScanner.isScanning) {
        await activeScanner.stop()
      }

      activeScanner.clear()
    } catch {
      // Arrêt best-effort — la session suivante recréera l'instance.
    }

    activeScanner = null
  }

  activeSessionId += 1
}

export function isHtml5QrcodeRunning(): boolean {
  return activeScanner?.isScanning ?? false
}

export async function startHtml5QrcodeScanner(options: {
  target: HTMLElement
  config: Html5QrcodeDevConfig
  onDetected: (payload: Html5QrcodeDetectionPayload) => void
  onError?: (error: Error) => void
}): Promise<{ sessionId: number; camera: Html5QrcodeCameraSnapshot; stop: () => Promise<void> }> {
  await stopHtml5QrcodeScanner()

  const sessionId = activeSessionId + 1
  activeSessionId = sessionId

  options.target.innerHTML = ''
  options.target.id = HTML5_QRCODE_SCANNER_ELEMENT_ID

  const scanner = new Html5Qrcode(HTML5_QRCODE_SCANNER_ELEMENT_ID, {
    formatsToSupport: [
      Html5QrcodeSupportedFormats.EAN_13,
      Html5QrcodeSupportedFormats.EAN_8,
      Html5QrcodeSupportedFormats.UPC_A,
    ],
    verbose: false,
    useBarCodeDetectorIfSupported: false,
  })

  activeScanner = scanner

  const scanConfig = buildScanConfig(options.config)

  try {
    await scanner.start(
      { facingMode: options.config.facingMode },
      scanConfig,
      (decodedText, result) => {
        if (sessionId !== activeSessionId) {
          return
        }

        options.onDetected({
          rawValue: decodedText,
          format: result.result.format?.formatName ?? 'unknown',
        })
      },
      () => {
        // NO_CODE_FOUND — ignoré pendant le benchmark
      },
    )

    await new Promise((resolve) => window.setTimeout(resolve, 500))

    return {
      sessionId,
      camera: readCameraSnapshot(scanner, options.config),
      stop: async () => {
        if (sessionId === activeSessionId) {
          await stopHtml5QrcodeScanner()
        }
      },
    }
  } catch (error) {
    await stopHtml5QrcodeScanner()
    const wrapped = error instanceof Error ? error : new Error(String(error))
    options.onError?.(wrapped)
    throw wrapped
  }
}

export function shouldCountDetection(
  previous: Html5QrcodeBenchmarkDetection | null,
  rawValue: string,
  elapsedMs: number,
  dedupWindowMs = HTML5_QRCODE_DEDUP_WINDOW_MS,
): boolean {
  if (!previous) {
    return true
  }

  if (previous.rawValue !== rawValue) {
    return true
  }

  return elapsedMs - previous.elapsedMs > dedupWindowMs
}

export function recordHtml5QrcodeDetection(input: {
  configurationId: string
  configurationLabel: string
  elapsedMs: number
  payload: Html5QrcodeDetectionPayload
  config: Html5QrcodeDevConfig
  camera: Html5QrcodeCameraSnapshot
  expectedBarcode: string
  expectedFormat: string
  previousCounted: Html5QrcodeBenchmarkDetection | null
}): Html5QrcodeBenchmarkDetection {
  const normalizedFormat = normalizeQuaggaFormat(input.payload.format)
  const checkDigitValid = isCheckDigitValid(normalizedFormat, input.payload.rawValue)
  const isExpected =
    input.payload.rawValue === input.expectedBarcode
    && normalizedFormat === input.expectedFormat.replace('-', '_')
  const classification = classifyQuagga2Detection(
    input.payload.rawValue,
    input.payload.format,
    input.expectedBarcode,
    input.expectedFormat,
  )
  const countedForMetrics = shouldCountDetection(
    input.previousCounted,
    input.payload.rawValue,
    input.elapsedMs,
  )

  return {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    elapsedMs: input.elapsedMs,
    configurationId: input.configurationId,
    configurationLabel: input.configurationLabel,
    rawValue: input.payload.rawValue,
    format: input.payload.format,
    normalizedFormat,
    checkDigitValid,
    isExpected,
    classification,
    countedForMetrics,
    requestedWidth: input.camera.requestedWidth,
    requestedHeight: input.camera.requestedHeight,
    actualWidth: input.camera.actualWidth,
    actualHeight: input.camera.actualHeight,
    actualFps: input.camera.actualFps,
    config: input.config,
  }
}

export function determineHtml5QrcodeStatus(input: {
  frames: number
  detections: number
  correct: number
  incorrect: number
  distinctValues: number
  mostFrequent: string | null
  expectedBarcode: string
  temporalStability: number
}): Html5QrcodeConfigStatus {
  if (input.frames < 10) {
    return 'INSUFFICIENT_DATA'
  }

  if (input.detections === 0) {
    return 'NO_DETECTION'
  }

  if (input.correct === 0) {
    if (input.distinctValues === 1 && input.mostFrequent && input.mostFrequent !== input.expectedBarcode) {
      return 'STABLE_INCORRECT'
    }

    if (input.distinctValues >= 2 || input.temporalStability < 70) {
      return 'UNSTABLE_DECODING'
    }

    return 'INCORRECT_DECODING'
  }

  if (input.correct === 1) {
    return input.incorrect > 0 && input.distinctValues >= 2 ? 'UNSTABLE_DECODING' : 'CORRECT_ONCE'
  }

  if (input.incorrect > 0 && input.correct / input.detections < 0.7) {
    return 'UNSTABLE_DECODING'
  }

  return 'REPEATABLE_CORRECT'
}

export function finalizeHtml5QrcodeBenchmarkResult(input: {
  configuration: Html5QrcodeBenchmarkConfiguration
  camera: Html5QrcodeCameraSnapshot
  frames: number
  allDetections: Html5QrcodeBenchmarkDetection[]
  expectedBarcode: string
  durationMs: number
  errorMessage?: string | null
}): Html5QrcodeBenchmarkResult {
  const detections = input.allDetections.filter((item) => item.countedForMetrics)
  const values = detections.map((item) => item.rawValue)
  const mostFrequent = computeMostFrequent(values)
  const correct = detections.filter((item) => item.classification === 'CORRECT').length
  const incorrect = detections.length - correct
  const checkDigitValidDetections = detections.filter((item) => item.checkDigitValid).length
  const invalidCheckDigitDetections = detections.filter((item) => !item.checkDigitValid).length
  const validButWrongEan13Detections = detections.filter((item) => item.classification === 'WRONG_VALID_CHECKSUM').length
  const falsePositiveRate = detections.length > 0 ? ((incorrect / detections.length) * 100) : 0
  const temporalStability = detections.length > 0 ? (mostFrequent.count / detections.length) * 100 : 0
  const correctStability = detections.length > 0 ? (correct / detections.length) * 100 : 0
  const firstDetection = detections[0] ?? null
  const firstCorrect = detections.find((item) => item.classification === 'CORRECT') ?? null
  const multiFrameLevels = analyzeMultiFrameConfirmations(
    detections.map((item) => ({
      id: item.id,
      timestamp: item.timestamp,
      elapsedMs: item.elapsedMs,
      configurationId: item.configurationId,
      rawValue: item.rawValue,
      format: item.format,
      normalizedFormat: item.normalizedFormat,
      checkDigitValid: item.checkDigitValid,
      isExpected: item.isExpected,
      classification: item.classification,
      widthRatio: null,
      frameWidth: item.actualWidth,
      frameHeight: item.actualHeight,
      config: {
        width: item.config.requestedWidth ?? 0,
        height: item.config.requestedHeight ?? 0,
        patchSize: 'medium',
        halfSample: true,
        locate: true,
        frequency: item.config.fps,
        numOfWorkers: 0,
        readers: [],
      },
    })),
    input.expectedBarcode,
  )
  const threeConfirm = multiFrameLevels.find((item) => item.level === 'THREE_CONFIRM')

  return {
    configurationId: input.configuration.id,
    label: input.configuration.label,
    requestedWidth: input.camera.requestedWidth,
    requestedHeight: input.camera.requestedHeight,
    actualWidth: input.camera.actualWidth,
    actualHeight: input.camera.actualHeight,
    actualFps: input.camera.actualFps,
    qrBoxRatio: input.camera.qrBoxRatio,
    frames: input.frames,
    detections: detections.length,
    rawDetectionEvents: input.allDetections.length,
    correct,
    incorrect,
    invalidCheckDigitDetections,
    validButWrongEan13Detections,
    correctRate: computeRate(correct, input.frames),
    detectionRate: computeRate(detections.length, input.frames),
    distinctValues: computeDistinctValues(values),
    mostFrequent: mostFrequent.value,
    checkDigitValidDetections,
    checkDigitInvalidDetections: detections.length - checkDigitValidDetections,
    correctStability: `${correctStability.toFixed(1)}%`,
    temporalStability: `${temporalStability.toFixed(1)}%`,
    falsePositiveRate: `${falsePositiveRate.toFixed(1)}%`,
    timeToFirstDetectionMs: firstDetection?.elapsedMs ?? null,
    timeToFirstCorrectMs: firstCorrect?.elapsedMs ?? null,
    timeToFirstStableCorrectMs: threeConfirm?.timeToConfirmationMs ?? null,
    multiFrameLevels,
    score: calculateBarcodeBenchmarkScore({
      frames: input.frames,
      detections: detections.length,
      correct,
      falsePositiveRate,
      timeToFirstCorrectMs: firstCorrect?.elapsedMs ?? null,
      durationMs: input.durationMs,
    }),
    status: input.errorMessage
      ? 'CONFIGURATION_ERROR'
      : determineHtml5QrcodeStatus({
        frames: input.frames,
        detections: detections.length,
        correct,
        incorrect,
        distinctValues: computeDistinctValues(values),
        mostFrequent: mostFrequent.value,
        expectedBarcode: input.expectedBarcode,
        temporalStability,
      }),
    errorMessage: input.errorMessage ?? null,
  }
}

export function formatResolution(
  width: number | null,
  height: number | null,
): string {
  if (width == null || height == null) {
    return '—'
  }

  return `${width}×${height}`
}

export function buildHtml5QrcodeBenchmarkReport(options: {
  expectedBarcode: string
  expectedFormat: string
  durationSeconds: number
  settleMs: number
  results: Html5QrcodeBenchmarkResult[]
  detections: Html5QrcodeBenchmarkDetection[]
}): string {
  const best = [...options.results].sort((a, b) => b.score.total - a.score.total)[0] ?? null
  const totalDetections = options.detections.filter((item) => item.countedForMetrics).length
  const totalCorrect = options.detections.filter((item) => item.countedForMetrics && item.classification === 'CORRECT').length
  const totalIncorrect = totalDetections - totalCorrect
  const correctRate = totalDetections > 0 ? `${((totalCorrect / totalDetections) * 100).toFixed(1)}%` : '0.0%'
  const totalFrames = options.results.reduce((sum, item) => sum + item.frames, 0)
  const detectionRate = totalFrames > 0 ? `${((totalDetections / totalFrames) * 100).toFixed(1)}%` : '0.0%'
  const twoConfirm = best?.multiFrameLevels.find((item) => item.level === 'TWO_CONFIRM')
  const threeConfirm = best?.multiFrameLevels.find((item) => item.level === 'THREE_CONFIRM')

  return [
    '=== HTML5-QRCODE BENCHMARK REPORT ===',
    '',
    `Expected: ${options.expectedBarcode}`,
    '',
    'Expected format:',
    options.expectedFormat,
    '',
    `Duration: ${options.durationSeconds}s`,
    `Settle: ${options.settleMs}ms`,
    '',
    `Configurations tested: ${options.results.length}`,
    `Total detections: ${totalDetections}`,
    `Correct detections: ${totalCorrect}`,
    `Incorrect detections: ${totalIncorrect}`,
    `Correct rate: ${correctRate}`,
    `Detection rate: ${detectionRate}`,
    best ? `Best configuration: ${best.label}` : 'Best configuration: —',
    best ? `Best score: ${best.score.total}` : 'Best score: —',
    best?.timeToFirstCorrectMs != null ? `Time to first correct: ${best.timeToFirstCorrectMs} ms` : 'Time to first correct: —',
    twoConfirm ? `Two-confirm: ${twoConfirm.confirmations} (${twoConfirm.timeToConfirmationMs ?? '—'} ms)` : 'Two-confirm: —',
    threeConfirm ? `Three-confirm: ${threeConfirm.confirmations} (${threeConfirm.timeToConfirmationMs ?? '—'} ms)` : 'Three-confirm: —',
    '',
    'EXPERIMENTAL ONLY — NOT PRODUCTION',
  ].join('\n')
}

export function buildHtml5QrcodeBenchmarkCsv(results: Html5QrcodeBenchmarkResult[]): string {
  const header = [
    'configurationId', 'label', 'requestedResolution', 'actualResolution', 'qrBoxRatio',
    'frames', 'detections', 'correct', 'incorrect', 'correctRate', 'detectionRate',
    'temporalStability', 'checkDigitValid', 'timeToFirstCorrect', 'score', 'status',
  ].join(',')

  const rows = results.map((item) => [
    item.configurationId,
    item.label,
    formatResolution(item.requestedWidth, item.requestedHeight),
    formatResolution(item.actualWidth, item.actualHeight),
    `${Math.round(item.qrBoxRatio * 100)}%`,
    item.frames,
    item.detections,
    item.correct,
    item.incorrect,
    item.correctRate,
    item.detectionRate,
    item.temporalStability,
    item.checkDigitValidDetections,
    item.timeToFirstCorrectMs ?? '',
    item.score.total,
    item.status,
  ].join(','))

  return [header, ...rows].join('\n')
}

export function pickRankedConfigurations(results: Html5QrcodeBenchmarkResult[]): {
  bestOverall: Html5QrcodeBenchmarkResult | null
  secondOverall: Html5QrcodeBenchmarkResult | null
  thirdOverall: Html5QrcodeBenchmarkResult | null
  bestAccuracy: Html5QrcodeBenchmarkResult | null
  bestStability: Html5QrcodeBenchmarkResult | null
  bestDetectionRate: Html5QrcodeBenchmarkResult | null
  fastestCorrectRead: Html5QrcodeBenchmarkResult | null
} {
  const sortedByScore = [...results].sort((a, b) => b.score.total - a.score.total)
  const parseRate = (value: string): number => Number.parseFloat(value) || 0

  return {
    bestOverall: sortedByScore[0] ?? null,
    secondOverall: sortedByScore[1] ?? null,
    thirdOverall: sortedByScore[2] ?? null,
    bestAccuracy: [...results].sort((a, b) => b.score.accuracy - a.score.accuracy)[0] ?? null,
    bestStability: [...results].sort((a, b) => parseRate(b.temporalStability) - parseRate(a.temporalStability))[0] ?? null,
    bestDetectionRate: [...results].sort((a, b) => parseRate(b.detectionRate) - parseRate(a.detectionRate))[0] ?? null,
    fastestCorrectRead: [...results]
      .filter((item) => item.timeToFirstCorrectMs != null)
      .sort((a, b) => (a.timeToFirstCorrectMs ?? Infinity) - (b.timeToFirstCorrectMs ?? Infinity))[0] ?? null,
  }
}

export function averageActualFps(results: Html5QrcodeBenchmarkResult[]): number | null {
  const values = results.map((item) => item.actualFps).filter((value): value is number => value != null)

  if (values.length === 0) {
    return null
  }

  return Number(average(values)!.toFixed(1))
}
