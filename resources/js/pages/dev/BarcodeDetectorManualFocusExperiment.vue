<script setup lang="ts">
import {
  analyzeManualFocusEffect,
  analyzeZoomEffect,
  applyExperimentConfiguration,
  average,
  buildComparisonTableRows,
  buildConfigKey,
  buildConfigurationLabel,
  buildExperimentConclusion,
  buildExperimentDiagnosticClipboard,
  buildInitialAggregates,
  buildRecommendedConfigurations,
  CAMERA_SETTLING_MS,
  classifyReadResult,
  computeAverageDetectionMs,
  computeDistanceForPreset,
  computeRate,
  createEmptySessionStats,
  createExperimentBarcodeDetector,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_SUBJECT,
  DETECTION_INTERVAL_MS,
  findBestConfiguration,
  FIXED_CAMERA_CONSTRAINTS,
  formatDurationMs,
  getDataReliabilityStatus,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  MAX_CONSTRAINT_LOG,
  MAX_EVENT_HISTORY,
  measureVideoSharpness,
  pickBestNativeBarcode,
  readTrackCapabilitiesDetails,
  readTrackSettingsDetails,
  resolveZoomLevels,
  roundToStep,
  TEST_DURATION_OPTIONS,
  type AppliedConfiguration,
  type BarcodeDetectorLike,
  type ComparisonVerdict,
  type ConfigurationAggregate,
  type ConstraintLogEntry,
  type EventHistoryEntry,
  type FocusModeType,
  type ManualDistancePreset,
  type ReadResultType,
  type RecommendedConfiguration,
  type SessionStats,
  type TestDurationSeconds,
} from '@/utils/barcodeDetectorManualFocusExperiment'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const supportedFormats = ref<string[]>([])

const subject = ref(DEFAULT_SUBJECT)
const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const focusMode = ref<FocusModeType>('manual')
const distancePreset = ref<ManualDistancePreset | 'custom'>('50')
const customFocusDistance = ref<number | null>(null)
const activeZoom = ref(1)
const testDurationSeconds = ref<TestDurationSeconds>(30)

const cameraState = ref<'idle' | 'starting' | 'active' | 'stopping' | 'error'>('idle')
const detectionLoopState = ref<'stopped' | 'running'>('stopped')
const cameraError = ref<{ name: string; message: string } | null>(null)
const copyMessage = ref<string | null>(null)

const settlingState = ref<'idle' | 'waiting'>('idle')
const settlingElapsedMs = ref(0)
const detectionRemainingMs = ref(0)

const trackSettings = ref(readTrackSettingsDetails(null))
const trackCapabilities = ref(readTrackCapabilitiesDetails(null))
const trackState = ref('—')
const appliedControls = ref<AppliedConfiguration | null>(null)

const stats = ref<SessionStats>(createEmptySessionStats())
const aggregates = ref<ConfigurationAggregate[]>([])
const eventHistory = ref<EventHistoryEntry[]>([])
const constraintLog = ref<ConstraintLogEntry[]>([])
const lastIncorrect = ref<{ expected: string; detected: string } | null>(null)

const capturedFrameUrl = ref<string | null>(null)
const capturedSharpness = ref<number | null>(null)

const sharpnessValues = ref<number[]>([])
const correctSharpnessValues = ref<number[]>([])
const incorrectSharpnessValues = ref<number[]>([])
const notFoundSharpnessValues = ref<number[]>([])
const completedDetectionDurations = ref<number[]>([])

const recommendedConfigs = ref<RecommendedConfiguration[]>([])
const showRecommendedPanel = ref(false)
const matrixRunState = ref<'idle' | 'running'>('idle')
const matrixRunIndex = ref(0)

let cameraSessionId = 0
let detectionSessionId = 0
let settlingSessionId = 0
let diagnosticsTimer: number | null = null
let detectionTimer: number | null = null
let detectionLoopAnimationId: number | null = null
let detectionInProgress = false
let lastDetectionTime = 0
let matrixRunAbort = false

const supportsContinuous = computed(() => trackCapabilities.value.focusModes.includes('continuous'))
const supportsManual = computed(() => trackCapabilities.value.focusModes.includes('manual'))
const supportsFocusDistance = computed(() => trackCapabilities.value.focusDistance.supported)
const supportsZoom = computed(() => trackCapabilities.value.zoom.supported)

const availableZoomLevels = computed(() => resolveZoomLevels(trackCapabilities.value))

const requestedFocusDistance = computed(() => {
  if (focusMode.value !== 'manual' || !supportsFocusDistance.value) {
    return null
  }

  if (distancePreset.value === 'custom') {
    return customFocusDistance.value
  }

  return computeDistanceForPreset(trackCapabilities.value, distancePreset.value)
})

const currentConfigurationLabel = computed(() =>
  buildConfigurationLabel(focusMode.value, distancePreset.value, requestedFocusDistance.value, activeZoom.value),
)

const comparisonRows = computed(() => buildComparisonTableRows(aggregates.value))
const bestConfiguration = computed(() => findBestConfiguration(aggregates.value))

const zoomComparisonZoom = computed(() => {
  const levels = availableZoomLevels.value
  return levels.includes(4) ? 4 : levels[Math.floor(levels.length / 2)] ?? 1
})

