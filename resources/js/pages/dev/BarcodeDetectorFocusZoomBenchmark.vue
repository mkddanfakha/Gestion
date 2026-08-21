<script setup lang="ts">
import {
  applyCameraConfiguration,
  buildBenchmarkConfigurations,
  buildBenchmarkConclusion,
  buildBenchmarkDiagnosticClipboard,
  buildFocusComparison,
  buildFocusDistanceValues,
  buildHeatmapCells,
  buildZoomComparison,
  classifyReadResult,
  clampZoomValue,
  createBenchmarkBarcodeDetector,
  createEmptyConfigurationResult,
  DEFAULT_DURATION_SECONDS,
  DEFAULT_EXPECTED_BARCODE,
  DETECTION_INTERVAL_MS,
  DURATION_OPTIONS,
  finalizeConfigurationResult,
  FIXED_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  pickBestNativeBarcode,
  rankConfigurationResults,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  resolveZoomLevels,
  roundToStep,
  findBestFocus,
  findBestZoom,
  type AttemptHistoryEntry,
  type BarcodeDetectorLike,
  type BenchmarkConfiguration,
  type BenchmarkPreset,
  type ConfigurationResult,
  type DurationSeconds,
  type TrackCapabilitiesSnapshot,
} from '@/utils/barcodeDetectorFocusZoomBenchmark'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type BenchmarkUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED' | 'ERROR'

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const benchmarkState = ref<BenchmarkUiState>('IDLE')
const cameraError = ref<{ name: string; message: string } | null>(null)
const copyMessage = ref<string | null>(null)
const benchmarkMessage = ref<string | null>(null)

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const durationSeconds = ref<DurationSeconds>(DEFAULT_DURATION_SECONDS)
const preset = ref<BenchmarkPreset>('FULL')
const customFocusDistanceInput = ref('0.39')

const capabilities = ref<TrackCapabilitiesSnapshot>(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<BenchmarkConfiguration[]>([])
const results = ref<ConfigurationResult[]>([])
const history = ref<AttemptHistoryEntry[]>([])
const currentConfigLabel = ref<string | null>(null)
const remainingSeconds = ref(0)

let cameraSessionId = 0
let benchmarkSessionId = 0
let benchmarkAbort = false
let diagnosticsTimer: number | null = null

const supportsManual = computed(() => capabilities.value.focusModes.includes('manual'))
const supportsFocusDistance = computed(() => capabilities.value.focusDistance.supported)
const supportsZoom = computed(() => capabilities.value.zoom.supported)
const benchmarkSupported = computed(() =>
  supportsManual.value
  && supportsFocusDistance.value
  && isNativeBarcodeDetectorAvailable(),
)

const enabledConfigurations = computed(() => configurations.value.filter((item) => item.enabled))
const enabledConfigCount = computed(() => enabledConfigurations.value.length)
const estimatedMinutes = computed(() =>
  Math.ceil((enabledConfigCount.value * durationSeconds.value) / 60),
)

const focusValues = computed(() => buildFocusDistanceValues(capabilities.value.focusDistance))
const zoomValues = computed(() => resolveZoomLevels(capabilities.value.zoom))

const rankedResults = computed(() => rankConfigurationResults(results.value))
const bestConfiguration = computed(() => rankedResults.value[0] ?? null)
const zoomComparison = computed(() => buildZoomComparison(results.value))
const focusComparison = computed(() => buildFocusComparison(results.value))
const bestZoom = computed(() => findBestZoom(results.value))
const bestFocus = computed(() => findBestFocus(results.value))
const heatmapCells = computed(() => buildHeatmapCells(results.value, focusValues.value, zoomValues.value))
const conclusion = computed(() => buildBenchmarkConclusion(bestConfiguration.value, results.value))

const canRunBenchmark = computed(() =>
  cameraState.value === 'READY'
  && benchmarkSupported.value
  && detectorRef.value != null
  && benchmarkState.value !== 'RUNNING'
  && enabledConfigCount.value > 0,
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

function refreshDiagnostics(): void {
  const track = getVideoTrack()
  capabilities.value = readTrackCapabilitiesSnapshot(track)
  trackSettings.value = readTrackSettingsSnapshot(track)
  rebuildConfigurations()
}

function rebuildConfigurations(): void {
  const built = buildBenchmarkConfigurations({
    capabilities: capabilities.value,
    preset: preset.value,
  })

  configurations.value = built.map((item) => {
    const existing = configurations.value.find((config) => config.id === item.id)
    return existing ? { ...item, enabled: existing.enabled } : item
  })

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyConfigurationResult(config)
  })
}

