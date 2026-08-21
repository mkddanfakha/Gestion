<script setup lang="ts">
import {
  buildComparisonTableRows,
  buildProfileComparisonEntries,
  buildProfileConstraints,
  buildResolutionConclusion,
  buildResolutionDiagnosticClipboard,
  computeAverageDetectionMs,
  computeErrorRate,
  computeNotFoundRate,
  computeSuccessRate,
  createEmptyDetectionStats,
  createInitialProfileCodeAggregates,
  createNativeBarcodeDetector,
  DETECTION_INTERVAL_MS,
  getEnvironmentDiagnostics,
  getProfileDefinition,
  hasSufficientTestData,
  formatNativeBarcodeFormat,
  MAX_SUCCESS_HISTORY,
  pickBestNativeBarcode,
  readActualTrackDetails,
  readCameraTrackDiagnostics,
  REFERENCE_EAN_FORMAT,
  REFERENCE_EAN_VALUE,
  RESOLUTION_PROFILES,
  SMALL_EAN_TEST_LABEL,
  STANDARD_EAN_TEST_LABEL,
  summarizeRequestedConstraints,
  updateDetectionDurationStats,
  type BarcodeDetectorLike,
  type CodeTestCategory,
  type DetectionStats,
  type ProfileCodeAggregate,
  type ResolutionProfileId,
  type SuccessHistoryEntry,
} from '@/utils/barcodeDetectorResolutionTest'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const DEBUG = import.meta.env.DEV
const START_TIMEOUT_MS = 10_000

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const supportedFormats = ref<string[]>([])
const activeProfileId = ref<ResolutionProfileId | null>(null)
const activeTestCategory = ref<CodeTestCategory>('small-ean')
const cameraState = ref<'idle' | 'starting' | 'active' | 'stopping' | 'error'>('idle')
const detectionLoopState = ref<'stopped' | 'running'>('stopped')
const cameraError = ref<{ name: string; message: string; constraint: string } | null>(null)
const copyMessage = ref<string | null>(null)
const smallEanProfileHint = ref<ResolutionProfileId>('1280x720')
const standardEanProfileHint = ref<ResolutionProfileId>('1280x720')

const videoWidth = ref(0)
const videoHeight = ref(0)
const readyState = ref(0)
const currentTime = ref(0)
const streamActive = ref(false)
const trackDiagnostics = ref(readCameraTrackDiagnostics(null))
const actualTrackDetails = ref(readActualTrackDetails(null))
const lastObservedCurrentTimeForFlow = ref(0)
const currentTimeProgressing = ref(true)

const stats = ref<DetectionStats>(createEmptyDetectionStats())
const profileCodeAggregates = ref<ProfileCodeAggregate[]>(createInitialProfileCodeAggregates())
const successHistory = ref<SuccessHistoryEntry[]>([])
const lastSuccess = ref<SuccessHistoryEntry | null>(null)
const capturedFrameUrl = ref<string | null>(null)
const capturedFrameMeta = ref<{ width: number; height: number; mimeType: string; sizeBytes: number } | null>(null)

let cameraSessionId = 0
let detectionSessionId = 0
let diagnosticsTimer: number | null = null
let frameAnimationId: number | null = null
let detectionLoopAnimationId: number | null = null
let detectionInProgress = false
let lastDetectionTime = 0
let lastObservedCurrentTime = -1
const completedDetectionDurations: number[] = []
const aggregateDetectionDurations = new Map<string, number[]>()
const aggregateSuccessIntervals = new Map<string, number[]>()
const lastSuccessAtByAggregate = new Map<string, number>()

const activeProfileLabel = computed(() => {
  return activeProfileId.value ? getProfileDefinition(activeProfileId.value).label : '—'
})

const activeTestLabel = computed(() => {
  return activeTestCategory.value === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL
})

const requestedConstraints = computed(() => {
  if (!activeProfileId.value) {
    return { facingMode: '—', width: 0, height: 0 }
  }

  return summarizeRequestedConstraints(activeProfileId.value)
})

const comparisonTableRows = computed(() => buildComparisonTableRows(profileCodeAggregates.value))
const profileComparisonEntries = computed(() => buildProfileComparisonEntries(profileCodeAggregates.value))