const zoomEffect = computed<ComparisonVerdict>(() => {
  if (focusMode.value === 'manual') {
    return analyzeZoomEffect(aggregates.value, 'manual', requestedFocusDistance.value)
  }

  return analyzeZoomEffect(aggregates.value, 'continuous', null)
})

const manualFocusEffect = computed(() => analyzeManualFocusEffect(aggregates.value, zoomComparisonZoom.value))

const conclusion = computed(() => buildExperimentConclusion({
  stats: stats.value,
  bestConfiguration: bestConfiguration.value,
  zoomEffect: zoomEffect.value,
  manualFocusEffect: manualFocusEffect.value,
  appliedControls: appliedControls.value,
}))

const dataReliability = computed(() => getDataReliabilityStatus(stats.value.attempts))

const canApplyConfiguration = computed(() =>
  cameraState.value === 'active'
  && (focusMode.value !== 'continuous' || supportsContinuous.value)
  && (focusMode.value !== 'manual' || supportsManual.value),
)

const canStartDetection = computed(() =>
  cameraState.value === 'active'
  && environment.value.barcodeDetectorAvailable
  && detectorRef.value != null
  && settlingState.value === 'idle'
  && appliedControls.value?.configurationValid === true,
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
  if (diagnosticsTimer !== null) {
    window.clearInterval(diagnosticsTimer)
    diagnosticsTimer = null
  }

  if (detectionTimer !== null) {
    window.clearInterval(detectionTimer)
    detectionTimer = null
  }

  if (detectionLoopAnimationId !== null) {
    cancelAnimationFrame(detectionLoopAnimationId)
    detectionLoopAnimationId = null
  }
}

function refreshDiagnostics(): void {
  const track = getVideoTrack()
  trackSettings.value = readTrackSettingsDetails(track)
  trackCapabilities.value = readTrackCapabilitiesDetails(track)
  trackState.value = track?.readyState ?? '—'

  if (aggregates.value.length === 0) {
    aggregates.value = buildInitialAggregates(trackCapabilities.value)
  }

  recommendedConfigs.value = buildRecommendedConfigurations(trackCapabilities.value)

  if (customFocusDistance.value == null && trackCapabilities.value.focusDistance.min != null) {
    customFocusDistance.value = computeDistanceForPreset(trackCapabilities.value, '50')
  }
}

async function ensureDetector(): Promise<boolean> {
  if (detectorRef.value) {
    return true
  }

  if (!isNativeBarcodeDetectorAvailable()) {
    cameraError.value = { name: 'BarcodeDetectorUnavailable', message: 'BarcodeDetector non disponible.' }
    return false
  }

  try {
    const created = await createExperimentBarcodeDetector()
    detectorRef.value = created.detector
    supportedFormats.value = created.formatsUsed
    return true
  } catch (error) {
    cameraError.value = {
      name: error instanceof Error ? error.name : 'Error',
      message: error instanceof Error ? error.message : String(error),
    }

    return false
  }
}

async function waitForSettling(sessionId: number): Promise<void> {
  settlingSessionId += 1
  const localId = settlingSessionId
  settlingState.value = 'waiting'
  settlingElapsedMs.value = 0
  const startedAt = Date.now()

  while (Date.now() - startedAt < CAMERA_SETTLING_MS) {
    if (!isCameraSessionActive(sessionId) || localId !== settlingSessionId) {
      settlingState.value = 'idle'
      settlingElapsedMs.value = 0
      return
    }

    settlingElapsedMs.value = Date.now() - startedAt
    await new Promise((resolve) => window.setTimeout(resolve, 50))
  }

  settlingState.value = 'idle'
  settlingElapsedMs.value = CAMERA_SETTLING_MS
}

function isCameraSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
}

function isDetectionSessionActive(sessionId: number): boolean {
  return sessionId === detectionSessionId
}

async function waitForVideoReady(video: HTMLVideoElement, sessionId: number): Promise<boolean> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < START_TIMEOUT_MS) {
    if (!isCameraSessionActive(sessionId)) {
      return false
    }

    if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && video.videoWidth > 0) {
      return true
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return false
}

async function applyConfiguration(): Promise<boolean> {
  const track = getVideoTrack()

  if (!track) {
    return false
  }

  stopDetectionLoop()

  const result = await applyExperimentConfiguration(track, {
    focusMode: focusMode.value,
    requestedFocusDistance: requestedFocusDistance.value,
    requestedZoom: activeZoom.value,
    capabilities: trackCapabilities.value,
  })

  appliedControls.value = {
    ...result.applied,
    distancePreset: focusMode.value === 'manual' ? distancePreset.value : null,
    configurationLabel: currentConfigurationLabel.value,
  }

  constraintLog.value = [result.log, ...constraintLog.value].slice(0, MAX_CONSTRAINT_LOG)
  refreshDiagnostics()

  await waitForSettling(cameraSessionId)
  refreshDiagnostics()

  return result.applied.configurationValid
}

function resetSessionStats(): void {
  stats.value = createEmptySessionStats()
  sharpnessValues.value = []
  correctSharpnessValues.value = []
  incorrectSharpnessValues.value = []
  notFoundSharpnessValues.value = []
  completedDetectionDurations.value = []
}