function heatmapClass(correctRate: string): string {
  if (correctRate === '—') {
    return 'barcode-focus-zoom-benchmark__heatmap-cell--empty'
  }

  const value = Number.parseFloat(correctRate)

  if (value >= 95) {
    return 'barcode-focus-zoom-benchmark__heatmap-cell--high'
  }

  if (value >= 75) {
    return 'barcode-focus-zoom-benchmark__heatmap-cell--mid'
  }

  return 'barcode-focus-zoom-benchmark__heatmap-cell--low'
}

async function ensureDetector(): Promise<boolean> {
  if (detectorRef.value) {
    return true
  }

  if (!isNativeBarcodeDetectorAvailable()) {
    return false
  }

  try {
    const created = await createBenchmarkBarcodeDetector()
    detectorRef.value = created.detector
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
  stopBenchmark()
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

function stopBenchmark(): void {
  benchmarkAbort = true
  benchmarkSessionId += 1

  if (benchmarkState.value === 'RUNNING') {
    benchmarkState.value = 'STOPPED'
    benchmarkMessage.value = 'Benchmark stopped by user.'
  }
}

async function runDetectionForConfiguration(
  config: BenchmarkConfiguration,
  sessionId: number,
): Promise<ConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyConfigurationResult(config)
  }

  currentConfigLabel.value = config.label

  const applied = await applyCameraConfiguration(track, {
    requestedFocusDistance: config.requestedFocusDistance,
    requestedZoom: clampZoomValue(config.requestedZoom, capabilities.value.zoom),
    focusDistanceStep: capabilities.value.focusDistance.step,
    zoomStep: capabilities.value.zoom.step,
  })

  if (applied.configurationStatus !== 'VALID') {
    return finalizeConfigurationResult(config, applied, {
      attempts: 0,
      correct: 0,
      incorrect: 0,
      notFound: 0,
      errors: 0,
      sharpnessValues: [],
      correctSharpnessValues: [],
      notFoundSharpnessValues: [],
      correctLatencies: [],
      timeToFirstCorrectMs: null,
      durationSeconds: durationSeconds.value,
    })
  }

  let attempts = 0
  let correct = 0
  let incorrect = 0
  let notFound = 0
  let errors = 0
  const sharpnessValues: number[] = []
  const correctSharpnessValues: number[] = []
  const notFoundSharpnessValues: number[] = []
  const correctLatencies: number[] = []
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
    const tick = async (timestamp: number): Promise<void> => {
      if (benchmarkAbort || sessionId !== benchmarkSessionId) {
        window.clearInterval(countdownTimer)
        resolve()
        return
      }

      if (performance.now() >= endAt) {
        window.clearInterval(countdownTimer)
        resolve()
        return
      }

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp
        const attemptStartedAt = performance.now()
        const sharpness = measureVideoSharpness(video, canvas)

        if (sharpness != null) {
          sharpnessValues.push(sharpness)
        }

        try {
          attempts += 1
          const detections = await detector.detect(video)
          const latencyMs = performance.now() - attemptStartedAt
          const best = pickBestNativeBarcode(detections)
          const rawValue = best?.rawValue ?? ''
          const resultType = classifyReadResult(rawValue, expectedBarcode.value)

          if (resultType === 'CORRECT') {
            correct += 1
            correctLatencies.push(latencyMs)

            if (timeToFirstCorrectMs == null) {
              timeToFirstCorrectMs = Math.round(performance.now() - startedAt)
            }

            if (sharpness != null) {
              correctSharpnessValues.push(sharpness)
            }
          } else if (resultType === 'INCORRECT') {
            incorrect += 1
          } else {
            notFound += 1

            if (sharpness != null) {
              notFoundSharpnessValues.push(sharpness)
            }
          }

          history.value = [{
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
            timestamp: new Date().toLocaleTimeString('fr-FR'),
            focusDistance: applied.actualFocusDistance,
            zoom: applied.actualZoom,
            sharpness,
            result: resultType,
            decodedValue: rawValue,
            latencyMs: Math.round(latencyMs),
          }, ...history.value].slice(0, 200)
        } catch {
          errors += 1
        } finally {
          detectionInProgress = false
        }
      }

      requestAnimationFrame(tick)
    }

    requestAnimationFrame(tick)
  })

  refreshDiagnostics()

  return finalizeConfigurationResult(config, applied, {
    attempts,
    correct,
    incorrect,
    notFound,
    errors,
    sharpnessValues,
    correctSharpnessValues,
    notFoundSharpnessValues,
    correctLatencies,
    timeToFirstCorrectMs,
    durationSeconds: durationSeconds.value,
  })
}

