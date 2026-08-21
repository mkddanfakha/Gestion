<script setup lang="ts">
import {
  buildLiveConclusion,
  buildLiveDiagnosticClipboard,
  computeAverageDetectionMs,
  createNativeLiveDetector,
  formatNativeBarcodeFormat,
  FULL_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  MAX_SUCCESS_HISTORY,
  MIN_DETECTION_INTERVAL_MS,
  NOT_FOUND_MILESTONE,
  pickBestBarcode,
  readCameraTrackDiagnostics,
  REFERENCE_EAN_VALUE,
  SIMPLE_CAMERA_CONSTRAINTS,
  type BarcodeDetectorLike,
  type CameraState,
  type DetectionLoopState,
  type EnvironmentDiagnostics,
  type LiveStats,
  type SuccessHistoryEntry,
} from '@/utils/nativeBarcodeDetectorLiveTest'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const DEBUG = import.meta.env.DEV
const START_TIMEOUT_MS = 10_000

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref<EnvironmentDiagnostics>(getEnvironmentDiagnostics())
const cameraState = ref<CameraState>('idle')
const detectionLoopState = ref<DetectionLoopState>('stopped')
const constraintLabel = ref('—')
const formatsUsed = ref<string[]>([])
const cameraError = ref<{ name: string; message: string; constraint: string } | null>(null)
const copyMessage = ref<string | null>(null)
const milestoneMessage = ref<string | null>(null)

const videoWidth = ref(0)
const videoHeight = ref(0)
const readyState = ref(0)
const streamActive = ref(false)
const trackDiagnostics = ref(readCameraTrackDiagnostics(null))

const stats = ref<LiveStats>({
  framesSeen: 0,
  detectionAttempts: 0,
  successfulDetections: 0,
  notFound: 0,
  errors: 0,
  lastDetectionMs: null,
  averageDetectionMs: null,
})

const lastDetectedValue = ref<string | null>(null)
const lastDetectedFormat = ref<string | null>(null)
const successHistory = ref<SuccessHistoryEntry[]>([])

let cameraSessionId = 0
let detectionSessionId = 0
let loopAnimationId: number | null = null
let diagnosticsTimer: number | null = null
let lastDetectionTime = 0
let detectionInProgress = false
let lastObservedCurrentTime = -1
const completedDetectionDurations: number[] = []

const barcodeDetectorLabel = computed(() => {
  return environment.value.barcodeDetectorAvailable
    ? 'BarcodeDetector natif : DISPONIBLE'
    : 'BarcodeDetector natif : NON DISPONIBLE'
})

const conclusion = computed(() => buildLiveConclusion({
  barcodeDetectorAvailable: environment.value.barcodeDetectorAvailable,
  successfulDetections: stats.value.successfulDetections,
  detectionAttempts: stats.value.detectionAttempts,
  errors: stats.value.errors,
  lastDetectedValue: lastDetectedValue.value,
  lastDetectedFormat: lastDetectedFormat.value,
  lastErrorName: cameraError.value?.name ?? null,
  lastErrorMessage: cameraError.value?.message ?? null,
}))

const canStartDetection = computed(() => {
  return cameraState.value === 'active'
    && environment.value.barcodeDetectorAvailable
    && detectorRef.value != null
    && videoWidth.value > 0
    && videoHeight.value > 0
})

function serializeError(error: unknown): { name: string; message: string; constraint: string } {
  if (error instanceof DOMException || error instanceof Error) {
    const record = error as Error & { constraint?: string }

    return {
      name: record.name || '—',
      message: record.message || '—',
      constraint: record.constraint ? String(record.constraint) : '—',
    }
  }

  return {
    name: '—',
    message: String(error),
    constraint: '—',
  }
}

function isCameraSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
}

function isDetectionSessionActive(sessionId: number): boolean {
  return sessionId === detectionSessionId
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

function stopLoopAnimation(): void {
  if (loopAnimationId !== null) {
    cancelAnimationFrame(loopAnimationId)
    loopAnimationId = null
  }
}

function stopDiagnosticsPolling(): void {
  if (diagnosticsTimer !== null) {
    window.clearInterval(diagnosticsTimer)
    diagnosticsTimer = null
  }
}

function refreshDiagnostics(): void {
  const video = videoRef.value

  videoWidth.value = video?.videoWidth ?? 0
  videoHeight.value = video?.videoHeight ?? 0
  readyState.value = video?.readyState ?? 0
  streamActive.value = activeStream.value?.active ?? false
  trackDiagnostics.value = readCameraTrackDiagnostics(activeStream.value)
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    refreshDiagnostics()
  }, 500)
}

