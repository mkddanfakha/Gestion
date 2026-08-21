<script setup lang="ts">
import {
  applyCameraControlsProfile,
  applyFocusModeToTrack,
  applyZoomToTrack,
  buildCameraControlsConclusion,
  buildCameraControlsDiagnosticClipboard,
  buildComparisonTableRows,
  buildFocusZoomComparisonEntries,
  buildProfileAggregateKey,
  CAMERA_SETTLING_MS,
  clampZoomValue,
  computeAverageDetectionMs,
  computeErrorRate,
  computeSuccessRate,
  createEmptyDetectionStats,
  createInitialProfileAggregates,
  createTestBarcodeDetector,
  DETECTION_INTERVAL_MS,
  FIXED_CAMERA_CONSTRAINTS,
  FOCUS_PROFILES,
  formatDataSufficiencyStatus,
  formatDetectionDetails,
  formatDurationMs,
  formatNativeBarcodeFormat,
  getEnvironmentDiagnostics,
  isFocusProfileSupported,
  pickBestNativeBarcode,
  readCameraTrackDiagnostics,
  readTrackCapabilitiesDetails,
  readTrackSettingsDetails,
  REFERENCE_EAN_FORMAT,
  REFERENCE_EAN_VALUE,
  resolveZoomLevels,
  serializeConstraintError,
  SMALL_EAN_TEST_LABEL,
  STANDARD_EAN_TEST_LABEL,
  REQUESTED_HEIGHT,
  REQUESTED_WIDTH,
  type BarcodeDetectorLike,
  type CodeTestCategory,
  type ConstraintApplicationResult,
  type DetectionStats,
  type FocusProfileId,
  type ProfileCodeAggregate,
  type SuccessHistoryEntry,
  type TrackCapabilitiesDetails,
} from '@/utils/barcodeDetectorCameraControlsTest'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const supportedFormats = ref<string[]>([])
const detectorCreationNote = ref<string | null>(null)
const activeFocusProfileId = ref<FocusProfileId>('default')
const activeZoom = ref<number>(1)
const activeTestCategory = ref<CodeTestCategory>('small-ean')
const cameraState = ref<'idle' | 'starting' | 'active' | 'stopping' | 'error'>('idle')
const detectionLoopState = ref<'stopped' | 'running'>('stopped')
const cameraError = ref<{ name: string; message: string; constraint: string } | null>(null)
const copyMessage = ref<string | null>(null)
const settlingState = ref<'idle' | 'waiting'>('idle')
const settlingElapsedMs = ref(0)
const lastConstraintMessages = ref<string[]>([])

const videoWidth = ref(0)
const videoHeight = ref(0)
const readyState = ref(0)
const currentTime = ref(0)
const streamActive = ref(false)
const trackDiagnostics = ref(readCameraTrackDiagnostics(null))
const trackSettings = ref(readTrackSettingsDetails(null))
const trackCapabilities = ref<TrackCapabilitiesDetails>(readTrackCapabilitiesDetails(null))
const currentFocusRequested = ref('default')
const currentZoomRequested = ref('1')
const lastObservedCurrentTimeForFlow = ref(0)
const currentTimeProgressing = ref(true)

const stats = ref<DetectionStats>(createEmptyDetectionStats())
const profileAggregates = ref<ProfileCodeAggregate[]>(createInitialProfileAggregates(readTrackCapabilitiesDetails(null)))
const successHistory = ref<SuccessHistoryEntry[]>([])
const lastSuccess = ref<SuccessHistoryEntry | null>(null)
const capturedFrameUrl = ref<string | null>(null)
const capturedFrameMeta = ref<{ width: number; height: number } | null>(null)

let cameraSessionId = 0
let detectionSessionId = 0
let settlingSessionId = 0
let diagnosticsTimer: number | null = null
let detectionLoopAnimationId: number | null = null
let detectionInProgress = false
let lastDetectionTime = 0
const completedDetectionDurations: number[] = []
const aggregateDetectionDurations = new Map<string, number[]>()
const aggregateSuccessIntervals = new Map<string, number[]>()
const lastSuccessAtByAggregate = new Map<string, number>()

