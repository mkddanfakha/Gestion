<script setup lang="ts">
import {
  applyExperimentConfiguration,
  buildHeatmapData,
  buildPhase2Configurations,
  buildPhase2Csv,
  buildPhase2ExportJson,
  buildPhase2Report,
  buildPhase2Warnings,
  buildResolutionConstraints,
  buildWhyConfigurationWon,
  calculateBarcodeConfidenceScore,
  calculateBarcodeSizeRatio,
  classifyPhase2Detection,
  countPhase2Configurations,
  createBenchmarkMetadata,
  createComparisonBarcodeDetector,
  createEmptyPhase2Result,
  DEFAULT_DURATION_SECONDS,
  DEFAULT_EXPECTED_BARCODES,
  DEFAULT_FOCUS_LEVELS,
  DEFAULT_SETTLE_MS,
  DEFAULT_TARGET_SIZES,
  DEFAULT_VALIDATION_POLICY,
  DETECTION_INTERVAL_MS,
  evaluateValidationPolicy,
  filterPhase2Results,
  finalizePhase2Result,
  getEffectiveVideoDimensions,
  getEnvironmentDiagnostics,
  isCheckDigitValid,
  isManualFocusSupported,
  isNativeBarcodeDetectorAvailable,
  isZoomSupported,
  markExperimentalBest,
  matchesExpectedBarcode,
  measureVideoSharpness,
  normalizeDetections,
  pickTopResults,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  resolveZoomLevels,
  RESOLUTION_PRESETS,
  sortPhase2Results,
  type AppliedExperimentSnapshot,
  type BenchmarkMode,
  type ConfigStatus,
  type ExpectedBarcodeSpec,
  type OrderMode,
  type Phase2BenchmarkMetadata,
  type Phase2ConfigurationResult,
  type Phase2RawDetection,
  type Phase2RunConfiguration,
  type PhysicalConfirmationMethod,
  type ValidationPolicy,
  type ValidationState,
} from '@/utils/barcodeReliabilityPhase2'
import {
  DURATION_OPTIONS,
  SETTLE_OPTIONS,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import type { BarcodeDetectorLike } from '@/utils/barcodeSizeZoomComparison'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000
const MAX_LIVE_EVENTS = 20

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type RunUiState = 'IDLE' | 'RUNNING' | 'PAUSED' | 'STOPPED' | 'COMPLETED'
type BarcodeInputMode = 'simple' | 'json'
type ResultsLimit = 5 | 10 | 'all'

interface LiveEvent {
  id: string
  timestamp: string
  message: string
  level: 'info' | 'warn' | 'error'
}

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const runState = ref<RunUiState>('IDLE')
const isPaused = ref(false)
const cameraError = ref<string | null>(null)
const copyMessage = ref<string | null>(null)

const benchmarkMode = ref<BenchmarkMode>('QUICK')
const orderMode = ref<OrderMode>('ORDERED')
const randomSeed = ref<number | null>(null)
const durationSeconds = ref(DEFAULT_DURATION_SECONDS)
const settleMs = ref(DEFAULT_SETTLE_MS)
const physicalConfirmed = ref(false)
const physicalConfirmationMethod = ref<PhysicalConfirmationMethod>(null)
const barcodeInputMode = ref<BarcodeInputMode>('simple')

const simpleBarcodeValue = ref(DEFAULT_EXPECTED_BARCODES[0]!.value)
const simpleBarcodeFormat = ref(DEFAULT_EXPECTED_BARCODES[0]!.format)
const expectedBarcodesJson = ref(JSON.stringify([...DEFAULT_EXPECTED_BARCODES], null, 2))
const expectedBarcodesParseError = ref<string | null>(null)

const validationPolicy = ref<ValidationPolicy>({ ...DEFAULT_VALIDATION_POLICY })

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<Phase2RunConfiguration[]>([])
const results = ref<Phase2ConfigurationResult[]>([])
const rawDetections = ref<Phase2RawDetection[]>([])
const liveEvents = ref<LiveEvent[]>([])
const metadata = ref<Phase2BenchmarkMetadata | null>(null)
const lastApplied = ref<AppliedExperimentSnapshot | null>(null)

const currentConfig = ref<Phase2RunConfiguration | null>(null)
const currentConfigIndex = ref(0)
const elapsedSeconds = ref(0)
const remainingSeconds = ref(0)
const benchmarkStartedAt = ref<string | null>(null)
const benchmarkFinishedAt = ref<string | null>(null)

const sortKey = ref<Parameters<typeof sortPhase2Results>[1]>('overallScore')
const filterResolution = ref('')
const filterTarget = ref('')
const filterFocus = ref('')
const filterStatus = ref('')
const filterMinScore = ref<number | null>(null)
const filterValidatedOnly = ref(false)
const filterExpectedOnly = ref(false)
const resultsLimit = ref<ResultsLimit>(5)

const liveStatus = ref({
  rawValue: '—',
  format: '—',
  validationState: '—',
  widthRatio: '—',
  sharpness: '—',
  focus: '—',
  zoom: '—',
  confidenceScore: '—',
  frameIndex: 0,
})

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let countdownTimer: number | null = null

const expectedBarcodes = computed<ExpectedBarcodeSpec[]>(() => {
  if (barcodeInputMode.value === 'simple') {
    return [{
      value: simpleBarcodeValue.value.trim(),
      format: simpleBarcodeFormat.value.trim(),
    }]
  }

  try {
    const parsed = JSON.parse(expectedBarcodesJson.value) as ExpectedBarcodeSpec[]

    if (!Array.isArray(parsed) || parsed.length === 0) {
      expectedBarcodesParseError.value = 'JSON must be a non-empty array'
      return []
    }

    expectedBarcodesParseError.value = null
    return parsed.map((item) => ({
      value: String(item.value ?? '').trim(),
      format: String(item.format ?? 'ean_13').trim(),
    }))
  } catch {
    expectedBarcodesParseError.value = 'Invalid JSON'
    return []
  }
})

const manualFocusSupported = computed(() => isManualFocusSupported(capabilities.value))
const zoomSupported = computed(() => isZoomSupported(capabilities.value))
const configCount = computed(() => configurations.value.length)
const previewConfigCount = computed(() => countPhase2Configurations(buildConfigOptions()))

const progressPercent = computed(() =>
  configCount.value > 0 ? Math.round((currentConfigIndex.value / configCount.value) * 100) : 0,
)

const guideStyle = computed(() => {
  const ratio = currentConfig.value?.targetSize.guideWidthRatio ?? 0.45

  return {
    width: `${ratio * 100}%`,
    aspectRatio: '2 / 1',
  }
})

const markedResults = computed(() => markExperimentalBest(results.value))

const filteredResults = computed(() =>
  sortPhase2Results(
    filterPhase2Results(markedResults.value, {
      resolution: filterResolution.value || undefined,
      target: filterTarget.value || undefined,
      focus: filterFocus.value || undefined,
      status: filterStatus.value || undefined,
      minScore: filterMinScore.value ?? undefined,
      validatedOnly: filterValidatedOnly.value,
      expectedOnly: filterExpectedOnly.value,
    }),
    sortKey.value,
  ),
)

const displayedResults = computed(() => pickTopResults(filteredResults.value, resultsLimit.value))

const bestConfiguration = computed(() =>
  markedResults.value.find((item) => item.status === 'EXPERIMENTAL_BEST')
  ?? pickTopResults(markedResults.value, 1)[0]
  ?? null,
)

const bestConfigReasons = computed(() =>
  bestConfiguration.value ? buildWhyConfigurationWon(bestConfiguration.value) : [],
)

const warnings = computed(() => buildPhase2Warnings({
  physicalBarcodeConfirmed: physicalConfirmed.value,
  expectedBarcodes: expectedBarcodes.value,
  focusSupported: manualFocusSupported.value,
  zoomSupported: zoomSupported.value,
  results: markedResults.value,
}))

const resolutionFilterOptions = computed(() =>
  [...new Set(markedResults.value.map((item) => item.resolutionLabel))].sort(),
)

const targetFilterOptions = computed(() =>
  [...new Set(markedResults.value.map((item) => item.targetSizeLabel))].sort(),
)

const focusFilterOptions = computed(() =>
  [...new Set(markedResults.value.map((item) => String(item.focusRequested)))].sort((a, b) => Number(a) - Number(b)),
)

const statusFilterOptions = computed(() =>
  [...new Set(markedResults.value.map((item) => item.status))].sort(),
)

const resolutionChart = computed(() => {
  const map = new Map<string, { expected: number; detections: number; frames: number }>()

  for (const item of markedResults.value) {
    const entry = map.get(item.resolutionLabel) ?? { expected: 0, detections: 0, frames: 0 }
    entry.expected += item.expectedDetections
    entry.detections += item.detections
    entry.frames += item.frames
    map.set(item.resolutionLabel, entry)
  }

  return [...map.entries()].map(([label, stats]) => ({
    label,
    detectionRate: stats.frames > 0 ? (stats.detections / stats.frames) * 100 : 0,
    expectedRate: stats.frames > 0 ? (stats.expected / stats.frames) * 100 : 0,
  }))
})

const focusChart = computed(() => {
  const map = new Map<number, { expected: number; detections: number; frames: number }>()

  for (const item of markedResults.value) {
    const entry = map.get(item.focusRequested) ?? { expected: 0, detections: 0, frames: 0 }
    entry.expected += item.expectedDetections
    entry.detections += item.detections
    entry.frames += item.frames
    map.set(item.focusRequested, entry)
  }

  return [...map.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([focus, stats]) => ({
      label: String(focus),
      detectionRate: stats.frames > 0 ? (stats.detections / stats.frames) * 100 : 0,
      expectedRate: stats.frames > 0 ? (stats.expected / stats.frames) * 100 : 0,
    }))
})

const scatterPoints = computed(() =>
  displayedResults.value
    .filter((item) => item.frames > 0)
    .map((item) => ({
      id: item.configurationId,
      label: item.label,
      x: Number.parseFloat(item.detectionRate),
      y: Number.parseFloat(item.expectedRate),
    })),
)

const expectedRateHeatmap = computed(() =>
  buildHeatmapData(markedResults.value.filter((item) => item.frames > 0), 'targetSizeLabel', 'focusRequested', 'expectedRate'),
)

const overallScoreHeatmap = computed(() =>
  buildHeatmapData(markedResults.value.filter((item) => item.frames > 0), 'targetSizeLabel', 'focusRequested', 'overallScore'),
)

const heatmapXLabels = (cells: ReturnType<typeof buildHeatmapData>) =>
  [...new Set(cells.map((item) => item.x))]

const heatmapYLabels = (cells: ReturnType<typeof buildHeatmapData>) =>
  [...new Set(cells.map((item) => item.y))]

const canStartBenchmark = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && physicalConfirmed.value
  && expectedBarcodes.value.length > 0
  && expectedBarcodes.value.every((item) => item.value.length > 0)
  && expectedBarcodesParseError.value == null
  && configCount.value > 0
  && runState.value !== 'RUNNING'
  && runState.value !== 'PAUSED',
)

