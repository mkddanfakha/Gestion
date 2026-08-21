<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const DEBUG = import.meta.env.DEV

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

const VIDEO_EVENTS = [
  'loadedmetadata',
  'loadeddata',
  'canplay',
  'canplaythrough',
  'play',
  'playing',
  'pause',
  'waiting',
  'stalled',
  'suspend',
  'ended',
  'error',
  'resize',
  'timeupdate',
] as const

interface LogEntry {
  id: string
  timestamp: string
  message: string
}

interface VideoStateSnapshot {
  srcObject: 'YES' | 'NO'
  paused: boolean
  ended: boolean
  readyState: number
  networkState: number
  videoWidth: number
  videoHeight: number
  currentTime: string
  duration: string
  autoplay: boolean
  muted: boolean
  playsInline: boolean
}

interface StreamSnapshot {
  exists: 'YES' | 'NO'
  active: boolean
  id: string
  trackCount: number
  videoTrackCount: number
}

interface TrackSnapshot {
  kind: string
  label: string
  readyState: string
  enabled: boolean
  muted: boolean
  settings: string
  constraints: string
}

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const simpleTestStream = shallowRef<MediaStream | null>(null)

const logs = ref<LogEntry[]>([])
const browserDiagnostics = ref({
  secureContext: false,
  mediaDevices: 'unavailable' as 'available' | 'unavailable',
  getUserMedia: 'unavailable' as 'available' | 'unavailable',
  userAgent: '—',
  platform: '—',
})

const videoState = ref<VideoStateSnapshot>(emptyVideoState())
const streamState = ref<StreamSnapshot>(emptyStreamState())
const trackSnapshots = ref<TrackSnapshot[]>([])
const frameCounter = ref(0)
const previousCurrentTime = ref(-1)
const currentTimeEvolving = ref<'YES' | 'NO'>('NO')
const playResult = ref<string>('—')
const lastCameraError = ref<{ name: string; message: string; constraint: string } | null>(null)
const simpleTestResult = ref<string>('—')
const copyMessage = ref<string | null>(null)
const cameraStarting = ref(false)

let sessionId = 0
let pollTimer: number | null = null
let frameAnimationId: number | null = null
const videoEventHandlers = new Map<string, EventListener>()

function emptyVideoState(): VideoStateSnapshot {
  return {
    srcObject: 'NO',
    paused: true,
    ended: false,
    readyState: 0,
    networkState: 0,
    videoWidth: 0,
    videoHeight: 0,
    currentTime: '0.000',
    duration: 'NaN',
    autoplay: false,
    muted: false,
    playsInline: false,
  }
}

function emptyStreamState(): StreamSnapshot {
  return {
    exists: 'NO',
    active: false,
    id: '—',
    trackCount: 0,
    videoTrackCount: 0,
  }
}

function formatTimestamp(date = new Date()): string {
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  const seconds = String(date.getSeconds()).padStart(2, '0')
  const ms = String(date.getMilliseconds()).padStart(3, '0')

  return `${hours}:${minutes}:${seconds}.${ms}`
}

function appendLog(message: string): void {
  logs.value = [
    {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      timestamp: formatTimestamp(),
      message,
    },
    ...logs.value,
  ].slice(0, 200)
}