function startFrameCounter(): void {
  stopLoopAnimation()
  lastObservedCurrentTime = -1

  const tick = (): void => {
    const video = videoRef.value

    if (!video || cameraState.value !== 'active' || detectionLoopState.value === 'running') {
      loopAnimationId = null
      return
    }

    if (video.currentTime !== lastObservedCurrentTime) {
      lastObservedCurrentTime = video.currentTime
      stats.value = {
        ...stats.value,
        framesSeen: stats.value.framesSeen + 1,
      }
    }

    loopAnimationId = requestAnimationFrame(tick)
  }

  loopAnimationId = requestAnimationFrame(tick)
}

async function waitForVideoReady(video: HTMLVideoElement, sessionId: number): Promise<boolean> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < START_TIMEOUT_MS) {
    if (!isCameraSessionActive(sessionId)) {
      return false
    }

    if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
      return true
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return false
}

function cleanupFailedCamera(stream: MediaStream, video: HTMLVideoElement | null): void {
  stopTracks(stream)

  if (video) {
    try {
      video.pause()
    } catch {
      // ignorer
    }

    video.srcObject = null
  }
}

async function initializeDetector(): Promise<void> {
  if (!environment.value.barcodeDetectorAvailable) {
    detectorRef.value = null
    formatsUsed.value = []
    return
  }

  try {
    const created = await createNativeLiveDetector()
    detectorRef.value = created.detector
    formatsUsed.value = created.formatsUsed
  } catch (error) {
    detectorRef.value = null
    formatsUsed.value = []
    cameraError.value = serializeError(error)
  }
}

async function startCamera(): Promise<void> {
  if (!DEBUG || cameraState.value === 'starting' || cameraState.value === 'active') {
    return
  }

  await stopCamera()

  const sessionId = ++cameraSessionId
  cameraState.value = 'starting'
  cameraError.value = null
  milestoneMessage.value = null
  environment.value = getEnvironmentDiagnostics()

  if (!window.isSecureContext) {
    cameraState.value = 'error'
    cameraError.value = {
      name: 'SecurityError',
      message: 'Contexte non sécurisé — HTTPS requis.',
      constraint: '—',
    }
    return
  }

  if (!navigator.mediaDevices?.getUserMedia) {
    cameraState.value = 'error'
    cameraError.value = {
      name: 'TypeError',
      message: 'navigator.mediaDevices.getUserMedia indisponible.',
      constraint: '—',
    }
    return
  }

  let stream: MediaStream | null = null

  try {
    try {
      stream = await navigator.mediaDevices.getUserMedia(FULL_CAMERA_CONSTRAINTS)
      constraintLabel.value = 'environment + 1280×720 (ideal)'
    } catch {
      stream = await navigator.mediaDevices.getUserMedia(SIMPLE_CAMERA_CONSTRAINTS)
      constraintLabel.value = 'video: true (fallback)'
    }

    if (!isCameraSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      cleanupFailedCamera(stream, null)
      activeStream.value = null
      cameraState.value = 'error'
      cameraError.value = {
        name: 'Error',
        message: 'Élément vidéo introuvable.',
        constraint: '—',
      }
      return
    }

    video.srcObject = stream
    await video.play()

    if (!isCameraSessionActive(sessionId)) {
      cleanupFailedCamera(stream, video)
      activeStream.value = null
      return
    }

    const ready = await waitForVideoReady(video, sessionId)

    if (!isCameraSessionActive(sessionId)) {
      cleanupFailedCamera(stream, video)
      activeStream.value = null
      return
    }

    if (!ready) {
      cleanupFailedCamera(stream, video)
      activeStream.value = null
      cameraState.value = 'error'
      cameraError.value = {
        name: 'TimeoutError',
        message: 'La caméra a été ouverte mais le flux vidéo n\'a pas fourni d\'image.',
        constraint: constraintLabel.value,
      }
      return
    }

    await initializeDetector()
    cameraState.value = 'active'
    refreshDiagnostics()
    startDiagnosticsPolling()
    startFrameCounter()
  } catch (error) {
    if (stream) {
      cleanupFailedCamera(stream, videoRef.value)
      activeStream.value = null
    }

    if (isCameraSessionActive(sessionId)) {
      cameraState.value = 'error'
      cameraError.value = serializeError(error)
    }
  }
}