const videoFlowWarning = computed(() => {
  if (cameraState.value !== 'active') {
    return null
  }

  if (videoWidth.value === 0 || videoHeight.value === 0) {
    return 'videoWidth ou videoHeight vaut 0 — le problème vient probablement du flux vidéo plutôt que de BarcodeDetector.'
  }

  if (!currentTimeProgressing.value) {
    return 'currentTime ne progresse pas — le flux vidéo semble figé.'
  }

  return null
})

const conclusion = computed(() => {
  return buildResolutionConclusion({
    aggregates: profileCodeAggregates.value,
    activeActualResolution: trackDiagnostics.value.resolution,
    activeRequestedResolution: requestedConstraints.value.width > 0
      ? `${requestedConstraints.value.width}×${requestedConstraints.value.height}`
      : '—',
  })
})

const canStartDetection = computed(() => {
  return cameraState.value === 'active'
    && environment.value.barcodeDetectorAvailable
    && detectorRef.value != null
    && videoWidth.value > 0
    && videoHeight.value > 0
    && currentTimeProgressing.value
})

function aggregateKey(profileId: ResolutionProfileId, codeCategory: CodeTestCategory): string {
  return `${profileId}:${codeCategory}`
}

function getAggregate(profileId: ResolutionProfileId, codeCategory: CodeTestCategory): ProfileCodeAggregate | undefined {
  return profileCodeAggregates.value.find(
    (item) => item.profileId === profileId && item.codeCategory === codeCategory,
  )
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

function stopDetectionLoopAnimation(): void {
  if (detectionLoopAnimationId !== null) {
    cancelAnimationFrame(detectionLoopAnimationId)
    detectionLoopAnimationId = null
  }
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

function refreshDiagnostics(): void {
  const video = videoRef.value

  videoWidth.value = video?.videoWidth ?? 0
  videoHeight.value = video?.videoHeight ?? 0
  readyState.value = video?.readyState ?? 0
  currentTime.value = video?.currentTime ?? 0
  streamActive.value = activeStream.value?.active ?? false
  trackDiagnostics.value = readCameraTrackDiagnostics(activeStream.value)
  actualTrackDetails.value = readActualTrackDetails(activeStream.value)

  if (video && cameraState.value === 'active') {
    currentTimeProgressing.value = video.currentTime > lastObservedCurrentTimeForFlow.value
    lastObservedCurrentTimeForFlow.value = video.currentTime
  }
}

function updateTestDurations(): void {
  const now = Date.now()

  if (stats.value.testStartedAt != null) {
    stats.value = {
      ...stats.value,
      testDurationMs: now - stats.value.testStartedAt,
    }
  }

  if (!activeProfileId.value) {
    return
  }

  const key = aggregateKey(activeProfileId.value, activeTestCategory.value)
  const aggregate = getAggregate(activeProfileId.value, activeTestCategory.value)

  if (!aggregate || aggregate.testStartedAt == null) {
    return
  }

  profileCodeAggregates.value = profileCodeAggregates.value.map((item) => {
    if (aggregateKey(item.profileId, item.codeCategory) !== key) {
      return item
    }

    return {
      ...item,
      testDurationMs: now - item.testStartedAt!,
    }
  })
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    refreshDiagnostics()
    updateTestDurations()
  }, 500)
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
      stats.value = {
        ...stats.value,
        framesSeen: stats.value.framesSeen + 1,
      }

      if (activeProfileId.value) {
        const key = aggregateKey(activeProfileId.value, activeTestCategory.value)
        profileCodeAggregates.value = profileCodeAggregates.value.map((item) => {
          if (aggregateKey(item.profileId, item.codeCategory) !== key) {
            return item
          }

          return {
            ...item,
            framesSeen: item.framesSeen + 1,
          }
        })
      }
    }

    frameAnimationId = requestAnimationFrame(tick)
  }

  frameAnimationId = requestAnimationFrame(tick)
}

async function waitForVideoReady(video: HTMLVideoElement, sessionId: number): Promise<boolean> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < START_TIMEOUT_MS) {
    if (!isCameraSessionActive(sessionId)) {
      return false
    }

    if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && video.videoWidth > 0 && video.videoHeight > 0) {
      return true
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return false
}

async function ensureDetector(): Promise<boolean> {
  if (detectorRef.value) {
    return true
  }

  if (!environment.value.barcodeDetectorAvailable) {
    cameraError.value = {
      name: 'BarcodeDetectorUnavailable',
      message: 'BarcodeDetector natif non disponible sur ce navigateur.',
      constraint: '—',
    }

    return false
  }

  try {
    const created = await createNativeBarcodeDetector()
    detectorRef.value = created.detector
    supportedFormats.value = created.formatsUsed.length > 0
      ? created.formatsUsed
      : ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39']

    return true
  } catch (error) {
    cameraError.value = serializeError(error)
    return false
  }
}