const activeFocusProfile = computed(() => FOCUS_PROFILES.find((item) => item.id === activeFocusProfileId.value)!)
const activeTestLabel = computed(() =>
  activeTestCategory.value === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL,
)
const availableZoomLevels = computed(() => resolveZoomLevels(trackCapabilities.value))
const comparisonTableRows = computed(() => buildComparisonTableRows(profileAggregates.value))
const focusZoomComparisonEntries = computed(() => buildFocusZoomComparisonEntries(profileAggregates.value))

const focusSupport = computed(() => ({
  default: true,
  continuous: isFocusProfileSupported(FOCUS_PROFILES[1], trackCapabilities.value),
  'single-shot': isFocusProfileSupported(FOCUS_PROFILES[2], trackCapabilities.value),
}))

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

const conclusion = computed(() => buildCameraControlsConclusion({
  aggregates: profileAggregates.value,
  capabilities: trackCapabilities.value,
}))

const canStartDetection = computed(() => {
  return cameraState.value === 'active'
    && environment.value.barcodeDetectorAvailable
    && detectorRef.value != null
    && videoWidth.value > 0
    && videoHeight.value > 0
    && currentTimeProgressing.value
    && settlingState.value === 'idle'
})

function getVideoTrack(): MediaStreamTrack | null {
  return activeStream.value?.getVideoTracks()[0] ?? null
}

function serializeError(error: unknown): { name: string; message: string; constraint: string } {
  return serializeConstraintError(error)
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

function stopDiagnosticsPolling(): void {
  if (diagnosticsTimer !== null) {
    window.clearInterval(diagnosticsTimer)
    diagnosticsTimer = null
  }
}

function refreshDiagnostics(): void {
  const video = videoRef.value
  const track = getVideoTrack()

  videoWidth.value = video?.videoWidth ?? 0
  videoHeight.value = video?.videoHeight ?? 0
  readyState.value = video?.readyState ?? 0
  currentTime.value = video?.currentTime ?? 0
  streamActive.value = activeStream.value?.active ?? false
  trackDiagnostics.value = readCameraTrackDiagnostics(activeStream.value)
  trackSettings.value = readTrackSettingsDetails(track)
  trackCapabilities.value = readTrackCapabilitiesDetails(track)

  if (video && cameraState.value === 'active') {
    currentTimeProgressing.value = video.currentTime > lastObservedCurrentTimeForFlow.value
    lastObservedCurrentTimeForFlow.value = video.currentTime
  }
}

function updateTestDurations(): void {
  const now = Date.now()

  if (stats.value.testStartedAt != null) {
    stats.value = { ...stats.value, testDurationMs: now - stats.value.testStartedAt }
  }

  const key = buildProfileAggregateKey(activeFocusProfileId.value, activeZoom.value, activeTestCategory.value)
  const aggregate = profileAggregates.value.find(
    (item) => buildProfileAggregateKey(item.focusProfileId, item.requestedZoom ?? 1, item.codeCategory) === key,
  )

  if (!aggregate || aggregate.testStartedAt == null) {
    return
  }

  profileAggregates.value = profileAggregates.value.map((item) => {
    if (buildProfileAggregateKey(item.focusProfileId, item.requestedZoom ?? 1, item.codeCategory) !== key) {
      return item
    }

    return { ...item, testDurationMs: now - item.testStartedAt! }
  })
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    refreshDiagnostics()
    updateTestDurations()
  }, 500)
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
    const created = await createTestBarcodeDetector()
    detectorRef.value = created.detector
    supportedFormats.value = created.formatsUsed.length > 0
      ? created.formatsUsed
      : ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39']
    detectorCreationNote.value = created.creationNote

    return true
  } catch (error) {
    cameraError.value = serializeError(error)
    return false
  }
}

