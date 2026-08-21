<script setup lang="ts">
import {
  analyzeMultiFrameLevels,
  analyzeSharpnessBuckets,
  analyzeWidthRatioBuckets,
  applyExperimentConfiguration,
  buildMatrixConfigurations,
  buildMatrixConclusion,
  buildMatrixCsv,
  buildMatrixExportJson,
  buildMatrixRawCsv,
  buildMatrixReport,
  buildPhaseBConfigurations,
  buildResolutionConstraints,
  calculateBarcodeSizeRatio,
  classifyDetection,
  computeGroupRepeatability,
  computeHammingDistance,
  computeMatchingDigits,
  computeRepeatabilityGroups,
  createComparisonBarcodeDetector,
  createEmptyMatrixResult,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
  DETECTION_INTERVAL_MS,
  evaluateProductionCriteria,
  finalizeMatrixResult,
  FINE_FOCUS_LEVELS,
  FIXED_ZOOM,
  getEffectiveVideoDimensions,
  getEnvironmentDiagnostics,
  isCheckDigitValid,
  isManualFocusSupported,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  normalizeDetections,
  pickTopConfigurations,
  PLACEMENT_GUIDES,
  PHASE_A_FOCUS_LEVELS,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  RESOLUTION_PRESETS,
  type AppliedExperimentSnapshot,
  type DetectionClassification,
  type ExperimentPhase,
  type MatrixConfigurationResult,
  type MatrixRawDetection,
  type MatrixRunConfiguration,
  type OrderMode,
} from '@/utils/barcodeDecodeReliabilityMatrix'
import {
  DEFAULT_DURATION_SECONDS,
  DEFAULT_SETTLE_MS,
  DURATION_OPTIONS,
  SETTLE_OPTIONS,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import type { BarcodeDetectorLike } from '@/utils/barcodeSizeZoomComparison'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000
const PHASE_B_TOP_COUNT = 5

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type RunUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED'
type RawFilter = 'all' | DetectionClassification

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const runState = ref<RunUiState>('IDLE')
const cameraError = ref<string | null>(null)
const copyMessage = ref<string | null>(null)

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const expectedFormat = ref(DEFAULT_EXPECTED_FORMAT)
const physicalConfirmed = ref(false)
const experimentPhase = ref<ExperimentPhase>('A')
const orderMode = ref<OrderMode>('RANDOMIZED')
const randomSeed = ref<number | null>(null)
const placementGuideId = ref<string>('free')
const repetitions = ref(3)
const durationSeconds = ref(DEFAULT_DURATION_SECONDS)
const settleMs = ref(DEFAULT_SETTLE_MS)
const rawFilter = ref<RawFilter>('all')
const rawSearch = ref('')

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<MatrixRunConfiguration[]>([])
const results = ref<MatrixConfigurationResult[]>([])
const rawDetections = ref<MatrixRawDetection[]>([])
const lastApplied = ref<AppliedExperimentSnapshot | null>(null)

const currentConfig = ref<MatrixRunConfiguration | null>(null)
const currentConfigIndex = ref(0)
const elapsedSeconds = ref(0)
const remainingSeconds = ref(0)
const benchmarkStartedAt = ref<string | null>(null)
const benchmarkFinishedAt = ref<string | null>(null)

const liveStatus = ref({
  rawValue: '—',
  format: '—',
  classification: '—',
  widthRatio: '—',
  sharpness: '—',
  focus: '—',
  zoom: '—',
})

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let countdownTimer: number | null = null

const placementGuideLabel = computed(() =>
  PLACEMENT_GUIDES.find((item) => item.id === placementGuideId.value)?.label ?? 'Position libre',
)

const manualFocusSupported = computed(() => isManualFocusSupported(capabilities.value))
const configCount = computed(() => configurations.value.length)

const phaseAResults = computed(() => results.value.filter((item) => item.phase === 'A'))
const canRunPhaseB = computed(() => phaseAResults.value.some((item) => item.frames > 0))

const widthBuckets = computed(() => analyzeWidthRatioBuckets(rawDetections.value))
const sharpnessBuckets = computed(() => analyzeSharpnessBuckets(rawDetections.value))
const multiFrameLevels = computed(() => analyzeMultiFrameLevels(rawDetections.value, expectedBarcode.value))
const repeatabilityGroups = computed(() => computeRepeatabilityGroups(results.value))
const topConfigurations = computed(() => pickTopConfigurations(results.value, 5))
const productionCriteria = computed(() => evaluateProductionCriteria(results.value))
const conclusionText = computed(() => buildMatrixConclusion(results.value, productionCriteria.value))

const filteredRawDetections = computed(() => {
  const search = rawSearch.value.trim().toLowerCase()

  return rawDetections.value.filter((item) => {
    if (rawFilter.value !== 'all' && item.classification !== rawFilter.value) {
      return false
    }

    if (!search) {
      return true
    }

    return (
      item.rawValue.toLowerCase().includes(search)
      || item.format.toLowerCase().includes(search)
      || item.classification.toLowerCase().includes(search)
      || item.resolutionLabel.toLowerCase().includes(search)
    )
  })
})

const progressPercent = computed(() =>
  configCount.value > 0 ? Math.round((currentConfigIndex.value / configCount.value) * 100) : 0,
)

const resolutionChart = computed(() => {
  const map = new Map<string, { correct: number; frames: number }>()

  for (const item of results.value) {
    const entry = map.get(item.resolutionLabel) ?? { correct: 0, frames: 0 }
    entry.correct += item.expectedReads
    entry.frames += item.frames
    map.set(item.resolutionLabel, entry)
  }

  return [...map.entries()].map(([label, stats]) => ({
    label,
    rate: stats.frames > 0 ? (stats.correct / stats.frames) * 100 : 0,
  }))
})

const focusChart = computed(() => {
  const map = new Map<number, { correct: number; frames: number }>()

  for (const item of results.value) {
    const entry = map.get(item.focusRequested) ?? { correct: 0, frames: 0 }
    entry.correct += item.expectedReads
    entry.frames += item.frames
    map.set(item.focusRequested, entry)
  }

  return [...map.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([focus, stats]) => ({
      label: String(focus),
      rate: stats.frames > 0 ? (stats.correct / stats.frames) * 100 : 0,
    }))
})

const widthRatioChart = computed(() =>
  widthBuckets.value
    .filter((item) => item.detections > 0)
    .map((item) => ({
      label: item.bucketLabel,
      rate: item.detections > 0
        ? (item.expectedReads / item.detections) * 100
        : 0,
    })),
)

const canStartExperiment = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && manualFocusSupported.value
  && expectedBarcode.value.trim().length > 0
  && physicalConfirmed.value
  && configCount.value > 0
  && runState.value !== 'RUNNING'
  && (experimentPhase.value !== 'B' || canRunPhaseB.value),
)

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