function markTestStarted(profileId: ResolutionProfileId, codeCategory: CodeTestCategory): void {
  const now = Date.now()
  stats.value = {
    ...stats.value,
    testStartedAt: now,
    testDurationMs: 0,
  }

  profileCodeAggregates.value = profileCodeAggregates.value.map((item) => {
    if (item.profileId !== profileId || item.codeCategory !== codeCategory) {
      return item
    }

    return {
      ...item,
      testStartedAt: item.testStartedAt ?? now,
      testDurationMs: item.testStartedAt ? now - item.testStartedAt : 0,
    }
  })
}

function updateActiveAggregate(options: {
  incrementAttempts?: boolean
  incrementSuccess?: boolean
  incrementNotFound?: boolean
  incrementErrors?: boolean
  durationMs?: number
  successAt?: number
}): void {
  if (!activeProfileId.value) {
    return
  }

  const profileId = activeProfileId.value
  const codeCategory = activeTestCategory.value
  const key = aggregateKey(profileId, codeCategory)

  profileCodeAggregates.value = profileCodeAggregates.value.map((aggregate) => {
    if (aggregate.profileId !== profileId || aggregate.codeCategory !== codeCategory) {
      return aggregate
    }

    const next = { ...aggregate }

    if (options.incrementAttempts) {
      next.detectionAttempts += 1
    }

    if (options.incrementSuccess) {
      next.successfulDetections += 1
    }

    if (options.incrementNotFound) {
      next.notFound += 1
    }

    if (options.incrementErrors) {
      next.errors += 1
    }

    if (options.durationMs != null) {
      const durations = [...(aggregateDetectionDurations.get(key) ?? []), options.durationMs]
      aggregateDetectionDurations.set(key, durations)
      next.lastDetectionMs = options.durationMs
      next.averageDetectionMs = computeAverageDetectionMs(durations)
      next.minDetectionMs = Math.min(...durations)
      next.maxDetectionMs = Math.max(...durations)
    }

    if (options.successAt != null) {
      const previousSuccessAt = lastSuccessAtByAggregate.get(key)

      if (previousSuccessAt != null) {
        const intervalMs = options.successAt - previousSuccessAt
        const intervals = [...(aggregateSuccessIntervals.get(key) ?? []), intervalMs]
        aggregateSuccessIntervals.set(key, intervals)
        next.averageSuccessIntervalMs = computeAverageDetectionMs(intervals)
        next.minSuccessIntervalMs = Math.min(...intervals)
        next.maxSuccessIntervalMs = Math.max(...intervals)
      }

      lastSuccessAtByAggregate.set(key, options.successAt)
    }

    if (trackDiagnostics.value.resolution !== '—') {
      next.actualResolution = trackDiagnostics.value.resolution
    }

    return next
  })
}

async function stopCamera(): Promise<void> {
  stopDetectionLoop()
  cameraSessionId += 1
  cameraState.value = 'stopping'

  const stream = activeStream.value
  stopTracks(stream)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  stopFrameCounter()
  stopDiagnosticsPolling()
  refreshDiagnostics()
  cameraState.value = 'idle'
}

async function startProfile(profileId: ResolutionProfileId): Promise<void> {
  if (!DEBUG) {
    return
  }

  cameraError.value = null
  await stopCamera()

  if (!(await ensureDetector())) {
    cameraState.value = 'error'
    return
  }

  const sessionId = ++cameraSessionId
  activeProfileId.value = profileId
  cameraState.value = 'starting'

  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0
  lastSuccess.value = null
  lastObservedCurrentTimeForFlow.value = 0
  currentTimeProgressing.value = true

  try {
    const stream = await navigator.mediaDevices.getUserMedia(buildProfileConstraints(profileId))

    if (!isCameraSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo indisponible.')
    }

    video.srcObject = stream
    video.muted = true
    video.playsInline = true

    await new Promise<void>((resolve) => {
      const onReady = (): void => {
        video.removeEventListener('loadedmetadata', onReady)
        video.removeEventListener('canplay', onReady)
        resolve()
      }

      video.addEventListener('loadedmetadata', onReady)
      video.addEventListener('canplay', onReady)

      if (video.readyState >= HTMLMediaElement.HAVE_METADATA) {
        onReady()
      }
    })

    await video.play()

    const ready = await waitForVideoReady(video, sessionId)

    if (!ready) {
      throw new Error('La vidéo n\'a pas atteint un état prêt dans le délai imparti.')
    }

    if (!isCameraSessionActive(sessionId)) {
      return
    }

    refreshDiagnostics()
    startDiagnosticsPolling()
    startFrameCounter()
    cameraState.value = 'active'
  } catch (error) {
    if (!isCameraSessionActive(sessionId)) {
      return
    }

    cameraError.value = serializeError(error)
    cameraState.value = 'error'
    stopTracks(activeStream.value)
    activeStream.value = null
  }
}