async function runBenchmark(configs: BenchmarkConfiguration[]): Promise<void> {
  if (!canRunBenchmark.value) {
    return
  }

  stopBenchmark()
  benchmarkSessionId += 1
  const sessionId = benchmarkSessionId
  benchmarkAbort = false
  benchmarkState.value = 'RUNNING'
  benchmarkMessage.value = null

  for (let index = 0; index < configs.length; index += 1) {
    if (benchmarkAbort || sessionId !== benchmarkSessionId) {
      break
    }

    const config = configs[index]!
    benchmarkMessage.value = `Configuration ${index + 1}/${configs.length} — ${config.label}`

    const result = await runDetectionForConfiguration(config, sessionId)

    results.value = results.value.map((item) => item.configId === config.id ? result : item)
  }

  currentConfigLabel.value = null
  remainingSeconds.value = 0

  if (benchmarkAbort) {
    benchmarkState.value = 'STOPPED'
    return
  }

  benchmarkState.value = 'COMPLETED'
  benchmarkMessage.value = `Benchmark completed — ${configs.length} configurations tested.`
}

async function runBenchmarkWithConfirmation(): Promise<void> {
  const confirmed = window.confirm(
    `This benchmark may take several minutes.\n\n${enabledConfigCount.value} configurations × ${durationSeconds.value} seconds ≈ ${estimatedMinutes.value} minutes\n\nStart?`,
  )

  if (!confirmed) {
    return
  }

  await runBenchmark(enabledConfigurations.value)
}

async function runQuickTest(): Promise<void> {
  const focus = roundToStep(
    Number.parseFloat(customFocusDistanceInput.value),
    capabilities.value.focusDistance.min ?? 0,
    capabilities.value.focusDistance.max ?? 1,
    capabilities.value.focusDistance.step,
  )

  const config: BenchmarkConfiguration = {
    id: `quick:${focus}:1`,
    label: `Quick ${focus} × 1×`,
    requestedFocusDistance: focus,
    requestedZoom: 1,
    enabled: true,
  }

  benchmarkSessionId += 1
  benchmarkAbort = false
  benchmarkState.value = 'RUNNING'
  benchmarkMessage.value = 'Quick test running...'

  const result = await runDetectionForConfiguration(config, benchmarkSessionId)

  results.value = [
    result,
    ...results.value.filter((item) => item.configId !== config.id),
  ]

  benchmarkState.value = 'COMPLETED'
  benchmarkMessage.value = 'Quick test completed.'
  currentConfigLabel.value = null
}

function clearResults(): void {
  results.value = configurations.value.map(createEmptyConfigurationResult)
  history.value = []
  benchmarkState.value = 'IDLE'
  benchmarkMessage.value = null
}