function buildConfigOptions() {
  return {
    mode: benchmarkMode.value,
    expectedBarcodes: expectedBarcodes.value,
    focusLevels: [...DEFAULT_FOCUS_LEVELS],
    resolutions: [...RESOLUTION_PRESETS],
    targetSizes: [...DEFAULT_TARGET_SIZES],
    zoomLevels: resolveZoomLevels(capabilities.value),
    orderMode: orderMode.value,
    randomSeed: randomSeed.value ?? Date.now(),
  }
}

function pushLiveEvent(message: string, level: LiveEvent['level'] = 'info'): void {
  const entry: LiveEvent = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    message,
    level,
  }

  liveEvents.value = [entry, ...liveEvents.value].slice(0, MAX_LIVE_EVENTS)
}

function rebuildConfigurations(): void {
  if (orderMode.value === 'RANDOMIZED' && randomSeed.value == null) {
    randomSeed.value = Date.now()
  }

  configurations.value = buildPhase2Configurations(buildConfigOptions())
  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configurationId === config.id)
    return existing ?? createEmptyPhase2Result(config)
  })
}

function resetBenchmark(): void {
  stopRun()
  rawDetections.value = []
  liveEvents.value = []
  metadata.value = null
  benchmarkStartedAt.value = null
  benchmarkFinishedAt.value = null
  randomSeed.value = orderMode.value === 'RANDOMIZED' ? Date.now() : null
  rebuildConfigurations()
  results.value = configurations.value.map((config) => createEmptyPhase2Result(config))
  currentConfig.value = null
  currentConfigIndex.value = 0
  copyMessage.value = null
  pushLiveEvent('Benchmark reset')
}

function clearResults(): void {
  rawDetections.value = []
  results.value = configurations.value.map((config) => createEmptyPhase2Result(config))
  metadata.value = null
  benchmarkStartedAt.value = null
  benchmarkFinishedAt.value = null
  pushLiveEvent('Results cleared')
}

function getVideoTrack(): MediaStreamTrack | null {
  return activeStream.value?.getVideoTracks()[0] ?? null
}

function stopTracks(stream: MediaStream | null): void {
  stream?.getTracks().forEach((track) => {
    try {
      track.stop()
    } catch {
      // ignorer
    }
  })
}