function recordSuccess(rawValue: string, format: string, durationMs: number): void {
  const entry: SuccessHistoryEntry = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }),
    profileLabel: activeProfileLabel.value,
    rawValue,
    format,
    resolution: trackDiagnostics.value.resolution,
    durationMs,
    codeCategory: activeTestCategory.value,
  }

  lastSuccess.value = entry
  successHistory.value = [entry, ...successHistory.value].slice(0, MAX_SUCCESS_HISTORY)
}

async function runDetectionAttempt(): Promise<void> {
  const detector = detectorRef.value
  const video = videoRef.value

  if (!detector || !video || detectionInProgress || !activeProfileId.value) {
    return
  }

  if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || video.videoWidth <= 0 || video.videoHeight <= 0) {
    return
  }

  detectionInProgress = true
  stats.value = {
    ...stats.value,
    detectionAttempts: stats.value.detectionAttempts + 1,
  }
  updateActiveAggregate({ incrementAttempts: true })

  const startedAt = performance.now()

  try {
    const barcodes = await detector.detect(video)
    const durationMs = Math.round(performance.now() - startedAt)
    const best = pickBestNativeBarcode(barcodes)

    if (best?.rawValue?.trim()) {
      stats.value = {
        ...updateDetectionDurationStats(stats.value, completedDetectionDurations, durationMs),
        successfulDetections: stats.value.successfulDetections + 1,
      }
      completedDetectionDurations.push(durationMs)
      updateActiveAggregate({
        incrementSuccess: true,
        durationMs,
        successAt: Date.now(),
      })
      recordSuccess(best.rawValue.trim(), formatNativeBarcodeFormat(best.format), durationMs)
    } else {
      stats.value = {
        ...stats.value,
        notFound: stats.value.notFound + 1,
      }
      updateActiveAggregate({ incrementNotFound: true })
    }
  } catch (error) {
    stats.value = {
      ...stats.value,
      errors: stats.value.errors + 1,
    }
    updateActiveAggregate({ incrementErrors: true })
    cameraError.value = serializeError(error)
  } finally {
    detectionInProgress = false
    updateTestDurations()
  }
}

function startDetectionLoop(): void {
  if (!canStartDetection.value || detectionLoopState.value === 'running' || !activeProfileId.value) {
    return
  }

  detectionSessionId += 1
  const sessionId = detectionSessionId
  detectionLoopState.value = 'running'
  lastDetectionTime = 0
  markTestStarted(activeProfileId.value, activeTestCategory.value)
  stopDetectionLoopAnimation()

  const tick = (): void => {
    if (!isDetectionSessionActive(sessionId) || detectionLoopState.value !== 'running') {
      detectionLoopAnimationId = null
      return
    }

    const now = performance.now()

    if (now - lastDetectionTime >= DETECTION_INTERVAL_MS && !detectionInProgress) {
      lastDetectionTime = now
      void runDetectionAttempt()
    }

    detectionLoopAnimationId = requestAnimationFrame(tick)
  }

  detectionLoopAnimationId = requestAnimationFrame(tick)
}

function stopDetectionLoop(): void {
  detectionSessionId += 1
  detectionLoopState.value = 'stopped'
  stopDetectionLoopAnimation()
  detectionInProgress = false
  updateTestDurations()
}

async function detectOnce(): Promise<void> {
  if (!canStartDetection.value || !activeProfileId.value) {
    return
  }

  markTestStarted(activeProfileId.value, activeTestCategory.value)
  await runDetectionAttempt()
}