function updateAggregate(resultType: ReadResultType, sharpness: number | null): void {
  const key = buildConfigKey(focusMode.value, requestedFocusDistance.value, activeZoom.value)

  aggregates.value = aggregates.value.map((item) => {
    if (item.configKey !== key) {
      return item
    }

    return {
      ...item,
      attempts: stats.value.attempts,
      correct: stats.value.correct,
      incorrect: stats.value.incorrect,
      notFound: stats.value.notFound,
      errors: stats.value.errors,
      averageDetectionMs: stats.value.averageDetectionMs,
      averageSharpness: average(sharpnessValues.value),
      correctAverageSharpness: average(correctSharpnessValues.value),
      incorrectAverageSharpness: average(incorrectSharpnessValues.value),
      notFoundAverageSharpness: average(notFoundSharpnessValues.value),
      actualFocus: trackSettings.value.focusMode,
      actualDistance: trackSettings.value.focusDistance,
      actualZoom: trackSettings.value.zoom,
      focusValidation: appliedControls.value?.focusValidation ?? 'UNKNOWN',
      configurationValid: appliedControls.value?.configurationValid ?? false,
      dataReliability: getDataReliabilityStatus(stats.value.attempts),
    }
  })
}

async function runSingleDetection(sessionId: number): Promise<void> {
  if (detectionInProgress || !isDetectionSessionActive(sessionId) || settlingState.value === 'waiting') {
    return
  }

  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!detector || !video || !canvas) {
    return
  }

  detectionInProgress = true
  const startedAt = performance.now()
  const sharpness = measureVideoSharpness(video, canvas)

  if (sharpness != null) {
    sharpnessValues.value = [...sharpnessValues.value, sharpness]
    stats.value = {
      ...stats.value,
      averageSharpness: average(sharpnessValues.value),
      minSharpness: sharpnessValues.value.length ? Math.min(...sharpnessValues.value) : null,
      maxSharpness: sharpnessValues.value.length ? Math.max(...sharpnessValues.value) : null,
    }
  }

  try {
    stats.value = { ...stats.value, attempts: stats.value.attempts + 1 }
    const results = await detector.detect(video)
    const durationMs = performance.now() - startedAt
    const best = pickBestNativeBarcode(results)
    const rawValue = best?.rawValue ?? ''
    const resultType = classifyReadResult(rawValue, expectedBarcode.value)

    completedDetectionDurations.value = [...completedDetectionDurations.value, durationMs]
    stats.value = {
      ...stats.value,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations.value),
      minDetectionMs: completedDetectionDurations.value.length ? Math.min(...completedDetectionDurations.value) : null,
      maxDetectionMs: completedDetectionDurations.value.length ? Math.max(...completedDetectionDurations.value) : null,
    }

    if (resultType === 'CORRECT') {
      stats.value = { ...stats.value, correct: stats.value.correct + 1 }

      if (sharpness != null) {
        correctSharpnessValues.value = [...correctSharpnessValues.value, sharpness]
      }
    } else if (resultType === 'INCORRECT') {
      stats.value = { ...stats.value, incorrect: stats.value.incorrect + 1 }
      lastIncorrect.value = { expected: expectedBarcode.value, detected: rawValue }

      if (sharpness != null) {
        incorrectSharpnessValues.value = [...incorrectSharpnessValues.value, sharpness]
      }
    } else if (resultType === 'NOT_FOUND') {
      stats.value = { ...stats.value, notFound: stats.value.notFound + 1 }

      if (sharpness != null) {
        notFoundSharpnessValues.value = [...notFoundSharpnessValues.value, sharpness]
      }
    }

    stats.value = {
      ...stats.value,
      correctAverageSharpness: average(correctSharpnessValues.value),
      incorrectAverageSharpness: average(incorrectSharpnessValues.value),
      notFoundAverageSharpness: average(notFoundSharpnessValues.value),
    }

    const entry: EventHistoryEntry = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      timestamp: new Date().toLocaleTimeString('fr-FR'),
      configuration: currentConfigurationLabel.value,
      focusMode: trackSettings.value.focusMode,
      focusDistance: trackSettings.value.focusDistance,
      zoom: trackSettings.value.zoom,
      sharpness,
      resultType,
      rawValue,
      expectedValue: expectedBarcode.value,
      durationMs,
    }

    eventHistory.value = [entry, ...eventHistory.value].slice(0, MAX_EVENT_HISTORY)
    updateAggregate(resultType, sharpness)
  } catch {
    stats.value = { ...stats.value, errors: stats.value.errors + 1 }
    updateAggregate('ERROR', sharpness)
  } finally {
    detectionInProgress = false
  }
}

function stopDetectionLoop(): void {
  detectionSessionId += 1
  detectionLoopState.value = 'stopped'
  detectionRemainingMs.value = 0

  if (detectionTimer !== null) {
    window.clearInterval(detectionTimer)
    detectionTimer = null
  }

  if (detectionLoopAnimationId !== null) {
    cancelAnimationFrame(detectionLoopAnimationId)
    detectionLoopAnimationId = null
  }

  detectionInProgress = false
}