function applyPhaseDefaults(): void {
  if (experimentPhase.value === 'A') {
    repetitions.value = 3
    durationSeconds.value = 12
  } else if (experimentPhase.value === 'B') {
    repetitions.value = 4
    durationSeconds.value = 25
  } else {
    repetitions.value = 3
    durationSeconds.value = 15
  }
}

function rebuildConfigurations(): void {
  applyPhaseDefaults()

  const common = {
    placementGuideLabel: placementGuideLabel.value,
    expectedBarcode: expectedBarcode.value,
    expectedFormat: expectedFormat.value,
    orderMode: orderMode.value,
    randomSeed: randomSeed.value ?? Date.now(),
    preserveOrder: orderMode.value === 'FIXED' ? configurations.value : undefined,
  }

  if (experimentPhase.value === 'A') {
    configurations.value = buildMatrixConfigurations({
      phase: 'A',
      resolutionPresets: RESOLUTION_PRESETS,
      focusLevels: [...PHASE_A_FOCUS_LEVELS],
      repetitions: repetitions.value,
      ...common,
    })
  } else if (experimentPhase.value === 'FINE_FOCUS') {
    const preset = RESOLUTION_PRESETS.find((item) => item.id === '1920x1080') ?? RESOLUTION_PRESETS[0]!

    configurations.value = buildMatrixConfigurations({
      phase: 'FINE_FOCUS',
      resolutionPresets: [preset],
      focusLevels: [...FINE_FOCUS_LEVELS],
      repetitions: repetitions.value,
      ...common,
    })
  } else {
    const topGroups = computeRepeatabilityGroups(phaseAResults.value).slice(0, PHASE_B_TOP_COUNT)

    configurations.value = buildPhaseBConfigurations(topGroups, {
      repetitions: repetitions.value,
      placementGuideLabel: placementGuideLabel.value,
      expectedBarcode: expectedBarcode.value,
      expectedFormat: expectedFormat.value,
      orderMode: orderMode.value,
      randomSeed: randomSeed.value ?? Date.now(),
    })
  }

  if (orderMode.value === 'RANDOMIZED' && randomSeed.value == null) {
    randomSeed.value = Date.now()
  }

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyMatrixResult(config)
  })
}