function stopTimers(): void {
  if (countdownTimer !== null) {
    window.clearInterval(countdownTimer)
    countdownTimer = null
  }
}

function refreshDiagnostics(): void {
  const track = getVideoTrack()
  capabilities.value = readTrackCapabilitiesSnapshot(track)
  trackSettings.value = readTrackSettingsSnapshot(track)
}

async function waitForVideoDimensions(video: HTMLVideoElement, timeoutMs = 5000): Promise<void> {
  const startedAt = performance.now()

  while (video.videoWidth <= 0 || video.videoHeight <= 0) {
    if (performance.now() - startedAt > timeoutMs) {
      throw new Error('Video dimensions timeout')
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }
}

async function waitWhilePaused(sessionId: number): Promise<boolean> {
  while (isPaused.value && !runAbort && sessionId === runSessionId) {
    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return !runAbort && sessionId === runSessionId
}

async function startCameraWithPreset(preset = RESOLUTION_PRESETS[0]!): Promise<void> {
  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'STARTING'
  cameraError.value = null

  try {
    stopTracks(activeStream.value)

    const stream = await navigator.mediaDevices.getUserMedia(buildResolutionConstraints(preset))

    if (sessionId !== cameraSessionId) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Video element unavailable')
    }

    video.srcObject = stream
    video.playsInline = true
    video.muted = true

    await Promise.race([
      video.play(),
      new Promise<never>((_, reject) => {
        window.setTimeout(() => reject(new Error('Video play timeout')), START_TIMEOUT_MS)
      }),
    ])

    await waitForVideoDimensions(video)
    refreshDiagnostics()
    rebuildConfigurations()

    if (!detectorRef.value) {
      const detectorResult = await createComparisonBarcodeDetector()
      detectorRef.value = detectorResult.detector
    }

    cameraState.value = 'READY'
    pushLiveEvent('Camera ready')
  } catch (error) {
    cameraState.value = 'ERROR'

    if (error instanceof DOMException && error.name === 'NotAllowedError') {
      cameraError.value = 'Camera permission denied'
    } else {
      cameraError.value = error instanceof Error ? error.message : 'Camera stream failed'
    }

    pushLiveEvent(cameraError.value, 'error')
  }
}

async function applyConfigurationResolution(preset: typeof RESOLUTION_PRESETS[number]): Promise<void> {
  const track = getVideoTrack()
  const video = videoRef.value

  if (!track || !video) {
    return
  }

  try {
    await track.applyConstraints({
      width: { ideal: preset.width },
      height: { ideal: preset.height },
      facingMode: 'environment',
    })
  } catch {
    stopTracks(activeStream.value)
    await startCameraWithPreset(preset)
    return
  }

  await new Promise((resolve) => window.setTimeout(resolve, 1000))
  await waitForVideoDimensions(video)
  refreshDiagnostics()
}

async function stopCamera(): Promise<void> {
  stopRun()
  stopTimers()
  cameraSessionId += 1
  cameraState.value = 'STOPPED'

  stopTracks(activeStream.value)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  detectorRef.value = null
  refreshDiagnostics()
  cameraState.value = 'IDLE'
  pushLiveEvent('Camera stopped')
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1
  isPaused.value = false
  stopTimers()
  currentConfig.value = null

  if (runState.value === 'RUNNING' || runState.value === 'PAUSED') {
    runState.value = 'STOPPED'
    pushLiveEvent('Benchmark stopped', 'warn')
  }
}

function pauseRun(): void {
  if (runState.value !== 'RUNNING') {
    return
  }

  isPaused.value = true
  runState.value = 'PAUSED'
  pushLiveEvent('Benchmark paused')
}

function resumeRun(): void {
  if (runState.value !== 'PAUSED') {
    return
  }

  isPaused.value = false
  runState.value = 'RUNNING'
  pushLiveEvent('Benchmark resumed')
}

async function runConfiguration(
  config: Phase2RunConfiguration,
  configIndex: number,
  sessionId: number,
): Promise<Phase2ConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return finalizePhase2Result(config, {
      requestedFocusDistance: config.focusRequested,
      requestedZoom: config.zoomRequested,
      actualFocusDistance: '—',
      actualZoom: '—',
      configurationStatus: 'INVALID',
      focusModeValidation: '—',
      focusDistanceValidation: '—',
      zoomValidation: '—',
    }, {
      dims: getEffectiveVideoDimensions(video?.videoWidth ?? 0, video?.videoHeight ?? 0, config.resolution.width, config.resolution.height),
      frames: 0,
      detections: [],
      expectedBarcodes: expectedBarcodes.value,
      validationPolicy: validationPolicy.value,
      durationMs: durationSeconds.value * 1000,
      focusSupported: manualFocusSupported.value,
      zoomSupported: zoomSupported.value,
      errorMessage: 'Camera or detector unavailable',
    })
  }

  currentConfig.value = config
  currentConfigIndex.value = configIndex + 1
  pushLiveEvent(`Config ${configIndex + 1}/${configCount.value}: ${config.label}`)

  await applyConfigurationResolution(config.resolution)

  let applied: AppliedExperimentSnapshot
  let configError: string | null = null

  try {
    applied = await applyExperimentConfiguration(track, {
      requestedFocusDistance: config.focusRequested,
      requestedZoom: config.zoomRequested,
      focusDistanceCapabilities: capabilities.value.focusDistance,
      zoomStep: capabilities.value.zoom.step,
    })
  } catch (error) {
    applied = {
      requestedFocusDistance: config.focusRequested,
      requestedZoom: config.zoomRequested,
      actualFocusDistance: '—',
      actualZoom: '—',
      configurationStatus: 'INVALID',
      focusModeValidation: '—',
      focusDistanceValidation: '—',
      zoomValidation: '—',
    }
    configError = error instanceof Error ? error.message : 'Configuration apply failed'
  }

  lastApplied.value = applied
  liveStatus.value.focus = applied.actualFocusDistance
  liveStatus.value.zoom = `${applied.actualZoom}×`

  const dims = getEffectiveVideoDimensions(
    video.videoWidth,
    video.videoHeight,
    config.resolution.width,
    config.resolution.height,
  )

  if (configError || applied.configurationStatus !== 'VALID') {
    const message = configError ?? `Configuration apply failed: ${applied.configurationStatus}`
    pushLiveEvent(message, 'error')

    return finalizePhase2Result(config, applied, {
      dims,
      frames: 0,
      detections: [],
      expectedBarcodes: expectedBarcodes.value,
      validationPolicy: validationPolicy.value,
      durationMs: durationSeconds.value * 1000,
      focusSupported: manualFocusSupported.value,
      zoomSupported: zoomSupported.value,
      errorMessage: message,
    })
  }

  const settleStartedAt = performance.now()

  while (performance.now() - settleStartedAt < settleMs.value) {
    if (!(await waitWhilePaused(sessionId)) || runAbort || sessionId !== runSessionId) {
      return createEmptyPhase2Result(config)
    }

    await new Promise((resolve) => window.setTimeout(resolve, 50))
  }

  if (runAbort || sessionId !== runSessionId) {
    return createEmptyPhase2Result(config)
  }

  let frameIndex = 0
  const configDetections: Phase2RawDetection[] = []
  const distinctFramesByValue = new Map<string, Set<number>>()
  let detectionInProgress = false
  let lastDetectionTime = 0

  const startedAt = performance.now()
  const endAt = startedAt + durationSeconds.value * 1000
  remainingSeconds.value = durationSeconds.value
  elapsedSeconds.value = 0

  stopTimers()
  countdownTimer = window.setInterval(() => {
    const elapsed = performance.now() - startedAt
    elapsedSeconds.value = Math.floor(elapsed / 1000)
    remainingSeconds.value = Math.max(0, Math.ceil((endAt - performance.now()) / 1000))
  }, 200)

  await new Promise<void>((resolve) => {
    const tick = (timestamp: number): void => {
      if (runAbort || sessionId !== runSessionId || performance.now() >= endAt) {
        resolve()
        return
      }

      if (isPaused.value) {
        window.requestAnimationFrame(tick)
        return
      }

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp

        void (async () => {
          if (!(await waitWhilePaused(sessionId)) || runAbort || sessionId !== runSessionId) {
            detectionInProgress = false
            resolve()
            return
          }

          frameIndex += 1
          const elapsedMs = Math.round(performance.now() - startedAt)
          const sharpness = measureVideoSharpness(video, canvas)

          try {
            const rawResults = normalizeDetections(await detector.detect(video))
            const validation = evaluateValidationPolicy(configDetections, expectedBarcodes.value, validationPolicy.value)

            if (rawResults.length === 0) {
              liveStatus.value = {
                ...liveStatus.value,
                rawValue: '—',
                format: '—',
                validationState: 'NO_DETECTION',
                sharpness: sharpness != null ? String(Math.round(sharpness)) : '—',
                frameIndex,
              }
            }

            for (const [detectionIndex, result] of rawResults.entries()) {
              const rawValue = result.rawValue ?? ''
              const format = result.format ?? '—'

              if (!rawValue) {
                continue
              }

              const ratios = result.boundingBox
                ? calculateBarcodeSizeRatio(result.boundingBox, video.videoWidth, video.videoHeight)
                : null
              const checkDigitValid = isCheckDigitValid(format, rawValue)
              const isExpected = matchesExpectedBarcode(rawValue, format, expectedBarcodes.value)
              const frameSet = distinctFramesByValue.get(rawValue) ?? new Set<number>()

              if (!distinctFramesByValue.has(rawValue)) {
                distinctFramesByValue.set(rawValue, frameSet)
              }

              frameSet.add(frameIndex)
              const sameValueDistinctFrameCount = frameSet.size
              const temporalStabilityPercent = configDetections.length > 0
                ? (configDetections.filter((item) => item.rawValue === rawValue).length / configDetections.length) * 100
                : 100

              const confidenceScore = calculateBarcodeConfidenceScore({
                checkDigitValid,
                formatMatches: isExpected,
                isExpected,
                sameValueCount: sameValueDistinctFrameCount,
                temporalStabilityPercent,
                sharpness,
                widthRatio: ratios?.widthRatio ?? null,
                targetSize: config.targetSize,
              })

              const validationState: ValidationState = classifyPhase2Detection({
                rawValue,
                format,
                expectedBarcodes: expectedBarcodes.value,
                checkDigitValid,
                sameValueDistinctFrameCount,
                validated: validation.validated,
                hasAnyDetection: true,
              })

              liveStatus.value = {
                rawValue,
                format,
                validationState,
                widthRatio: ratios?.widthRatio != null ? `${(ratios.widthRatio * 100).toFixed(1)}%` : '—',
                sharpness: sharpness != null ? String(Math.round(sharpness)) : '—',
                focus: applied.actualFocusDistance,
                zoom: `${applied.actualZoom}×`,
                confidenceScore: confidenceScore.toFixed(1),
                frameIndex,
              }

              const entry: Phase2RawDetection = {
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                timestamp: new Date().toLocaleTimeString('fr-FR'),
                elapsedMs,
                configurationId: config.id,
                frameIndex,
                detectionIndex,
                rawValue,
                format,
                checkDigitValid,
                isExpected,
                videoWidth: dims.nativeWidth,
                videoHeight: dims.nativeHeight,
                requestedResolution: `${config.resolution.width}×${config.resolution.height}`,
                actualResolution: `${dims.nativeWidth}×${dims.nativeHeight}`,
                requestedFocus: config.focusRequested,
                actualFocus: applied.actualFocusDistance,
                requestedZoom: config.zoomRequested,
                actualZoom: `${applied.actualZoom}×`,
                sharpness,
                widthRatio: ratios?.widthRatio ?? null,
                confidenceScore,
                validationState,
              }

              configDetections.push(entry)
              rawDetections.value = [entry, ...rawDetections.value]
              pushLiveEvent(`${rawValue} · ${validationState} · score ${confidenceScore.toFixed(1)}`)
            }
          } catch {
            // ignorer erreurs ponctuelles detect()
          } finally {
            detectionInProgress = false
          }
        })()
      }

      window.requestAnimationFrame(tick)
    }

    window.requestAnimationFrame(tick)
  })

  stopTimers()

  return finalizePhase2Result(config, applied, {
    dims,
    frames: frameIndex,
    detections: configDetections,
    expectedBarcodes: expectedBarcodes.value,
    validationPolicy: validationPolicy.value,
    durationMs: durationSeconds.value * 1000,
    focusSupported: manualFocusSupported.value,
    zoomSupported: zoomSupported.value,
  })
}

