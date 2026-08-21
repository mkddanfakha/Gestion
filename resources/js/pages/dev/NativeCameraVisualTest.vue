<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const DEBUG = import.meta.env.DEV

type CameraState = 'idle' | 'starting' | 'active' | 'stopping' | 'error'
type ConstraintMode = 'full' | 'simple'

interface CameraErrorDetails {
  name: string
  message: string
  constraint: string
}

interface CapturedImageInfo {
  width: number
  height: number
  aspectRatio: string
  dataUrl: string
  dataUrlSizeKb: string
}

const START_TIMEOUT_MS = 10_000
const DIAGNOSTICS_INTERVAL_MS = 500

const FULL_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
  },
  audio: false,
}

const SIMPLE_CONSTRAINTS: MediaStreamConstraints = {
  video: true,
  audio: false,
}

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const cameraState = ref<CameraState>('idle')
const activeConstraintMode = ref<ConstraintMode | null>(null)
const cameraError = ref<CameraErrorDetails | null>(null)

const videoWidth = ref(0)
const videoHeight = ref(0)
const readyState = ref(0)
const paused = ref(true)
const streamActive = ref(false)
const trackCount = ref(0)
const videoTrackCount = ref(0)
const trackState = ref('—')
const facingMode = ref('—')
const trackResolution = ref('—')
const frameRate = ref('—')
const framesSeen = ref(0)
const currentTimeLabel = ref('0.00')
const secureContext = ref(false)
const getUserMediaSupported = ref(false)

const capturedImage = ref<CapturedImageInfo | null>(null)
const multiCaptureImages = ref<CapturedImageInfo[]>([])
const captureRunning = ref(false)
const multiCaptureRunning = ref(false)

let cameraSessionId = 0
let diagnosticsTimer: number | null = null
let frameAnimationId: number | null = null
let lastObservedCurrentTime = -1
let captureCanvas: HTMLCanvasElement | null = null

const constraintLabel = computed(() => {
  if (activeConstraintMode.value === 'full') {
    return 'environment + 1280×720 (ideal)'
  }

  if (activeConstraintMode.value === 'simple') {
    return 'video: true (fallback)'
  }

  return '—'
})

const canCapture = computed(() => {
  return cameraState.value === 'active'
    && streamActive.value
    && videoWidth.value > 0
    && videoHeight.value > 0
})

const visualConclusion = computed(() => {
  if (!capturedImage.value) {
    return null
  }

  const hasWorkingPipeline = videoWidth.value > 0
    && videoHeight.value > 0
    && framesSeen.value > 0
    && capturedImage.value.width > 0
    && capturedImage.value.height > 0

  if (hasWorkingPipeline) {
    return {
      title: 'Flux vidéo → canvas fonctionnel.',
      detail: 'Vérifiez maintenant visuellement si le code-barres est présent et net dans l\'image capturée.',
    }
  }

  return {
    title: 'La caméra fournit un flux mais l\'image capturée semble inutilisable. Vérification visuelle nécessaire.',
    detail: 'Ce test ne prétend pas automatiquement juger la qualité de l\'image — inspectez-la directement.',
  }
})

function display(value: unknown): string {
  if (value == null || value === '') return '—'
  return String(value)
}

function refreshEnvironmentDiagnostics(): void {
  const mediaDevicesAvailable = typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'

  secureContext.value = typeof window !== 'undefined' ? window.isSecureContext : false
  getUserMediaSupported.value = mediaDevicesAvailable
    && typeof navigator.mediaDevices.getUserMedia === 'function'
}

function serializeCameraError(error: unknown): CameraErrorDetails {
  if (error instanceof DOMException || error instanceof Error) {
    const record = error as Error & { constraint?: string }

    return {
      name: display(record.name),
      message: display(record.message),
      constraint: display(record.constraint),
    }
  }

  return {
    name: '—',
    message: display(error),
    constraint: '—',
  }
}

function isSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
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

function stopFrameCounter(): void {
  if (frameAnimationId !== null) {
    cancelAnimationFrame(frameAnimationId)
    frameAnimationId = null
  }
}