async function waitForCameraSettling(sessionId: number): Promise<void> {
  settlingSessionId += 1
  const localSessionId = settlingSessionId
  settlingState.value = 'waiting'
  settlingElapsedMs.value = 0
  const startedAt = Date.now()

  while (Date.now() - startedAt < CAMERA_SETTLING_MS) {
    if (!isCameraSessionActive(sessionId) || localSessionId !== settlingSessionId) {
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

function pushConstraintMessage(result: ConstraintApplicationResult): void {
  const status = result.success ? 'SUCCESS' : 'FAILED'
  lastConstraintMessages.value = [
    `Constraint application: ${status}`,
    `Name: ${result.name}`,
    `Message: ${result.message}`,
    `Constraint: ${result.constraint}`,
    `Focus requested: ${result.requestedFocus}`,
    `Zoom requested: ${result.requestedZoom ?? '—'}`,
    `Focus actual: ${result.actualFocus}`,
    `Zoom actual: ${result.actualZoom}`,
    ...lastConstraintMessages.value,
  ].slice(0, 12)
}

function updateAggregateActuals(): void {
  const key = buildProfileAggregateKey(activeFocusProfileId.value, activeZoom.value, activeTestCategory.value)

  profileAggregates.value = profileAggregates.value.map((item) => {
    if (buildProfileAggregateKey(item.focusProfileId, item.requestedZoom ?? 1, item.codeCategory) !== key) {
      return item
    }

    return {
      ...item,
      actualFocusMode: trackSettings.value.focusMode,
      actualZoom: trackSettings.value.zoom,
    }
  })
}

async function applyCurrentProfileControls(sessionId: number): Promise<boolean> {
  const track = getVideoTrack()

  if (!track) {
    return false
  }

  stopDetectionLoop()

  const result = await applyCameraControlsProfile(
    track,
    activeFocusProfile.value,
    activeZoom.value,
    trackCapabilities.value,
  )

  pushConstraintMessage(result.focusResult)
  pushConstraintMessage(result.zoomResult)

  currentFocusRequested.value = activeFocusProfile.value.constraintValue ?? 'default'
  currentZoomRequested.value = `${activeZoom.value}`

  refreshDiagnostics()
  updateAggregateActuals()

  if (result.skippedZoom) {
    lastConstraintMessages.value = [result.skipReason ?? 'Zoom skipped', ...lastConstraintMessages.value]
  }

  await waitForCameraSettling(sessionId)

  return result.focusResult.success && (!result.skippedZoom ? result.zoomResult.success : true)
}

async function applyFocusOnly(): Promise<void> {
  const track = getVideoTrack()

  if (!track) {
    return
  }

  stopDetectionLoop()
  const result = await applyFocusModeToTrack(track, activeFocusProfile.value)
  pushConstraintMessage(result)
  currentFocusRequested.value = activeFocusProfile.value.constraintValue ?? 'default'
  refreshDiagnostics()
  updateAggregateActuals()
  await waitForCameraSettling(cameraSessionId)
}

async function applyZoomOnly(): Promise<void> {
  const track = getVideoTrack()

  if (!track) {
    return
  }

  stopDetectionLoop()
  const clamped = clampZoomValue(activeZoom.value, trackCapabilities.value)
  const result = await applyZoomToTrack(track, clamped, trackCapabilities.value)
  pushConstraintMessage(result)
  currentZoomRequested.value = `${activeZoom.value}`
  refreshDiagnostics()
  updateAggregateActuals()
  await waitForCameraSettling(cameraSessionId)
}

function markTestStarted(): void {
  const now = Date.now()
  stats.value = { ...stats.value, testStartedAt: now, testDurationMs: 0 }

  const key = buildProfileAggregateKey(activeFocusProfileId.value, activeZoom.value, activeTestCategory.value)

  profileAggregates.value = profileAggregates.value.map((item) => {
    if (buildProfileAggregateKey(item.focusProfileId, item.requestedZoom ?? 1, item.codeCategory) !== key) {
      return item
    }

    return {
      ...item,
      testStartedAt: item.testStartedAt ?? now,
      testDurationMs: item.testStartedAt ? now - item.testStartedAt : 0,
      actualFocusMode: trackSettings.value.focusMode,
      actualZoom: trackSettings.value.zoom,
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
  const key = buildProfileAggregateKey(activeFocusProfileId.value, activeZoom.value, activeTestCategory.value)

  profileAggregates.value = profileAggregates.value.map((aggregate) => {
    if (buildProfileAggregateKey(aggregate.focusProfileId, aggregate.requestedZoom ?? 1, aggregate.codeCategory) !== key) {
      return aggregate
    }

    const next = { ...aggregate }

    if (options.incrementAttempts) next.detectionAttempts += 1
    if (options.incrementSuccess) next.successfulDetections += 1
    if (options.incrementNotFound) next.notFound += 1
    if (options.incrementErrors) next.errors += 1

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

    next.actualFocusMode = trackSettings.value.focusMode
    next.actualZoom = trackSettings.value.zoom

    return next
  })
}

function recordSuccess(rawValue: string, format: string, durationMs: number, details: { boundingBox: string; cornerPoints: string }): void {
  const entry: SuccessHistoryEntry = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    focusLabel: activeFocusProfile.value.label,
    zoomLabel: `${activeZoom.value}×`,
    rawValue,
    format,
    durationMs,
    codeCategory: activeTestCategory.value,
    boundingBox: details.boundingBox,
    cornerPoints: details.cornerPoints,
  }

  lastSuccess.value = entry
  successHistory.value = [entry, ...successHistory.value].slice(0, 20)
}

async function runSingleDetection(sessionId: number): Promise<void> {
  if (detectionInProgress || !isDetectionSessionActive(sessionId) || settlingState.value === 'waiting') {
    return
  }

  const detector = detectorRef.value
  const video = videoRef.value

  if (!detector || !video || video.videoWidth <= 0 || video.videoHeight <= 0) {
    return
  }

  detectionInProgress = true
  const startedAt = performance.now()

  try {
    updateActiveAggregate({ incrementAttempts: true })
    stats.value = { ...stats.value, detectionAttempts: stats.value.detectionAttempts + 1 }

    const results = await detector.detect(video)
    const durationMs = performance.now() - startedAt
    const best = pickBestNativeBarcode(results)

    completedDetectionDurations.push(durationMs)
    stats.value = {
      ...stats.value,
      lastDetectionMs: durationMs,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations),
      minDetectionMs: Math.min(...completedDetectionDurations),
      maxDetectionMs: Math.max(...completedDetectionDurations),
    }

    if (best?.rawValue) {
      const details = formatDetectionDetails(best)
      updateActiveAggregate({ incrementSuccess: true, durationMs, successAt: Date.now() })
      stats.value = { ...stats.value, successfulDetections: stats.value.successfulDetections + 1 }
      recordSuccess(best.rawValue, formatNativeBarcodeFormat(best.format), durationMs, details)
    } else {
      updateActiveAggregate({ incrementNotFound: true, durationMs })
      stats.value = { ...stats.value, notFound: stats.value.notFound + 1 }
    }
  } catch {
    const durationMs = performance.now() - startedAt
    updateActiveAggregate({ incrementErrors: true, durationMs })
    stats.value = { ...stats.value, errors: stats.value.errors + 1 }
  } finally {
    detectionInProgress = false
  }
}

function stopDetectionLoop(): void {
  detectionSessionId += 1
  detectionLoopState.value = 'stopped'
  stopDetectionLoopAnimation()
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
  markTestStarted()
  lastDetectionTime = 0

  const tick = (timestamp: number): void => {
    if (!isDetectionSessionActive(sessionId)) {
      detectionLoopAnimationId = null
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

async function detectNow(): Promise<void> {
  await runSingleDetection(detectionSessionId)
}

async function stopCamera(): Promise<void> {
  stopDetectionLoop()
  settlingSessionId += 1
  settlingState.value = 'idle'
  cameraSessionId += 1
  cameraState.value = 'stopping'

  const stream = activeStream.value
  stopTracks(stream)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  stopDiagnosticsPolling()
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
  cameraError.value = null

  try {
    const stream = await navigator.mediaDevices.getUserMedia(FIXED_CAMERA_CONSTRAINTS)

    if (!isCameraSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    profileAggregates.value = createInitialProfileAggregates(readTrackCapabilitiesDetails(getVideoTrack()))

    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo indisponible.')
    }

    video.srcObject = stream
    video.playsInline = true
    video.muted = true

    await video.play()

    const ready = await waitForVideoReady(video, sessionId)

    if (!ready || !isCameraSessionActive(sessionId)) {
      throw new Error('La vidéo n\'est pas devenue active dans le délai imparti.')
    }

    refreshDiagnostics()
    await applyCurrentProfileControls(sessionId)

    cameraState.value = 'active'
    lastObservedCurrentTimeForFlow.value = video.currentTime
    currentTimeProgressing.value = true
    startDiagnosticsPolling()
  } catch (error) {
    if (isCameraSessionActive(sessionId)) {
      cameraError.value = serializeError(error)
      cameraState.value = 'error'
      stopTracks(activeStream.value)
      activeStream.value = null
    }
  }
}

async function selectFocusProfile(profileId: FocusProfileId): Promise<void> {
  if (profileId !== 'default' && !focusSupport.value[profileId]) {
    return
  }

  activeFocusProfileId.value = profileId
  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0

  if (cameraState.value === 'active') {
    await applyCurrentProfileControls(cameraSessionId)
  }
}

async function selectZoom(zoom: number): Promise<void> {
  if (!availableZoomLevels.value.includes(zoom)) {
    return
  }

  activeZoom.value = zoom
  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0

  if (cameraState.value === 'active') {
    await applyCurrentProfileControls(cameraSessionId)
  }
}

function selectTestCategory(category: CodeTestCategory): void {
  activeTestCategory.value = category
  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0
}

function resetStatistics(): void {
  stopDetectionLoop()
  stats.value = createEmptyDetectionStats()
  profileAggregates.value = createInitialProfileAggregates(trackCapabilities.value)
  successHistory.value = []
  lastSuccess.value = null
  completedDetectionDurations.length = 0
  aggregateDetectionDurations.clear()
  aggregateSuccessIntervals.clear()
  lastSuccessAtByAggregate.clear()
  lastConstraintMessages.value = []
}

function resetCurrentProfile(): void {
  stopDetectionLoop()
  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0

  const key = buildProfileAggregateKey(activeFocusProfileId.value, activeZoom.value, activeTestCategory.value)
  aggregateDetectionDurations.delete(key)
  aggregateSuccessIntervals.delete(key)
  lastSuccessAtByAggregate.delete(key)

  profileAggregates.value = profileAggregates.value.map((item) => {
    if (buildProfileAggregateKey(item.focusProfileId, item.requestedZoom ?? 1, item.codeCategory) !== key) {
      return item
    }

    return {
      ...item,
      ...createEmptyDetectionStats(),
      actualFocusMode: trackSettings.value.focusMode,
      actualZoom: trackSettings.value.zoom,
    }
  })
}

function captureFrame(): void {
  const video = videoRef.value
  const canvas = document.createElement('canvas')

  if (!video || video.videoWidth <= 0 || video.videoHeight <= 0) {
    return
  }

  if (capturedFrameUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(capturedFrameUrl.value)
  }

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d')

  if (!context) {
    return
  }

  context.drawImage(video, 0, 0)
  capturedFrameUrl.value = canvas.toDataURL('image/jpeg', 0.85)
  capturedFrameMeta.value = { width: canvas.width, height: canvas.height }
}

async function copyDiagnostic(): Promise<void> {
  const text = buildCameraControlsDiagnosticClipboard({
    environment: environment.value,
    supportedFormats: supportedFormats.value,
    detectorCreationNote: detectorCreationNote.value,
    requestedWidth: REQUESTED_WIDTH,
    requestedHeight: REQUESTED_HEIGHT,
    trackSettings: trackSettings.value,
    trackDiagnostics: trackDiagnostics.value,
    capabilities: trackCapabilities.value,
    currentFocusRequested: currentFocusRequested.value,
    currentZoomRequested: currentZoomRequested.value,
    activeTestLabel: activeTestLabel.value,
    activeProfileLabel: `${activeFocusProfile.value.label} + ${activeZoom.value}×`,
    sessionStats: stats.value,
    aggregates: profileAggregates.value,
    successHistory: successHistory.value,
    lastConstraintResults: lastConstraintMessages.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié dans le presse-papiers.'
  } catch {
    copyMessage.value = 'Impossible de copier le diagnostic.'
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

  if (capturedFrameUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(capturedFrameUrl.value)
  }

  void stopCamera()
})
</script>

<template>
  <Head title="Test Focus/Zoom caméra BarcodeDetector" />

  <div class="barcode-reader-test-page barcode-detector-camera-controls-test">
    <div class="barcode-reader-test-page__container barcode-detector-camera-controls-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Test Focus / Zoom matériel</h1>
          <p class="barcode-reader-test-page__subtitle">
            Diagnostic DEV — focusMode et zoom réel du MediaStreamTrack avant BarcodeDetector.detect(video).
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
      </header>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">BarcodeDetector</h2>
        <dl class="barcode-detector-camera-controls-test__grid">
          <div><dt>Available</dt><dd>{{ environment.barcodeDetectorAvailable ? 'YES' : 'NO' }}</dd></div>
          <div class="barcode-detector-camera-controls-test__grid-full">
            <dt>Formats</dt>
            <dd>{{ supportedFormats.length > 0 ? supportedFormats.join(', ') : '—' }}</dd>
          </div>
          <div v-if="detectorCreationNote" class="barcode-detector-camera-controls-test__grid-full">
            <dt>Creation note</dt><dd>{{ detectorCreationNote }}</dd>
          </div>
        </dl>
      </section>

      <section class="barcode-detector-camera-controls-test__section barcode-detector-camera-controls-test__section--video">
        <div class="barcode-detector-camera-controls-test__video-wrap">
          <video
            ref="videoRef"
            class="barcode-detector-camera-controls-test__video"
            autoplay
            muted
            playsinline
          />
        </div>

        <div class="barcode-detector-camera-controls-test__actions">
          <button type="button" class="btn btn-sm btn-primary" :disabled="cameraState === 'starting'" @click="startCamera">Start camera</button>
          <button type="button" class="btn btn-sm btn-outline-danger" :disabled="cameraState === 'idle'" @click="stopCamera">Stop camera</button>
          <button type="button" class="btn btn-sm btn-success" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="startDetectionLoop">Start detection</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="detectionLoopState !== 'running'" @click="stopDetectionLoop">Stop detection</button>
          <button type="button" class="btn btn-sm btn-outline-primary" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="detectNow">Detect now</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="resetStatistics">Reset statistics</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="resetCurrentProfile">Reset current profile</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="cameraState !== 'active'" @click="captureFrame">Capture frame</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="copyDiagnostic">Copier diagnostic</button>
        </div>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Camera</h2>
        <dl class="barcode-detector-camera-controls-test__grid">
          <div><dt>Camera</dt><dd>{{ cameraState === 'active' ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Stream</dt><dd>{{ streamActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Track</dt><dd>{{ trackDiagnostics.trackState }}</dd></div>
          <div><dt>Requested</dt><dd>{{ REQUESTED_WIDTH }} × {{ REQUESTED_HEIGHT }}</dd></div>
          <div><dt>Actual</dt><dd>{{ trackSettings.width ?? '—' }} × {{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>FPS</dt><dd>{{ trackSettings.frameRate }}</dd></div>
          <div><dt>Facing</dt><dd>{{ trackSettings.facingMode }}</dd></div>
          <div><dt>Video width</dt><dd>{{ videoWidth }}</dd></div>
          <div><dt>Video height</dt><dd>{{ videoHeight }}</dd></div>
          <div><dt>ReadyState</dt><dd>{{ readyState }}</dd></div>
          <div><dt>CurrentTime</dt><dd>{{ currentTime.toFixed(2) }}</dd></div>
          <div><dt>Camera settling</dt><dd>{{ settlingState === 'waiting' ? `${settlingElapsedMs} ms` : 'idle' }}</dd></div>
        </dl>
        <p v-if="videoFlowWarning" class="barcode-detector-camera-controls-test__warning mt-2 mb-0">{{ videoFlowWarning }}</p>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Capabilities</h2>
        <dl class="barcode-detector-camera-controls-test__grid">
          <div><dt>Focus mode</dt><dd>{{ trackCapabilities.focus.label }}</dd></div>
          <div><dt>Zoom</dt><dd>{{ trackCapabilities.zoom.label }}</dd></div>
          <div class="barcode-detector-camera-controls-test__grid-full">
            <dt>Focus modes</dt>
            <dd>{{ trackCapabilities.focus.modes.length > 0 ? trackCapabilities.focus.modes.join(', ') : '—' }}</dd>
          </div>
          <div><dt>Zoom min</dt><dd>{{ trackCapabilities.zoom.min ?? '—' }}</dd></div>
          <div><dt>Zoom max</dt><dd>{{ trackCapabilities.zoom.max ?? '—' }}</dd></div>
          <div><dt>Zoom step</dt><dd>{{ trackCapabilities.zoom.step ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Current controls</h2>
        <dl class="barcode-detector-camera-controls-test__grid">
          <div><dt>Focus requested</dt><dd>{{ currentFocusRequested }}</dd></div>
          <div><dt>Focus actual</dt><dd>{{ trackSettings.focusMode }}</dd></div>
          <div><dt>Zoom requested</dt><dd>{{ currentZoomRequested }}×</dd></div>
          <div><dt>Zoom actual</dt><dd>{{ trackSettings.zoom }}</dd></div>
        </dl>
        <div class="barcode-detector-camera-controls-test__actions mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="cameraState !== 'active'" @click="applyFocusOnly">Apply focus</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="cameraState !== 'active' || !trackCapabilities.zoom.supported" @click="applyZoomOnly">Apply zoom</button>
        </div>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Test subject</h2>
        <div class="barcode-detector-camera-controls-test__actions mb-2">
          <button type="button" class="btn btn-sm" :class="activeTestCategory === 'small-ean' ? 'btn-primary' : 'btn-outline-primary'" @click="selectTestCategory('small-ean')">
            Small EAN-13
          </button>
          <button type="button" class="btn btn-sm" :class="activeTestCategory === 'standard-ean' ? 'btn-primary' : 'btn-outline-primary'" @click="selectTestCategory('standard-ean')">
            Standard EAN-13
          </button>
        </div>
        <p class="barcode-detector-camera-controls-test__muted mb-0">
          Test subject: <strong>{{ activeTestLabel }}</strong>
          <span v-if="activeTestCategory === 'standard-ean'" class="font-monospace"> — {{ REFERENCE_EAN_VALUE }} ({{ REFERENCE_EAN_FORMAT }})</span>
        </p>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Focus profile</h2>
        <div class="barcode-detector-camera-controls-test__actions">
          <button
            v-for="profile in FOCUS_PROFILES"
            :key="profile.id"
            type="button"
            class="btn btn-sm"
            :class="activeFocusProfileId === profile.id ? 'btn-warning' : 'btn-outline-warning'"
            :disabled="profile.id !== 'default' && !focusSupport[profile.id]"
            @click="selectFocusProfile(profile.id)"
          >
            {{ profile.label }}
          </button>
        </div>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Zoom matériel</h2>
        <div class="barcode-detector-camera-controls-test__actions">
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
        <p class="barcode-detector-camera-controls-test__muted mb-0 mt-2">
          Profil actif : <strong>{{ activeFocusProfile.label }} + {{ activeZoom }}×</strong>
        </p>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Compteurs session ({{ activeFocusProfile.label }} + {{ activeZoom }}× — {{ activeTestLabel }})</h2>
        <dl class="barcode-detector-camera-controls-test__grid">
          <div><dt>Attempts</dt><dd>{{ stats.detectionAttempts }}</dd></div>
          <div><dt>Success</dt><dd>{{ stats.successfulDetections }}</dd></div>
          <div><dt>Not found</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Success rate</dt><dd>{{ computeSuccessRate(stats) }}</dd></div>
          <div><dt>Error rate</dt><dd>{{ computeErrorRate(stats) }}</dd></div>
          <div><dt>Average detection</dt><dd>{{ formatDurationMs(stats.averageDetectionMs) }}</dd></div>
          <div><dt>Min</dt><dd>{{ formatDurationMs(stats.minDetectionMs) }}</dd></div>
          <div><dt>Max</dt><dd>{{ formatDurationMs(stats.maxDetectionMs) }}</dd></div>
          <div><dt>Data status</dt><dd>{{ formatDataSufficiencyStatus(stats.detectionAttempts) }}</dd></div>
        </dl>
      </section>

      <section v-if="lastSuccess" class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Dernier succès</h2>
        <dl class="barcode-detector-camera-controls-test__grid">
          <div class="barcode-detector-camera-controls-test__grid-full"><dt>Value</dt><dd class="font-monospace">{{ lastSuccess.rawValue }}</dd></div>
          <div><dt>Format</dt><dd>{{ lastSuccess.format }}</dd></div>
          <div><dt>BoundingBox</dt><dd>{{ lastSuccess.boundingBox }}</dd></div>
          <div><dt>CornerPoints</dt><dd>{{ lastSuccess.cornerPoints }}</dd></div>
        </dl>
      </section>

      <section v-if="lastConstraintMessages.length > 0" class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Constraint application</h2>
        <pre class="barcode-detector-camera-controls-test__pre mb-0">{{ lastConstraintMessages.join('\n') }}</pre>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Tableau comparatif</h2>
        <div class="barcode-detector-camera-controls-test__table-wrap">
          <table class="table table-sm barcode-detector-camera-controls-test__table mb-0">
            <thead>
              <tr>
                <th>Focus</th>
                <th>Zoom</th>
                <th>Actual zoom</th>
                <th>Code</th>
                <th class="text-end">Attempts</th>
                <th class="text-end">Success</th>
                <th class="text-end">Not found</th>
                <th class="text-end">Rate</th>
                <th class="text-end">Avg</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in comparisonTableRows"
                :key="`${row.focusLabel}-${row.zoomLabel}-${row.codeCategory}`"
                :class="{ 'table-secondary': !row.enabled }"
              >
                <td>{{ row.focusLabel }}</td>
                <td>{{ row.zoomLabel }}</td>
                <td>{{ row.actualZoom }}</td>
                <td>{{ row.codeLabel }}</td>
                <td class="text-end">{{ row.attempts }}</td>
                <td class="text-end">{{ row.success }}</td>
                <td class="text-end">{{ row.notFound }}</td>
                <td class="text-end">{{ row.successRate }}</td>
                <td class="text-end">{{ row.averageMs }}</td>
                <td>{{ row.dataStatus }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Comparaison Small vs Standard</h2>
        <div class="barcode-detector-camera-controls-test__table-wrap">
          <table class="table table-sm barcode-detector-camera-controls-test__table mb-0">
            <thead>
              <tr>
                <th>Focus</th>
                <th>Zoom</th>
                <th class="text-end">Small EAN</th>
                <th class="text-end">Standard EAN</th>
                <th class="text-end">Écart</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in focusZoomComparisonEntries" :key="`${entry.focusLabel}-${entry.zoomLabel}`">
                <td>{{ entry.focusLabel }}</td>
                <td>{{ entry.zoomLabel }}</td>
                <td class="text-end">{{ entry.smallSuccessRate }}</td>
                <td class="text-end">{{ entry.standardSuccessRate }}</td>
                <td class="text-end">{{ entry.differencePoints }}</td>
                <td>{{ entry.dataStatus }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="successHistory.length > 0" class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Historique succès</h2>
        <div class="barcode-detector-camera-controls-test__history">
          <div v-for="entry in successHistory" :key="entry.id" class="barcode-detector-camera-controls-test__history-item font-monospace">
            {{ entry.timestamp }} — {{ entry.focusLabel }} {{ entry.zoomLabel }} — {{ entry.rawValue }} — {{ entry.format }} — {{ formatDurationMs(entry.durationMs) }}
          </div>
        </div>
      </section>

      <section v-if="capturedFrameUrl" class="barcode-detector-camera-controls-test__section">
        <h2 class="barcode-detector-camera-controls-test__section-title">Capture visuelle</h2>
        <p class="barcode-detector-camera-controls-test__muted">Original camera frame — capture visuelle uniquement, non utilisée pour le décodage.</p>
        <p v-if="capturedFrameMeta" class="barcode-detector-camera-controls-test__muted">{{ capturedFrameMeta.width }} × {{ capturedFrameMeta.height }}</p>
        <img :src="capturedFrameUrl" alt="Original camera frame" class="barcode-detector-camera-controls-test__preview-image">
      </section>

      <section class="barcode-detector-camera-controls-test__section barcode-detector-camera-controls-test__conclusion">
        <h2 class="barcode-detector-camera-controls-test__section-title">Conclusion</h2>
        <pre class="barcode-detector-camera-controls-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-detector-camera-controls-test__muted mb-0">{{ copyMessage }}</p>
    </div>
  </div>
</template>