function resetDetectionCounters(): void {
  stats.value = {
    framesSeen: 0,
    detectionAttempts: 0,
    successfulDetections: 0,
    notFound: 0,
    errors: 0,
    lastDetectionMs: null,
    averageDetectionMs: null,
  }
  lastDetectedValue.value = null
  lastDetectedFormat.value = null
  completedDetectionDurations.length = 0
  milestoneMessage.value = null
}

async function runSingleDetection(): Promise<void> {
  const video = videoRef.value
  const detector = detectorRef.value

  if (!video || !detector || !canStartDetection.value || detectionInProgress) {
    return
  }

  detectionInProgress = true
  const start = performance.now()

  try {
    stats.value = {
      ...stats.value,
      detectionAttempts: stats.value.detectionAttempts + 1,
    }

    const barcodes = await detector.detect(video)
    const durationMs = Math.round(performance.now() - start)
    completedDetectionDurations.push(durationMs)

    stats.value = {
      ...stats.value,
      lastDetectionMs: durationMs,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations),
    }

    const best = pickBestBarcode(barcodes)

    if (!best?.rawValue) {
      stats.value = {
        ...stats.value,
        notFound: stats.value.notFound + 1,
      }

      if (stats.value.detectionAttempts >= NOT_FOUND_MILESTONE && stats.value.successfulDetections === 0) {
        milestoneMessage.value = `${stats.value.detectionAttempts} tentatives effectuées sans détection.`
      }

      return
    }

    recordSuccess(best.rawValue, best.format, durationMs)
  } catch (error) {
    const durationMs = Math.round(performance.now() - start)
    completedDetectionDurations.push(durationMs)

    stats.value = {
      ...stats.value,
      errors: stats.value.errors + 1,
      lastDetectionMs: durationMs,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations),
    }

    cameraError.value = serializeError(error)
  } finally {
    detectionInProgress = false
  }
}

function recordSuccess(rawValue: string, format: string | undefined, durationMs: number): void {
  lastDetectedValue.value = rawValue
  lastDetectedFormat.value = formatNativeBarcodeFormat(format)

  stats.value = {
    ...stats.value,
    successfulDetections: stats.value.successfulDetections + 1,
    lastDetectionMs: durationMs,
    averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations),
  }

  successHistory.value = [
    {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      timestamp: new Date().toLocaleTimeString(),
      rawValue,
      format: formatNativeBarcodeFormat(format),
      durationMs,
    },
    ...successHistory.value,
  ].slice(0, MAX_SUCCESS_HISTORY)
}

function startDetectionLoop(): void {
  if (!canStartDetection.value || detectionLoopState.value === 'running') {
    return
  }

  stopLoopAnimation()
  detectionSessionId += 1
  const sessionId = detectionSessionId
  detectionLoopState.value = 'running'
  lastDetectionTime = 0

  const loop = (): void => {
    if (!isDetectionSessionActive(sessionId) || detectionLoopState.value !== 'running') {
      loopAnimationId = null
      return
    }

    const video = videoRef.value

    if (video && video.currentTime !== lastObservedCurrentTime) {
      lastObservedCurrentTime = video.currentTime
      stats.value = {
        ...stats.value,
        framesSeen: stats.value.framesSeen + 1,
      }
    }

    const now = performance.now()

    if (
      !detectionInProgress
      && detectorRef.value
      && video
      && now - lastDetectionTime >= MIN_DETECTION_INTERVAL_MS
    ) {
      lastDetectionTime = now
      void runSingleDetection()
    }

    loopAnimationId = requestAnimationFrame(loop)
  }

  loopAnimationId = requestAnimationFrame(loop)
}

function stopDetectionLoop(): void {
  detectionSessionId += 1
  detectionLoopState.value = 'stopped'
  stopLoopAnimation()

  if (cameraState.value === 'active') {
    startFrameCounter()
  }
}

async function stopCamera(): Promise<void> {
  cameraSessionId += 1
  stopDetectionLoop()
  stopDiagnosticsPolling()
  stopLoopAnimation()

  cameraState.value = 'stopping'
  detectionInProgress = false

  const stream = activeStream.value
  const video = videoRef.value

  stopTracks(stream)
  activeStream.value = null
  detectorRef.value = null
  formatsUsed.value = []

  if (video) {
    try {
      video.pause()
    } catch {
      // ignorer
    }

    video.srcObject = null
  }

  cameraState.value = 'idle'
  constraintLabel.value = '—'
  refreshDiagnostics()
}

function clearHistory(): void {
  successHistory.value = []
}