function resetExperiment(): void {
  stopRun()
  rawDetections.value = []
  benchmarkStartedAt.value = null
  benchmarkFinishedAt.value = null
  randomSeed.value = orderMode.value === 'RANDOMIZED' ? Date.now() : null
  rebuildConfigurations()
  results.value = configurations.value.map((config) => createEmptyMatrixResult(config))
  currentConfig.value = null
  currentConfigIndex.value = 0
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

    if (!detectorRef.value) {
      const detectorResult = await createComparisonBarcodeDetector()
      detectorRef.value = detectorResult.detector
    }

    cameraState.value = 'READY'
  } catch (error) {
    cameraState.value = 'ERROR'

    if (error instanceof DOMException && error.name === 'NotAllowedError') {
      cameraError.value = 'Camera permission denied'
    } else {
      cameraError.value = error instanceof Error ? error.message : 'Camera stream failed'
    }
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
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1
  stopTimers()
  currentConfig.value = null

  if (runState.value === 'RUNNING') {
    runState.value = 'STOPPED'
  }
}

async function runConfiguration(
  config: MatrixRunConfiguration,
  configIndex: number,
  sessionId: number,
): Promise<MatrixConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyMatrixResult(config)
  }

  currentConfig.value = config
  currentConfigIndex.value = configIndex + 1

  await applyConfigurationResolution(config.resolutionPreset)

  const applied = await applyExperimentConfiguration(track, {
    requestedFocusDistance: config.focusRequested,
    requestedZoom: config.requestedZoom,
    focusDistanceCapabilities: capabilities.value.focusDistance,
    zoomStep: capabilities.value.zoom.step,
  })

  lastApplied.value = applied
  liveStatus.value.focus = applied.actualFocusDistance
  liveStatus.value.zoom = `${applied.actualZoom}×`

  const dims = getEffectiveVideoDimensions(
    video.videoWidth,
    video.videoHeight,
    config.resolutionPreset.width,
    config.resolutionPreset.height,
  )

  const startedAtIso = new Date().toISOString()

  if (applied.configurationStatus !== 'VALID') {
    return finalizeMatrixResult(config, applied, {
      dims,
      frames: 0,
      detections: [],
      sharpnessValues: [],
      startedAt: startedAtIso,
      finishedAt: new Date().toISOString(),
      repetitionsWithCorrectInGroup: 0,
      totalRepetitionsInGroup: 1,
    })
  }

  await new Promise((resolve) => window.setTimeout(resolve, settleMs.value))

  if (runAbort || sessionId !== runSessionId) {
    return createEmptyMatrixResult(config)
  }

  let frames = 0
  const configDetections: MatrixRawDetection[] = []
  const sharpnessValues: number[] = []
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

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp

        void (async () => {
          const elapsedMs = Math.round(performance.now() - startedAt)
          const sharpness = measureVideoSharpness(video, canvas)

          if (sharpness != null) {
            sharpnessValues.push(sharpness)
          }

          try {
            frames += 1
            const rawResults = normalizeDetections(await detector.detect(video))

            if (rawResults.length === 0) {
              liveStatus.value.classification = 'NO DETECTION'
            }

            for (const result of rawResults) {
              const rawValue = result.rawValue ?? ''
              const format = result.format ?? '—'

              if (!rawValue) {
                continue
              }

              const ratios = result.boundingBox
                ? calculateBarcodeSizeRatio(result.boundingBox, video.videoWidth, video.videoHeight)
                : null
              const classification = classifyDetection(
                rawValue,
                format,
                config.expectedBarcode,
                config.expectedFormat,
              )
              const checkDigitValid = isCheckDigitValid(format, rawValue)
              const timestampLabel = new Date().toLocaleTimeString('fr-FR')

              liveStatus.value = {
                rawValue,
                format,
                classification,
                widthRatio: ratios?.widthRatio != null ? `${(ratios.widthRatio * 100).toFixed(1)}%` : '—',
                sharpness: sharpness != null ? String(Math.round(sharpness)) : '—',
                focus: applied.actualFocusDistance,
                zoom: `${applied.actualZoom}×`,
              }

              const entry: MatrixRawDetection = {
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                timestamp: timestampLabel,
                elapsedMs,
                configId: config.id,
                phase: config.phase,
                repetition: config.repetition,
                resolutionLabel: config.resolutionPreset.label,
                focusRequested: config.focusRequested,
                focusActual: applied.actualFocusDistance,
                zoomActual: `${applied.actualZoom}×`,
                placementGuideLabel: config.placementGuideLabel,
                format,
                rawValue,
                classification,
                checkDigitValid,
                hammingDistance: computeHammingDistance(config.expectedBarcode, rawValue),
                matchingDigits: computeMatchingDigits(config.expectedBarcode, rawValue),
                boundingBoxWidth: ratios?.boundingBoxWidth ?? null,
                boundingBoxHeight: ratios?.boundingBoxHeight ?? null,
                widthRatio: ratios?.widthRatio ?? null,
                heightRatio: ratios?.heightRatio ?? null,
                nativeVideoWidth: dims.nativeWidth,
                nativeVideoHeight: dims.nativeHeight,
                logicalVideoWidth: dims.logicalWidth,
                logicalVideoHeight: dims.logicalHeight,
                sharpness,
              }

              configDetections.push(entry)
              rawDetections.value = [entry, ...rawDetections.value]
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

  const expectedReads = configDetections.filter((item) => item.classification === 'EXPECTED').length
  const completedBefore = results.value.filter(
    (item) => item.configId !== config.id && item.frames > 0,
  )
  const repeatability = computeGroupRepeatability(config, expectedReads, completedBefore)

  return finalizeMatrixResult(config, applied, {
    dims,
    frames,
    detections: configDetections,
    sharpnessValues,
    startedAt: startedAtIso,
    finishedAt: new Date().toISOString(),
    repetitionsWithCorrectInGroup: repeatability.repetitionsWithCorrect,
    totalRepetitionsInGroup: repeatability.totalRepetitionsInGroup,
  })
}

async function startExperiment(): Promise<void> {
  if (!canStartExperiment.value) {
    return
  }

  runAbort = false
  runSessionId += 1
  const sessionId = runSessionId
  runState.value = 'RUNNING'
  benchmarkStartedAt.value = new Date().toISOString()
  benchmarkFinishedAt.value = null

  const ordered = [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex)

  for (let index = 0; index < ordered.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = ordered[index]!
    const result = await runConfiguration(config, index, sessionId)
    results.value = results.value.map((item) => (item.configId === config.id ? result : item))
  }

  benchmarkFinishedAt.value = new Date().toISOString()

  if (runAbort || sessionId !== runSessionId) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
  currentConfig.value = null
}

function buildReportPayload() {
  return {
    environment: environment.value,
    trackSettings: trackSettings.value,
    phase: experimentPhase.value,
    orderMode: orderMode.value,
    randomSeed: randomSeed.value,
    durationSeconds: durationSeconds.value,
    settleMs: settleMs.value,
    repetitions: repetitions.value,
    placementGuide: placementGuideLabel.value,
    benchmarkStartedAt: benchmarkStartedAt.value,
    benchmarkFinishedAt: benchmarkFinishedAt.value,
    configurationOrder: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
    results: [...results.value].sort((a, b) => a.orderIndex - b.orderIndex),
    rawDetections: rawDetections.value,
    widthBuckets: widthBuckets.value,
    sharpnessBuckets: sharpnessBuckets.value,
    multiFrameLevels: multiFrameLevels.value,
    repeatabilityGroups: repeatabilityGroups.value,
    topConfigurations: topConfigurations.value,
    productionCriteria: productionCriteria.value,
    conclusion: conclusionText.value,
  }
}

function buildFullReport(): string {
  return buildMatrixReport({
    environment: environment.value,
    trackSettings: trackSettings.value,
    phase: experimentPhase.value,
    orderMode: orderMode.value,
    randomSeed: randomSeed.value,
    durationSeconds: durationSeconds.value,
    settleMs: settleMs.value,
    repetitions: repetitions.value,
    results: results.value,
    rawDetections: rawDetections.value,
    widthBuckets: widthBuckets.value,
    sharpnessBuckets: sharpnessBuckets.value,
    multiFrameLevels: multiFrameLevels.value,
    repeatabilityGroups: repeatabilityGroups.value,
    topConfigurations: topConfigurations.value,
    productionCriteria: productionCriteria.value,
    conclusion: conclusionText.value,
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

function exportJson(): void {
  const blob = new Blob([buildMatrixExportJson(buildReportPayload())], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `barcode-decode-reliability-matrix-${Date.now()}.json`
  anchor.click()
  URL.revokeObjectURL(url)
  copyMessage.value = 'JSON exporté.'
}

function exportCsv(): void {
  const blob = new Blob([buildMatrixCsv(results.value)], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `barcode-decode-reliability-matrix-${Date.now()}.csv`
  anchor.click()
  URL.revokeObjectURL(url)
  copyMessage.value = 'CSV synthèse exporté.'
}

function exportRawCsv(): void {
  const blob = new Blob([buildMatrixRawCsv(rawDetections.value)], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `barcode-decode-reliability-matrix-raw-${Date.now()}.csv`
  anchor.click()
  URL.revokeObjectURL(url)
  copyMessage.value = 'CSV raw exporté.'
}

function resolutionAppliedLabel(result: MatrixConfigurationResult): string {
  const native = `${result.actualNativeWidth ?? '—'}×${result.actualNativeHeight ?? '—'}`
  const logical = `${result.actualLogicalWidth ?? '—'}×${result.actualLogicalHeight ?? '—'}`

  return result.orientationSwapped ? `${native} (logical ${logical})` : native
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopRun()
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
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
  <Head title="Barcode Decode Reliability Matrix" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Decode Reliability Matrix</h1>
          <p class="barcode-reader-test-page__subtitle">
            Matrice résolution × focus × widthRatio réel — DEV isolé (aucun impact production)
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Phase 1</Link>
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-secondary">Phase 2</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">BarcodeDetector: <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong></p>
        <p class="mb-1">Manual focus: <strong>{{ manualFocusSupported ? 'SUPPORTED' : 'NOT SUPPORTED' }}</strong></p>
        <p class="mb-0 barcode-decode-reliability-matrix__muted">
          Focus 0.22 = point de départ expérimental NON VALIDÉ. Classement expérimental uniquement — jamais « recommended production ».
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">1. PARAMÈTRES</h2>
        <div class="barcode-decode-reliability-matrix__grid-form">
          <div>
            <label>Expected barcode</label>
            <input v-model="expectedBarcode" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          </div>
          <div>
            <label>Expected format</label>
            <input v-model="expectedFormat" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          </div>
          <div>
            <label>Phase</label>
            <select v-model="experimentPhase" class="form-select form-select-sm" :disabled="runState === 'RUNNING'" @change="rebuildConfigurations">
              <option value="A">A — Exploration (3 res × 5 focus)</option>
              <option value="FINE_FOCUS">Fine focus (1920×1080, 0.19–0.25)</option>
              <option value="B">B — Confirmation (top phase A)</option>
            </select>
          </div>
          <div>
            <label>Target size requested (guide utilisateur)</label>
            <select v-model="placementGuideId" class="form-select form-select-sm" :disabled="runState === 'RUNNING'" @change="rebuildConfigurations">
              <option v-for="guide in PLACEMENT_GUIDES" :key="guide.id" :value="guide.id">{{ guide.label }}</option>
            </select>
          </div>
          <div>
            <label>Repetitions</label>
            <input v-model.number="repetitions" type="number" min="1" max="10" class="form-control form-control-sm" :disabled="runState === 'RUNNING'" @change="rebuildConfigurations">
          </div>
          <div>
            <label>Duration (s)</label>
            <select v-model.number="durationSeconds" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in DURATION_OPTIONS" :key="option" :value="option">{{ option }} s</option>
            </select>
          </div>
          <div>
            <label>Settle (ms)</label>
            <select v-model.number="settleMs" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in SETTLE_OPTIONS" :key="option" :value="option">{{ option }} ms</option>
            </select>
          </div>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-2">
          <button
            type="button"
            class="btn btn-sm"
            :class="orderMode === 'FIXED' ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING'"
            @click="orderMode = 'FIXED'; rebuildConfigurations()"
          >
            Fixed order
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="orderMode === 'RANDOMIZED' ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING'"
            @click="orderMode = 'RANDOMIZED'; randomSeed = Date.now(); rebuildConfigurations()"
          >
            Randomized
          </button>
        </div>

        <div class="form-check mt-2">
          <input id="physical-confirmed" v-model="physicalConfirmed" class="form-check-input" type="checkbox" :disabled="runState === 'RUNNING'">
          <label class="form-check-label" for="physical-confirmed">Code-barres physique confirmé ({{ expectedBarcode }})</label>
        </div>

        <p class="barcode-decode-reliability-matrix__muted mb-0 mt-2">
          Zoom: {{ FIXED_ZOOM }}× · Configurations: {{ configCount }}
          <span v-if="orderMode === 'RANDOMIZED' && randomSeed != null"> · Seed: {{ randomSeed }}</span>
          <span v-if="experimentPhase === 'B' && !canRunPhaseB" class="barcode-decode-reliability-matrix__warning"> — Phase B nécessite des résultats phase A</span>
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__section--video">
        <h2 class="barcode-decode-reliability-matrix__section-title">2. CAMÉRA</h2>
        <div class="barcode-decode-reliability-matrix__video-wrap">
          <video ref="videoRef" class="barcode-decode-reliability-matrix__video" autoplay muted playsinline />
          <div v-if="runState === 'RUNNING'" class="barcode-decode-reliability-matrix__live-panel">
            <div>{{ liveStatus.rawValue }}</div>
            <div>{{ liveStatus.format }} · {{ liveStatus.classification }}</div>
            <div>widthRatio {{ liveStatus.widthRatio }} · sharpness {{ liveStatus.sharpness }}</div>
            <div>focus {{ liveStatus.focus }} · zoom {{ liveStatus.zoom }}</div>
          </div>
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-decode-reliability-matrix__hidden-canvas" aria-hidden="true" />

        <dl class="barcode-decode-reliability-matrix__grid mt-3">
          <div><dt>Native resolution</dt><dd>{{ trackSettings.width ?? '—' }}×{{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>FPS</dt><dd>{{ trackSettings.frameRate }}</dd></div>
          <div><dt>Facing</dt><dd>{{ trackSettings.facingMode }}</dd></div>
          <div><dt>Focus req / actual</dt><dd>{{ lastApplied?.requestedFocusDistance ?? '—' }} / {{ lastApplied?.actualFocusDistance ?? '—' }}</dd></div>
          <div><dt>Zoom req / actual</dt><dd>{{ FIXED_ZOOM }}× / {{ lastApplied?.actualZoom ?? trackSettings.zoom }}×</dd></div>
          <div><dt>Placement guide</dt><dd>{{ placementGuideLabel }}</dd></div>
        </dl>

        <div class="barcode-decode-reliability-matrix__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCameraWithPreset()">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStartExperiment" @click="startExperiment">Start</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING'" @click="resetExperiment">Reset</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Copy Report</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportJson">Export JSON</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportCsv">Export CSV</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportRawCsv">Export Raw CSV</button>
        </div>
        <p v-if="copyMessage" class="barcode-decode-reliability-matrix__muted mb-0 mt-2">{{ copyMessage }}</p>
        <p v-if="cameraError" class="barcode-decode-reliability-matrix__warning mb-0 mt-2">{{ cameraError }}</p>
      </section>

      <section v-if="currentConfig && runState === 'RUNNING'" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">3. CONFIGURATION EN COURS</h2>
        <pre class="barcode-decode-reliability-matrix__pre mb-0">{{ currentConfig.resolutionPreset.label }} · focus {{ currentConfig.focusRequested }} · rep {{ currentConfig.repetition }}
Target size requested: {{ currentConfig.placementGuideLabel }}
Elapsed: {{ elapsedSeconds }} s · Remaining: {{ remainingSeconds }} s</pre>
      </section>

      <section v-if="runState === 'RUNNING' || runState === 'COMPLETED'" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">4. PROGRESSION</h2>
        <div class="progress mb-2" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-bar" :style="{ width: `${progressPercent}%` }">{{ progressPercent }}%</div>
        </div>
        <p class="mb-0">{{ currentConfigIndex }} / {{ configCount }} configurations</p>
      </section>

      <section v-if="topConfigurations.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">5. TOP 5 — Experimental ranking only</h2>
        <div v-for="(item, index) in topConfigurations" :key="item.configId" class="barcode-decode-reliability-matrix__result-card">
          <div class="barcode-decode-reliability-matrix__result-title">#{{ index + 1 }} — {{ item.resolutionLabel }} / focus {{ item.focusRequested }} / rep {{ item.repetition }}</div>
          <p class="mb-1 barcode-decode-reliability-matrix__muted">
            Expected: {{ item.expectedReads }} · Score: {{ item.experimentalScore }} · Avg correct width: {{ item.averageCorrectWidthRatio != null ? `${(item.averageCorrectWidthRatio * 100).toFixed(1)}%` : '—' }}
            · Faux positifs (valid wrong + invalid): {{ item.validWrongReads + item.invalidReads }}
          </p>
        </div>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">6. TABLEAU DE SYNTHÈSE</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Resolution</th>
                <th>Focus</th>
                <th>Rep</th>
                <th>Target size</th>
                <th>Actual res</th>
                <th>Det</th>
                <th>Expected</th>
                <th>Correct rate</th>
                <th>Avg width</th>
                <th>Correct width</th>
                <th>Sharpness</th>
                <th>Score</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in results.filter((row) => row.frames > 0)" :key="item.configId">
                <td>{{ item.resolutionLabel }}</td>
                <td>{{ item.focusRequested }}</td>
                <td>{{ item.repetition }}</td>
                <td>{{ item.placementGuideLabel }}</td>
                <td>{{ resolutionAppliedLabel(item) }}</td>
                <td>{{ item.detections }}</td>
                <td>{{ item.expectedReads }}</td>
                <td>{{ item.correctRate }}</td>
                <td>{{ item.averageWidthRatio != null ? `${(item.averageWidthRatio * 100).toFixed(1)}%` : '—' }}</td>
                <td>{{ item.averageCorrectWidthRatio != null ? `${(item.averageCorrectWidthRatio * 100).toFixed(1)}%` : '—' }}</td>
                <td>{{ item.averageSharpness ?? '—' }}</td>
                <td>{{ item.experimentalScore }}</td>
                <td>{{ item.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="widthBuckets.some((item) => item.detections > 0)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">7. WIDTH RATIO (boundingBox réel)</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Bucket</th>
                <th>Detections</th>
                <th>Expected</th>
                <th>Correct rate</th>
                <th>Valid wrong</th>
                <th>Invalid</th>
                <th>Avg sharpness</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in widthBuckets.filter((row) => row.detections > 0)" :key="item.bucketLabel">
                <td>{{ item.bucketLabel }}</td>
                <td>{{ item.detections }}</td>
                <td>{{ item.expectedReads }}</td>
                <td>{{ item.correctRate }}</td>
                <td>{{ item.validWrongCount }}</td>
                <td>{{ item.invalidCount }}</td>
                <td>{{ item.averageSharpness }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="barcode-decode-reliability-matrix__chart">
          <div v-for="item in widthRatioChart" :key="item.label" class="barcode-decode-reliability-matrix__chart-row">
            <span>{{ item.label }}</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${Math.min(100, item.rate)}%` }" />
            </div>
            <span>{{ item.rate.toFixed(1) }}%</span>
          </div>
        </div>
      </section>

      <section v-if="sharpnessBuckets.some((item) => item.detections > 0)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">8. SHARPNESS</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Bucket</th>
                <th>Detections</th>
                <th>Expected</th>
                <th>Incorrect</th>
                <th>Correct rate</th>
                <th>Avg widthRatio</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in sharpnessBuckets.filter((row) => row.detections > 0)" :key="item.bucketLabel">
                <td>{{ item.bucketLabel }}</td>
                <td>{{ item.detections }}</td>
                <td>{{ item.expectedReads }}</td>
                <td>{{ item.incorrect }}</td>
                <td>{{ item.correctRate }}</td>
                <td>{{ item.averageWidthRatio }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="multiFrameLevels.some((item) => item.confirmations > 0)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">9. MULTI-FRAME</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Level</th>
                <th>Confirmations</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Avg delay</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in multiFrameLevels" :key="item.level">
                <td>{{ item.level }}</td>
                <td>{{ item.confirmations }}</td>
                <td>{{ item.correctConfirmations }}</td>
                <td>{{ item.incorrectConfirmations }}</td>
                <td>{{ item.averageDelayMs }} ms</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="resolutionChart.length > 0 || focusChart.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">10. GRAPHIQUES DEV</h2>
        <h3 class="barcode-decode-reliability-matrix__subsection-title">Correct rate vs resolution</h3>
        <div class="barcode-decode-reliability-matrix__chart">
          <div v-for="item in resolutionChart" :key="item.label" class="barcode-decode-reliability-matrix__chart-row">
            <span>{{ item.label }}</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${Math.min(100, item.rate)}%` }" />
            </div>
            <span>{{ item.rate.toFixed(2) }}%</span>
          </div>
        </div>
        <h3 class="barcode-decode-reliability-matrix__subsection-title mt-3">Correct rate vs focus</h3>
        <div class="barcode-decode-reliability-matrix__chart">
          <div v-for="item in focusChart" :key="item.label" class="barcode-decode-reliability-matrix__chart-row">
            <span>{{ item.label }}</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${Math.min(100, item.rate)}%` }" />
            </div>
            <span>{{ item.rate.toFixed(2) }}%</span>
          </div>
        </div>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">11. RAW DETECTIONS</h2>
        <div class="barcode-decode-reliability-matrix__grid-form mb-2">
          <div>
            <label>Filtrer classification</label>
            <select v-model="rawFilter" class="form-select form-select-sm">
              <option value="all">Toutes</option>
              <option value="EXPECTED">EXPECTED</option>
              <option value="CORRECT_VALUE">CORRECT_VALUE</option>
              <option value="VALID_WRONG">VALID_WRONG</option>
              <option value="INVALID">INVALID</option>
              <option value="NOISE">NOISE</option>
            </select>
          </div>
          <div>
            <label>Recherche</label>
            <input v-model="rawSearch" type="search" class="form-control form-control-sm" placeholder="valeur, format, résolution…">
          </div>
        </div>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Time</th>
                <th>Res</th>
                <th>Focus</th>
                <th>Target</th>
                <th>Value</th>
                <th>Format</th>
                <th>Class</th>
                <th>Width%</th>
                <th>Sharp</th>
                <th>Rep</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in filteredRawDetections.slice(0, 200)" :key="item.id">
                <td>{{ item.timestamp }}</td>
                <td>{{ item.resolutionLabel }}</td>
                <td>{{ item.focusRequested }}</td>
                <td>{{ item.placementGuideLabel }}</td>
                <td class="font-monospace">{{ item.rawValue }}</td>
                <td>{{ item.format }}</td>
                <td>{{ item.classification }}</td>
                <td>{{ item.widthRatio != null ? `${(item.widthRatio * 100).toFixed(1)}%` : '—' }}</td>
                <td>{{ item.sharpness ?? '—' }}</td>
                <td>{{ item.repetition }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="runState === 'COMPLETED'" class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__conclusion">
        <h2 class="barcode-decode-reliability-matrix__section-title">12. RAPPORT & CONCLUSION</h2>
        <pre class="barcode-decode-reliability-matrix__pre">{{ buildFullReport() }}</pre>
        <h3 class="barcode-decode-reliability-matrix__subsection-title">Critères production (information only)</h3>
        <ul class="mb-0">
          <li v-for="detail in productionCriteria.details" :key="detail">{{ detail }}</li>
        </ul>
      </section>
    </div>
  </div>
</template>
