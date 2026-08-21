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

interface BasicDiagnostics {
  secureContext: boolean
  mediaDevices: boolean
  getUserMedia: boolean
  userAgent: string
  protocol: string
  host: string
  visibilityState: string
}

interface LiveDiagnostics {
  cameraState: CameraState
  videoWidth: number
  videoHeight: number
  readyState: number
  paused: boolean
  streamActive: boolean
  trackCount: number
  videoTrackCount: number
  trackState: string
  facingMode: string
  observedFrames: number
  currentTime: string
  resolution: string
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
const cameraError = ref<CameraErrorDetails | null>(null)
const basicDiagnostics = ref<BasicDiagnostics>(createBasicDiagnostics())
const liveDiagnostics = ref<LiveDiagnostics>(createEmptyLiveDiagnostics())

let cameraSessionId = 0
let diagnosticsTimer: number | null = null
let frameAnimationId: number | null = null
let lastObservedCurrentTime = -1

const secureContextLabel = computed(() => basicDiagnostics.value.secureContext ? 'YES' : 'NO')
const mediaDevicesLabel = computed(() => basicDiagnostics.value.mediaDevices ? 'YES' : 'NO')
const getUserMediaLabel = computed(() => basicDiagnostics.value.getUserMedia ? 'YES' : 'NO')
const browserLabel = computed(() => inferBrowserLabel(basicDiagnostics.value.userAgent))

function createBasicDiagnostics(): BasicDiagnostics {
  const mediaDevicesAvailable = typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'

  return {
    secureContext: typeof window !== 'undefined' ? window.isSecureContext : false,
    mediaDevices: mediaDevicesAvailable,
    getUserMedia: mediaDevicesAvailable
      && typeof navigator.mediaDevices.getUserMedia === 'function',
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '—',
    protocol: typeof location !== 'undefined' ? location.protocol : '—',
    host: typeof location !== 'undefined' ? location.host : '—',
    visibilityState: typeof document !== 'undefined' ? document.visibilityState : '—',
  }
}

function createEmptyLiveDiagnostics(): LiveDiagnostics {
  return {
    cameraState: 'idle',
    videoWidth: 0,
    videoHeight: 0,
    readyState: 0,
    paused: true,
    streamActive: false,
    trackCount: 0,
    videoTrackCount: 0,
    trackState: '—',
    facingMode: '—',
    observedFrames: 0,
    currentTime: '0.00',
    resolution: '—',
  }
}

function inferBrowserLabel(userAgent: string): string {
  if (/Edg\//.test(userAgent)) return 'Microsoft Edge'
  if (/OPR\//.test(userAgent) || /Opera/.test(userAgent)) return 'Opera'
  if (/SamsungBrowser/.test(userAgent)) return 'Samsung Internet'
  if (/CriOS/.test(userAgent)) return 'Chrome iOS'
  if (/Chrome\//.test(userAgent) && !/Edg\//.test(userAgent)) return 'Chrome'
  if (/FxiOS/.test(userAgent)) return 'Firefox iOS'
  if (/Firefox\//.test(userAgent)) return 'Firefox'
  if (/Safari/.test(userAgent) && !/Chrome/.test(userAgent)) return 'Safari'
  return 'Navigateur inconnu'
}

function display(value: unknown): string {
  if (value == null || value === '') return '—'
  return String(value)
}

async function runBasicCameraDiagnostics(): Promise<void> {
  basicDiagnostics.value = createBasicDiagnostics()

  if (DEBUG) {
    console.info('[NativeCameraMinimalTest] basic diagnostics', basicDiagnostics.value)
  }
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

function mapCameraErrorMessage(error: unknown): string {
  if (!(error instanceof DOMException)) {
    return error instanceof Error ? error.message : String(error)
  }

  switch (error.name) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
      return 'Permission caméra refusée.'
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return 'Aucune caméra disponible.'
    case 'NotReadableError':
    case 'TrackStartError':
      return 'Caméra indisponible ou déjà utilisée.'
    case 'OverconstrainedError':
    case 'ConstraintNotSatisfiedError':
      return 'Contraintes caméra non satisfaites.'
    case 'SecurityError':
      return 'Accès caméra bloqué (contexte non sécurisé).'
    case 'AbortError':
      return 'Démarrage caméra interrompu.'
    case 'TypeError':
      return 'API getUserMedia indisponible ou mal configurée.'
    default:
      return error.message || 'Erreur caméra inconnue.'
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

  liveDiagnostics.value = {
    cameraState: cameraState.value,
    videoWidth: video?.videoWidth ?? 0,
    videoHeight: video?.videoHeight ?? 0,
    readyState: video?.readyState ?? 0,
    paused: video?.paused ?? true,
    streamActive: stream?.active ?? false,
    trackCount: tracks.length,
    videoTrackCount: videoTracks.length,
    trackState: display(track?.readyState),
    facingMode: display(settings?.facingMode),
    observedFrames: liveDiagnostics.value.observedFrames,
    currentTime: video ? video.currentTime.toFixed(2) : '0.00',
    resolution: video && video.videoWidth > 0 && video.videoHeight > 0
      ? `${video.videoWidth} × ${video.videoHeight}`
      : '—',
  }
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
      liveDiagnostics.value = {
        ...liveDiagnostics.value,
        observedFrames: liveDiagnostics.value.observedFrames + 1,
        currentTime: video.currentTime.toFixed(2),
      }
    }

    frameAnimationId = requestAnimationFrame(tick)
  }

  frameAnimationId = requestAnimationFrame(tick)
}

async function waitForVideoReady(
  video: HTMLVideoElement,
  sessionId: number,
): Promise<boolean> {
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

async function startCameraWithConstraints(
  constraints: MediaStreamConstraints,
  mode: ConstraintMode,
): Promise<void> {
  if (!DEBUG) {
    return
  }

  if (cameraState.value === 'starting' || cameraState.value === 'stopping') {
    return
  }

  await stopCamera()

  const sessionId = ++cameraSessionId
  cameraState.value = 'starting'
  cameraError.value = null
  liveDiagnostics.value = {
    ...createEmptyLiveDiagnostics(),
    cameraState: 'starting',
  }

  await runBasicCameraDiagnostics()

  if (!isSessionActive(sessionId)) {
    return
  }

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
    stream = await navigator.mediaDevices.getUserMedia(constraints)

    if (!isSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      cleanupFailedStart(stream, null)
      activeStream.value = null

      if (isSessionActive(sessionId)) {
        cameraState.value = 'error'
        cameraError.value = {
          name: 'Error',
          message: 'Élément vidéo introuvable.',
          constraint: '—',
        }
      }

      return
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

      return
    }

    if (!isSessionActive(sessionId)) {
      cleanupFailedStart(stream, video)
      activeStream.value = null
      return
    }

    const ready = await waitForVideoReady(video, sessionId)

    if (!isSessionActive(sessionId)) {
      cleanupFailedStart(stream, video)
      activeStream.value = null
      return
    }

    if (!ready) {
      cleanupFailedStart(stream, video)
      activeStream.value = null
      cameraState.value = 'error'
      cameraError.value = {
        name: 'TimeoutError',
        message: 'La caméra a été ouverte mais le flux vidéo n\'a pas fourni d\'image.',
        constraint: mode === 'full' ? 'width/height/facingMode' : '—',
      }

      if (DEBUG) {
        console.error('[NativeCameraMinimalTest] video ready timeout', {
          videoWidth: video.videoWidth,
          videoHeight: video.videoHeight,
          readyState: video.readyState,
        })
      }

      return
    }

    cameraState.value = 'active'
    refreshLiveDiagnostics()
    startDiagnosticsPolling()
    startFrameCounter()

    if (DEBUG) {
      console.info('[NativeCameraMinimalTest] camera started', {
        mode,
        resolution: `${video.videoWidth}×${video.videoHeight}`,
        readyState: video.readyState,
      })
    }
  } catch (error) {
    if (stream) {
      stopTracks(stream)
      activeStream.value = null

      const video = videoRef.value

      if (video) {
        try {
          video.pause()
        } catch {
          // ignorer
        }

        video.srcObject = null
      }
    }

    if (isSessionActive(sessionId)) {
      cameraState.value = 'error'
      cameraError.value = serializeCameraError(error)

      if (DEBUG) {
        console.error('[NativeCameraMinimalTest] getUserMedia failed', {
          ...cameraError.value,
          mapped: mapCameraErrorMessage(error),
        })
      }
    }
  }
}

async function startCamera(): Promise<void> {
  await startCameraWithConstraints(FULL_CONSTRAINTS, 'full')
}

async function startSimpleCamera(): Promise<void> {
  await startCameraWithConstraints(SIMPLE_CONSTRAINTS, 'simple')
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
  liveDiagnostics.value = createEmptyLiveDiagnostics()

  if (DEBUG) {
    console.info('[NativeCameraMinimalTest] camera stopped')
  }
}

function handlePageHide(): void {
  void stopCamera()
}

onMounted(() => {
  void runBasicCameraDiagnostics()
  window.addEventListener('pagehide', handlePageHide)
})

onBeforeUnmount(() => {
  window.removeEventListener('pagehide', handlePageHide)
  void stopCamera()
})
</script>

<template>
  <Head title="DEV — Caméra native minimale" />

  <div class="barcode-reader-test-page native-camera-minimal-test">
    <div class="barcode-reader-test-page__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / DIAGNOSTIC UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">Test caméra natif minimal</h1>
          <p class="barcode-reader-test-page__intro mb-0">
            getUserMedia → MediaStream → video. Sans ZXing, BarcodeDetector ni vue-qrcode-reader.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
          Retour
        </Link>
      </header>

      <section class="native-camera-minimal-test__section">
        <h2 class="native-camera-minimal-test__section-title">Diagnostic environnement</h2>

        <dl class="native-camera-minimal-test__grid">
          <div><dt>Secure Context</dt><dd>{{ secureContextLabel }}</dd></div>
          <div><dt>Protocol</dt><dd>{{ basicDiagnostics.protocol }}</dd></div>
          <div><dt>Host</dt><dd>{{ basicDiagnostics.host }}</dd></div>
          <div><dt>Navigateur</dt><dd>{{ browserLabel }}</dd></div>
          <div><dt>mediaDevices</dt><dd>{{ mediaDevicesLabel }}</dd></div>
          <div><dt>getUserMedia</dt><dd>{{ getUserMediaLabel }}</dd></div>
          <div><dt>visibilityState</dt><dd>{{ basicDiagnostics.visibilityState }}</dd></div>
          <div class="native-camera-minimal-test__grid-full">
            <dt>User agent</dt>
            <dd class="native-camera-minimal-test__break">{{ basicDiagnostics.userAgent }}</dd>
          </div>
        </dl>
      </section>

      <section class="native-camera-minimal-test__section">
        <h2 class="native-camera-minimal-test__section-title">État caméra</h2>

        <dl class="native-camera-minimal-test__grid">
          <div><dt>Camera</dt><dd>{{ liveDiagnostics.cameraState }}</dd></div>
          <div><dt>Video</dt><dd>{{ liveDiagnostics.resolution }}</dd></div>
          <div><dt>ReadyState</dt><dd>{{ liveDiagnostics.readyState }}</dd></div>
          <div><dt>Paused</dt><dd>{{ liveDiagnostics.paused ? 'true' : 'false' }}</dd></div>
          <div><dt>Stream</dt><dd>{{ liveDiagnostics.streamActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Tracks</dt><dd>{{ liveDiagnostics.trackCount }}</dd></div>
          <div><dt>Video tracks</dt><dd>{{ liveDiagnostics.videoTrackCount }}</dd></div>
          <div><dt>Track state</dt><dd>{{ liveDiagnostics.trackState }}</dd></div>
          <div><dt>Facing mode</dt><dd>{{ liveDiagnostics.facingMode }}</dd></div>
          <div><dt>Frames observées</dt><dd>{{ liveDiagnostics.observedFrames }}</dd></div>
          <div><dt>CurrentTime</dt><dd>{{ liveDiagnostics.currentTime }}</dd></div>
          <div><dt>videoWidth</dt><dd>{{ liveDiagnostics.videoWidth }}</dd></div>
          <div><dt>videoHeight</dt><dd>{{ liveDiagnostics.videoHeight }}</dd></div>
        </dl>

        <div v-if="cameraError" class="native-camera-minimal-test__error-block">
          <p class="native-camera-minimal-test__error-title mb-2">Erreur caméra</p>
          <dl class="native-camera-minimal-test__grid">
            <div><dt>Name</dt><dd>{{ cameraError.name }}</dd></div>
            <div><dt>Constraint</dt><dd>{{ cameraError.constraint }}</dd></div>
            <div class="native-camera-minimal-test__grid-full">
              <dt>Message</dt>
              <dd class="native-camera-minimal-test__break">{{ cameraError.message }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section class="native-camera-minimal-test__section">
        <div class="native-camera-minimal-test__video-wrap">
          <video
            ref="videoRef"
            class="native-camera-minimal-test__video"
            autoplay
            muted
            playsinline
          />
        </div>

        <div class="native-camera-minimal-test__actions">
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
            class="btn btn-outline-primary"
            :disabled="cameraState === 'starting' || cameraState === 'stopping' || cameraState === 'active'"
            @click="startSimpleCamera"
          >
            Test caméra simple
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
    </div>
  </div>
</template>
