<script setup lang="ts">
import {
  applyCameraConfiguration,
  buildComparisonConfigurations,
  buildComparisonConclusion,
  buildComparisonDiagnosticClipboard,
  buildZoomEffectRows,
  clampFocusDistance,
  clampZoomValue,
  classifyReadResult,
  createComparisonBarcodeDetector,
  createEmptyConfigurationResult,
  DEFAULT_DURATION_SECONDS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_FOCUS_DISTANCE,
  DETECTION_INTERVAL_MS,
  DURATION_OPTIONS,
  extractBarcodeGeometry,
  finalizeConfigurationResult,
  findBestObservedZoom,
  FIXED_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  normalizeDetections,
  pickBestNativeBarcode,
  randomizeConfigurationOrder,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  resolveAvailableZoomLevels,
  buildObservation,
  type AppliedConfigurationSnapshot,
  type BarcodeDetectorLike,
  type BarcodeTarget,
  type ComparisonConfiguration,
  type ConfigurationResult,
  type DurationSeconds,
  type RawDetectionEntry,
} from '@/utils/barcodeSizeZoomComparison'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type RunUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED'

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const runState = ref<RunUiState>('IDLE')
const cameraError = ref<{ name: string; message: string } | null>(null)
const copyMessage = ref<string | null>(null)
const runMessage = ref<string | null>(null)

const barcodeAExpected = ref(DEFAULT_EXPECTED_BARCODE)
const barcodeBExpected = ref(DEFAULT_EXPECTED_BARCODE)
const selectedBarcode = ref<BarcodeTarget>('A')
const focusDistance = ref(DEFAULT_FOCUS_DISTANCE)
const durationSeconds = ref<DurationSeconds>(DEFAULT_DURATION_SECONDS)

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<ComparisonConfiguration[]>([])
const results = ref<ConfigurationResult[]>([])
const rawDetections = ref<RawDetectionEntry[]>([])
const lastAppliedFocus = ref<AppliedConfigurationSnapshot | null>(null)

const currentConfigLabel = ref<string | null>(null)
const remainingSeconds = ref(0)
const currentBarcodeSize = ref<{ width: number | null; height: number | null; ratio: number | null }>({
  width: null,
  height: null,
  ratio: null,
})

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let diagnosticsTimer: number | null = null

const zoomLevels = computed(() => resolveAvailableZoomLevels(capabilities.value.zoom))
const expectedBarcode = computed(() => selectedBarcode.value === 'A' ? barcodeAExpected.value : barcodeBExpected.value)
const configCount = computed(() => configurations.value.length)

const bestZoomA = computed(() => findBestObservedZoom(results.value, 'A'))
const bestZoomB = computed(() => findBestObservedZoom(results.value, 'B'))
const zoomEffectA = computed(() => buildZoomEffectRows(results.value, 'A'))
const zoomEffectB = computed(() => buildZoomEffectRows(results.value, 'B'))
const observation = computed(() => buildObservation(results.value))
const conclusion = computed(() => buildComparisonConclusion({
  bestZoomA: bestZoomA.value,
  bestZoomB: bestZoomB.value,
  results: results.value,
}))

const canRun = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && runState.value !== 'RUNNING',
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
}

function rebuildConfigurations(): void {
  configurations.value = buildComparisonConfigurations({
    barcodeAExpected: barcodeAExpected.value,
    barcodeBExpected: barcodeBExpected.value,
    zoomLevels: zoomLevels.value,
    order: configurations.value,
  })

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyConfigurationResult(config, focusDistance.value)
  })
}

function refreshDiagnostics(): void {
  const track = getVideoTrack()
  capabilities.value = readTrackCapabilitiesSnapshot(track)
  trackSettings.value = readTrackSettingsSnapshot(track)

  const clamped = clampFocusDistance(focusDistance.value, capabilities.value.focusDistance)

  if (clamped != null) {
    focusDistance.value = clamped
  }

  rebuildConfigurations()
}

function randomizeOrder(): void {
  configurations.value = randomizeConfigurationOrder(configurations.value)
}

async function ensureDetector(): Promise<boolean> {
  if (detectorRef.value) {
    return true
  }

  if (!isNativeBarcodeDetectorAvailable()) {
    return false
  }

  try {
    detectorRef.value = (await createComparisonBarcodeDetector()).detector
    return true
  } catch {
    return false
  }
}

function isCameraSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
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

async function startCamera(): Promise<void> {
  await stopCamera()

  if (!(await ensureDetector())) {
    cameraState.value = 'ERROR'
    cameraError.value = { name: 'BarcodeDetectorUnavailable', message: 'BarcodeDetector non disponible.' }
    return
  }

  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'STARTING'

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
    cameraState.value = 'READY'
    diagnosticsTimer = window.setInterval(refreshDiagnostics, 500)
  } catch (error) {
    cameraError.value = {
      name: error instanceof Error ? error.name : 'Error',
      message: error instanceof Error ? error.message : String(error),
    }
    cameraState.value = 'ERROR'
    stopTracks(activeStream.value)
    activeStream.value = null
  }
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
  refreshDiagnostics()
  cameraState.value = 'IDLE'
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1

  if (runState.value === 'RUNNING') {
    runState.value = 'STOPPED'
    runMessage.value = 'Comparison stopped by user.'
  }
}

async function runConfiguration(
  config: ComparisonConfiguration,
  sessionId: number,
): Promise<ConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyConfigurationResult(config, focusDistance.value)
  }

  currentConfigLabel.value = config.label
  selectedBarcode.value = config.barcodeTarget

  const applied = await applyCameraConfiguration(track, {
    requestedFocusDistance: focusDistance.value,
    requestedZoom: clampZoomValue(config.requestedZoom, capabilities.value.zoom),
    focusDistanceStep: capabilities.value.focusDistance.step,
    zoomStep: capabilities.value.zoom.step,
  })

  lastAppliedFocus.value = applied

  if (applied.configurationStatus !== 'VALID') {
    return finalizeConfigurationResult(config, focusDistance.value, applied, {
      framesAnalyzed: 0,
      detections: 0,
      correct: 0,
      incorrect: 0,
      notFound: 0,
      correctLatencies: [],
      timeToFirstCorrectMs: null,
      sharpnessValues: [],
      barcodeWidths: [],
      barcodeHeights: [],
      widthRatios: [],
    })
  }

  let framesAnalyzed = 0
  let detections = 0
  let correct = 0
  let incorrect = 0
  let notFound = 0
  const sharpnessValues: number[] = []
  const correctLatencies: number[] = []
  const barcodeWidths: number[] = []
  const barcodeHeights: number[] = []
  const widthRatios: number[] = []
  let timeToFirstCorrectMs: number | null = null
  let detectionInProgress = false
  let lastDetectionTime = 0

  const startedAt = performance.now()
  const endAt = startedAt + durationSeconds.value * 1000
  remainingSeconds.value = durationSeconds.value

  const countdownTimer = window.setInterval(() => {
    remainingSeconds.value = Math.max(0, Math.ceil((endAt - performance.now()) / 1000))
  }, 200)

  await new Promise<void>((resolve) => {
    const tick = (timestamp: number): void => {
      if (runAbort || sessionId !== runSessionId || performance.now() >= endAt) {
        window.clearInterval(countdownTimer)
        resolve()
        return
      }

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp

        void (async () => {
          const attemptStartedAt = performance.now()
          const sharpness = measureVideoSharpness(video, canvas)

          if (sharpness != null) {
            sharpnessValues.push(sharpness)
          }

          try {
            framesAnalyzed += 1
            const rawResults = normalizeDetections(await detector.detect(video))
            const best = pickBestNativeBarcode(rawResults)
            const rawValue = best?.rawValue ?? ''
            const resultType = classifyReadResult(rawValue, config.expectedBarcode)
            const geometry = extractBarcodeGeometry(best, video.videoWidth)

            if (rawValue) {
              detections += 1
              rawDetections.value = [{
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                timestamp: new Date().toLocaleTimeString('fr-FR'),
                barcodeTarget: config.barcodeTarget,
                zoom: applied.actualZoom,
                format: best?.format ?? '—',
                rawValue,
                classification: resultType,
                boundingBoxWidth: geometry.width,
                boundingBoxHeight: geometry.height,
                widthRatio: geometry.widthRatio,
              }, ...rawDetections.value].slice(0, 10)

              currentBarcodeSize.value = {
                width: geometry.width,
                height: geometry.height,
                ratio: geometry.widthRatio,
              }
            }

            if (resultType === 'CORRECT') {
              correct += 1
              correctLatencies.push(performance.now() - attemptStartedAt)

              if (timeToFirstCorrectMs == null) {
                timeToFirstCorrectMs = Math.round(performance.now() - startedAt)
              }

              if (geometry.width != null) {
                barcodeWidths.push(geometry.width)
              }

              if (geometry.height != null) {
                barcodeHeights.push(geometry.height)
              }

              if (geometry.widthRatio != null) {
                widthRatios.push(geometry.widthRatio)
              }
            } else if (resultType === 'INCORRECT') {
              incorrect += 1
            } else {
              notFound += 1
            }
          } catch {
            notFound += 1
          } finally {
            detectionInProgress = false
          }
        })()
      }

      requestAnimationFrame(tick)
    }

    requestAnimationFrame(tick)
  })

  refreshDiagnostics()

  return finalizeConfigurationResult(config, focusDistance.value, applied, {
    framesAnalyzed,
    detections,
    correct,
    incorrect,
    notFound,
    correctLatencies,
    timeToFirstCorrectMs,
    sharpnessValues,
    barcodeWidths,
    barcodeHeights,
    widthRatios,
  })
}

