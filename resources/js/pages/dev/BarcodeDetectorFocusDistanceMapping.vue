<script setup lang="ts">
import {
  applyFocusDistance,
  buildMappingConclusion,
  buildMappingDiagnosticClipboard,
  buildMappingGraphPoints,
  buildMappingSummary,
  buildRequestedFocusDistanceLabels,
  buildRequestedFocusDistanceValues,
  clampCustomFocusDistance,
  clampZoomValue,
  FIXED_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  resolveAvailableZoomLevels,
  STABILIZATION_MS,
  type MappingMeasurement,
  type TrackCapabilitiesSnapshot,
  type TrackSettingsSnapshot,
} from '@/utils/barcodeFocusDistanceMapping'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type MappingUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED' | 'ERROR'

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const mappingState = ref<MappingUiState>('IDLE')
const cameraError = ref<{ name: string; message: string } | null>(null)
const copyMessage = ref<string | null>(null)
const mappingMessage = ref<string | null>(null)

const capabilities = ref<TrackCapabilitiesSnapshot>(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref<TrackSettingsSnapshot>(readTrackSettingsSnapshot(null))
const measurements = ref<MappingMeasurement[]>([])
const mappingZoom = ref(1)
const customFocusDistanceInput = ref('0.20')

let cameraSessionId = 0
let mappingSessionId = 0
let mappingAbort = false
let diagnosticsTimer: number | null = null

const supportsManual = computed(() => capabilities.value.focusModes.includes('manual'))
const supportsFocusDistance = computed(() => capabilities.value.focusDistance.supported)
const supportsZoom = computed(() => capabilities.value.zoom.supported)
const availableZoomLevels = computed(() => resolveAvailableZoomLevels(capabilities.value.zoom))

const mappingSupported = computed(() => supportsManual.value && supportsFocusDistance.value)
const canRunMapping = computed(() => cameraState.value === 'READY' && mappingSupported.value && mappingState.value !== 'RUNNING')
const canTestCustom = computed(() => cameraState.value === 'READY' && mappingSupported.value && mappingState.value !== 'RUNNING')

const summary = computed(() => buildMappingSummary(measurements.value))
const graphPoints = computed(() => buildMappingGraphPoints(measurements.value))
const conclusion = computed(() => buildMappingConclusion(summary.value))

const graphBounds = computed(() => {
  const allValues = graphPoints.value.flatMap((point) => [point.requested, point.actual])

  if (allValues.length === 0) {
    return { min: 0, max: 1 }
  }

  const min = Math.min(...allValues)
  const max = Math.max(...allValues)
  const padding = Math.max((max - min) * 0.08, 0.01)

  return { min: Math.max(0, min - padding), max: max + padding }
})

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
}

function projectGraphX(value: number, width: number): number {
  const { min, max } = graphBounds.value
  const range = max - min || 1

  return 40 + ((value - min) / range) * (width - 60)
}

function projectGraphY(value: number, height: number): number {
  const { min, max } = graphBounds.value
  const range = max - min || 1

  return height - 30 - ((value - min) / range) * (height - 50)
}