function displayJson(value: unknown): string {
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

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

function isSessionActive(currentSession: number): boolean {
  return currentSession === sessionId
}

function refreshBrowserDiagnostics(): void {
  const mediaDevicesAvailable = typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'

  browserDiagnostics.value = {
    secureContext: typeof window !== 'undefined' ? window.isSecureContext : false,
    mediaDevices: mediaDevicesAvailable ? 'available' : 'unavailable',
    getUserMedia: mediaDevicesAvailable && typeof navigator.mediaDevices.getUserMedia === 'function'
      ? 'available'
      : 'unavailable',
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '—',
    platform: typeof navigator !== 'undefined' ? navigator.platform : '—',
  }
}

function refreshStreamDiagnostics(stream: MediaStream | null): void {
  if (!stream) {
    streamState.value = emptyStreamState()
    trackSnapshots.value = []
    return
  }

  const tracks = stream.getTracks()
  const videoTracks = stream.getVideoTracks()

  streamState.value = {
    exists: 'YES',
    active: stream.active,
    id: stream.id || '—',
    trackCount: tracks.length,
    videoTrackCount: videoTracks.length,
  }

  trackSnapshots.value = tracks.map((track, index) => {
    const settings = track.getSettings?.() ?? {}
    const constraints = track.getConstraints?.() ?? {}

    return {
      kind: track.kind || '—',
      label: track.label || `Track #${index + 1}`,
      readyState: track.readyState || '—',
      enabled: track.enabled,
      muted: track.muted,
      settings: displayJson(settings),
      constraints: displayJson(constraints),
    }
  })
}

function refreshVideoState(): void {
  const video = videoRef.value
  const stream = activeStream.value

  if (!video) {
    videoState.value = emptyVideoState()
    refreshStreamDiagnostics(stream)
    return
  }

  videoState.value = {
    srcObject: video.srcObject ? 'YES' : 'NO',
    paused: video.paused,
    ended: video.ended,
    readyState: video.readyState,
    networkState: video.networkState,
    videoWidth: video.videoWidth,
    videoHeight: video.videoHeight,
    currentTime: video.currentTime.toFixed(3),
    duration: Number.isFinite(video.duration) ? video.duration.toFixed(3) : 'NaN',
    autoplay: video.autoplay,
    muted: video.muted,
    playsInline: video.playsInline,
  }

  refreshStreamDiagnostics(stream)
}

function startPolling(): void {
  stopPolling()
  pollTimer = window.setInterval(() => {
    refreshVideoState()
  }, 500)
}

function stopPolling(): void {
  if (pollTimer !== null) {
    window.clearInterval(pollTimer)
    pollTimer = null
  }
}

function startFrameCounter(): void {
  stopFrameCounter()
  previousCurrentTime.value = -1

  const tick = (): void => {
    const video = videoRef.value

    if (!video || !activeStream.value) {
      frameAnimationId = null
      return
    }

    if (video.currentTime !== previousCurrentTime.value) {
      previousCurrentTime.value = video.currentTime
      frameCounter.value += 1
      currentTimeEvolving.value = frameCounter.value > 1 ? 'YES' : 'NO'
    }

    frameAnimationId = requestAnimationFrame(tick)
  }

  frameAnimationId = requestAnimationFrame(tick)
}

function stopFrameCounter(): void {
  if (frameAnimationId !== null) {
    cancelAnimationFrame(frameAnimationId)
    frameAnimationId = null
  }
}

function attachVideoEventListeners(): void {
  const video = videoRef.value

  if (!video) {
    return
  }

  for (const eventName of VIDEO_EVENTS) {
    const handler = (event: Event): void => {
      if (eventName === 'timeupdate') {
        return
      }

      if (eventName === 'error') {
        const mediaError = video.error

        if (mediaError) {
          appendLog(`video event: error code=${mediaError.code} message=${mediaError.message || '—'}`)
        } else {
          appendLog('video event: error')
        }

        return
      }

      appendLog(`video event: ${eventName}`)
    }

    video.addEventListener(eventName, handler)
    videoEventHandlers.set(eventName, handler)
  }
}

function detachVideoEventListeners(): void {
  const video = videoRef.value

  if (!video) {
    videoEventHandlers.clear()
    return
  }

  for (const [eventName, handler] of videoEventHandlers.entries()) {
    video.removeEventListener(eventName, handler)
  }

  videoEventHandlers.clear()
}

function waitForVideoEvent(video: HTMLVideoElement, eventName: string, timeoutMs = 10000): Promise<void> {
  return new Promise((resolve, reject) => {
    const timeout = window.setTimeout(() => {
      video.removeEventListener(eventName, onEvent)
      reject(new Error(`Timeout en attente de l'événement ${eventName}`))
    }, timeoutMs)

    const onEvent = (): void => {
      window.clearTimeout(timeout)
      video.removeEventListener(eventName, onEvent)
      resolve()
    }

    if (eventName === 'loadedmetadata' && video.readyState >= 1) {
      window.clearTimeout(timeout)
      resolve()
      return
    }

    if (eventName === 'canplay' && video.readyState >= 3) {
      window.clearTimeout(timeout)
      resolve()
      return
    }

    video.addEventListener(eventName, onEvent)
  })
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

async function stopCamera(): Promise<void> {
  sessionId += 1
  stopPolling()
  stopFrameCounter()

  stopTracks(activeStream.value)
  stopTracks(simpleTestStream.value)
  activeStream.value = null
  simpleTestStream.value = null

  const video = videoRef.value

  if (video) {
    try {
      video.pause()
    } catch {
      // ignorer
    }

    video.srcObject = null
  }

  frameCounter.value = 0
  previousCurrentTime.value = -1
  currentTimeEvolving.value = 'NO'
  playResult.value = '—'
  refreshVideoState()

  appendLog('Caméra arrêtée — stream active=false, srcObject=null')
}

async function startCamera(): Promise<void> {
  if (!DEBUG || cameraStarting.value || activeStream.value) {
    if (activeStream.value) {
      appendLog('Un stream est déjà actif. Arrêtez-le avant d\'en demander un nouveau.')
    }

    return
  }

  await stopCamera()

  const currentSession = ++sessionId
  cameraStarting.value = true
  lastCameraError.value = null
  playResult.value = '—'
  frameCounter.value = 0
  previousCurrentTime.value = -1
  currentTimeEvolving.value = 'NO'

  refreshBrowserDiagnostics()

  try {
    appendLog('[1] Vérification navigator.mediaDevices')

    if (browserDiagnostics.value.mediaDevices !== 'available') {
      throw new Error('navigator.mediaDevices indisponible')
    }

    appendLog('[1] mediaDevices disponible')

    if (!isSessionActive(currentSession)) {
      return
    }

    appendLog('[2] Appel getUserMedia() START')

    const stream = await navigator.mediaDevices.getUserMedia(FULL_CONSTRAINTS)

    if (!isSessionActive(currentSession)) {
      stopTracks(stream)
      return
    }

    appendLog('[3] getUserMedia() SUCCESS')
    appendLog('[4] MediaStream obtenu')

    activeStream.value = stream
    await nextTick()

    const tracks = stream.getTracks()
    appendLog(`[5] Nombre de tracks = ${tracks.length}`)

    const videoTrack = stream.getVideoTracks()[0]
    appendLog(`[6] Video track = ${videoTrack?.readyState ?? '—'}`)

    const settings = videoTrack?.getSettings?.() ?? {}
    appendLog(`[7] Track settings = ${displayJson(settings)}`)

    const video = videoRef.value

    if (!video) {
      throw new Error('Élément video introuvable')
    }

    video.srcObject = stream
    appendLog('[8] Attribution video.srcObject')

    appendLog('[9] Appel video.play() START')

    try {
      await video.play()
      playResult.value = 'play() SUCCESS'
      appendLog('[10] video.play() SUCCESS')
    } catch (playError) {
      const serialized = serializeError(playError)
      playResult.value = `play() ERROR — ${serialized.name}: ${serialized.message}`
      appendLog(`[10] video.play() ERROR — ${serialized.name}: ${serialized.message}`)
      throw playError
    }

    if (!isSessionActive(currentSession)) {
      return
    }

    try {
      await waitForVideoEvent(video, 'loadedmetadata')
      appendLog('[11] loadedmetadata')
    } catch (error) {
      appendLog(`[11] loadedmetadata timeout — ${error instanceof Error ? error.message : String(error)}`)
    }

    appendLog(`[12] Dimensions vidéo = ${video.videoWidth}×${video.videoHeight}`)

    try {
      await waitForVideoEvent(video, 'canplay')
      appendLog('[13] canplay')
    } catch (error) {
      appendLog(`[13] canplay timeout — ${error instanceof Error ? error.message : String(error)}`)
    }

    appendLog('[14] Démarrage compteur frames')
    refreshVideoState()
    startPolling()
    startFrameCounter()
  } catch (error) {
    const serialized = serializeError(error)
    lastCameraError.value = serialized
    appendLog(`ERREUR — ${serialized.name}: ${serialized.message}`)

    if (activeStream.value) {
      stopTracks(activeStream.value)
      activeStream.value = null

      if (videoRef.value) {
        videoRef.value.srcObject = null
      }
    }
  } finally {
    cameraStarting.value = false
    refreshVideoState()
  }
}

async function forceVideoPlay(): Promise<void> {
  const video = videoRef.value

  if (!video) {
    appendLog('Forcer video.play() — élément video absent')
    return
  }

  appendLog('Forcer video.play() START')

  try {
    await video.play()
    playResult.value = 'play() SUCCESS'
    appendLog('play() SUCCESS')
  } catch (error) {
    const serialized = serializeError(error)
    playResult.value = `play() ERROR — ${serialized.name}: ${serialized.message}`
    appendLog(`play() ERROR — name=${serialized.name} message=${serialized.message}`)
  }

  refreshVideoState()
}

async function reconnectStream(): Promise<void> {
  const stream = activeStream.value
  const video = videoRef.value

  if (!stream || !video) {
    appendLog('Reconnecter le stream — stream ou video absent')
    return
  }

  appendLog('Reconnecter le stream — pause()')
  video.pause()

  appendLog('Reconnecter le stream — srcObject = null')
  video.srcObject = null

  await new Promise((resolve) => window.setTimeout(resolve, 150))

  appendLog('Reconnecter le stream — srcObject réassigné')
  video.srcObject = stream

  appendLog('Reconnecter le stream — video.play() START')

  try {
    await video.play()
    playResult.value = 'play() SUCCESS'
    appendLog('Reconnecter le stream — play() SUCCESS')
  } catch (error) {
    const serialized = serializeError(error)
    playResult.value = `play() ERROR — ${serialized.name}: ${serialized.message}`
    appendLog(`Reconnecter le stream — play() ERROR — ${serialized.name}: ${serialized.message}`)
  }

  refreshVideoState()
  startFrameCounter()
}

async function testSimpleCamera(): Promise<void> {
  if (activeStream.value || simpleTestStream.value) {
    appendLog('Simple getUserMedia — arrêtez le stream actuel avant d\'en demander un nouveau.')
    return
  }

  appendLog('Simple getUserMedia START')

  try {
    const stream = await navigator.mediaDevices.getUserMedia(SIMPLE_CONSTRAINTS)
    simpleTestStream.value = stream
    simpleTestResult.value = 'Simple getUserMedia SUCCESS'
    appendLog('Simple getUserMedia SUCCESS')

    stopTracks(stream)
    simpleTestStream.value = null
    appendLog('Simple getUserMedia — stream de test arrêté')
  } catch (error) {
    const serialized = serializeError(error)
    simpleTestResult.value = `Simple getUserMedia ERROR — ${serialized.name}: ${serialized.message}`
    lastCameraError.value = serialized
    appendLog(`Simple getUserMedia ERROR — name=${serialized.name} message=${serialized.message} constraint=${serialized.constraint}`)
  }
}

const conclusion = computed(() => {
  if (lastCameraError.value?.name === 'NotReadableError') {
    return [
      'CONCLUSION',
      '',
      'Le navigateur n\'arrive pas à démarrer une source vidéo.',
      '',
      'Voir le détail de NotReadableError ci-dessus.',
    ].join('\n')
  }

  const streamOk = streamState.value.exists === 'YES' && streamState.value.active
  const trackLive = trackSnapshots.value.some((track) => track.readyState === 'live')
  const hasDimensions = videoState.value.videoWidth > 0 && videoState.value.videoHeight > 0
  const timeMoves = currentTimeEvolving.value === 'YES'

  if (streamOk && trackLive && hasDimensions && timeMoves) {
    return [
      'CONCLUSION',
      '',
      'Le flux caméra fonctionne correctement.',
      '',
      'getUserMedia → MediaStream → video',
      '',
      'fonctionne sur cet appareil.',
    ].join('\n')
  }

  if (streamOk && trackLive && !hasDimensions) {
    return [
      'CONCLUSION',
      '',
      'Le MediaStream est actif mais le <video>',
      'ne reçoit pas correctement ses dimensions.',
    ].join('\n')
  }

  if (streamOk && !timeMoves && frameCounter.value === 0) {
    return [
      'CONCLUSION',
      '',
      'Le MediaStream existe mais les frames vidéo',
      'ne semblent pas progresser.',
    ].join('\n')
  }

  return 'En attente de démarrage caméra et de données vidéo...'
})

async function copyDiagnostic(): Promise<void> {
  const text = [
    'Native Camera Stream Diagnostic',
    '',
    `Secure context: ${browserDiagnostics.value.secureContext}`,
    `mediaDevices: ${browserDiagnostics.value.mediaDevices}`,
    `getUserMedia: ${browserDiagnostics.value.getUserMedia}`,
    `Browser: ${browserDiagnostics.value.userAgent}`,
    `Platform: ${browserDiagnostics.value.platform}`,
    '',
    `Stream: ${streamState.value.exists}`,
    `Stream active: ${streamState.value.active}`,
    `Tracks: ${streamState.value.trackCount}`,
    `Video tracks: ${streamState.value.videoTrackCount}`,
    `Track state: ${trackSnapshots.value.map((track) => track.readyState).join(', ') || '—'}`,
    `Track settings: ${trackSnapshots.value.map((track) => track.settings).join(' | ') || '—'}`,
    '',
    `Video srcObject: ${videoState.value.srcObject}`,
    `paused: ${videoState.value.paused}`,
    `ended: ${videoState.value.ended}`,
    `readyState: ${videoState.value.readyState}`,
    `videoWidth: ${videoState.value.videoWidth}`,
    `videoHeight: ${videoState.value.videoHeight}`,
    `currentTime: ${videoState.value.currentTime}`,
    `frames observed: ${frameCounter.value}`,
    `currentTime evolution: ${currentTimeEvolving.value}`,
    `play() result: ${playResult.value}`,
    '',
    'Events:',
    ...logs.value.slice(0, 30).map((entry) => `${entry.timestamp} ${entry.message}`),
    '',
    `Errors: ${lastCameraError.value ? `${lastCameraError.value.name}: ${lastCameraError.value.message}` : '—'}`,
    '',
    conclusion.value,
  ].join('\n')

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié.'
  } catch {
    copyMessage.value = 'Impossible de copier le diagnostic.'
  }
}

function handlePageHide(): void {
  void stopCamera()
}

onMounted(async () => {
  refreshBrowserDiagnostics()
  await nextTick()
  attachVideoEventListeners()
  window.addEventListener('pagehide', handlePageHide)
})

onBeforeUnmount(() => {
  detachVideoEventListeners()
  window.removeEventListener('pagehide', handlePageHide)
  void stopCamera()
})
</script>

<template>
  <Head title="DEV — Diagnostic flux caméra" />

  <div class="barcode-reader-test-page native-camera-stream-diagnostic">
    <div class="barcode-reader-test-page__container native-camera-stream-diagnostic__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / DIAGNOSTIC FLUX UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">Diagnostic flux caméra</h1>
          <p class="barcode-reader-test-page__intro mb-0">
            getUserMedia → MediaStream → video. Aucun scan, aucun canvas, aucune librairie externe.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
          Retour
        </Link>
      </header>

      <section class="native-camera-stream-diagnostic__section native-camera-stream-diagnostic__section--video">
        <div class="native-camera-stream-diagnostic__video-wrap">
          <video
            ref="videoRef"
            class="native-camera-stream-diagnostic__video"
            autoplay
            muted
            playsinline
          />
        </div>

        <div class="native-camera-stream-diagnostic__actions">
          <button type="button" class="btn btn-primary" :disabled="cameraStarting || !!activeStream" @click="startCamera">
            Démarrer caméra
          </button>
          <button type="button" class="btn btn-outline-secondary" :disabled="!activeStream" @click="stopCamera">
            Arrêter caméra
          </button>
          <button type="button" class="btn btn-outline-primary" :disabled="!activeStream" @click="forceVideoPlay">
            Forcer video.play()
          </button>
          <button type="button" class="btn btn-outline-primary" :disabled="!activeStream" @click="reconnectStream">
            Reconnecter le stream
          </button>
          <button type="button" class="btn btn-outline-secondary" :disabled="!!activeStream || !!simpleTestStream" @click="testSimpleCamera">
            Tester caméra simple
          </button>
          <button type="button" class="btn btn-outline-secondary" @click="copyDiagnostic">
            Copier diagnostic
          </button>
        </div>

        <p v-if="copyMessage" class="native-camera-stream-diagnostic__muted mb-0 mt-2">
          {{ copyMessage }}
        </p>
      </section>

      <section class="native-camera-stream-diagnostic__section">
        <h2 class="native-camera-stream-diagnostic__section-title">Browser diagnostics</h2>
        <dl class="native-camera-stream-diagnostic__grid">
          <div><dt>secure context</dt><dd>{{ browserDiagnostics.secureContext ? 'true' : 'false' }}</dd></div>
          <div><dt>mediaDevices</dt><dd>{{ browserDiagnostics.mediaDevices }}</dd></div>
          <div><dt>getUserMedia</dt><dd>{{ browserDiagnostics.getUserMedia }}</dd></div>
          <div><dt>platform</dt><dd>{{ browserDiagnostics.platform }}</dd></div>
          <div class="native-camera-stream-diagnostic__grid-full"><dt>userAgent</dt><dd class="native-camera-stream-diagnostic__break">{{ browserDiagnostics.userAgent }}</dd></div>
        </dl>
      </section>

      <section class="native-camera-stream-diagnostic__section">
        <h2 class="native-camera-stream-diagnostic__section-title">Video state</h2>
        <dl class="native-camera-stream-diagnostic__grid">
          <div><dt>srcObject</dt><dd>{{ videoState.srcObject }}</dd></div>
          <div><dt>paused</dt><dd>{{ videoState.paused ? 'true' : 'false' }}</dd></div>
          <div><dt>ended</dt><dd>{{ videoState.ended ? 'true' : 'false' }}</dd></div>
          <div><dt>readyState</dt><dd>{{ videoState.readyState }}</dd></div>
          <div><dt>networkState</dt><dd>{{ videoState.networkState }}</dd></div>
          <div><dt>videoWidth</dt><dd>{{ videoState.videoWidth }}</dd></div>
          <div><dt>videoHeight</dt><dd>{{ videoState.videoHeight }}</dd></div>
          <div><dt>currentTime</dt><dd>{{ videoState.currentTime }}</dd></div>
          <div><dt>duration</dt><dd>{{ videoState.duration }}</dd></div>
          <div><dt>autoplay</dt><dd>{{ videoState.autoplay ? 'true' : 'false' }}</dd></div>
          <div><dt>muted</dt><dd>{{ videoState.muted ? 'true' : 'false' }}</dd></div>
          <div><dt>playsInline</dt><dd>{{ videoState.playsInline ? 'true' : 'false' }}</dd></div>
          <div><dt>Frames observées</dt><dd>{{ frameCounter }}</dd></div>
          <div><dt>currentTime évolution</dt><dd>{{ currentTimeEvolving }}</dd></div>
          <div><dt>play() result</dt><dd>{{ playResult }}</dd></div>
          <div><dt>Simple test</dt><dd>{{ simpleTestResult }}</dd></div>
        </dl>
      </section>

      <section class="native-camera-stream-diagnostic__section">
        <h2 class="native-camera-stream-diagnostic__section-title">Stream</h2>
        <dl class="native-camera-stream-diagnostic__grid">
          <div><dt>exists</dt><dd>{{ streamState.exists }}</dd></div>
          <div><dt>active</dt><dd>{{ streamState.active ? 'true' : 'false' }}</dd></div>
          <div><dt>id</dt><dd>{{ streamState.id }}</dd></div>
          <div><dt>tracks</dt><dd>{{ streamState.trackCount }}</dd></div>
          <div><dt>video tracks</dt><dd>{{ streamState.videoTrackCount }}</dd></div>
        </dl>

        <template v-for="(track, index) in trackSnapshots" :key="`${track.label}-${index}`">
          <h3 class="native-camera-stream-diagnostic__track-title">Track #{{ index + 1 }}</h3>
          <dl class="native-camera-stream-diagnostic__grid">
            <div><dt>kind</dt><dd>{{ track.kind }}</dd></div>
            <div><dt>label</dt><dd>{{ track.label }}</dd></div>
            <div><dt>readyState</dt><dd>{{ track.readyState }}</dd></div>
            <div><dt>enabled</dt><dd>{{ track.enabled ? 'true' : 'false' }}</dd></div>
            <div><dt>muted</dt><dd>{{ track.muted ? 'true' : 'false' }}</dd></div>
            <div class="native-camera-stream-diagnostic__grid-full"><dt>settings</dt><dd class="native-camera-stream-diagnostic__break">{{ track.settings }}</dd></div>
            <div class="native-camera-stream-diagnostic__grid-full"><dt>constraints</dt><dd class="native-camera-stream-diagnostic__break">{{ track.constraints }}</dd></div>
          </dl>
        </template>
      </section>

      <section v-if="lastCameraError" class="native-camera-stream-diagnostic__section native-camera-stream-diagnostic__error">
        <h2 class="native-camera-stream-diagnostic__section-title">Erreur caméra</h2>
        <dl class="native-camera-stream-diagnostic__grid">
          <div><dt>name</dt><dd>{{ lastCameraError.name }}</dd></div>
          <div><dt>constraint</dt><dd>{{ lastCameraError.constraint }}</dd></div>
          <div class="native-camera-stream-diagnostic__grid-full"><dt>message</dt><dd class="native-camera-stream-diagnostic__break">{{ lastCameraError.message }}</dd></div>
        </dl>
      </section>

      <section class="native-camera-stream-diagnostic__section">
        <h2 class="native-camera-stream-diagnostic__section-title">Journal</h2>
        <div class="native-camera-stream-diagnostic__log">
          <div v-for="entry in logs" :key="entry.id" class="native-camera-stream-diagnostic__log-line">
            <span class="native-camera-stream-diagnostic__log-time">{{ entry.timestamp }}</span>
            <span>{{ entry.message }}</span>
          </div>
        </div>
      </section>

      <section class="native-camera-stream-diagnostic__section native-camera-stream-diagnostic__conclusion">
        <h2 class="native-camera-stream-diagnostic__section-title">Conclusion</h2>
        <pre class="native-camera-stream-diagnostic__pre mb-0">{{ conclusion }}</pre>
      </section>
    </div>
  </div>
</template>