function resetStatistics(): void {
  stats.value = createEmptyDetectionStats()
  profileCodeAggregates.value = createInitialProfileCodeAggregates()
  successHistory.value = []
  lastSuccess.value = null
  completedDetectionDurations.length = 0
  aggregateDetectionDurations.clear()
  aggregateSuccessIntervals.clear()
  lastSuccessAtByAggregate.clear()
  copyMessage.value = null
}

async function captureFrame(): Promise<void> {
  const video = videoRef.value

  if (!video || video.videoWidth <= 0 || video.videoHeight <= 0) {
    return
  }

  if (capturedFrameUrl.value) {
    URL.revokeObjectURL(capturedFrameUrl.value)
    capturedFrameUrl.value = null
  }

  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight

  const context = canvas.getContext('2d')

  if (!context) {
    return
  }

  context.drawImage(video, 0, 0, canvas.width, canvas.height)

  const blob = await new Promise<Blob | null>((resolve) => {
    canvas.toBlob((value) => resolve(value), 'image/jpeg', 0.92)
  })

  if (!blob) {
    return
  }

  capturedFrameUrl.value = URL.createObjectURL(blob)
  capturedFrameMeta.value = {
    width: canvas.width,
    height: canvas.height,
    mimeType: blob.type || 'image/jpeg',
    sizeBytes: blob.size,
  }
}

function openCapturedFrame(): void {
  if (capturedFrameUrl.value) {
    window.open(capturedFrameUrl.value, '_blank', 'noopener,noreferrer')
  }
}

async function copyDiagnostic(): Promise<void> {
  const text = buildResolutionDiagnosticClipboard({
    environment: environment.value,
    supportedFormats: supportedFormats.value,
    activeProfileLabel: activeProfileLabel.value,
    activeTestCategory: activeTestCategory.value,
    requestedConstraints: requestedConstraints.value,
    actualTrackDetails: actualTrackDetails.value,
    trackDiagnostics: trackDiagnostics.value,
    cameraState: cameraState.value,
    streamActive: streamActive.value,
    videoWidth: videoWidth.value,
    videoHeight: videoHeight.value,
    readyState: readyState.value,
    currentTime: currentTime.value,
    stats: stats.value,
    aggregates: profileCodeAggregates.value,
    comparisonEntries: profileComparisonEntries.value,
    successHistory: successHistory.value,
    conclusion: conclusion.value,
    videoFlowWarning: videoFlowWarning.value,
  })

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      copyMessage.value = 'Diagnostic copié.'
      return
    }
  } catch {
    // fallback
  }

  copyMessage.value = 'Copie impossible.'
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

  if (capturedFrameUrl.value) {
    URL.revokeObjectURL(capturedFrameUrl.value)
  }

  void stopCamera()
})
</script>

