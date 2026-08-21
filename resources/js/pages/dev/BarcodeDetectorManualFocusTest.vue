<script setup lang="ts">
import {
  applyFocusProfileToTrack,
  applyZoomToTrack,
  average,
  buildCapabilityMismatchWarning,
  buildComparisonTableRows,
  buildConfigurationLabel,
  buildConfigKey,
  buildFocusProfiles,
  buildManualFocusConclusion,
  buildManualFocusDiagnosticClipboard,
  CAMERA_SETTLING_MS,
  classifyReadResult,
  computeAverageDetectionMs,
  computeRate,
  createEmptySessionStats,
  createInitialAggregates,
  createTestBarcodeDetector,
  DEFAULT_SMALL_EAN_VALUE,
  DETECTION_INTERVAL_MS,
  evaluateFocusApplicationStatus,
  findBestConfiguration,
  FIXED_CAMERA_CONSTRAINTS,
  formatDurationMs,
  getEnvironmentDiagnostics,
  getFocusProfile,
  isFocusProfileSelectable,
  measureVideoSharpness,
  pickBestNativeBarcode,
  readTrackCapabilitiesDetails,
  readTrackSettingsDetails,
  STANDARD_EAN_VALUE,
  TEST_DURATION_OPTIONS,
  type AppliedControlsSnapshot,
  type BarcodeDetectorLike,
  type ConfigurationAggregate,
  type ConstraintLogEntry,
  type EventHistoryEntry,
  type FocusProfileId,
  type ReadResultType,
  type SessionStats,
  type TestDurationSeconds,
} from '@/utils/barcodeDetectorManualFocusTest'
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
const detectorCreationNote = ref<string | null>(null)

const codeMode = ref<'small' | 'standard'>('small')
const expectedBarcode = ref(DEFAULT_SMALL_EAN_VALUE)
const activeFocusProfileId = ref<FocusProfileId>('default')
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
const appliedControls = ref<AppliedControlsSnapshot | null>(null)
const capabilityMismatchWarning = ref<string | null>(null)

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

let cameraSessionId = 0
let detectionSessionId = 0
let settlingSessionId = 0
let diagnosticsTimer: number | null = null
let detectionTimer: number | null = null
let detectionLoopAnimationId: number | null = null
let detectionInProgress = false
let lastDetectionTime = 0

const focusProfiles = computed(() => buildFocusProfiles(trackCapabilities.value))
const activeFocusProfile = computed(() => getFocusProfile(focusProfiles.value, activeFocusProfileId.value))
const availableZoomLevels = computed(() => {
  const caps = trackCapabilities.value

  if (!caps.zoom.supported || caps.zoom.max == null) {
    return [1]
  }

  return [1, 2, 3, 4, 6, 8].filter((level) => level <= caps.zoom.max!)
})
const comparisonRows = computed(() => buildComparisonTableRows(aggregates.value))
const bestConfiguration = computed(() => findBestConfiguration(aggregates.value))
const conclusion = computed(() => buildManualFocusConclusion({
  stats: stats.value,
  appliedControls: appliedControls.value,
  bestConfiguration: bestConfiguration.value,
  capabilityMismatchWarning: capabilityMismatchWarning.value,
}))

const canStartDetection = computed(() =>
  cameraState.value === 'active'
  && environment.value.barcodeDetectorAvailable
  && detectorRef.value != null
  && settlingState.value === 'idle'
  && (appliedControls.value?.validManualTest ?? true),
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
  capabilityMismatchWarning.value = buildCapabilityMismatchWarning(trackCapabilities.value, trackSettings.value)

  if (aggregates.value.length === 0) {
    aggregates.value = createInitialAggregates(focusProfiles.value, trackCapabilities.value)
  }
}