function formatNumber(value: number | null): string {
  return value == null || !Number.isFinite(value) ? '—' : value.toFixed(4)
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

  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'STARTING'
  cameraError.value = null

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
    mappingZoom.value = availableZoomLevels.value.includes(1) ? 1 : availableZoomLevels.value[0] ?? 1

    if (capabilities.value.focusDistance.min != null) {
      customFocusDistanceInput.value = String(capabilities.value.focusDistance.min)
    }

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
  stopMapping()
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

function clearResults(): void {
  measurements.value = []
  mappingMessage.value = null
  mappingState.value = 'IDLE'
}

function stopMapping(): void {
  mappingAbort = true
  mappingSessionId += 1

  if (mappingState.value === 'RUNNING') {
    mappingState.value = 'STOPPED'
    mappingMessage.value = 'Mapping stopped by user.'
  }
}

async function runMapping(): Promise<void> {
  const track = getVideoTrack()

  if (!track || !canRunMapping.value) {
    return
  }

  stopMapping()
  mappingSessionId += 1
  const sessionId = mappingSessionId
  mappingAbort = false
  mappingState.value = 'RUNNING'
  mappingMessage.value = null
  measurements.value = []

  refreshDiagnostics()

  const values = buildRequestedFocusDistanceValues(capabilities.value.focusDistance)
  const labels = buildRequestedFocusDistanceLabels(values, capabilities.value.focusDistance)
  const zoom = clampZoomValue(mappingZoom.value, capabilities.value.zoom)

  for (let index = 0; index < values.length; index += 1) {
    if (mappingAbort || sessionId !== mappingSessionId) {
      break
    }

    const requestedFocusDistance = values[index]!
    const label = labels[index] ?? requestedFocusDistance.toFixed(4)

    mappingMessage.value = `Mapping ${index + 1}/${values.length} — ${label} (${requestedFocusDistance})`

    const measurement = await applyFocusDistance(track, {
      requestedFocusDistance,
      requestedZoom: zoom,
      zoomSupported: supportsZoom.value,
      focusDistanceStep: capabilities.value.focusDistance.step,
      zoomStep: capabilities.value.zoom.step,
      label,
    })

    if (sessionId !== mappingSessionId) {
      break
    }

    measurements.value = [...measurements.value, measurement]
    refreshDiagnostics()
  }

  if (sessionId !== mappingSessionId) {
    return
  }

  if (mappingAbort) {
    mappingState.value = 'STOPPED'
    mappingMessage.value = 'Mapping stopped by user.'
    return
  }

  mappingState.value = 'COMPLETED'
  mappingMessage.value = `Mapping completed — ${measurements.value.length} measurements.`
}

async function testCustomValue(): Promise<void> {
  const track = getVideoTrack()

  if (!track || !canTestCustom.value) {
    return
  }

  const parsed = Number.parseFloat(customFocusDistanceInput.value)
  const clamped = clampCustomFocusDistance(parsed, capabilities.value.focusDistance)

  if (clamped == null) {
    cameraError.value = { name: 'InvalidValue', message: 'Valeur focusDistance invalide ou hors plage.' }
    return
  }

  const measurement = await applyFocusDistance(track, {
    requestedFocusDistance: clamped,
    requestedZoom: clampZoomValue(mappingZoom.value, capabilities.value.zoom),
    zoomSupported: supportsZoom.value,
    focusDistanceStep: capabilities.value.focusDistance.step,
    zoomStep: capabilities.value.zoom.step,
    label: `CUSTOM ${clamped}`,
  })

  measurements.value = [...measurements.value, measurement]
  refreshDiagnostics()
}

async function copyDiagnostic(): Promise<void> {
  const text = buildMappingDiagnosticClipboard({
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    mappingZoom: mappingZoom.value,
    stabilizationMs: STABILIZATION_MS,
    measurements: measurements.value,
    summary: summary.value,
    conclusion: conclusion.value,
    mappingStoppedByUser: mappingState.value === 'STOPPED',
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
    stopMapping()
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
  <Head title="Focus Distance Mapping" />

  <div class="barcode-reader-test-page barcode-focus-distance-mapping">
    <div class="barcode-reader-test-page__container barcode-focus-distance-mapping__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Focus Distance Mapping</h1>
          <p class="barcode-reader-test-page__subtitle">Cartographie REQUESTED → ACTUAL pour focusDistance (diagnostic caméra uniquement)</p>
        </div>
        <div class="barcode-focus-distance-mapping__header-links">
          <Link href="/dev/barcode-detector-manual-focus-experiment" class="btn btn-sm btn-outline-secondary">Expérience Focus × Zoom</Link>
          <Link href="/dev/barcode-detector-focus-zoom-benchmark" class="btn btn-sm btn-outline-secondary">Focus × Zoom Benchmark</Link>
          <Link href="/dev/barcode-detector-size-zoom-comparison" class="btn btn-sm btn-outline-secondary">Size × Zoom Comparison</Link>
          <Link href="/dev/barcode-detector-distance-focus" class="btn btn-sm btn-outline-secondary">Distance × Focus</Link>
          <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section v-if="!mappingSupported && cameraState === 'READY'" class="barcode-focus-distance-mapping__alert">
        <p v-if="!supportsFocusDistance" class="mb-0"><strong>Focus Distance Mapping: NOT SUPPORTED</strong> — This camera/browser does not expose focusDistance capabilities.</p>
        <p v-else-if="!supportsManual" class="mb-0"><strong>Manual focus: NOT SUPPORTED</strong></p>
      </section>

      <section class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">États</h2>
        <dl class="barcode-focus-distance-mapping__grid">
          <div><dt>CAMERA</dt><dd>{{ cameraState }}</dd></div>
          <div><dt>MAPPING</dt><dd>{{ mappingState }}</dd></div>
          <div><dt>Mapping zoom</dt><dd>{{ mappingZoom }}×</dd></div>
          <div><dt>Stabilization</dt><dd>{{ STABILIZATION_MS }} ms</dd></div>
        </dl>
      </section>

      <section class="barcode-focus-distance-mapping__section barcode-focus-distance-mapping__section--video">
        <div class="barcode-focus-distance-mapping__video-wrap">
          <video ref="videoRef" class="barcode-focus-distance-mapping__video" autoplay muted playsinline />
        </div>
        <div class="barcode-focus-distance-mapping__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCamera">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canRunMapping" @click="runMapping">Run Mapping</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="mappingState !== 'RUNNING'" @click="stopMapping">Stop Mapping</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyDiagnostic">Copy Diagnostic</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="clearResults">Clear Results</button>
        </div>
        <p v-if="mappingMessage" class="barcode-focus-distance-mapping__muted mb-0 mt-2">{{ mappingMessage }}</p>
      </section>

      <section class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">CAMERA CAPABILITIES</h2>
        <dl class="barcode-focus-distance-mapping__grid">
          <div class="barcode-focus-distance-mapping__grid-full"><dt>Focus modes</dt><dd>{{ capabilities.focusModes.join(', ') || '—' }}</dd></div>
          <div><dt>Focus distance min</dt><dd>{{ capabilities.focusDistance.min ?? '—' }}</dd></div>
          <div><dt>Focus distance max</dt><dd>{{ capabilities.focusDistance.max ?? '—' }}</dd></div>
          <div><dt>Focus distance step</dt><dd>{{ capabilities.focusDistance.step ?? '—' }}</dd></div>
          <div><dt>Zoom min</dt><dd>{{ capabilities.zoom.min ?? '—' }}</dd></div>
          <div><dt>Zoom max</dt><dd>{{ capabilities.zoom.max ?? '—' }}</dd></div>
          <div><dt>Zoom step</dt><dd>{{ capabilities.zoom.step ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">Mapping configuration</h2>
        <p class="barcode-focus-distance-mapping__muted">Test principal à 1× pour isoler le focus. Sélecteur optionnel si supporté.</p>
        <div class="barcode-focus-distance-mapping__actions">
          <button
            v-for="zoom in availableZoomLevels"
            :key="zoom"
            type="button"
            class="btn btn-sm"
            :class="mappingZoom === zoom ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="mappingState === 'RUNNING'"
            @click="mappingZoom = zoom"
          >
            {{ zoom }}×
          </button>
        </div>
        <div class="barcode-focus-distance-mapping__form-row mt-3">
          <label for="custom-focus-distance">Custom focusDistance</label>
          <div class="barcode-focus-distance-mapping__inline-form">
            <input id="custom-focus-distance" v-model="customFocusDistanceInput" type="text" class="form-control form-control-sm font-monospace">
            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="!canTestCustom" @click="testCustomValue">Test custom value</button>
          </div>
        </div>
      </section>

      <section class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">RAW SETTINGS</h2>
        <pre class="barcode-focus-distance-mapping__pre">{{ JSON.stringify(trackSettings.raw, null, 2) }}</pre>
        <h3 class="h6 mt-3">RAW CAPABILITIES</h3>
        <pre class="barcode-focus-distance-mapping__pre">{{ JSON.stringify(capabilities.raw, null, 2) }}</pre>
      </section>

      <section v-if="measurements.length > 0" class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">Observed mapping</h2>
        <dl class="barcode-focus-distance-mapping__grid mb-3">
          <div><dt>Total</dt><dd>{{ summary.total }}</dd></div>
          <div><dt>MATCH</dt><dd>{{ summary.match }}</dd></div>
          <div><dt>MISMATCH</dt><dd>{{ summary.mismatch }}</dd></div>
          <div><dt>APPLY_ERROR</dt><dd>{{ summary.applyError }}</dd></div>
          <div><dt>UNKNOWN</dt><dd>{{ summary.unknown }}</dd></div>
          <div><dt>Match rate</dt><dd>{{ summary.matchRate }}</dd></div>
        </dl>

        <div class="barcode-focus-distance-mapping__table-wrap">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Label</th>
                <th>REQUESTED</th>
                <th>ACTUAL</th>
                <th>DIFFERENCE</th>
                <th>Tolerance</th>
                <th>MODE</th>
                <th>ZOOM</th>
                <th>Focus mode</th>
                <th>Focus distance</th>
                <th>Overall</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in measurements" :key="row.id">
                <td>{{ row.label }}</td>
                <td>{{ formatNumber(row.requestedFocusDistance) }}</td>
                <td>{{ formatNumber(row.actualFocusDistance) }}</td>
                <td>{{ formatNumber(row.difference) }}</td>
                <td>{{ formatNumber(row.tolerance) }}</td>
                <td>{{ row.actualFocusMode }}</td>
                <td>{{ row.actualZoom }}×</td>
                <td>{{ row.focusModeStatus }}</td>
                <td :class="`barcode-focus-distance-mapping__status--${row.focusDistanceStatus.toLowerCase().replace('_', '-')}`">{{ row.focusDistanceStatus }}</td>
                <td>{{ row.overallStatus }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="graphPoints.length > 0" class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">Requested → Actual graph</h2>
        <p class="barcode-focus-distance-mapping__muted">Ligne de référence : actual = requested</p>
        <svg viewBox="0 0 420 280" class="barcode-focus-distance-mapping__graph" role="img" aria-label="Graphique requested versus actual focus distance">
          <line :x1="40" :y1="250" :x2="400" :y2="250" stroke="currentColor" stroke-opacity="0.35" />
          <line :x1="40" :y1="20" :x2="40" :y2="250" stroke="currentColor" stroke-opacity="0.35" />
          <line
            :x1="projectGraphX(graphBounds.min, 420)"
            :y1="projectGraphY(graphBounds.min, 280)"
            :x2="projectGraphX(graphBounds.max, 420)"
            :y2="projectGraphY(graphBounds.max, 280)"
            stroke="#6c757d"
            stroke-dasharray="4 4"
          />
          <circle
            v-for="(point, index) in graphPoints"
            :key="`${point.requested}-${point.actual}-${index}`"
            :cx="projectGraphX(point.requested, 420)"
            :cy="projectGraphY(point.actual, 280)"
            r="5"
            :class="`barcode-focus-distance-mapping__graph-point barcode-focus-distance-mapping__graph-point--${point.status.toLowerCase().replace('_', '-')}`"
          />
          <text x="210" y="275" text-anchor="middle" class="barcode-focus-distance-mapping__graph-label">Requested focusDistance</text>
          <text x="12" y="140" transform="rotate(-90 12 140)" text-anchor="middle" class="barcode-focus-distance-mapping__graph-label">Actual focusDistance</text>
        </svg>
      </section>

      <section v-if="measurements.length > 0" class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">Conclusion</h2>
        <pre class="barcode-focus-distance-mapping__pre mb-0">{{ conclusion }}</pre>
      </section>

      <section class="barcode-focus-distance-mapping__section">
        <h2 class="barcode-focus-distance-mapping__section-title">Tests spécifiques recommandés</h2>
        <pre class="barcode-focus-distance-mapping__pre mb-0">A — manual focusDistance 0.20 zoom 1×
B — manual focusDistance 0.39 zoom 1×
C — manual focusDistance 0.50 zoom 1×
D — manual focusDistance MIN zoom 1×
E — manual focusDistance MAX zoom 1×</pre>
      </section>

      <p v-if="copyMessage" class="barcode-focus-distance-mapping__muted">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-focus-distance-mapping__warning">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