async function runComparison(configs: ComparisonConfiguration[]): Promise<void> {
  if (!canRun.value) {
    return
  }

  stopRun()
  runSessionId += 1
  const sessionId = runSessionId
  runAbort = false
  runState.value = 'RUNNING'
  runMessage.value = null

  for (let index = 0; index < configs.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = configs[index]!
    runMessage.value = `Configuration ${index + 1}/${configs.length} — ${config.label} — place Barcode ${config.barcodeTarget} in front of camera`

    const result = await runConfiguration(config, sessionId)

    results.value = results.value.map((item) => item.configId === config.id ? result : item)
  }

  currentConfigLabel.value = null
  remainingSeconds.value = 0

  if (runAbort) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
  runMessage.value = `Comparison completed — ${configs.length} configurations tested.`
}

async function runComparisonWithConfirmation(): Promise<void> {
  const minutes = Math.ceil((configCount.value * durationSeconds.value) / 60)
  const confirmed = window.confirm(
    `Run ${configCount.value} configurations × ${durationSeconds.value}s ≈ ${minutes} min?\n\nKeep each barcode immobile during its tests.`,
  )

  if (!confirmed) {
    return
  }

  await runComparison([...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex))
}

async function runQuickTest(): Promise<void> {
  const config = configurations.value.find(
    (item) => item.barcodeTarget === selectedBarcode.value && item.requestedZoom === 1,
  ) ?? {
    id: `quick-${selectedBarcode.value}-1`,
    label: `Quick Barcode ${selectedBarcode.value} × 1×`,
    barcodeTarget: selectedBarcode.value,
    expectedBarcode: expectedBarcode.value,
    requestedZoom: 1,
    orderIndex: 0,
  }

  runSessionId += 1
  runAbort = false
  runState.value = 'RUNNING'
  runMessage.value = 'Quick test running...'

  const result = await runConfiguration(config, runSessionId)

  results.value = results.value.map((item) =>
    item.configId === config.id ? result : item,
  ).concat(
    results.value.some((item) => item.configId === config.id) ? [] : [result],
  )

  runState.value = 'COMPLETED'
  runMessage.value = 'Quick test completed.'
  currentConfigLabel.value = null
}