async function startBenchmark(): Promise<void> {
  if (!canStartBenchmark.value) {
    return
  }

  if (benchmarkMode.value === 'FULL' && previewConfigCount.value > 10) {
    const confirmed = window.confirm(
      `FULL mode will run ${previewConfigCount.value} configurations. Continue?`,
    )

    if (!confirmed) {
      return
    }
  }

  rebuildConfigurations()
  runAbort = false
  isPaused.value = false
  runSessionId += 1
  const sessionId = runSessionId
  runState.value = 'RUNNING'
  benchmarkStartedAt.value = new Date().toISOString()
  benchmarkFinishedAt.value = null
  rawDetections.value = []
  liveEvents.value = []
  pushLiveEvent(`Benchmark started (${benchmarkMode.value}, ${configCount.value} configs)`)

  refreshDiagnostics()
  metadata.value = createBenchmarkMetadata({
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    mode: benchmarkMode.value,
    orderMode: orderMode.value,
    randomSeed: randomSeed.value,
    expectedBarcodes: expectedBarcodes.value,
    physicalBarcodeConfirmed: physicalConfirmed.value,
    physicalConfirmationMethod: physicalConfirmationMethod.value,
    durationSeconds: durationSeconds.value,
    settleMs: settleMs.value,
  })
  metadata.value.validationPolicy = { ...validationPolicy.value }

  const ordered = [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex)

  for (let index = 0; index < ordered.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = ordered[index]!
    const result = await runConfiguration(config, index, sessionId)
    results.value = results.value.map((item) =>
      item.configurationId === config.id ? result : item,
    )
  }

  benchmarkFinishedAt.value = new Date().toISOString()
  results.value = markExperimentalBest(results.value)

  if (runAbort || sessionId !== runSessionId) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
  currentConfig.value = null
  pushLiveEvent('Benchmark completed')
}