<template>
  <Head title="Test résolution BarcodeDetector" />

  <div class="barcode-reader-test-page barcode-detector-resolution-test">
    <div class="barcode-reader-test-page__container barcode-detector-resolution-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Test résolution BarcodeDetector</h1>
          <p class="barcode-reader-test-page__subtitle mb-0">
            Page DEV isolée — compare la détection native selon la résolution réellement accordée.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-outline-secondary btn-sm">
          Retour
        </Link>
      </header>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Type de test actif</h2>
        <div class="barcode-detector-resolution-test__actions mb-2">
          <button
            type="button"
            class="btn btn-sm"
            :class="activeTestCategory === 'small-ean' ? 'btn-primary' : 'btn-outline-primary'"
            @click="activeTestCategory = 'small-ean'"
          >
            {{ SMALL_EAN_TEST_LABEL }}
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="activeTestCategory === 'standard-ean' ? 'btn-primary' : 'btn-outline-primary'"
            @click="activeTestCategory = 'standard-ean'"
          >
            {{ STANDARD_EAN_TEST_LABEL }}
          </button>
        </div>
        <p class="barcode-detector-resolution-test__muted mb-1">
          Test actif : <strong>{{ activeTestLabel }}</strong>
          <span v-if="activeProfileId"> — profil {{ activeProfileLabel }}</span>
        </p>
        <p class="barcode-detector-resolution-test__reference mb-0">
          Code de référence : <span class="font-monospace">{{ REFERENCE_EAN_VALUE }}</span> — Format : {{ REFERENCE_EAN_FORMAT }}
        </p>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Environnement</h2>
        <dl class="barcode-detector-resolution-test__grid">
          <div><dt>Browser</dt><dd>{{ environment.browserLabel }}</dd></div>
          <div><dt>Secure context</dt><dd>{{ environment.secureContext ? 'yes' : 'no' }}</dd></div>
          <div><dt>Platform</dt><dd>{{ environment.platform }}</dd></div>
          <div><dt>BarcodeDetector</dt><dd>{{ environment.barcodeDetectorAvailable ? 'disponible' : 'indisponible' }}</dd></div>
          <div class="barcode-detector-resolution-test__grid-full">
            <dt>Formats utilisés</dt>
            <dd>{{ supportedFormats.length > 0 ? supportedFormats.join(', ') : '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="barcode-detector-resolution-test__section barcode-detector-resolution-test__section--video">
        <div class="barcode-detector-resolution-test__video-wrap">
          <video
            ref="videoRef"
            class="barcode-detector-resolution-test__video"
            autoplay
            muted
            playsinline
          ></video>
        </div>

        <div class="barcode-detector-resolution-test__actions">
          <button type="button" class="btn btn-outline-secondary" @click="resetStatistics">
            Réinitialiser statistiques
          </button>
          <button type="button" class="btn btn-outline-primary" @click="startProfile('640x480')">
            Démarrer 640×480
          </button>
          <button type="button" class="btn btn-outline-primary" @click="startProfile('1280x720')">
            Démarrer 1280×720
          </button>
          <button type="button" class="btn btn-outline-primary" @click="startProfile('1920x1080')">
            Démarrer 1920×1080
          </button>
          <button type="button" class="btn btn-outline-primary" @click="startProfile('maximum')">
            Démarrer Maximum
          </button>
          <button type="button" class="btn btn-outline-success" :disabled="!canStartDetection" @click="startDetectionLoop">
            Démarrer détection
          </button>
          <button type="button" class="btn btn-outline-warning" @click="stopDetectionLoop">
            Arrêter détection
          </button>
          <button type="button" class="btn btn-outline-info" :disabled="!canStartDetection" @click="detectOnce">
            Détecter maintenant
          </button>
          <button type="button" class="btn btn-outline-secondary" :disabled="!canStartDetection" @click="captureFrame">
            Capturer frame
          </button>
          <button type="button" class="btn btn-outline-secondary" @click="stopCamera">
            Arrêter caméra
          </button>
          <button type="button" class="btn btn-outline-secondary" @click="copyDiagnostic">
            Copier diagnostic
          </button>
        </div>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Diagnostic caméra</h2>
        <dl class="barcode-detector-resolution-test__grid">
          <div><dt>Profil</dt><dd>{{ activeProfileLabel }}</dd></div>
          <div><dt>Camera</dt><dd>{{ cameraState }}</dd></div>
          <div><dt>Stream</dt><dd>{{ streamActive ? 'active' : 'stopped' }}</dd></div>
          <div><dt>Video readyState</dt><dd>{{ readyState }}</dd></div>
          <div><dt>Video width</dt><dd>{{ videoWidth || '—' }}</dd></div>
          <div><dt>Video height</dt><dd>{{ videoHeight || '—' }}</dd></div>
          <div><dt>CurrentTime</dt><dd>{{ currentTime.toFixed(2) }}</dd></div>
          <div><dt>Frames</dt><dd>{{ stats.framesSeen }}</dd></div>
          <div><dt>Track state</dt><dd>{{ trackDiagnostics.trackState }}</dd></div>
          <div><dt>Facing mode</dt><dd>{{ actualTrackDetails.facingMode }}</dd></div>
          <div><dt>FPS</dt><dd>{{ actualTrackDetails.frameRate }}</dd></div>
          <div><dt>Aspect ratio</dt><dd>{{ actualTrackDetails.aspectRatio }}</dd></div>
          <div><dt>DeviceId</dt><dd>{{ actualTrackDetails.deviceId }}</dd></div>
          <div><dt>Demandé</dt><dd>{{ requestedConstraints.width > 0 ? `${requestedConstraints.width} × ${requestedConstraints.height}` : '—' }}</dd></div>
          <div><dt>Réel</dt><dd>{{ actualTrackDetails.width && actualTrackDetails.height ? `${actualTrackDetails.width} × ${actualTrackDetails.height}` : '—' }}</dd></div>
        </dl>
        <p v-if="videoFlowWarning" class="barcode-detector-resolution-test__warning mb-0 mt-2">
          {{ videoFlowWarning }}
        </p>
        <div v-if="cameraError" class="barcode-detector-resolution-test__error-block mt-3">
          <p class="barcode-detector-resolution-test__error-title mb-2">Erreur</p>
          <dl class="barcode-detector-resolution-test__grid">
            <div><dt>name</dt><dd>{{ cameraError.name }}</dd></div>
            <div><dt>constraint</dt><dd>{{ cameraError.constraint }}</dd></div>
            <div class="barcode-detector-resolution-test__grid-full">
              <dt>message</dt>
              <dd class="barcode-detector-resolution-test__break">{{ cameraError.message }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Compteurs session active ({{ activeTestLabel }})</h2>
        <dl class="barcode-detector-resolution-test__grid">
          <div><dt>Frames observées</dt><dd>{{ stats.framesSeen }}</dd></div>
          <div><dt>Tentatives</dt><dd>{{ stats.detectionAttempts }}</dd></div>
          <div><dt>Succès</dt><dd>{{ stats.successfulDetections }}</dd></div>
          <div><dt>NOT FOUND</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Erreurs</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Taux succès</dt><dd>{{ computeSuccessRate(stats) }}</dd></div>
          <div><dt>NOT FOUND rate</dt><dd>{{ computeNotFoundRate(stats) }}</dd></div>
          <div><dt>Error rate</dt><dd>{{ computeErrorRate(stats) }}</dd></div>
          <div><dt>Durée test</dt><dd>{{ stats.testDurationMs ? `${Math.round(stats.testDurationMs / 1000)} s` : '—' }}</dd></div>
          <div><dt>Données suffisantes</dt><dd>{{ hasSufficientTestData(stats) ? 'oui' : 'Données insuffisantes' }}</dd></div>
          <div><dt>Last detection</dt><dd>{{ stats.lastDetectionMs ?? '—' }} ms</dd></div>
          <div><dt>Average detection</dt><dd>{{ stats.averageDetectionMs ?? '—' }} ms</dd></div>
          <div><dt>Min detection</dt><dd>{{ stats.minDetectionMs ?? '—' }} ms</dd></div>
          <div><dt>Max detection</dt><dd>{{ stats.maxDetectionMs ?? '—' }} ms</dd></div>
        </dl>
      </section>

      <section v-if="lastSuccess" class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Dernier succès</h2>
        <p class="barcode-detector-resolution-test__success mb-1">SUCCESS</p>
        <dl class="barcode-detector-resolution-test__grid">
          <div class="barcode-detector-resolution-test__grid-full"><dt>Value</dt><dd class="font-monospace">{{ lastSuccess.rawValue }}</dd></div>
          <div><dt>Format</dt><dd>{{ lastSuccess.format }}</dd></div>
          <div><dt>Temps</dt><dd>{{ lastSuccess.durationMs }} ms</dd></div>
          <div><dt>Résolution caméra</dt><dd>{{ lastSuccess.resolution }}</dd></div>
          <div><dt>Profil</dt><dd>{{ lastSuccess.profileLabel }}</dd></div>
          <div><dt>Timestamp</dt><dd>{{ lastSuccess.timestamp }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Tableau comparatif</h2>
        <div class="barcode-detector-resolution-test__table-wrap">
          <table class="table table-sm barcode-detector-resolution-test__table mb-0">
            <thead>
              <tr>
                <th>Profil</th>
                <th>Résolution réelle</th>
                <th>Code</th>
                <th>Tentatives</th>
                <th>Succès</th>
                <th>Taux</th>
                <th>NOT FOUND</th>
                <th>Erreurs</th>
                <th>Moyenne</th>
                <th>Min</th>
                <th>Max</th>
                <th>Données</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in comparisonTableRows" :key="`${row.profileLabel}-${row.codeCategory}`">
                <td>{{ row.profileLabel }}</td>
                <td>{{ row.actualResolution }}</td>
                <td>{{ row.codeLabel }}</td>
                <td>{{ row.attempts || '—' }}</td>
                <td>{{ row.attempts ? row.success : '—' }}</td>
                <td>{{ row.successRate }}</td>
                <td>{{ row.notFoundRate }}</td>
                <td>{{ row.errorRate }}</td>
                <td>{{ row.averageMs }}</td>
                <td>{{ row.minMs }}</td>
                <td>{{ row.maxMs }}</td>
                <td>{{ row.dataSufficient ? 'OK' : 'Insuffisant' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Comparaison</h2>
        <div class="barcode-detector-resolution-test__table-wrap">
          <table class="table table-sm barcode-detector-resolution-test__table mb-0">
            <thead>
              <tr>
                <th>Profil</th>
                <th>Résolution réelle</th>
                <th>Petit EAN-13</th>
                <th>EAN-13 standard</th>
                <th>Écart</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in profileComparisonEntries" :key="entry.profileLabel">
                <td>{{ entry.profileLabel }}</td>
                <td>{{ entry.actualResolution }}</td>
                <td>{{ entry.smallSuccessRate }}</td>
                <td>{{ entry.standardSuccessRate }}</td>
                <td>{{ entry.differencePoints }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Test petit EAN-13</h2>
        <p class="barcode-detector-resolution-test__muted">
          Présentez le même petit EAN-13 devant la caméra, même distance, orientation et éclairage pendant 20–30 secondes.
        </p>
        <button type="button" class="btn btn-sm btn-outline-primary me-2 mb-2" @click="activeTestCategory = 'small-ean'">
          Activer {{ SMALL_EAN_TEST_LABEL }}
        </button>
        <select id="small-ean-profile" v-model="smallEanProfileHint" class="form-select form-select-sm mb-2">
          <option value="640x480">640×480</option>
          <option value="1280x720">1280×720</option>
          <option value="1920x1080">1920×1080</option>
          <option value="maximum">Maximum</option>
        </select>
        <button type="button" class="btn btn-sm btn-outline-primary" @click="activeTestCategory = 'small-ean'; startProfile(smallEanProfileHint)">
          Démarrer {{ getProfileDefinition(smallEanProfileHint).label }}
        </button>
      </section>

      <section class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Test EAN-13 standard</h2>
        <p class="barcode-detector-resolution-test__muted">
          Code connu : <span class="font-monospace">{{ REFERENCE_EAN_VALUE }}</span> — {{ REFERENCE_EAN_FORMAT }}
        </p>
        <button type="button" class="btn btn-sm btn-outline-primary me-2 mb-2" @click="activeTestCategory = 'standard-ean'">
          Activer {{ STANDARD_EAN_TEST_LABEL }}
        </button>
        <select id="standard-ean-profile" v-model="standardEanProfileHint" class="form-select form-select-sm mb-2">
          <option value="640x480">640×480</option>
          <option value="1280x720">1280×720</option>
          <option value="1920x1080">1920×1080</option>
          <option value="maximum">Maximum</option>
        </select>
        <button type="button" class="btn btn-sm btn-outline-primary" @click="activeTestCategory = 'standard-ean'; startProfile(standardEanProfileHint)">
          Démarrer {{ getProfileDefinition(standardEanProfileHint).label }}
        </button>
      </section>

      <section v-if="successHistory.length > 0" class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Historique (20 derniers succès)</h2>
        <div class="barcode-detector-resolution-test__history">
          <div
            v-for="entry in successHistory"
            :key="entry.id"
            class="barcode-detector-resolution-test__history-item font-monospace"
          >
            {{ entry.timestamp }} — {{ entry.profileLabel }} — {{ entry.resolution }} — {{ entry.rawValue }} — {{ entry.format }} — {{ entry.durationMs }} ms
          </div>
        </div>
      </section>

      <section v-if="capturedFrameMeta" class="barcode-detector-resolution-test__section">
        <h2 class="barcode-detector-resolution-test__section-title">Frame capturée</h2>
        <p class="barcode-detector-resolution-test__muted">
          Cette capture est uniquement visuelle et n'est pas utilisée pour le décodage.
        </p>
        <dl class="barcode-detector-resolution-test__grid">
          <div><dt>Dimensions capture</dt><dd>{{ capturedFrameMeta.width }} × {{ capturedFrameMeta.height }}</dd></div>
          <div><dt>Taille</dt><dd>{{ Math.round(capturedFrameMeta.sizeBytes / 1024) }} KB</dd></div>
        </dl>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="openCapturedFrame">
          Ouvrir l'image
        </button>
      </section>

      <section class="barcode-detector-resolution-test__section barcode-detector-resolution-test__conclusion">
        <h2 class="barcode-detector-resolution-test__section-title">Conclusion</h2>
        <pre class="barcode-detector-resolution-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-detector-resolution-test__muted mb-0">
        {{ copyMessage }}
      </p>
    </div>
  </div>
</template>