async function copyDiagnostic(): Promise<void> {
  const text = buildComparisonDiagnosticClipboard({
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    barcodeAExpected: barcodeAExpected.value,
    barcodeBExpected: barcodeBExpected.value,
    focusDistance: focusDistance.value,
    appliedFocus: lastAppliedFocus.value,
    durationSeconds: durationSeconds.value,
    results: results.value,
    rawDetections: rawDetections.value,
    bestZoomA: bestZoomA.value,
    bestZoomB: bestZoomB.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function onFocusSliderInput(event: Event): void {
  const value = Number.parseFloat((event.target as HTMLInputElement).value)
  const clamped = clampFocusDistance(value, capabilities.value.focusDistance)

  if (clamped != null) {
    focusDistance.value = clamped
  }
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
  <Head title="Barcode Size × Zoom Comparison" />

  <div class="barcode-reader-test-page barcode-size-zoom-comparison">
    <div class="barcode-reader-test-page__container barcode-size-zoom-comparison__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Size × Zoom Comparison</h1>
          <p class="barcode-reader-test-page__subtitle">Compare Barcode A vs B — focus fixe, zoom 1×/2×/3×/4× (DEV isolé)</p>
        </div>
        <div class="barcode-size-zoom-comparison__header-links">
          <Link href="/dev/barcode-detector-focus-zoom-benchmark" class="btn btn-sm btn-outline-secondary">Focus × Zoom Benchmark</Link>
          <Link href="/dev/barcode-detector-distance-focus" class="btn btn-sm btn-outline-secondary">Distance × Focus</Link>
          <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-size-zoom-comparison__banner">
        <p class="mb-0">BarcodeDetector: <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong></p>
      </section>

      <section class="barcode-size-zoom-comparison__section barcode-size-zoom-comparison__section--video">
        <div class="barcode-size-zoom-comparison__video-wrap">
          <video ref="videoRef" class="barcode-size-zoom-comparison__video" autoplay muted playsinline />
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-size-zoom-comparison__hidden-canvas" aria-hidden="true" />
        <div class="barcode-size-zoom-comparison__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCamera">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-warning btn-sm" :disabled="!canRun" @click="runQuickTest">Quick Test</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canRun" @click="runComparisonWithConfirmation">Run Comparison</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyDiagnostic">Copy Diagnostic</button>
        </div>
        <p v-if="runMessage" class="barcode-size-zoom-comparison__muted mb-0 mt-2">{{ runMessage }}</p>
        <p v-if="currentConfigLabel" class="barcode-size-zoom-comparison__muted mb-0">Current: {{ currentConfigLabel }} — Remaining: {{ remainingSeconds }} s</p>
      </section>

      <section class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">TEST CONDITIONS</h2>
        <pre class="barcode-size-zoom-comparison__pre mb-3">Keep the barcode immobile during each configuration.
Same orientation, distance, lighting, phone, camera, browser.</pre>

        <div class="barcode-size-zoom-comparison__form-row">
          <label>Test barcode</label>
          <div class="barcode-size-zoom-comparison__actions">
            <button type="button" class="btn btn-sm" :class="selectedBarcode === 'A' ? 'btn-primary' : 'btn-outline-secondary'" @click="selectedBarcode = 'A'">Barcode A — easier</button>
            <button type="button" class="btn btn-sm" :class="selectedBarcode === 'B' ? 'btn-primary' : 'btn-outline-secondary'" @click="selectedBarcode = 'B'">Barcode B — smaller / difficult</button>
          </div>
        </div>

        <div class="barcode-size-zoom-comparison__grid-form">
          <div>
            <label>Barcode A expected value</label>
            <input v-model="barcodeAExpected" type="text" class="form-control form-control-sm font-monospace">
          </div>
          <div>
            <label>Barcode B expected value</label>
            <input v-model="barcodeBExpected" type="text" class="form-control form-control-sm font-monospace">
          </div>
        </div>

        <p class="mb-2">Expected barcode (selected): <strong class="font-monospace">{{ expectedBarcode }}</strong></p>

        <div class="barcode-size-zoom-comparison__form-row">
          <label>Focus distance: {{ focusDistance }}</label>
          <input
            v-if="capabilities.focusDistance.min != null && capabilities.focusDistance.max != null"
            type="range"
            :min="capabilities.focusDistance.min"
            :max="capabilities.focusDistance.max"
            :step="capabilities.focusDistance.step ?? 0.01"
            :value="focusDistance"
            :disabled="runState === 'RUNNING'"
            @input="onFocusSliderInput"
          >
        </div>

        <div class="barcode-size-zoom-comparison__form-row">
          <label>Duration</label>
          <div class="barcode-size-zoom-comparison__actions">
            <button
              v-for="duration in DURATION_OPTIONS"
              :key="duration"
              type="button"
              class="btn btn-sm"
              :class="durationSeconds === duration ? 'btn-primary' : 'btn-outline-secondary'"
              :disabled="runState === 'RUNNING'"
              @click="durationSeconds = duration"
            >
              {{ duration }} s
            </button>
          </div>
        </div>
      </section>

      <section class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Test order ({{ configCount }} configurations)</h2>
        <div class="barcode-size-zoom-comparison__actions mb-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="runState === 'RUNNING'" @click="randomizeOrder">Randomize order</button>
        </div>
        <p class="font-monospace barcode-size-zoom-comparison__muted mb-0">
          {{ [...configurations].sort((a, b) => a.orderIndex - b.orderIndex).map((item) => `${item.barcodeTarget}${item.requestedZoom}`).join(' → ') }}
        </p>
      </section>

      <section v-if="currentBarcodeSize.width != null" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Barcode apparent size</h2>
        <p class="mb-0">Width: {{ currentBarcodeSize.width }} px — Height: {{ currentBarcodeSize.height ?? '—' }} px — Width ratio: {{ currentBarcodeSize.ratio ?? '—' }}</p>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">RAW DETECTION (last 10)</h2>
        <div v-for="entry in rawDetections" :key="entry.id" class="font-monospace barcode-size-zoom-comparison__raw-item">
          {{ entry.timestamp }} — Barcode {{ entry.barcodeTarget }} — zoom {{ entry.zoom }}× — format: {{ entry.format }} — rawValue: {{ entry.rawValue }} — {{ entry.classification }}
        </div>
      </section>

      <section v-if="results.some((item) => item.framesAnalyzed > 0 || item.applied)" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Results</h2>
        <div class="barcode-size-zoom-comparison__table-wrap">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Barcode</th>
                <th>Zoom</th>
                <th>Focus</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Not Found</th>
                <th>Correct Rate</th>
                <th>Detection Rate</th>
                <th>Sharpness</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in results" :key="row.configId">
                <td>{{ row.barcodeTarget }}</td>
                <td>{{ row.requestedZoom }}×</td>
                <td>{{ row.requestedFocusDistance }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.incorrect }}</td>
                <td>{{ row.notFound }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.detectionRate }}</td>
                <td>{{ row.averageSharpness ?? '—' }}</td>
                <td>{{ row.configurationStatus }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Barcode A</h2>
        <p v-if="bestZoomA" class="mb-0">Best zoom: {{ bestZoomA.zoom }}× — Correct: {{ bestZoomA.correctRate }} — Detection: {{ bestZoomA.detectionRate }}</p>
        <p v-else class="mb-0">NO VALID BEST CONFIGURATION — no correct detection recorded.</p>
      </section>

      <section class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Barcode B</h2>
        <p v-if="bestZoomB" class="mb-0">Best zoom: {{ bestZoomB.zoom }}× — Correct: {{ bestZoomB.correctRate }} — Detection: {{ bestZoomB.detectionRate }}</p>
        <p v-else class="mb-0">NO VALID BEST CONFIGURATION — no correct detection recorded.</p>
      </section>

      <section v-if="zoomEffectA.length > 0" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Zoom effect</h2>
        <h3 class="h6">Barcode A</h3>
        <ul>
          <li v-for="row in zoomEffectA" :key="`a-${row.zoom}`">{{ row.zoom }}× → correct {{ row.correctRate }} / detection {{ row.detectionRate }}</li>
        </ul>
        <h3 class="h6">Barcode B</h3>
        <ul>
          <li v-for="row in zoomEffectB" :key="`b-${row.zoom}`">{{ row.zoom }}× → correct {{ row.correctRate }} / detection {{ row.detectionRate }}</li>
        </ul>
      </section>

      <section v-if="zoomEffectA.length > 0" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Graphs</h2>
        <div class="barcode-size-zoom-comparison__graphs">
          <div>
            <h3 class="h6">Zoom → Correct rate</h3>
            <svg viewBox="0 0 320 180" class="barcode-size-zoom-comparison__graph">
              <polyline :points="zoomEffectA.map((row, i) => `${40 + i * 60},${150 - Math.min(130, Number.parseFloat(row.correctRate) || 0)}`).join(' ')" fill="none" stroke="#0d6efd" stroke-width="2" />
              <polyline :points="zoomEffectB.map((row, i) => `${40 + i * 60},${150 - Math.min(130, Number.parseFloat(row.correctRate) || 0)}`).join(' ')" fill="none" stroke="#dc3545" stroke-width="2" />
            </svg>
          </div>
        </div>
      </section>

      <section v-if="observation" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Observation</h2>
        <pre class="barcode-size-zoom-comparison__pre mb-0">{{ observation }}</pre>
      </section>

      <section v-if="conclusion" class="barcode-size-zoom-comparison__section">
        <h2 class="barcode-size-zoom-comparison__section-title">Conclusion</h2>
        <pre class="barcode-size-zoom-comparison__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-size-zoom-comparison__muted">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-size-zoom-comparison__warning">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