async function ensureDetector(): Promise<boolean> {
  if (detectorRef.value) {
    return true
  }

  if (!environment.value.barcodeDetectorAvailable) {
    cameraError.value = { name: 'BarcodeDetectorUnavailable', message: 'BarcodeDetector non disponible.' }
    return false
  }

  try {
    const created = await createTestBarcodeDetector()
    detectorRef.value = created.detector
    supportedFormats.value = created.formatsUsed.length > 0 ? created.formatsUsed : ['ean_13']
    detectorCreationNote.value = created.creationNote
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

async function applyConfiguration(): Promise<void> {
  const track = getVideoTrack()

  if (!track) {
    return
  }

  stopDetectionLoop()

  const profile = activeFocusProfile.value
  const focusResult = await applyFocusProfileToTrack(track, profile, trackCapabilities.value)
  constraintLog.value = [focusResult.log, ...constraintLog.value].slice(0, 30)

  const zoomResult = await applyZoomToTrack(track, activeZoom.value, trackCapabilities.value)
  constraintLog.value = [zoomResult, ...constraintLog.value].slice(0, 30)

  refreshDiagnostics()

  const evaluation = evaluateFocusApplicationStatus({
    profile,
    requestedFocusMode: profile.constraintFocusMode ?? 'default',
    actualFocusMode: trackSettings.value.focusMode,
    requestedFocusDistance: focusResult.focusDistance,
    actualFocusDistance: trackSettings.value.focusDistance,
  })

  appliedControls.value = {
    requestedFocusMode: profile.constraintFocusMode ?? 'default',
    actualFocusMode: trackSettings.value.focusMode,
    requestedFocusDistance: focusResult.focusDistance != null ? String(focusResult.focusDistance) : '—',
    actualFocusDistance: trackSettings.value.focusDistance,
    requestedZoom: `${activeZoom.value}`,
    actualZoom: trackSettings.value.zoom,
    focusStatus: evaluation.status,
    focusStatusMessage: evaluation.message,
    validManualTest: evaluation.validManualTest,
    capabilityMismatchWarning: capabilityMismatchWarning.value,
  }

  await waitForSettling(cameraSessionId)
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
  const key = buildConfigKey(activeFocusProfileId.value, activeZoom.value)

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
      requestedFocus: activeFocusProfile.value.requestedFocusLabel,
      requestedDistance: appliedControls.value?.requestedFocusDistance ?? '—',
      focusStatus: appliedControls.value?.focusStatus ?? 'UNKNOWN',
      validManualTest: appliedControls.value?.validManualTest ?? true,
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
      lastDetectionMs: durationMs,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations.value),
      minDetectionMs: Math.min(...completedDetectionDurations.value),
      maxDetectionMs: Math.max(...completedDetectionDurations.value),
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
      configuration: buildConfigurationLabel(activeFocusProfile.value, activeZoom.value),
      focusMode: trackSettings.value.focusMode,
      focusDistance: trackSettings.value.focusDistance,
      zoom: trackSettings.value.zoom,
      sharpness,
      resultType,
      rawValue,
      expectedValue: expectedBarcode.value,
      durationMs,
    }

    eventHistory.value = [entry, ...eventHistory.value].slice(0, 50)
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

async function stopCamera(): Promise<void> {
  stopDetectionLoop()
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
    aggregates.value = createInitialAggregates(focusProfiles.value, trackCapabilities.value)
    await applyConfiguration()

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

function selectCodeMode(mode: 'small' | 'standard'): void {
  codeMode.value = mode
  expectedBarcode.value = mode === 'standard' ? STANDARD_EAN_VALUE : DEFAULT_SMALL_EAN_VALUE
  resetSessionStats()
}

function selectFocusProfile(profileId: FocusProfileId): void {
  activeFocusProfileId.value = profileId
}

function selectZoom(zoom: number): void {
  activeZoom.value = zoom
}

function resetStatistics(): void {
  stopDetectionLoop()
  resetSessionStats()
  eventHistory.value = []
  constraintLog.value = []
  lastIncorrect.value = null
  aggregates.value = createInitialAggregates(focusProfiles.value, trackCapabilities.value)
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

async function copyDiagnostic(): Promise<void> {
  const text = buildManualFocusDiagnosticClipboard({
    environment: environment.value,
    supportedFormats: supportedFormats.value,
    detectorCreationNote: detectorCreationNote.value,
    trackSettings: trackSettings.value,
    capabilities: trackCapabilities.value,
    appliedControls: appliedControls.value,
    expectedBarcode: expectedBarcode.value,
    testDurationSeconds: testDurationSeconds.value,
    stats: stats.value,
    aggregates: aggregates.value,
    constraintLog: constraintLog.value,
    history: eventHistory.value,
    capabilityMismatchWarning: capabilityMismatchWarning.value,
    bestConfiguration: bestConfiguration.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  aggregates.value = createInitialAggregates(focusProfiles.value, trackCapabilities.value)
  window.addEventListener('pagehide', () => { void stopCamera() })
})

onBeforeUnmount(() => {
  void stopCamera()
})
</script>

<template>
  <Head title="Test Focus manuel BarcodeDetector" />

  <div class="barcode-reader-test-page barcode-detector-manual-focus-test">
    <div class="barcode-reader-test-page__container barcode-detector-manual-focus-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Test Focus manuel réel</h1>
          <p class="barcode-reader-test-page__subtitle">Vérification REQUESTED vs ACTUAL — focusDistance + zoom matériel + BarcodeDetector</p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
      </header>

      <section v-if="appliedControls" class="barcode-detector-manual-focus-test__focus-status" :class="`barcode-detector-manual-focus-test__focus-status--${appliedControls.focusStatus.toLowerCase().replace(' ', '-')}`">
        <h2>FOCUS STATUS — {{ appliedControls.focusStatus }}</h2>
        <p>Requested: {{ appliedControls.requestedFocusMode }} — Actual: {{ appliedControls.actualFocusMode }}</p>
        <p>{{ appliedControls.focusStatusMessage }}</p>
        <p v-if="!appliedControls.validManualTest" class="barcode-detector-manual-focus-test__invalid-manual">NOT A VALID MANUAL TEST</p>
      </section>

      <section v-if="capabilityMismatchWarning" class="barcode-detector-manual-focus-test__warning-box">
        <pre class="barcode-detector-manual-focus-test__pre mb-0">{{ capabilityMismatchWarning }}</pre>
      </section>

      <section class="barcode-detector-manual-focus-test__section barcode-detector-manual-focus-test__section--video">
        <div class="barcode-detector-manual-focus-test__video-banner">
          FOCUS ACTUEL : {{ trackSettings.focusMode }} — DISTANCE : {{ trackSettings.focusDistance }} — ZOOM : {{ trackSettings.zoom }}×
        </div>
        <div class="barcode-detector-manual-focus-test__video-wrap">
          <video ref="videoRef" class="barcode-detector-manual-focus-test__video" autoplay muted playsinline />
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-detector-manual-focus-test__hidden-canvas" aria-hidden="true" />

        <div class="barcode-detector-manual-focus-test__actions">
          <button type="button" class="btn btn-primary btn-lg" :disabled="cameraState === 'starting'" @click="startCamera">Démarrer caméra</button>
          <button type="button" class="btn btn-outline-danger btn-lg" :disabled="cameraState === 'idle'" @click="stopCamera">Arrêter caméra</button>
          <button type="button" class="btn btn-warning btn-lg" :disabled="cameraState !== 'active'" @click="applyConfiguration">Appliquer</button>
          <button type="button" class="btn btn-success btn-lg" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="startDetectionLoop">Démarrer détection</button>
          <button type="button" class="btn btn-outline-secondary btn-lg" :disabled="detectionLoopState !== 'running'" @click="stopDetectionLoop">Arrêter détection</button>
          <button type="button" class="btn btn-outline-secondary" @click="resetStatistics">Réinitialiser statistiques</button>
          <button type="button" class="btn btn-outline-secondary" :disabled="cameraState !== 'active'" @click="captureFrame">Capturer frame</button>
          <button type="button" class="btn btn-outline-secondary" @click="copyDiagnostic">Copier diagnostic</button>
        </div>

        <p class="barcode-detector-manual-focus-test__muted mb-0 mt-2">
          Camera settling: {{ settlingState === 'waiting' ? `${settlingElapsedMs} ms` : 'idle' }}
          <span v-if="detectionLoopState === 'running'"> — Temps restant: {{ Math.ceil(detectionRemainingMs / 1000) }} s</span>
        </p>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Capabilities</h2>
        <dl class="barcode-detector-manual-focus-test__grid">
          <div class="barcode-detector-manual-focus-test__grid-full"><dt>Focus modes</dt><dd>{{ trackCapabilities.focusModes.join(', ') || 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Focus distance min</dt><dd>{{ trackCapabilities.focusDistance.min ?? 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Focus distance max</dt><dd>{{ trackCapabilities.focusDistance.max ?? 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Focus distance step</dt><dd>{{ trackCapabilities.focusDistance.step ?? 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Zoom min</dt><dd>{{ trackCapabilities.zoom.min ?? 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Zoom max</dt><dd>{{ trackCapabilities.zoom.max ?? 'NON DISPONIBLE' }}</dd></div>
          <div><dt>Zoom step</dt><dd>{{ trackCapabilities.zoom.step ?? 'NON DISPONIBLE' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Code attendu</h2>
        <div class="barcode-detector-manual-focus-test__actions mb-2">
          <button type="button" class="btn btn-sm" :class="codeMode === 'small' ? 'btn-primary' : 'btn-outline-primary'" @click="selectCodeMode('small')">Code petit (6043000070493)</button>
          <button type="button" class="btn btn-sm" :class="codeMode === 'standard' ? 'btn-primary' : 'btn-outline-primary'" @click="selectCodeMode('standard')">Code standard (6202312030117)</button>
        </div>
        <label class="form-label">Expected barcode</label>
        <input v-model="expectedBarcode" type="text" class="form-control font-monospace mb-0">
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Focus</h2>
        <div class="barcode-detector-manual-focus-test__actions">
          <button
            v-for="profile in focusProfiles"
            :key="profile.id"
            type="button"
            class="btn btn-sm"
            :class="activeFocusProfileId === profile.id ? 'btn-warning' : 'btn-outline-warning'"
            :disabled="!isFocusProfileSelectable(profile, trackCapabilities)"
            :title="profile.unavailableReason ?? ''"
            @click="selectFocusProfile(profile.id)"
          >
            {{ profile.label }}
          </button>
        </div>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Zoom</h2>
        <div class="barcode-detector-manual-focus-test__actions">
          <button
            v-for="zoom in availableZoomLevels"
            :key="zoom"
            type="button"
            class="btn btn-sm"
            :class="activeZoom === zoom ? 'btn-info' : 'btn-outline-info'"
            @click="selectZoom(zoom)"
          >
            {{ zoom }}×
          </button>
        </div>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Durée test</h2>
        <div class="barcode-detector-manual-focus-test__actions">
          <button
            v-for="duration in TEST_DURATION_OPTIONS"
            :key="duration"
            type="button"
            class="btn btn-sm"
            :class="testDurationSeconds === duration ? 'btn-secondary' : 'btn-outline-secondary'"
            @click="testDurationSeconds = duration"
          >
            {{ duration }} s
          </button>
        </div>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Statistiques</h2>
        <dl class="barcode-detector-manual-focus-test__grid">
          <div><dt>Attempts</dt><dd>{{ stats.attempts }}</dd></div>
          <div><dt>Correct</dt><dd>{{ stats.correct }}</dd></div>
          <div><dt>Incorrect</dt><dd>{{ stats.incorrect }}</dd></div>
          <div><dt>Not found</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Correct rate</dt><dd>{{ computeRate(stats.correct, stats.attempts) }}</dd></div>
          <div><dt>Incorrect rate</dt><dd>{{ computeRate(stats.incorrect, stats.attempts) }}</dd></div>
          <div><dt>Not found rate</dt><dd>{{ computeRate(stats.notFound, stats.attempts) }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Indice de netteté empirique</h2>
        <dl class="barcode-detector-manual-focus-test__grid">
          <div><dt>Sharpness score</dt><dd>{{ stats.averageSharpness ?? '—' }}</dd></div>
          <div><dt>Min</dt><dd>{{ stats.minSharpness ?? '—' }}</dd></div>
          <div><dt>Max</dt><dd>{{ stats.maxSharpness ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">SHARPNESS VS DETECTION</h2>
        <dl class="barcode-detector-manual-focus-test__grid">
          <div><dt>Correct avg sharpness</dt><dd>{{ stats.correctAverageSharpness ?? '—' }}</dd></div>
          <div><dt>Incorrect avg sharpness</dt><dd>{{ stats.incorrectAverageSharpness ?? '—' }}</dd></div>
          <div><dt>Not found avg sharpness</dt><dd>{{ stats.notFoundAverageSharpness ?? '—' }}</dd></div>
        </dl>
        <p class="barcode-detector-manual-focus-test__muted mb-0">Indice empirique uniquement. Corrélation ≠ causalité.</p>
      </section>

      <section v-if="lastIncorrect" class="barcode-detector-manual-focus-test__alert">
        <h2 class="barcode-detector-manual-focus-test__section-title">⚠️ Lecture incorrecte</h2>
        <p class="mb-1"><strong>Expected:</strong> <span class="font-monospace">{{ lastIncorrect.expected }}</span></p>
        <p class="mb-0"><strong>Detected:</strong> <span class="font-monospace">{{ lastIncorrect.detected }}</span></p>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Meilleure configuration</h2>
        <p class="mb-1"><strong>{{ bestConfiguration.label }}</strong></p>
        <p class="barcode-detector-manual-focus-test__muted mb-0">{{ bestConfiguration.reason }}</p>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Tableau comparatif</h2>
        <div class="barcode-detector-manual-focus-test__table-wrap">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Configuration</th>
                <th>Focus demandé</th>
                <th>Focus réel</th>
                <th>Distance</th>
                <th>Zoom</th>
                <th class="text-end">Correct</th>
                <th class="text-end">Incorrect</th>
                <th class="text-end">Not found</th>
                <th class="text-end">Sharpness</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in comparisonRows" :key="row.configuration">
                <td>{{ row.configuration }}</td>
                <td>{{ row.requestedFocus }}</td>
                <td>{{ row.actualFocus }}</td>
                <td>{{ row.distance }}</td>
                <td>{{ row.zoom }}</td>
                <td class="text-end">{{ row.correct }}</td>
                <td class="text-end">{{ row.incorrect }}</td>
                <td class="text-end">{{ row.notFound }}</td>
                <td class="text-end">{{ row.sharpness }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="eventHistory.length > 0" class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Historique (50 derniers)</h2>
        <div class="barcode-detector-manual-focus-test__history">
          <div v-for="entry in eventHistory" :key="entry.id" class="font-monospace barcode-detector-manual-focus-test__history-item">
            {{ entry.timestamp }} — {{ entry.configuration }} — focusDistance: {{ entry.focusDistance }} — zoom: {{ entry.zoom }} — sharpness: {{ entry.sharpness ?? '—' }} — {{ entry.resultType }} — {{ entry.rawValue || '—' }} — {{ formatDurationMs(entry.durationMs) }}
          </div>
        </div>
      </section>

      <section v-if="capturedFrameUrl" class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Original frame</h2>
        <p>Sharpness: {{ capturedSharpness ?? '—' }}</p>
        <img :src="capturedFrameUrl" alt="Original frame" class="barcode-detector-manual-focus-test__preview-image">
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Protocole recommandé</h2>
        <pre class="barcode-detector-manual-focus-test__pre mb-0">Phase A — DEFAULT + 1×/2×/3×/4×/6×/8× (20–30 s)
Phase B — MANUAL 25%/50%/75%/100% + zoom (vérifier Actual focus: manual AVANT détection)
Phase C — 6202312030117 avec DEFAULT + MANUAL 25/50/75/100 + 3×</pre>
      </section>

      <section class="barcode-detector-manual-focus-test__section">
        <h2 class="barcode-detector-manual-focus-test__section-title">Conclusion</h2>
        <pre class="barcode-detector-manual-focus-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-detector-manual-focus-test__muted">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-detector-manual-focus-test__warning">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