function buildReportPayload(): Record<string, unknown> {
  return {
    metadata: metadata.value,
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    configurations: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
    results: [...markedResults.value].sort((a, b) => a.orderIndex - b.orderIndex),
    rawDetections: rawDetections.value,
    warnings: warnings.value,
    bestConfiguration: bestConfiguration.value,
    benchmarkStartedAt: benchmarkStartedAt.value,
    benchmarkFinishedAt: benchmarkFinishedAt.value,
  }
}

function buildFullReport(): string {
  if (!metadata.value) {
    return 'No benchmark metadata available.'
  }

  return buildPhase2Report({
    metadata: metadata.value,
    configurations: configurations.value,
    results: markedResults.value,
    warnings: warnings.value,
    bestConfiguration: bestConfiguration.value,
  })
}

async function copyReport(): Promise<void> {
  try {
    await navigator.clipboard.writeText(buildFullReport())
    copyMessage.value = 'Rapport copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function downloadBlob(content: string, filename: string, mime: string): void {
  const blob = new Blob([content], { type: mime })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

function exportJson(): void {
  downloadBlob(
    buildPhase2ExportJson(buildReportPayload()),
    `barcode-reliability-phase2-${Date.now()}.json`,
    'application/json',
  )
  copyMessage.value = 'JSON exporté.'
}

function exportCsv(): void {
  downloadBlob(
    buildPhase2Csv(markedResults.value),
    `barcode-reliability-phase2-${Date.now()}.csv`,
    'text/csv',
  )
  copyMessage.value = 'CSV exporté.'
}

function exportReport(): void {
  downloadBlob(
    buildFullReport(),
    `barcode-reliability-phase2-report-${Date.now()}.txt`,
    'text/plain',
  )
  copyMessage.value = 'Rapport exporté.'
}

function syncJsonFromSimple(): void {
  expectedBarcodesJson.value = JSON.stringify([{
    value: simpleBarcodeValue.value,
    format: simpleBarcodeFormat.value,
  }], null, 2)
}

function statusBadgeClass(status: ConfigStatus): string {
  if (status === 'EXPERIMENTAL_BEST' || status === 'VALIDATED') {
    return 'text-success'
  }

  if (status === 'CONFIGURATION_ERROR' || status === 'WRONG_DECODING') {
    return 'text-danger'
  }

  return ''
}

function heatmapCellValue(
  cells: ReturnType<typeof buildHeatmapData>,
  x: string,
  y: string,
): number | null {
  const cell = cells.find((item) => item.x === x && item.y === y)
  return cell?.value ?? null
}

function heatmapCellColor(value: number | null, max = 100): string {
  if (value == null || !Number.isFinite(value)) {
    return 'rgba(255,255,255,0.06)'
  }

  const ratio = Math.min(1, Math.max(0, value / max))
  const hue = ratio * 120

  return `hsla(${hue}, 70%, 42%, 0.85)`
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopRun()
  }
}

watch([benchmarkMode, orderMode, expectedBarcodes], () => {
  if (runState.value === 'IDLE' || runState.value === 'STOPPED' || runState.value === 'COMPLETED') {
    rebuildConfigurations()
  }
})

watch([simpleBarcodeValue, simpleBarcodeFormat], () => {
  if (barcodeInputMode.value === 'simple') {
    syncJsonFromSimple()
  }
})

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  syncJsonFromSimple()
  rebuildConfigurations()
  window.addEventListener('pagehide', () => { void stopCamera() })
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  void stopCamera()
})
</script>