function stopDiagnosticsPolling(): void {
  if (diagnosticsTimer !== null) {
    window.clearInterval(diagnosticsTimer)
    diagnosticsTimer = null
  }
}

function refreshLiveDiagnostics(): void {
  const video = videoRef.value
  const stream = activeStream.value
  const tracks = stream?.getTracks() ?? []
  const videoTracks = stream?.getVideoTracks() ?? []
  const track = videoTracks[0]
  const settings = track?.getSettings?.()

  videoWidth.value = video?.videoWidth ?? 0
  videoHeight.value = video?.videoHeight ?? 0
  readyState.value = video?.readyState ?? 0
  paused.value = video?.paused ?? true
  streamActive.value = stream?.active ?? false
  trackCount.value = tracks.length
  videoTrackCount.value = videoTracks.length
  trackState.value = display(track?.readyState)
  facingMode.value = display(settings?.facingMode)
  trackResolution.value = settings?.width && settings?.height
    ? `${settings.width} × ${settings.height}`
    : '—'
  frameRate.value = settings?.frameRate ? `${settings.frameRate}` : '—'
  currentTimeLabel.value = video ? video.currentTime.toFixed(2) : '0.00'
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    refreshLiveDiagnostics()
  }, DIAGNOSTICS_INTERVAL_MS)
}

function startFrameCounter(): void {
  stopFrameCounter()
  lastObservedCurrentTime = -1

  const tick = (): void => {
    const video = videoRef.value

    if (!video || cameraState.value !== 'active') {
      frameAnimationId = null
      return
    }

    if (video.currentTime !== lastObservedCurrentTime) {
      lastObservedCurrentTime = video.currentTime
      framesSeen.value += 1
      currentTimeLabel.value = video.currentTime.toFixed(2)
    }

    frameAnimationId = requestAnimationFrame(tick)
  }

  frameAnimationId = requestAnimationFrame(tick)
}

async function waitForVideoReady(video: HTMLVideoElement, sessionId: number): Promise<boolean> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < START_TIMEOUT_MS) {
    if (!isSessionActive(sessionId)) {
      return false
    }

    if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
      return true
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return false
}