async function copyDiagnostic(): Promise<void> {
  const text = buildLiveDiagnosticClipboard({
    environment: environment.value,
    cameraState: cameraState.value,
    streamActive: streamActive.value,
    videoWidth: videoWidth.value,
    videoHeight: videoHeight.value,
    readyState: readyState.value,
    trackDiagnostics: trackDiagnostics.value,
    stats: stats.value,
    lastDetectedValue: lastDetectedValue.value,
    lastDetectedFormat: lastDetectedFormat.value,
    formatsUsed: formatsUsed.value,
    constraintLabel: constraintLabel.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié dans le presse-papiers.'
  } catch {
    copyMessage.value = 'Impossible de copier automatiquement le diagnostic.'
  }
}

function handlePageHide(): void {
  void stopCamera()
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  window.addEventListener('pagehide', handlePageHide)
})

onBeforeUnmount(() => {
  window.removeEventListener('pagehide', handlePageHide)
  void stopCamera()
})
</script>

<template>
  <Head title="DEV — BarcodeDetector live" />

  <div class="barcode-reader-test-page native-barcode-detector-live-test">
    <div class="barcode-reader-test-page__container native-barcode-detector-live-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / TEST LIVE UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">Test BarcodeDetector live</h1>
          <p class="barcode-reader-test-page__intro mb-0">
            Caméra → MediaStream → video → BarcodeDetector.detect(video). Sans ZXing ni polyfill.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
          Retour
        </Link>
      </header>

      <section class="native-barcode-detector-live-test__section">
        <p class="native-barcode-detector-live-test__reference mb-0">
          Code de référence utilisé lors du test image :
          <span class="font-monospace">{{ REFERENCE_EAN_VALUE }}</span>
        </p>
        <p class="native-barcode-detector-live-test__muted mb-0">
          Information de diagnostic uniquement — aucune validation métier automatique.
        </p>
      </section>

      <section class="native-barcode-detector-live-test__section">
        <h2 class="native-barcode-detector-live-test__section-title">Environnement</h2>
        <dl class="native-barcode-detector-live-test__grid">
          <div><dt>Navigateur</dt><dd>{{ environment.browserLabel }}</dd></div>
          <div><dt>Secure context</dt><dd>{{ environment.secureContext ? 'true' : 'false' }}</dd></div>
          <div><dt>Platform</dt><dd>{{ environment.platform }}</dd></div>
          <div><dt>{{ barcodeDetectorLabel.split(' : ')[0] }}</dt><dd>{{ barcodeDetectorLabel.split(' : ')[1] }}</dd></div>
          <div><dt>Contrainte caméra</dt><dd>{{ constraintLabel }}</dd></div>
          <div><dt>Formats utilisés</dt><dd>{{ formatsUsed.length > 0 ? formatsUsed.join(', ') : '—' }}</dd></div>
          <div class="native-barcode-detector-live-test__grid-full">
            <dt>User agent</dt>
            <dd class="native-barcode-detector-live-test__break">{{ environment.userAgent }}</dd>
          </div>
        </dl>
      </section>

      <section class="native-barcode-detector-live-test__section native-barcode-detector-live-test__section--video">
        <div class="native-barcode-detector-live-test__video-wrap">
          <video
            ref="videoRef"
            class="native-barcode-detector-live-test__video"
            autoplay
            muted
            playsinline
          />
          <div class="native-barcode-detector-live-test__overlay" aria-hidden="true">
            <div class="native-barcode-detector-live-test__guide">
              CODE-BARRES ICI
            </div>
          </div>
        </div>

        <div class="native-barcode-detector-live-test__actions">
          <button
            type="button"
            class="btn btn-primary"
            :disabled="cameraState === 'starting' || cameraState === 'active'"
            @click="startCamera"
          >
            Démarrer caméra
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="cameraState === 'idle' || cameraState === 'stopping'"
            @click="stopCamera"
          >
            Arrêter caméra
          </button>
          <button
            type="button"
            class="btn btn-outline-success"
            :disabled="!canStartDetection || detectionLoopState === 'running'"
            @click="startDetectionLoop"
          >
            Démarrer détection
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="detectionLoopState !== 'running'"
            @click="stopDetectionLoop"
          >
            Arrêter détection
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!canStartDetection"
            @click="runSingleDetection"
          >
            Détecter maintenant
          </button>
        </div>
      </section>

      <section class="native-barcode-detector-live-test__section">
        <h2 class="native-barcode-detector-live-test__section-title">Diagnostic caméra</h2>
        <dl class="native-barcode-detector-live-test__grid">
          <div><dt>Camera</dt><dd>{{ cameraState === 'active' ? (detectionLoopState === 'running' ? 'scanning' : 'active') : cameraState }}</dd></div>
          <div><dt>Stream</dt><dd>{{ streamActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Video</dt><dd>{{ videoWidth > 0 ? `${videoWidth} × ${videoHeight}` : '—' }}</dd></div>
          <div><dt>ReadyState</dt><dd>{{ readyState }}</dd></div>
          <div><dt>Frames</dt><dd>{{ stats.framesSeen }}</dd></div>
          <div><dt>Detection attempts</dt><dd>{{ stats.detectionAttempts }}</dd></div>
          <div><dt>Successful detections</dt><dd>{{ stats.successfulDetections }}</dd></div>
          <div><dt>Not found</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Tracks</dt><dd>{{ trackDiagnostics.trackCount }}</dd></div>
          <div><dt>Video tracks</dt><dd>{{ trackDiagnostics.videoTrackCount }}</dd></div>
          <div><dt>Track state</dt><dd>{{ trackDiagnostics.trackState }}</dd></div>
          <div><dt>Facing mode</dt><dd>{{ trackDiagnostics.facingMode }}</dd></div>
          <div><dt>Track resolution</dt><dd>{{ trackDiagnostics.resolution }}</dd></div>
          <div><dt>Frame rate</dt><dd>{{ trackDiagnostics.frameRate }}</dd></div>
          <div><dt>Last detection</dt><dd>{{ stats.lastDetectionMs != null ? `${stats.lastDetectionMs} ms` : '—' }}</dd></div>
          <div><dt>Average detection</dt><dd>{{ stats.averageDetectionMs != null ? `${stats.averageDetectionMs} ms` : '—' }}</dd></div>
        </dl>

        <div v-if="cameraError" class="native-barcode-detector-live-test__error-block">
          <p class="native-barcode-detector-live-test__error-title mb-2">Erreur</p>
          <dl class="native-barcode-detector-live-test__grid">
            <div><dt>Name</dt><dd>{{ cameraError.name }}</dd></div>
            <div><dt>Constraint</dt><dd>{{ cameraError.constraint }}</dd></div>
            <div class="native-barcode-detector-live-test__grid-full">
              <dt>Message</dt>
              <dd class="native-barcode-detector-live-test__break">{{ cameraError.message }}</dd>
            </div>
          </dl>
        </div>

        <p v-if="milestoneMessage" class="native-barcode-detector-live-test__muted mb-0 mt-2">
          {{ milestoneMessage }}
        </p>
      </section>

      <section v-if="lastDetectedValue" class="native-barcode-detector-live-test__section">
        <h2 class="native-barcode-detector-live-test__section-title">Dernier résultat</h2>
        <p class="native-barcode-detector-live-test__success mb-1">SUCCESS</p>
        <dl class="native-barcode-detector-live-test__grid">
          <div class="native-barcode-detector-live-test__grid-full"><dt>Raw value</dt><dd class="font-monospace">{{ lastDetectedValue }}</dd></div>
          <div><dt>Format</dt><dd>{{ lastDetectedFormat }}</dd></div>
          <div><dt>Détections réussies</dt><dd>{{ stats.successfulDetections }}</dd></div>
        </dl>
      </section>

      <section class="native-barcode-detector-live-test__section native-barcode-detector-live-test__conclusion">
        <h2 class="native-barcode-detector-live-test__section-title">Conclusion</h2>
        <pre class="native-barcode-detector-live-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <section v-if="successHistory.length > 0" class="native-barcode-detector-live-test__section">
        <div class="native-barcode-detector-live-test__history-header">
          <h2 class="native-barcode-detector-live-test__section-title mb-0">Historique des succès</h2>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearHistory">
            Effacer historique
          </button>
        </div>

        <div class="native-barcode-detector-live-test__history">
          <article
            v-for="entry in successHistory"
            :key="entry.id"
            class="native-barcode-detector-live-test__history-item"
          >
            <div>{{ entry.timestamp }} — SUCCESS — {{ entry.format }}</div>
            <div class="font-monospace">{{ entry.rawValue }}</div>
            <div>{{ entry.durationMs }} ms</div>
          </article>
        </div>
      </section>

      <section class="native-barcode-detector-live-test__section">
        <button type="button" class="btn btn-outline-secondary" @click="copyDiagnostic">
          Copier diagnostic
        </button>
        <p v-if="copyMessage" class="native-barcode-detector-live-test__muted mb-0 mt-2">
          {{ copyMessage }}
        </p>
      </section>
    </div>
  </div>
</template>