function waitForDetectionComplete(): Promise<void> {
  return new Promise((resolve) => {
    const check = (): void => {
      if (detectionLoopState.value === 'stopped') {
        resolve()
        return
      }

      window.setTimeout(check, 200)
    }

    check()
  })
}

async function startDetectionLoop(): Promise<void> {
  if (!canStartDetection.value) {
    return
  }

  stopDetectionLoop()
  detectionSessionId += 1
  const sessionId = detectionSessionId
  detectionLoopState.value = 'running'
  resetSessionStats()
  lastDetectionTime = 0

  const endAt = Date.now() + testDurationSeconds.value * 1000
  detectionRemainingMs.value = testDurationSeconds.value * 1000

  detectionTimer = window.setInterval(() => {
    detectionRemainingMs.value = Math.max(0, endAt - Date.now())

    if (detectionRemainingMs.value <= 0) {
      stopDetectionLoop()
    }
  }, 200)

  const tick = (timestamp: number): void => {
    if (!isDetectionSessionActive(sessionId)) {
      return
    }

    if (timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
      lastDetectionTime = timestamp
      void runSingleDetection(sessionId)
    }

    detectionLoopAnimationId = requestAnimationFrame(tick)
  }

  detectionLoopAnimationId = requestAnimationFrame(tick)
}

async function runSelectedConfiguration(): Promise<void> {
  const valid = await applyConfiguration()

  if (valid) {
    await startDetectionLoop()
  }
}

async function runRecommendedMatrix(): Promise<void> {
  if (recommendedConfigs.value.length === 0) {
    return
  }

  const estimatedMinutes = Math.ceil((recommendedConfigs.value.length * testDurationSeconds.value) / 60)
  const confirmed = window.confirm(
    `Lancer ${recommendedConfigs.value.length} configurations recommandées (${testDurationSeconds.value}s chacune, ~${estimatedMinutes} min) ?\n\nVous pourrez arrêter entre chaque test.`,
  )

  if (!confirmed) {
    return
  }

  matrixRunAbort = false
  matrixRunState.value = 'running'
  matrixRunIndex.value = 0

  for (let index = 0; index < recommendedConfigs.value.length; index += 1) {
    if (matrixRunAbort) {
      break
    }

    matrixRunIndex.value = index
    const config = recommendedConfigs.value[index]!
    applyRecommendedConfig(config)

    const valid = await applyConfiguration()

    if (!valid) {
      continue
    }

    await startDetectionLoop()
    await waitForDetectionComplete()

    if (index < recommendedConfigs.value.length - 1 && !matrixRunAbort) {
      const next = recommendedConfigs.value[index + 1]!.label
      const proceed = window.confirm(`Configuration suivante : ${next}\n\nContinuer ?`)
      if (!proceed) {
        break
      }
    }
  }

  matrixRunState.value = 'idle'
}

function stopMatrixRun(): void {
  matrixRunAbort = true
  stopDetectionLoop()
  matrixRunState.value = 'idle'
}

function applyRecommendedConfig(config: RecommendedConfiguration): void {
  focusMode.value = config.focusMode
  activeZoom.value = config.zoom

  if (config.distancePreset) {
    distancePreset.value = config.distancePreset
  }
}

function selectMatrixConfig(mode: FocusModeType, preset: ManualDistancePreset | null, zoom: number): void {
  focusMode.value = mode
  activeZoom.value = zoom

  if (preset) {
    distancePreset.value = preset
  }
}

function prepareRecommendedTest(): void {
  recommendedConfigs.value = buildRecommendedConfigurations(trackCapabilities.value)
  showRecommendedPanel.value = true

  if (recommendedConfigs.value.length > 0) {
    applyRecommendedConfig(recommendedConfigs.value[0]!)
  }
}

async function stopCamera(): Promise<void> {
  stopDetectionLoop()
  stopMatrixRun()
  stopTimers()
  settlingSessionId += 1
  cameraSessionId += 1
  cameraState.value = 'stopping'

  stopTracks(activeStream.value)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  cameraState.value = 'idle'
  refreshDiagnostics()
}

async function startCamera(): Promise<void> {
  await stopCamera()

  if (!(await ensureDetector())) {
    cameraState.value = 'error'
    return
  }

  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'starting'

  try {
    const stream = await navigator.mediaDevices.getUserMedia(FIXED_CAMERA_CONSTRAINTS)

    if (!isCameraSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Vidéo indisponible')
    }

    video.srcObject = stream
    video.playsInline = true
    video.muted = true
    await video.play()

    if (!(await waitForVideoReady(video, sessionId))) {
      throw new Error('Vidéo inactive')
    }

    refreshDiagnostics()
    aggregates.value = buildInitialAggregates(trackCapabilities.value)
    recommendedConfigs.value = buildRecommendedConfigurations(trackCapabilities.value)

    if (!supportsManual.value && supportsContinuous.value) {
      focusMode.value = 'continuous'
    }

    cameraState.value = 'active'
    diagnosticsTimer = window.setInterval(refreshDiagnostics, 500)
  } catch (error) {
    cameraError.value = {
      name: error instanceof Error ? error.name : 'Error',
      message: error instanceof Error ? error.message : String(error),
    }
    cameraState.value = 'error'
    stopTracks(activeStream.value)
    activeStream.value = null
  }
}