function cleanupFailedStart(stream: MediaStream, video: HTMLVideoElement | null): void {
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

function buildCapturedImageInfo(canvas: HTMLCanvasElement): CapturedImageInfo {
  const dataUrl = canvas.toDataURL('image/jpeg', 0.95)
  const aspectRatio = canvas.height > 0
    ? (canvas.width / canvas.height).toFixed(2)
    : '—'

  return {
    width: canvas.width,
    height: canvas.height,
    aspectRatio,
    dataUrl,
    dataUrlSizeKb: `${Math.round(dataUrl.length / 1024)} Ko`,
  }
}

function captureCurrentFrame(): CapturedImageInfo | null {
  const video = videoRef.value

  if (!video || !canCapture.value) {
    return null
  }

  const width = video.videoWidth
  const height = video.videoHeight

  if (!captureCanvas) {
    captureCanvas = document.createElement('canvas')
  }

  captureCanvas.width = width
  captureCanvas.height = height

  const context = captureCanvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(video, 0, 0, width, height)

  return buildCapturedImageInfo(captureCanvas)
}

async function tryStartWithConstraints(
  constraints: MediaStreamConstraints,
  mode: ConstraintMode,
  sessionId: number,
): Promise<MediaStream | null> {
  try {
    const stream = await navigator.mediaDevices.getUserMedia(constraints)

    if (!isSessionActive(sessionId)) {
      stopTracks(stream)
      return null
    }

    activeConstraintMode.value = mode

    return stream
  } catch (error) {
    if (mode === 'simple' && isSessionActive(sessionId)) {
      throw error
    }

    if (DEBUG) {
      console.info('[NativeCameraVisualTest] constrained start failed, fallback to simple', error)
    }

    return null
  }
}

async function activateStream(stream: MediaStream, sessionId: number): Promise<boolean> {
  activeStream.value = stream
  await nextTick()

  const video = videoRef.value

  if (!video) {
    cleanupFailedStart(stream, null)
    activeStream.value = null
    return false
  }

  video.srcObject = stream

  try {
    await video.play()
  } catch (playError) {
    cleanupFailedStart(stream, video)
    activeStream.value = null

    if (isSessionActive(sessionId)) {
      cameraState.value = 'error'
      cameraError.value = serializeCameraError(playError)
    }

    return false
  }

  if (!isSessionActive(sessionId)) {
    cleanupFailedStart(stream, video)
    activeStream.value = null
    return false
  }

  const ready = await waitForVideoReady(video, sessionId)

  if (!isSessionActive(sessionId)) {
    cleanupFailedStart(stream, video)
    activeStream.value = null
    return false
  }

  if (!ready) {
    cleanupFailedStart(stream, video)
    activeStream.value = null

    cameraState.value = 'error'
    cameraError.value = {
      name: 'TimeoutError',
      message: 'La caméra a été ouverte mais le flux vidéo n\'a pas fourni d\'image.',
      constraint: display(activeConstraintMode.value),
    }

    return false
  }

  cameraState.value = 'active'
  refreshLiveDiagnostics()
  startDiagnosticsPolling()
  startFrameCounter()

  return true
}

async function startCamera(): Promise<void> {
  if (!DEBUG || cameraState.value === 'starting' || cameraState.value === 'stopping') {
    return
  }

  await stopCamera()

  const sessionId = ++cameraSessionId
  cameraState.value = 'starting'
  cameraError.value = null
  activeConstraintMode.value = null
  capturedImage.value = null
  multiCaptureImages.value = []
  framesSeen.value = 0

  refreshEnvironmentDiagnostics()

  if (!secureContext.value) {
    cameraState.value = 'error'
    cameraError.value = {
      name: 'SecurityError',
      message: 'Contexte non sécurisé — HTTPS requis.',
      constraint: '—',
    }
    return
  }

  if (!getUserMediaSupported.value) {
    cameraState.value = 'error'
    cameraError.value = {
      name: 'TypeError',
      message: 'navigator.mediaDevices.getUserMedia indisponible.',
      constraint: '—',
    }
    return
  }

  try {
    let stream = await tryStartWithConstraints(FULL_CONSTRAINTS, 'full', sessionId)

    if (!stream) {
      stream = await tryStartWithConstraints(SIMPLE_CONSTRAINTS, 'simple', sessionId)
    }

    if (!stream) {
      cameraState.value = 'error'
      cameraError.value = {
        name: 'Error',
        message: 'Impossible d\'ouvrir la caméra avec les contraintes testées.',
        constraint: '—',
      }
      return
    }

    await activateStream(stream, sessionId)
  } catch (error) {
    if (isSessionActive(sessionId)) {
      cameraState.value = 'error'
      cameraError.value = serializeCameraError(error)

      if (DEBUG) {
        console.error('[NativeCameraVisualTest] start failed', cameraError.value)
      }
    }
  }
}

async function stopCamera(): Promise<void> {
  cameraSessionId += 1
  cameraState.value = 'stopping'

  stopFrameCounter()
  stopDiagnosticsPolling()

  const stream = activeStream.value
  const video = videoRef.value

  stopTracks(stream)
  activeStream.value = null
  activeConstraintMode.value = null

  if (video) {
    try {
      video.pause()
    } catch {
      // ignorer
    }

    video.srcObject = null
  }

  cameraState.value = 'idle'
  cameraError.value = null
  refreshLiveDiagnostics()
}

async function captureImage(): Promise<void> {
  if (!canCapture.value || captureRunning.value) {
    return
  }

  captureRunning.value = true

  try {
    const info = captureCurrentFrame()

    if (info) {
      capturedImage.value = info

      if (DEBUG) {
        console.info('[NativeCameraVisualTest] frame captured', {
          width: info.width,
          height: info.height,
        })
      }
    }
  } catch (error) {
    cameraError.value = serializeCameraError(error)
  } finally {
    captureRunning.value = false
  }
}

async function captureNewImage(): Promise<void> {
  await captureImage()
}

async function compareThreeCaptures(): Promise<void> {
  if (!canCapture.value || multiCaptureRunning.value) {
    return
  }

  multiCaptureRunning.value = true
  multiCaptureImages.value = []

  try {
    for (let index = 0; index < 3; index += 1) {
      const info = captureCurrentFrame()

      if (info) {
        multiCaptureImages.value = [...multiCaptureImages.value, info]
      }

      if (index < 2) {
        await new Promise((resolve) => window.setTimeout(resolve, 500))
      }
    }

    if (multiCaptureImages.value.length > 0) {
      capturedImage.value = multiCaptureImages.value[multiCaptureImages.value.length - 1] ?? null
    }
  } catch (error) {
    cameraError.value = serializeCameraError(error)
  } finally {
    multiCaptureRunning.value = false
  }
}

function openCapturedImage(): void {
  const dataUrl = capturedImage.value?.dataUrl

  if (!dataUrl) {
    return
  }

  window.open(dataUrl, '_blank', 'noopener,noreferrer')
}

function handlePageHide(): void {
  void stopCamera()
}

onMounted(() => {
  refreshEnvironmentDiagnostics()
  window.addEventListener('pagehide', handlePageHide)
})

onBeforeUnmount(() => {
  window.removeEventListener('pagehide', handlePageHide)
  void stopCamera()
})
</script>

<template>
  <Head title="DEV — Test caméra visuel" />

  <div class="barcode-reader-test-page native-camera-visual-test">
    <div class="barcode-reader-test-page__container native-camera-visual-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / TEST VISUEL UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">Test caméra visuel</h1>
          <p class="barcode-reader-test-page__intro mb-2">
            Pipeline : getUserMedia → MediaStream → video → canvas. Aucun moteur de lecture de code-barres.
          </p>
          <p class="native-camera-visual-test__notice mb-0">
            Ce test ne tente <strong>PAS</strong> de lire le code-barres.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
          Retour
        </Link>
      </header>

      <section class="native-camera-visual-test__section">
        <p class="native-camera-visual-test__instruction mb-0">
          Placez un EAN-13 réel devant la caméra, bien éclairé, puis capturez une image.
          Vérifiez visuellement si les barres verticales sont nettes et lisibles.
        </p>
      </section>

      <section class="native-camera-visual-test__section native-camera-visual-test__section--video">
        <div class="native-camera-visual-test__video-wrap">
          <video
            ref="videoRef"
            class="native-camera-visual-test__video"
            autoplay
            muted
            playsinline
          />
          <div class="native-camera-visual-test__overlay" aria-hidden="true">
            <div class="native-camera-visual-test__guide">
              Placez le code-barres ici
            </div>
          </div>
        </div>

        <div class="native-camera-visual-test__actions">
          <button
            type="button"
            class="btn btn-primary"
            :disabled="cameraState === 'starting' || cameraState === 'stopping' || cameraState === 'active'"
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
        </div>
      </section>

      <section class="native-camera-visual-test__section">
        <h2 class="native-camera-visual-test__section-title">Diagnostic caméra</h2>

        <dl class="native-camera-visual-test__grid">
          <div><dt>Camera</dt><dd>{{ cameraState }}</dd></div>
          <div><dt>Configuration</dt><dd>{{ constraintLabel }}</dd></div>
          <div><dt>Stream</dt><dd>{{ streamActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Video</dt><dd>{{ videoWidth > 0 && videoHeight > 0 ? `${videoWidth} × ${videoHeight}` : '—' }}</dd></div>
          <div><dt>ReadyState</dt><dd>{{ readyState }}</dd></div>
          <div><dt>Paused</dt><dd>{{ paused ? 'true' : 'false' }}</dd></div>
          <div><dt>CurrentTime</dt><dd>{{ currentTimeLabel }}</dd></div>
          <div><dt>Tracks</dt><dd>{{ trackCount }}</dd></div>
          <div><dt>Video tracks</dt><dd>{{ videoTrackCount }}</dd></div>
          <div><dt>Track state</dt><dd>{{ trackState }}</dd></div>
          <div><dt>Facing mode</dt><dd>{{ facingMode }}</dd></div>
          <div><dt>Track resolution</dt><dd>{{ trackResolution }}</dd></div>
          <div><dt>Frame rate</dt><dd>{{ frameRate }}</dd></div>
          <div><dt>Secure context</dt><dd>{{ secureContext ? 'true' : 'false' }}</dd></div>
          <div><dt>getUserMedia</dt><dd>{{ getUserMediaSupported ? 'supported' : 'unavailable' }}</dd></div>
          <div><dt>Frames observées</dt><dd>{{ framesSeen }}</dd></div>
        </dl>

        <div v-if="cameraError" class="native-camera-visual-test__error-block">
          <p class="native-camera-visual-test__error-title mb-2">Erreur caméra</p>
          <dl class="native-camera-visual-test__grid">
            <div><dt>Name</dt><dd>{{ cameraError.name }}</dd></div>
            <div><dt>Constraint</dt><dd>{{ cameraError.constraint }}</dd></div>
            <div class="native-camera-visual-test__grid-full">
              <dt>Message</dt>
              <dd class="native-camera-visual-test__break">{{ cameraError.message }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section class="native-camera-visual-test__section">
        <div class="native-camera-visual-test__actions">
          <button
            type="button"
            class="btn btn-outline-success"
            :disabled="!canCapture || captureRunning"
            @click="captureImage"
          >
            Capturer une image
          </button>
          <button
            type="button"
            class="btn btn-outline-success"
            :disabled="!canCapture || captureRunning"
            @click="captureNewImage"
          >
            Capturer une nouvelle image
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!canCapture || multiCaptureRunning"
            @click="compareThreeCaptures"
          >
            Comparer 3 captures
          </button>
        </div>
      </section>

      <section v-if="capturedImage" class="native-camera-visual-test__section">
        <h2 class="native-camera-visual-test__section-title">Image capturée</h2>

        <p class="native-camera-visual-test__frame-label mb-2">
          Frame capturée : {{ capturedImage.width }} × {{ capturedImage.height }}
        </p>

        <img
          :src="capturedImage.dataUrl"
          alt="Frame capturée depuis la caméra"
          class="native-camera-visual-test__captured-image"
        >

        <dl class="native-camera-visual-test__grid">
          <div><dt>Width</dt><dd>{{ capturedImage.width }}</dd></div>
          <div><dt>Height</dt><dd>{{ capturedImage.height }}</dd></div>
          <div><dt>Ratio</dt><dd>{{ capturedImage.aspectRatio }}</dd></div>
          <div><dt>Taille data URL</dt><dd>{{ capturedImage.dataUrlSizeKb }}</dd></div>
        </dl>

        <button
          type="button"
          class="btn btn-outline-secondary"
          @click="openCapturedImage"
        >
          Ouvrir l'image capturée
        </button>

        <div v-if="visualConclusion" class="native-camera-visual-test__conclusion">
          <p class="native-camera-visual-test__conclusion-title mb-1">TEST VISUEL</p>
          <p class="mb-1">{{ visualConclusion.title }}</p>
          <p class="mb-0 native-camera-visual-test__muted">{{ visualConclusion.detail }}</p>
        </div>
      </section>

      <section
        v-if="multiCaptureImages.length > 1"
        class="native-camera-visual-test__section"
      >
        <h2 class="native-camera-visual-test__section-title">Comparaison 3 captures</h2>

        <div class="native-camera-visual-test__multi-grid">
          <figure
            v-for="(image, index) in multiCaptureImages"
            :key="`${image.width}-${image.height}-${index}`"
            class="native-camera-visual-test__multi-item"
          >
            <img
              :src="image.dataUrl"
              :alt="`Capture ${index + 1}`"
              class="native-camera-visual-test__captured-image"
            >
            <figcaption>Capture {{ index + 1 }} — {{ image.width }} × {{ image.height }}</figcaption>
          </figure>
        </div>
      </section>
    </div>
  </div>
</template>