<template>
  <Head title="Barcode Reliability Benchmark Phase 2" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix barcode-reliability-phase2">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Reliability Benchmark — Phase 2</h1>
          <p class="barcode-reader-test-page__subtitle">
            Benchmark multi-configurations avec score de confiance expérimental — DEV isolé
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Phase 1</Link>
          <Link href="/dev/barcode-detector-decode-reliability-matrix" class="btn btn-sm btn-outline-secondary">Matrix</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">
          BarcodeDetector:
          <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong>
        </p>
        <p class="mb-1">
          Focus:
          <strong>{{ manualFocusSupported ? 'SUPPORTED' : 'NOT SUPPORTED' }}</strong>
          · Zoom:
          <strong>{{ zoomSupported ? 'SUPPORTED' : 'UNSUPPORTED' }}</strong>
        </p>
        <p class="mb-1 barcode-decode-reliability-matrix__warning">
          Experimental confidence score — not production validation.
        </p>
        <p class="mb-0 barcode-decode-reliability-matrix__warning">
          EXPERIMENTAL CANDIDATE — NOT AUTOMATICALLY APPLIED TO PRODUCTION
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">1. CONFIGURATION</h2>
        <div class="barcode-decode-reliability-matrix__grid-form">
          <div>
            <label>Mode</label>
            <select v-model="benchmarkMode" class="form-select form-select-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'" @change="rebuildConfigurations">
              <option value="QUICK">QUICK</option>
              <option value="FULL">FULL</option>
            </select>
          </div>
          <div>
            <label>Duration (s)</label>
            <select v-model.number="durationSeconds" class="form-select form-select-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
              <option v-for="option in DURATION_OPTIONS" :key="option" :value="option">{{ option }} s</option>
            </select>
          </div>
          <div>
            <label>Settle (ms)</label>
            <select v-model.number="settleMs" class="form-select form-select-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
              <option v-for="option in SETTLE_OPTIONS" :key="option" :value="option">{{ option }} ms</option>
            </select>
          </div>
          <div>
            <label>Physical confirmation method</label>
            <select v-model="physicalConfirmationMethod" class="form-select form-select-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
              <option :value="null">—</option>
              <option value="manual_inspection">Manual inspection</option>
              <option value="hardware_scanner">Hardware scanner</option>
              <option value="packaging">Packaging</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label>Min confirmations</label>
            <input v-model.number="validationPolicy.minimumConfirmations" type="number" min="1" max="20" class="form-control form-control-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
          </div>
          <div>
            <label>Min stability (%)</label>
            <input v-model.number="validationPolicy.minimumStability" type="number" min="0" max="100" class="form-control form-control-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
          </div>
          <div>
            <label>Min score</label>
            <input v-model.number="validationPolicy.minimumScore" type="number" min="0" max="100" class="form-control form-control-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
          </div>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-2">
          <button
            type="button"
            class="btn btn-sm"
            :class="orderMode === 'ORDERED' ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING' || runState === 'PAUSED'"
            @click="orderMode = 'ORDERED'; randomSeed = null; rebuildConfigurations()"
          >
            ORDERED
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="orderMode === 'RANDOMIZED' ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING' || runState === 'PAUSED'"
            @click="orderMode = 'RANDOMIZED'; randomSeed = Date.now(); rebuildConfigurations()"
          >
            RANDOMIZED
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="barcodeInputMode === 'simple' ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING' || runState === 'PAUSED'"
            @click="barcodeInputMode = 'simple'"
          >
            Simple fields
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="barcodeInputMode === 'json' ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING' || runState === 'PAUSED'"
            @click="barcodeInputMode = 'json'"
          >
            JSON array
          </button>
        </div>

        <div v-if="barcodeInputMode === 'simple'" class="barcode-decode-reliability-matrix__grid-form mt-2">
          <div>
            <label>Expected value</label>
            <input v-model="simpleBarcodeValue" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
          </div>
          <div>
            <label>Expected format</label>
            <input v-model="simpleBarcodeFormat" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
          </div>
        </div>

        <div v-else class="mt-2">
          <label>expectedBarcodes JSON</label>
          <textarea
            v-model="expectedBarcodesJson"
            class="form-control form-control-sm font-monospace"
            rows="4"
            :disabled="runState === 'RUNNING' || runState === 'PAUSED'"
          />
          <p v-if="expectedBarcodesParseError" class="barcode-decode-reliability-matrix__warning mb-0 mt-1">{{ expectedBarcodesParseError }}</p>
        </div>

        <div class="form-check mt-2">
          <input id="physical-confirmed" v-model="physicalConfirmed" class="form-check-input" type="checkbox" :disabled="runState === 'RUNNING' || runState === 'PAUSED'">
          <label class="form-check-label" for="physical-confirmed">Code-barres physique confirmé</label>
        </div>

        <p class="barcode-decode-reliability-matrix__muted mb-0 mt-2">
          Configurations: {{ previewConfigCount }}
          <span v-if="orderMode === 'RANDOMIZED' && randomSeed != null"> · Seed: {{ randomSeed }}</span>
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">2. CAMERA CAPABILITIES</h2>
        <dl class="barcode-decode-reliability-matrix__grid">
          <div><dt>Native resolution</dt><dd>{{ trackSettings.width ?? '—' }}×{{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>FPS</dt><dd>{{ trackSettings.frameRate ?? '—' }}</dd></div>
          <div><dt>Facing</dt><dd>{{ trackSettings.facingMode ?? '—' }}</dd></div>
          <div><dt>Focus</dt><dd>{{ manualFocusSupported ? 'SUPPORTED' : 'NOT SUPPORTED' }}</dd></div>
          <div><dt>Zoom</dt><dd>{{ zoomSupported ? 'SUPPORTED' : 'UNSUPPORTED' }}</dd></div>
          <div><dt>Zoom range</dt><dd>{{ capabilities.zoom.min ?? '—' }} – {{ capabilities.zoom.max ?? '—' }}</dd></div>
          <div><dt>Focus range</dt><dd>{{ capabilities.focusDistance.min ?? '—' }} – {{ capabilities.focusDistance.max ?? '—' }}</dd></div>
          <div><dt>Zoom levels (run)</dt><dd>{{ resolveZoomLevels(capabilities).join(', ') }}</dd></div>
        </dl>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">3. MATRIX PREVIEW</h2>
        <ol class="mb-0 ps-3 barcode-decode-reliability-matrix__muted">
          <li v-for="config in [...configurations].sort((a, b) => a.orderIndex - b.orderIndex)" :key="config.id">
            {{ config.label }}
          </li>
        </ol>
      </section>

      <section class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__section--video">
        <h2 class="barcode-decode-reliability-matrix__section-title">4. LIVE BENCHMARK</h2>
        <div class="barcode-decode-reliability-matrix__video-wrap">
          <video ref="videoRef" class="barcode-decode-reliability-matrix__video" autoplay muted playsinline />
          <div
            v-if="currentConfig"
            class="barcode-distance-focus-experiment__size-guide"
            :style="guideStyle"
          >
            <span class="barcode-distance-focus-experiment__size-guide-label">{{ currentConfig.targetSize.label }}</span>
          </div>
          <div v-if="runState === 'RUNNING' || runState === 'PAUSED'" class="barcode-decode-reliability-matrix__live-panel">
            <div>{{ liveStatus.rawValue }} · frame {{ liveStatus.frameIndex }}</div>
            <div>{{ liveStatus.format }} · {{ liveStatus.validationState }}</div>
            <div>widthRatio {{ liveStatus.widthRatio }} · sharpness {{ liveStatus.sharpness }}</div>
            <div>focus {{ liveStatus.focus }} · zoom {{ liveStatus.zoom }} · score {{ liveStatus.confidenceScore }}</div>
          </div>
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-decode-reliability-matrix__hidden-canvas" aria-hidden="true" />

        <div class="barcode-decode-reliability-matrix__actions mt-3">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCameraWithPreset()">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStartBenchmark" @click="startBenchmark">Start</button>
          <button type="button" class="btn btn-outline-warning btn-sm" :disabled="runState !== 'RUNNING'" @click="pauseRun">Pause</button>
          <button type="button" class="btn btn-outline-warning btn-sm" :disabled="runState !== 'PAUSED'" @click="resumeRun">Resume</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING' && runState !== 'PAUSED'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'" @click="resetBenchmark">Reset</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING' || runState === 'PAUSED'" @click="clearResults">Clear results</button>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Export Report (copy)</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportJson">Export JSON</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportCsv">Export CSV</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportReport">Export Report</button>
        </div>

        <p v-if="copyMessage" class="barcode-decode-reliability-matrix__muted mb-0 mt-2">{{ copyMessage }}</p>
        <p v-if="cameraError" class="barcode-decode-reliability-matrix__warning mb-0 mt-2">{{ cameraError }}</p>

        <div v-if="runState === 'RUNNING' || runState === 'PAUSED' || runState === 'COMPLETED'" class="mt-3">
          <div class="progress mb-2" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" :style="{ width: `${progressPercent}%` }">{{ progressPercent }}%</div>
          </div>
          <p class="mb-0 barcode-decode-reliability-matrix__muted">{{ currentConfigIndex }} / {{ configCount }} configurations · {{ runState }}</p>
        </div>
      </section>

      <section v-if="currentConfig && (runState === 'RUNNING' || runState === 'PAUSED')" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">5. CURRENT CONFIG</h2>
        <pre class="barcode-decode-reliability-matrix__pre mb-0">{{ currentConfig.label }}
Target size: {{ currentConfig.targetSize.label }} (guide {{ (currentConfig.targetSize.guideWidthRatio * 100).toFixed(0) }}%)
Focus requested: {{ currentConfig.focusRequested }} · Zoom requested: {{ currentConfig.zoomRequested }}×
Elapsed: {{ elapsedSeconds }} s · Remaining: {{ remainingSeconds }} s
Focus actual: {{ lastApplied?.actualFocusDistance ?? '—' }} · Zoom actual: {{ lastApplied?.actualZoom ?? '—' }}×</pre>
      </section>

      <section v-if="liveEvents.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">6. LIVE DETECTIONS / EVENT LOG (max {{ MAX_LIVE_EVENTS }})</h2>
        <ul class="list-unstyled mb-0">
          <li
            v-for="event in liveEvents"
            :key="event.id"
            class="barcode-reliability-phase2__event"
            :class="{
              'barcode-reliability-phase2__event--warn': event.level === 'warn',
              'barcode-reliability-phase2__event--error': event.level === 'error',
            }"
          >
            <span class="barcode-decode-reliability-matrix__muted">{{ event.timestamp }}</span>
            {{ event.message }}
          </li>
        </ul>
      </section>

      <section v-if="markedResults.some((item) => item.frames > 0 || item.errorMessage)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">7. RESULTS TABLE</h2>

        <div class="barcode-decode-reliability-matrix__grid-form mb-2">
          <div>
            <label>Sort by</label>
            <select v-model="sortKey" class="form-select form-select-sm">
              <option value="overallScore">Overall score</option>
              <option value="confidenceScore">Confidence score</option>
              <option value="expectedRate">Expected rate</option>
              <option value="detectionRate">Detection rate</option>
              <option value="validationRate">Validation rate</option>
              <option value="falsePositiveRate">False positive rate</option>
              <option value="temporalStability">Temporal stability</option>
              <option value="medianSharpness">Median sharpness</option>
              <option value="timeToValidationMs">Time to validation</option>
            </select>
          </div>
          <div>
            <label>Resolution</label>
            <select v-model="filterResolution" class="form-select form-select-sm">
              <option value="">All</option>
              <option v-for="option in resolutionFilterOptions" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>
          <div>
            <label>Target</label>
            <select v-model="filterTarget" class="form-select form-select-sm">
              <option value="">All</option>
              <option v-for="option in targetFilterOptions" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>
          <div>
            <label>Focus</label>
            <select v-model="filterFocus" class="form-select form-select-sm">
              <option value="">All</option>
              <option v-for="option in focusFilterOptions" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>
          <div>
            <label>Status</label>
            <select v-model="filterStatus" class="form-select form-select-sm">
              <option value="">All</option>
              <option v-for="option in statusFilterOptions" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>
          <div>
            <label>Min score</label>
            <input v-model.number="filterMinScore" type="number" min="0" max="100" class="form-control form-control-sm">
          </div>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mb-2">
          <button type="button" class="btn btn-sm" :class="resultsLimit === 5 ? 'btn-primary' : 'btn-outline-secondary'" @click="resultsLimit = 5">Top 5</button>
          <button type="button" class="btn btn-sm" :class="resultsLimit === 10 ? 'btn-primary' : 'btn-outline-secondary'" @click="resultsLimit = 10">Top 10</button>
          <button type="button" class="btn btn-sm" :class="resultsLimit === 'all' ? 'btn-primary' : 'btn-outline-secondary'" @click="resultsLimit = 'all'">All</button>
          <div class="form-check ms-2">
            <input id="validated-only" v-model="filterValidatedOnly" class="form-check-input" type="checkbox">
            <label class="form-check-label" for="validated-only">Validated only</label>
          </div>
          <div class="form-check ms-2">
            <input id="expected-only" v-model="filterExpectedOnly" class="form-check-input" type="checkbox">
            <label class="form-check-label" for="expected-only">Expected reads only</label>
          </div>
        </div>

        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Config</th>
                <th>Frames</th>
                <th>Det</th>
                <th>Expected</th>
                <th>Det rate</th>
                <th>Exp rate</th>
                <th>Val rate</th>
                <th>FP rate</th>
                <th>Stability</th>
                <th>Score</th>
                <th>Overall</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in displayedResults" :key="item.configurationId">
                <td>{{ item.resolutionLabel }} / {{ item.targetSizeLabel }} / f{{ item.focusRequested }} / z{{ item.zoomRequested }}</td>
                <td>{{ item.frames }}</td>
                <td>{{ item.detections }}</td>
                <td>{{ item.expectedDetections }}</td>
                <td>{{ item.detectionRate }}</td>
                <td>{{ item.expectedRate }}</td>
                <td>{{ item.validationRate }}</td>
                <td>{{ item.falsePositiveRate }}</td>
                <td>{{ item.temporalStability }}</td>
                <td>{{ item.confidenceScore }}</td>
                <td>{{ item.overallScore.overall }}</td>
                <td :class="statusBadgeClass(item.status)">{{ item.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="bestConfiguration" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">8. BEST CONFIG CARD</h2>
        <div class="barcode-decode-reliability-matrix__result-card">
          <div class="barcode-decode-reliability-matrix__result-title">{{ bestConfiguration.label }}</div>
          <p class="mb-1 barcode-decode-reliability-matrix__muted">
            Overall: {{ bestConfiguration.overallScore.overall }} · Expected rate: {{ bestConfiguration.expectedRate }}
            · Validation: {{ bestConfiguration.validationRate }} · Status: {{ bestConfiguration.status }}
          </p>
          <pre class="barcode-decode-reliability-matrix__pre mb-0">{{ bestConfigReasons.join('\n') }}</pre>
        </div>
      </section>

      <section v-if="resolutionChart.length > 0 || focusChart.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">9. CHARTS (CSS bars)</h2>
        <h3 class="barcode-decode-reliability-matrix__subsection-title">Expected rate vs resolution</h3>
        <div class="barcode-decode-reliability-matrix__chart">
          <div v-for="item in resolutionChart" :key="`res-exp-${item.label}`" class="barcode-decode-reliability-matrix__chart-row">
            <span>{{ item.label }}</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${Math.min(100, item.expectedRate)}%` }" />
            </div>
            <span>{{ item.expectedRate.toFixed(1) }}%</span>
          </div>
        </div>
        <h3 class="barcode-decode-reliability-matrix__subsection-title mt-3">Detection rate vs focus</h3>
        <div class="barcode-decode-reliability-matrix__chart">
          <div v-for="item in focusChart" :key="`focus-det-${item.label}`" class="barcode-decode-reliability-matrix__chart-row">
            <span>{{ item.label }}</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${Math.min(100, item.detectionRate)}%` }" />
            </div>
            <span>{{ item.detectionRate.toFixed(1) }}%</span>
          </div>
        </div>

        <h3 class="barcode-decode-reliability-matrix__subsection-title mt-3">Scatter — detection rate vs expected rate</h3>
        <div class="barcode-reliability-phase2__scatter">
          <div
            v-for="point in scatterPoints"
            :key="point.id"
            class="barcode-reliability-phase2__scatter-point"
            :style="{ left: `${Math.min(100, Math.max(0, point.x))}%`, bottom: `${Math.min(100, Math.max(0, point.y))}%` }"
            :title="`${point.label} — det ${point.x.toFixed(1)}% / exp ${point.y.toFixed(1)}%`"
          />
        </div>
      </section>

      <section v-if="expectedRateHeatmap.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">10. HEATMAPS (CSS grid)</h2>
        <h3 class="barcode-decode-reliability-matrix__subsection-title">Expected rate — target × focus</h3>
        <div
          class="barcode-reliability-phase2__heatmap"
          :style="{ gridTemplateColumns: `6rem repeat(${heatmapXLabels(expectedRateHeatmap).length}, minmax(2.5rem, 1fr))` }"
        >
          <div />
          <div v-for="x in heatmapXLabels(expectedRateHeatmap)" :key="`hx-${x}`" class="barcode-reliability-phase2__heatmap-label">{{ x }}</div>
          <template v-for="y in heatmapYLabels(expectedRateHeatmap)" :key="`row-${y}`">
            <div class="barcode-reliability-phase2__heatmap-label">{{ y }}</div>
            <div
              v-for="x in heatmapXLabels(expectedRateHeatmap)"
              :key="`${x}-${y}`"
              class="barcode-reliability-phase2__heatmap-cell"
              :style="{ background: heatmapCellColor(heatmapCellValue(expectedRateHeatmap, x, y), 100) }"
              :title="`${x} / focus ${y}: ${heatmapCellValue(expectedRateHeatmap, x, y)?.toFixed(1) ?? '—'}%`"
            >
              {{ heatmapCellValue(expectedRateHeatmap, x, y)?.toFixed(0) ?? '—' }}
            </div>
          </template>
        </div>

        <h3 class="barcode-decode-reliability-matrix__subsection-title mt-3">Overall score — target × focus</h3>
        <div
          class="barcode-reliability-phase2__heatmap"
          :style="{ gridTemplateColumns: `6rem repeat(${heatmapXLabels(overallScoreHeatmap).length}, minmax(2.5rem, 1fr))` }"
        >
          <div />
          <div v-for="x in heatmapXLabels(overallScoreHeatmap)" :key="`hx2-${x}`" class="barcode-reliability-phase2__heatmap-label">{{ x }}</div>
          <template v-for="y in heatmapYLabels(overallScoreHeatmap)" :key="`row2-${y}`">
            <div class="barcode-reliability-phase2__heatmap-label">{{ y }}</div>
            <div
              v-for="x in heatmapXLabels(overallScoreHeatmap)"
              :key="`${x}-${y}-score`"
              class="barcode-reliability-phase2__heatmap-cell"
              :style="{ background: heatmapCellColor(heatmapCellValue(overallScoreHeatmap, x, y), 100) }"
              :title="`${x} / focus ${y}: ${heatmapCellValue(overallScoreHeatmap, x, y)?.toFixed(1) ?? '—'}`"
            >
              {{ heatmapCellValue(overallScoreHeatmap, x, y)?.toFixed(0) ?? '—' }}
            </div>
          </template>
        </div>
      </section>

      <section v-if="warnings.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">11. WARNINGS</h2>
        <ul class="mb-0">
          <li v-for="warning in warnings" :key="warning" class="barcode-decode-reliability-matrix__warning">{{ warning }}</li>
        </ul>
      </section>

      <section v-if="runState === 'COMPLETED'" class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__conclusion">
        <h2 class="barcode-decode-reliability-matrix__section-title">12. EXPORT / REPORT</h2>
        <pre class="barcode-decode-reliability-matrix__pre">{{ buildFullReport() }}</pre>
      </section>
    </div>
  </div>
</template>

<style scoped>
.barcode-reliability-phase2__event {
  font-size: 0.78rem;
  font-family: ui-monospace, monospace;
  padding: 0.15rem 0;
  border-bottom: 1px solid var(--color-border-subtle);
}

.barcode-reliability-phase2__event--warn {
  color: #b45309;
}

.barcode-reliability-phase2__event--error {
  color: #dc3545;
}

.barcode-reliability-phase2__scatter {
  position: relative;
  width: 100%;
  max-width: 24rem;
  aspect-ratio: 1 / 1;
  border: 1px solid var(--color-border-subtle);
  border-radius: var(--radius-sm);
  background:
    linear-gradient(to right, rgba(255, 255, 255, 0.08) 1px, transparent 1px),
    linear-gradient(to top, rgba(255, 255, 255, 0.08) 1px, transparent 1px);
  background-size: 10% 10%;
}

.barcode-reliability-phase2__scatter-point {
  position: absolute;
  width: 0.55rem;
  height: 0.55rem;
  margin-left: -0.275rem;
  margin-bottom: -0.275rem;
  border-radius: 50%;
  background: #0d6efd;
  box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
}

.barcode-reliability-phase2__heatmap {
  display: grid;
  gap: 0.2rem;
  overflow-x: auto;
}

.barcode-reliability-phase2__heatmap-label {
  font-size: 0.68rem;
  color: var(--color-text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 0.15rem;
}

.barcode-reliability-phase2__heatmap-cell {
  min-height: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.68rem;
  border-radius: var(--radius-sm);
  color: #fff;
}
</style>