function resetStatistics(): void {
  stopDetectionLoop()
  resetSessionStats()
  eventHistory.value = []
  constraintLog.value = []
  lastIncorrect.value = null
  appliedControls.value = null
  aggregates.value = buildInitialAggregates(trackCapabilities.value)
}

function captureFrame(): void {
  const video = videoRef.value
  const canvas = document.createElement('canvas')

  if (!video || video.videoWidth <= 0) {
    return
  }

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d')

  if (!context) {
    return
  }

  context.drawImage(video, 0, 0)
  capturedFrameUrl.value = canvas.toDataURL('image/jpeg', 0.85)
  capturedSharpness.value = measureVideoSharpness(video, canvas)
}

function onCustomDistanceInput(event: Event): void {
  const value = Number.parseFloat((event.target as HTMLInputElement).value)
  const { min, max, step } = trackCapabilities.value.focusDistance

  if (min == null || max == null || !Number.isFinite(value)) {
    return
  }

  customFocusDistance.value = roundToStep(value, min, max, step)
  distancePreset.value = 'custom'
}

async function copyDiagnostic(): Promise<void> {
  const text = buildExperimentDiagnosticClipboard({
    environment: environment.value,
    supportedFormats: supportedFormats.value,
    trackSettings: trackSettings.value,
    capabilities: trackCapabilities.value,
    appliedControls: appliedControls.value,
    expectedBarcode: expectedBarcode.value,
    subject: subject.value,
    stats: stats.value,
    aggregates: aggregates.value,
    constraintLog: constraintLog.value,
    history: eventHistory.value,
    bestConfiguration: bestConfiguration.value,
    zoomEffect: zoomEffect.value,
    manualFocusEffect: manualFocusEffect.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopDetectionLoop()
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  window.addEventListener('pagehide', () => { void stopCamera() })
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  void stopCamera()
})
</script>

<template>
  <Head title="Expérience Focus manuel BarcodeDetector" />

  <div class="barcode-reader-test-page barcode-detector-manual-focus-experiment">
    <div class="barcode-reader-test-page__container barcode-detector-manual-focus-experiment__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Expérience Focus manuel × Zoom</h1>
          <p class="barcode-reader-test-page__subtitle">Laboratoire DEV — CONTINUOUS vs MANUAL, validation stricte REQUESTED vs ACTUAL</p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        <Link href="/dev/barcode-detector-focus-distance-mapping" class="btn btn-sm btn-outline-secondary">Focus Distance Mapping</Link>
        <Link href="/dev/barcode-detector-focus-zoom-benchmark" class="btn btn-sm btn-outline-secondary">Focus × Zoom Benchmark</Link>
        <Link href="/dev/barcode-detector-size-zoom-comparison" class="btn btn-sm btn-outline-secondary">Size × Zoom Comparison</Link>
        <Link href="/dev/barcode-detector-distance-focus" class="btn btn-sm btn-outline-secondary">Distance × Focus</Link>
        <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
        <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
        <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
      </header>

      <section class="barcode-detector-manual-focus-experiment__banner">
        <p class="mb-0">
          BarcodeDetector:
          <strong>{{ environment.barcodeDetectorAvailable ? 'Available' : 'Unavailable' }}</strong>
        </p>
      </section>

      <section v-if="appliedControls" class="barcode-detector-manual-focus-experiment__focus-status" :class="appliedControls.configurationValid ? 'barcode-detector-manual-focus-experiment__focus-status--valid' : appliedControls.constraintApplication === 'ERROR' ? 'barcode-detector-manual-focus-experiment__focus-status--error' : 'barcode-detector-manual-focus-experiment__focus-status--invalid'">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Constraint validation</h2>
        <p class="mb-1"><strong>Constraint application: {{ appliedControls.constraintApplication === 'ERROR' ? 'ERROR' : 'SUCCESS' }}</strong></p>
        <pre class="barcode-detector-manual-focus-experiment__pre mb-2">{{ appliedControls.focusValidationMessage }}</pre>
        <dl class="barcode-detector-manual-focus-experiment__grid mb-0">
          <div><dt>Focus mode</dt><dd>{{ appliedControls.focusModeStatus }}</dd></div>
          <div><dt>Focus distance</dt><dd>{{ appliedControls.focusDistanceStatus }}</dd></div>
          <div><dt>Zoom</dt><dd>{{ appliedControls.zoomStatus }}</dd></div>
          <div><dt>Experiment</dt><dd>{{ appliedControls.configurationValid ? 'VALID' : 'INVALID' }}</dd></div>
        </dl>
        <p v-if="appliedControls.applyErrorMessage" class="barcode-detector-manual-focus-experiment__warning mb-0 mt-2">
          {{ appliedControls.applyErrorName }} — {{ appliedControls.applyErrorMessage }}
        </p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section barcode-detector-manual-focus-experiment__section--video">
        <div class="barcode-detector-manual-focus-experiment__video-banner">
          <span>Flux caméra — diagnostic uniquement</span>
        </div>
        <div class="barcode-detector-manual-focus-experiment__video-wrap">
          <video ref="videoRef" class="barcode-detector-manual-focus-experiment__video" autoplay muted playsinline />
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-detector-manual-focus-experiment__hidden-canvas" aria-hidden="true" />

        <div class="barcode-detector-manual-focus-experiment__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'starting'" @click="startCamera">Start camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'active'" @click="stopCamera">Stop camera</button>
          <button type="button" class="btn btn-outline-primary btn-sm" :disabled="!canApplyConfiguration" @click="applyConfiguration">Apply configuration</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStartDetection" @click="startDetectionLoop">Start detection</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="detectionLoopState !== 'running'" @click="stopDetectionLoop">Stop detection</button>
          <button type="button" class="btn btn-warning btn-sm" :disabled="!canApplyConfiguration" @click="runSelectedConfiguration">Run selected configuration</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetStatistics">Reset results</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'active'" @click="captureFrame">Capture frame</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyDiagnostic">Copy diagnostic</button>
        </div>

        <p class="barcode-detector-manual-focus-experiment__muted mb-0 mt-2">
          Camera settling: {{ settlingState === 'waiting' ? `${settlingElapsedMs} ms` : 'idle' }}
          <span v-if="detectionLoopState === 'running'"> — Remaining: {{ Math.ceil(detectionRemainingMs / 1000) }} s</span>
        </p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">CAMERA</h2>
        <dl class="barcode-detector-manual-focus-experiment__grid">
          <div><dt>Requested resolution</dt><dd>1280×720 @ 30 fps</dd></div>
          <div><dt>Actual width</dt><dd>{{ trackSettings.width ?? '—' }}</dd></div>
          <div><dt>Actual height</dt><dd>{{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>Facing</dt><dd>{{ trackSettings.facingMode }}</dd></div>
          <div><dt>FPS</dt><dd>{{ trackSettings.frameRate }}</dd></div>
          <div><dt>Track state</dt><dd>{{ trackState }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">CAPABILITIES</h2>
        <dl class="barcode-detector-manual-focus-experiment__grid">
          <div class="barcode-detector-manual-focus-experiment__grid-full"><dt>Focus mode</dt><dd>{{ trackCapabilities.focusModes.join(', ') || 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Focus distance min</dt><dd>{{ trackCapabilities.focusDistance.min ?? '—' }}</dd></div>
          <div><dt>Focus distance max</dt><dd>{{ trackCapabilities.focusDistance.max ?? '—' }}</dd></div>
          <div><dt>Focus distance step</dt><dd>{{ trackCapabilities.focusDistance.step ?? '—' }}</dd></div>
          <div><dt>Zoom min</dt><dd>{{ trackCapabilities.zoom.min ?? '—' }}</dd></div>
          <div><dt>Zoom max</dt><dd>{{ trackCapabilities.zoom.max ?? '—' }}</dd></div>
          <div><dt>Zoom step</dt><dd>{{ trackCapabilities.zoom.step ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">CURRENT SETTINGS</h2>
        <dl class="barcode-detector-manual-focus-experiment__grid">
          <div><dt>Focus mode</dt><dd>{{ trackSettings.focusMode }}</dd></div>
          <div><dt>Focus distance</dt><dd>{{ trackSettings.focusDistance }}</dd></div>
          <div><dt>Zoom</dt><dd>{{ trackSettings.zoom }}</dd></div>
          <div><dt>Width</dt><dd>{{ trackSettings.width ?? '—' }}</dd></div>
          <div><dt>Height</dt><dd>{{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>Frame rate</dt><dd>{{ trackSettings.frameRate }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">TEST</h2>
        <div class="barcode-detector-manual-focus-experiment__form-row">
          <label>Subject</label>
          <input v-model="subject" type="text" class="form-control form-control-sm">
        </div>
        <div class="barcode-detector-manual-focus-experiment__form-row">
          <label>Expected barcode</label>
          <input v-model="expectedBarcode" type="text" class="form-control form-control-sm font-monospace">
        </div>
        <div class="barcode-detector-manual-focus-experiment__form-row">
          <label>Duration</label>
          <div class="barcode-detector-manual-focus-experiment__actions">
            <button
              v-for="duration in TEST_DURATION_OPTIONS"
              :key="duration"
              type="button"
              class="btn btn-sm"
              :class="testDurationSeconds === duration ? 'btn-primary' : 'btn-outline-secondary'"
              @click="testDurationSeconds = duration"
            >
              {{ duration }} s
            </button>
          </div>
        </div>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Focus mode</h2>
        <div class="barcode-detector-manual-focus-experiment__actions">
          <button type="button" class="btn btn-sm" :class="focusMode === 'continuous' ? 'btn-primary' : 'btn-outline-secondary'" :disabled="!supportsContinuous" @click="focusMode = 'continuous'">CONTINUOUS</button>
          <button type="button" class="btn btn-sm" :class="focusMode === 'manual' ? 'btn-primary' : 'btn-outline-secondary'" :disabled="!supportsManual" @click="focusMode = 'manual'">MANUAL</button>
        </div>
      </section>

      <section v-if="focusMode === 'manual'" class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Focus distance</h2>
        <div class="barcode-detector-manual-focus-experiment__actions mb-2">
          <button
            v-for="preset in ['min', '25', '50', '75', 'max']"
            :key="preset"
            type="button"
            class="btn btn-sm"
            :class="distancePreset === preset ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="!supportsFocusDistance"
            @click="distancePreset = preset as ManualDistancePreset"
          >
            MANUAL {{ preset === 'min' ? 'MIN' : preset === 'max' ? 'MAX' : `${preset}%` }}
          </button>
          <button type="button" class="btn btn-sm" :class="distancePreset === 'custom' ? 'btn-primary' : 'btn-outline-secondary'" @click="distancePreset = 'custom'">Custom</button>
        </div>
        <div v-if="supportsFocusDistance && trackCapabilities.focusDistance.min != null && trackCapabilities.focusDistance.max != null" class="barcode-detector-manual-focus-experiment__form-row">
          <label>Custom distance ({{ trackCapabilities.focusDistance.min }} → {{ trackCapabilities.focusDistance.max }}, step {{ trackCapabilities.focusDistance.step ?? '—' }})</label>
          <input
            type="range"
            :min="trackCapabilities.focusDistance.min"
            :max="trackCapabilities.focusDistance.max"
            :step="trackCapabilities.focusDistance.step ?? 0.01"
            :value="customFocusDistance ?? trackCapabilities.focusDistance.min"
            @input="onCustomDistanceInput"
          >
        </div>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0">
          Requested focus distance: {{ requestedFocusDistance ?? '—' }}
          — Actual focus distance: {{ trackSettings.focusDistance }}
        </p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Zoom</h2>
        <div class="barcode-detector-manual-focus-experiment__actions">
          <button
            v-for="zoom in availableZoomLevels"
            :key="zoom"
            type="button"
            class="btn btn-sm"
            :class="activeZoom === zoom ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="!supportsZoom"
            @click="activeZoom = zoom"
          >
            {{ zoom }}×
          </button>
        </div>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0 mt-2">
          Requested zoom: {{ activeZoom }}× — Actual zoom: {{ trackSettings.zoom }}×
        </p>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0">Configuration: <strong>{{ currentConfigurationLabel }}</strong></p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Matrice expérimentale</h2>
        <p class="barcode-detector-manual-focus-experiment__muted">Sélectionnez une configuration — aucun lancement automatique de la matrice complète.</p>

        <div v-if="supportsContinuous" class="mb-3">
          <h3 class="h6">CONTINUOUS</h3>
          <div class="barcode-detector-manual-focus-experiment__actions">
            <button
              v-for="zoom in availableZoomLevels"
              :key="`continuous-${zoom}`"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              @click="selectMatrixConfig('continuous', null, zoom)"
            >
              CONTINUOUS + {{ zoom }}×
            </button>
          </div>
        </div>

        <div v-if="supportsManual && supportsFocusDistance">
          <h3 class="h6">MANUAL</h3>
          <div v-for="preset in ['min', '25', '50', '75', 'max']" :key="preset" class="mb-2">
            <div class="barcode-detector-manual-focus-experiment__actions">
              <button
                v-for="zoom in availableZoomLevels"
                :key="`${preset}-${zoom}`"
                type="button"
                class="btn btn-sm btn-outline-secondary"
                @click="selectMatrixConfig('manual', preset as ManualDistancePreset, zoom)"
              >
                MANUAL {{ preset === 'min' ? 'MIN' : preset === 'max' ? 'MAX' : `${preset}%` }} + {{ zoom }}×
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Mode expérimental recommandé</h2>
        <div class="barcode-detector-manual-focus-experiment__actions">
          <button type="button" class="btn btn-sm btn-outline-primary" @click="prepareRecommendedTest">Recommended test</button>
          <button type="button" class="btn btn-sm btn-warning" :disabled="recommendedConfigs.length === 0 || matrixRunState === 'running'" @click="runRecommendedMatrix">Run recommended matrix</button>
          <button v-if="matrixRunState === 'running'" type="button" class="btn btn-sm btn-outline-danger" @click="stopMatrixRun">Stop matrix</button>
        </div>
        <p v-if="matrixRunState === 'running'" class="barcode-detector-manual-focus-experiment__muted mb-0 mt-2">
          Matrice en cours : {{ matrixRunIndex + 1 }} / {{ recommendedConfigs.length }}
        </p>
        <div v-if="showRecommendedPanel && recommendedConfigs.length > 0" class="barcode-detector-manual-focus-experiment__recommended-list mt-2">
          <button
            v-for="config in recommendedConfigs"
            :key="config.id"
            type="button"
            class="btn btn-sm btn-outline-secondary mb-1 me-1"
            @click="applyRecommendedConfig(config)"
          >
            {{ config.label }}
          </button>
        </div>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Statistiques</h2>
        <dl class="barcode-detector-manual-focus-experiment__grid">
          <div><dt>Attempts</dt><dd>{{ stats.attempts }}</dd></div>
          <div><dt>Correct</dt><dd>{{ stats.correct }}</dd></div>
          <div><dt>Incorrect</dt><dd>{{ stats.incorrect }}</dd></div>
          <div><dt>Not found</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Correct rate</dt><dd>{{ computeRate(stats.correct, stats.attempts) }}</dd></div>
          <div><dt>Incorrect rate</dt><dd>{{ computeRate(stats.incorrect, stats.attempts) }}</dd></div>
          <div><dt>Not found rate</dt><dd>{{ computeRate(stats.notFound, stats.attempts) }}</dd></div>
          <div><dt>Data reliability</dt><dd>{{ dataReliability }} ({{ stats.attempts }} attempts)</dd></div>
          <div><dt>Avg detection</dt><dd>{{ formatDurationMs(stats.averageDetectionMs) }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Sharpness score</h2>
        <dl class="barcode-detector-manual-focus-experiment__grid">
          <div><dt>Average sharpness</dt><dd>{{ stats.averageSharpness ?? '—' }}</dd></div>
          <div><dt>Min / Max</dt><dd>{{ stats.minSharpness ?? '—' }} / {{ stats.maxSharpness ?? '—' }}</dd></div>
          <div><dt>Correct reads avg</dt><dd>{{ stats.correctAverageSharpness ?? '—' }}</dd></div>
          <div><dt>Incorrect reads avg</dt><dd>{{ stats.incorrectAverageSharpness ?? '—' }}</dd></div>
          <div><dt>Not found avg</dt><dd>{{ stats.notFoundAverageSharpness ?? '—' }}</dd></div>
        </dl>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0">Indice empirique uniquement — pas une mesure optique absolue.</p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Zoom effect</h2>
        <p class="mb-1">Does zoom improve detection? <strong>{{ zoomEffect }}</strong></p>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0">Compare les configurations avec même focus mode et distance, zooms différents.</p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Focus effect</h2>
        <p class="mb-1">Does manual focus improve detection? <strong>{{ manualFocusEffect }}</strong> (zoom {{ zoomComparisonZoom }}×)</p>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0">Compare CONTINUOUS vs MANUAL distances à zoom fixe.</p>
      </section>

      <section v-if="lastIncorrect" class="barcode-detector-manual-focus-experiment__alert">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Lecture incorrecte</h2>
        <p class="mb-0 font-monospace">INCORRECT — Detected: {{ lastIncorrect.detected }} — Expected: {{ lastIncorrect.expected }}</p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Meilleure configuration</h2>
        <p class="mb-1"><strong>{{ bestConfiguration.label }}</strong></p>
        <p class="barcode-detector-manual-focus-experiment__muted mb-0">{{ bestConfiguration.reason }}</p>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Tableau comparatif</h2>
        <div class="barcode-detector-manual-focus-experiment__table-wrap">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Configuration</th>
                <th>Actual Focus</th>
                <th>Distance</th>
                <th>Zoom</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Not found</th>
                <th>Correct %</th>
                <th>Sharpness</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in comparisonRows" :key="row.configuration">
                <td>{{ row.configuration }}</td>
                <td>{{ row.actualFocus }}</td>
                <td>{{ row.distance }}</td>
                <td>{{ row.zoom }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.incorrect }}</td>
                <td>{{ row.notFound }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.sharpness }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="eventHistory.length > 0" class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Historique ({{ eventHistory.length }} derniers)</h2>
        <div class="barcode-detector-manual-focus-experiment__history">
          <div v-for="entry in eventHistory" :key="entry.id" class="font-monospace barcode-detector-manual-focus-experiment__history-item">
            <span>{{ entry.timestamp }}</span>
            <span>{{ entry.configuration }}</span>
            <span>{{ entry.focusMode }} focusDistance: {{ entry.focusDistance }} zoom: {{ entry.zoom }}×</span>
            <span>sharpness: {{ entry.sharpness ?? '—' }}</span>
            <span :class="`barcode-detector-manual-focus-experiment__result--${entry.resultType.toLowerCase().replace('_', '-')}`">{{ entry.resultType }}</span>
            <span v-if="entry.resultType === 'INCORRECT'">Detected: {{ entry.rawValue }} Expected: {{ entry.expectedValue }}</span>
            <span v-else-if="entry.rawValue">{{ entry.rawValue }}</span>
            <span>{{ Math.round(entry.durationMs) }} ms</span>
          </div>
        </div>
      </section>

      <section v-if="capturedFrameUrl" class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Capture visuelle</h2>
        <p class="barcode-detector-manual-focus-experiment__muted">Sharpness score: {{ capturedSharpness ?? '—' }}</p>
        <img :src="capturedFrameUrl" alt="Capture diagnostic" class="barcode-detector-manual-focus-experiment__preview-image">
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Protocole prioritaire</h2>
        <pre class="barcode-detector-manual-focus-experiment__pre mb-0">Série A — MANUAL distance fixe × zoom 1/2/3/4/6/8
Série B — zoom 4× × MANUAL MIN/25%/50%/75%/MAX
Série C — CONTINUOUS vs MANUAL × zoom 4×</pre>
      </section>

      <section class="barcode-detector-manual-focus-experiment__section">
        <h2 class="barcode-detector-manual-focus-experiment__section-title">Conclusion</h2>
        <pre class="barcode-detector-manual-focus-experiment__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-detector-manual-focus-experiment__muted">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-detector-manual-focus-experiment__warning">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