async function copyDiagnostic(): Promise<void> {
  const text = buildBenchmarkDiagnosticClipboard({
    environment: environment.value,
    capabilities: capabilities.value,
    trackSettings: trackSettings.value,
    expectedBarcode: expectedBarcode.value,
    durationSeconds: durationSeconds.value,
    preset: preset.value,
    totalConfigurations: enabledConfigCount.value,
    results: results.value,
    history: history.value,
    best: bestConfiguration.value,
    bestZoom: bestZoom.value,
    bestFocus: bestFocus.value,
    zoomComparison: zoomComparison.value,
    focusComparison: focusComparison.value,
    heatmap: heatmapCells.value,
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
    stopBenchmark()
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
  <Head title="Focus × Zoom Benchmark" />

  <div class="barcode-reader-test-page barcode-focus-zoom-benchmark">
    <div class="barcode-reader-test-page__container barcode-focus-zoom-benchmark__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Focus Distance × Zoom Benchmark</h1>
          <p class="barcode-reader-test-page__subtitle">Benchmark BarcodeDetector — MANUAL focusDistance × zoom (DEV isolé)</p>
        </div>
        <div class="barcode-focus-zoom-benchmark__header-links">
          <Link href="/dev/barcode-detector-focus-distance-mapping" class="btn btn-sm btn-outline-secondary">Focus Distance Mapping</Link>
          <Link href="/dev/barcode-detector-size-zoom-comparison" class="btn btn-sm btn-outline-secondary">Size × Zoom Comparison</Link>
          <Link href="/dev/barcode-detector-distance-focus" class="btn btn-sm btn-outline-secondary">Distance × Focus</Link>
          <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
          <Link href="/dev/barcode-detector-manual-focus-experiment" class="btn btn-sm btn-outline-secondary">Expérience Focus × Zoom</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-focus-zoom-benchmark__banner">
        <p class="mb-0">BarcodeDetector: <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong></p>
      </section>

      <section class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">États</h2>
        <dl class="barcode-focus-zoom-benchmark__grid">
          <div><dt>CAMERA</dt><dd>{{ cameraState }}</dd></div>
          <div><dt>BENCHMARK</dt><dd>{{ benchmarkState }}</dd></div>
          <div><dt>Configurations</dt><dd>{{ enabledConfigCount }}</dd></div>
          <div><dt>Estimated</dt><dd>≈ {{ estimatedMinutes }} min</dd></div>
        </dl>
      </section>

      <section class="barcode-focus-zoom-benchmark__section barcode-focus-zoom-benchmark__section--video">
        <div class="barcode-focus-zoom-benchmark__video-wrap">
          <video ref="videoRef" class="barcode-focus-zoom-benchmark__video" autoplay muted playsinline />
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-focus-zoom-benchmark__hidden-canvas" aria-hidden="true" />
        <div class="barcode-focus-zoom-benchmark__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCamera">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canRunBenchmark" @click="runBenchmarkWithConfirmation">Run Benchmark</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="benchmarkState !== 'RUNNING'" @click="stopBenchmark">Stop Benchmark</button>
          <button type="button" class="btn btn-warning btn-sm" :disabled="cameraState !== 'READY' || benchmarkState === 'RUNNING'" @click="runQuickTest">Quick Test</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyDiagnostic">Copy Diagnostic</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="clearResults">Clear Results</button>
        </div>
        <p v-if="benchmarkMessage" class="barcode-focus-zoom-benchmark__muted mb-0 mt-2">{{ benchmarkMessage }}</p>
        <p v-if="currentConfigLabel" class="barcode-focus-zoom-benchmark__muted mb-0">Current: {{ currentConfigLabel }} — Remaining: {{ remainingSeconds }} s</p>
      </section>

      <section class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">TEST CONDITIONS</h2>
        <pre class="barcode-focus-zoom-benchmark__pre mb-3">Keep the barcode:
- same physical barcode
- same orientation
- same approximate distance
- same lighting
- same phone
- same camera
- same browser

Do not move the barcode during a configuration.</pre>
        <div class="barcode-focus-zoom-benchmark__form-row">
          <label>Expected barcode</label>
          <input v-model="expectedBarcode" type="text" class="form-control form-control-sm font-monospace">
        </div>
        <div class="barcode-focus-zoom-benchmark__form-row">
          <label>Duration per configuration</label>
          <div class="barcode-focus-zoom-benchmark__actions">
            <button
              v-for="duration in DURATION_OPTIONS"
              :key="duration"
              type="button"
              class="btn btn-sm"
              :class="durationSeconds === duration ? 'btn-primary' : 'btn-outline-secondary'"
              :disabled="benchmarkState === 'RUNNING'"
              @click="durationSeconds = duration"
            >
              {{ duration }} s
            </button>
          </div>
        </div>
        <div class="barcode-focus-zoom-benchmark__form-row">
          <label>Benchmark preset</label>
          <div class="barcode-focus-zoom-benchmark__actions">
            <button type="button" class="btn btn-sm" :class="preset === 'FULL' ? 'btn-primary' : 'btn-outline-secondary'" :disabled="benchmarkState === 'RUNNING'" @click="preset = 'FULL'; rebuildConfigurations()">FULL</button>
            <button type="button" class="btn btn-sm" :class="preset === 'FAST' ? 'btn-primary' : 'btn-outline-secondary'" :disabled="benchmarkState === 'RUNNING'" @click="preset = 'FAST'; rebuildConfigurations()">FAST</button>
          </div>
        </div>
        <div class="barcode-focus-zoom-benchmark__form-row">
          <label>Quick test focusDistance</label>
          <input v-model="customFocusDistanceInput" type="text" class="form-control form-control-sm font-monospace" placeholder="0.39">
        </div>
      </section>

      <section class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Matrix configuration</h2>
        <p class="barcode-focus-zoom-benchmark__muted">{{ enabledConfigCount }} configurations enabled</p>
        <div class="barcode-focus-zoom-benchmark__matrix">
          <label v-for="config in configurations" :key="config.id" class="barcode-focus-zoom-benchmark__matrix-item">
            <input v-model="config.enabled" type="checkbox" :disabled="benchmarkState === 'RUNNING'">
            {{ config.label }}
          </label>
        </div>
      </section>

      <section v-if="bestConfiguration" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">BEST CONFIGURATION</h2>
        <dl class="barcode-focus-zoom-benchmark__grid">
          <div><dt>Focus distance</dt><dd>{{ bestConfiguration.requestedFocusDistance }}</dd></div>
          <div><dt>Zoom</dt><dd>{{ bestConfiguration.requestedZoom }}×</dd></div>
          <div><dt>Actual focus distance</dt><dd>{{ bestConfiguration.applied?.actualFocusDistance ?? '—' }}</dd></div>
          <div><dt>Actual zoom</dt><dd>{{ bestConfiguration.applied?.actualZoom ?? '—' }}×</dd></div>
          <div><dt>Correct rate</dt><dd>{{ bestConfiguration.correctRate }}</dd></div>
          <div><dt>Incorrect rate</dt><dd>{{ bestConfiguration.incorrectRate }}</dd></div>
          <div><dt>Not found rate</dt><dd>{{ bestConfiguration.notFoundRate }}</dd></div>
          <div><dt>Average sharpness</dt><dd>{{ bestConfiguration.averageSharpness ?? '—' }}</dd></div>
          <div><dt>Average detection latency</dt><dd>{{ bestConfiguration.averageDetectionLatencyMs ?? '—' }} ms</dd></div>
          <div><dt>Confidence</dt><dd>{{ bestConfiguration.confidence }}</dd></div>
        </dl>
        <p class="barcode-focus-zoom-benchmark__muted mb-0">Experimental result only. Do not integrate automatically into the scanner.</p>
      </section>

      <section v-if="results.some((item) => item.attempts > 0)" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Results</h2>
        <div class="barcode-focus-zoom-benchmark__table-wrap">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Focus</th>
                <th>Zoom</th>
                <th>Actual Focus</th>
                <th>Actual Zoom</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Not Found</th>
                <th>Correct %</th>
                <th>Avg Sharpness</th>
                <th>First Correct</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in results.filter((item) => item.attempts > 0 || item.applied)" :key="row.configId">
                <td>{{ row.requestedFocusDistance }}</td>
                <td>{{ row.requestedZoom }}×</td>
                <td>{{ row.applied?.actualFocusDistance ?? '—' }}</td>
                <td>{{ row.applied?.actualZoom ?? '—' }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.incorrect }}</td>
                <td>{{ row.notFound }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.averageSharpness ?? '—' }}</td>
                <td>{{ row.timeToFirstCorrectMs ?? '—' }} ms</td>
                <td>{{ row.configurationStatus }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="heatmapCells.length > 0" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Heatmap</h2>
        <div class="barcode-focus-zoom-benchmark__heatmap">
          <div class="barcode-focus-zoom-benchmark__heatmap-header">
            <span />
            <span v-for="zoom in zoomValues" :key="zoom">{{ zoom }}×</span>
          </div>
          <div v-for="focus in focusValues" :key="focus" class="barcode-focus-zoom-benchmark__heatmap-row">
            <span class="barcode-focus-zoom-benchmark__heatmap-label">{{ focus }}</span>
            <span
              v-for="zoom in zoomValues"
              :key="`${focus}-${zoom}`"
              class="barcode-focus-zoom-benchmark__heatmap-cell"
              :class="heatmapClass(heatmapCells.find((cell) => cell.focusDistance === focus && cell.zoom === zoom)?.correctRate ?? '—')"
            >
              {{ heatmapCells.find((cell) => cell.focusDistance === focus && cell.zoom === zoom)?.correctRate ?? '—' }}
            </span>
          </div>
        </div>
      </section>

      <section v-if="zoomComparison.length > 0" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Graphs</h2>
        <div class="barcode-focus-zoom-benchmark__graphs">
          <div>
            <h3 class="h6">Correct rate vs Zoom</h3>
            <svg viewBox="0 0 320 180" class="barcode-focus-zoom-benchmark__graph" role="img">
              <line x1="30" y1="150" x2="300" y2="150" stroke="currentColor" stroke-opacity="0.3" />
              <line x1="30" y1="20" x2="30" y2="150" stroke="currentColor" stroke-opacity="0.3" />
              <polyline
                :points="zoomComparison.map((row, index) => `${40 + index * 40},${150 - Math.min(130, Number.parseFloat(row.correctRate) || 0)}`).join(' ')"
                fill="none"
                stroke="#0d6efd"
                stroke-width="2"
              />
            </svg>
          </div>
          <div>
            <h3 class="h6">Correct rate vs Focus Distance</h3>
            <svg viewBox="0 0 320 180" class="barcode-focus-zoom-benchmark__graph" role="img">
              <line x1="30" y1="150" x2="300" y2="150" stroke="currentColor" stroke-opacity="0.3" />
              <line x1="30" y1="20" x2="30" y2="150" stroke="currentColor" stroke-opacity="0.3" />
              <polyline
                :points="focusComparison.map((row, index) => `${40 + index * 24},${150 - Math.min(130, Number.parseFloat(row.correctRate) || 0)}`).join(' ')"
                fill="none"
                stroke="#198754"
                stroke-width="2"
              />
            </svg>
          </div>
        </div>
      </section>

      <section v-if="zoomComparison.length > 0" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Does zoom improve detection?</h2>
        <ul class="mb-0">
          <li v-for="row in zoomComparison" :key="row.zoom">{{ row.zoom }}× → {{ row.correctRate }} (best focus {{ row.bestFocus }})</li>
        </ul>
        <p v-if="bestZoom" class="mb-0 mt-2"><strong>BEST ZOOM: {{ bestZoom.zoom }}×</strong> ({{ bestZoom.correctRate }})</p>
      </section>

      <section v-if="focusComparison.length > 0" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Does manual focus distance improve detection?</h2>
        <ul class="mb-0">
          <li v-for="row in focusComparison" :key="row.focusDistance">{{ row.focusDistance }} → {{ row.correctRate }} (best zoom {{ row.bestZoom }})</li>
        </ul>
        <p v-if="bestFocus" class="mb-0 mt-2"><strong>BEST FOCUS DISTANCE: {{ bestFocus.focusDistance }}</strong> ({{ bestFocus.correctRate }})</p>
      </section>

      <section v-if="conclusion" class="barcode-focus-zoom-benchmark__section">
        <h2 class="barcode-focus-zoom-benchmark__section-title">Conclusion</h2>
        <pre class="barcode-focus-zoom-benchmark__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-focus-zoom-benchmark__muted">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-focus-zoom-benchmark__warning">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
