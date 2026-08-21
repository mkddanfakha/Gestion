<script setup lang="ts">
import {
  ALT_REFERENCE_EAN_VALUE,
  applyFocusProfileToTrack,
  applyZoomToTrack,
  buildAvailableFocusProfiles,
  buildComparisonTableRows,
  buildFocusSharpnessConclusion,
  buildFocusSharpnessDiagnosticClipboard,
  buildProfileKey,
  CAMERA_SETTLING_MS,
  classifyDetectionResult,
  computeAverageDetectionMs,
  computeCorrectRate,
  computeIncorrectRate,
  computeNotFoundRate,
  computeSharpnessStats,
  createEmptySessionStats,
  createInitialProfileAggregates,
  createTestBarcodeDetector,
  DETECTION_INTERVAL_MS,
  EXPECTED_FORMAT,
  extractBoundingBoxDetails,
  FIXED_CAMERA_CONSTRAINTS,
  formatDurationMs,
  formatNativeBarcodeFormat,
  getEnvironmentDiagnostics,
  getExpectedBarcodeForSubject,
  getSubjectLabel,
  getVideoOrientationLabel,
  isFocusProfileAvailable,
  measureVideoSharpness,
  pickBestNativeBarcode,
  readTrackCapabilitiesDetails,
  readTrackSettingsDetails,
  REFERENCE_EAN_FORMAT,
  REFERENCE_EAN_VALUE,
  resolveZoomLevels,
  serializeConstraintError,
  SHARPNESS_SAMPLE_INTERVAL_MS,
  summarizeIncorrectValues,
  type BarcodeDetectorLike,
  type BoundingBoxDetails,
  type ConstraintLogEntry,
  type FocusProfileDefinition,
  type FocusProfileId,
  type ProfileAggregate,
  type ReadClassification,
  type ReadHistoryEntry,
  type RequestedControls,
  type SessionStats,
  type SharpnessStats,
  type TestSubjectId,
} from '@/utils/barcodeDetectorFocusSharpnessTest'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

const videoRef = ref<HTMLVideoElement | null>(null)
const overlayCanvasRef = ref<HTMLCanvasElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const supportedFormats = ref<string[]>([])
const detectorCreationNote = ref<string | null>(null)

const testSubject = ref<TestSubjectId>('small-ean')
const expectedBarcodeInput = ref('')
const customExpectedBarcode = ref(ALT_REFERENCE_EAN_VALUE)

const activeFocusProfileId = ref<FocusProfileId>('default')
const activeZoom = ref(1)

const cameraState = ref<'idle' | 'starting' | 'active' | 'stopping' | 'error'>('idle')
const detectionLoopState = ref<'stopped' | 'running'>('stopped')
const cameraError = ref<{ name: string; message: string } | null>(null)
const copyMessage = ref<string | null>(null)

const settlingState = ref<'idle' | 'waiting'>('idle')
const settlingElapsedMs = ref(0)

const videoWidth = ref(0)
const videoHeight = ref(0)
const readyState = ref(0)
const currentTime = ref(0)
const streamActive = ref(false)
const trackSettings = ref(readTrackSettingsDetails(null))
const trackCapabilities = ref(readTrackCapabilitiesDetails(null))
const requestedControls = ref<RequestedControls>({ focus: 'none', zoom: '1', focusDistance: '—' })
const videoOrientation = ref('—')

const stats = ref<SessionStats>(createEmptySessionStats())
const profileAggregates = ref<ProfileAggregate[]>(createInitialProfileAggregates(readTrackCapabilitiesDetails(null), 'small-ean'))
const readHistory = ref<ReadHistoryEntry[]>([])
const constraintLog = ref<ConstraintLogEntry[]>([])
const lastRead = ref<ReadHistoryEntry | null>(null)
const lastIncorrectRead = ref<ReadHistoryEntry | null>(null)
const lastBoundingBox = ref<BoundingBoxDetails | null>(null)

const sharpnessSamples = ref<number[]>([])
const sharpnessStats = ref<SharpnessStats>(computeSharpnessStats([]))
const correctSharpnessValues = ref<number[]>([])
const incorrectSharpnessValues = ref<number[]>([])
const notFoundSharpnessValues = ref<number[]>([])

const capturedFrameUrl = ref<string | null>(null)
const capturedFrameWithBoxUrl = ref<string | null>(null)
const capturedSharpness = ref<number | null>(null)

let cameraSessionId = 0
let detectionSessionId = 0
let settlingSessionId = 0
let diagnosticsTimer: number | null = null
let sharpnessTimer: number | null = null
let detectionLoopAnimationId: number | null = null
let detectionInProgress = false
let lastDetectionTime = 0
let lastCorrectValue: string | null = null
let lastCorrectAt: number | null = null

const completedDetectionDurations: number[] = []
const aggregateDetectionDurations = new Map<string, number[]>()
const aggregateSharpnessValues = new Map<string, number[]>()
const aggregateCorrectSharpness = new Map<string, number[]>()
const aggregateIncorrectSharpness = new Map<string, number[]>()
const aggregateNotFoundSharpness = new Map<string, number[]>()

const availableFocusProfiles = computed(() => buildAvailableFocusProfiles(trackCapabilities.value))
const activeFocusProfile = computed(() =>
  availableFocusProfiles.value.find((item) => item.id === activeFocusProfileId.value) ?? availableFocusProfiles.value[0]!,
)
const availableZoomLevels = computed(() => resolveZoomLevels(trackCapabilities.value))
const expectedBarcode = computed(() => {
  if (testSubject.value === 'custom') {
    return customExpectedBarcode.value.trim()
  }

  if (testSubject.value === 'standard-ean') {
    return REFERENCE_EAN_VALUE
  }

  return expectedBarcodeInput.value.trim()
})
const testSubjectLabel = computed(() => getSubjectLabel(testSubject.value))
const comparisonRows = computed(() => buildComparisonTableRows(profileAggregates.value))
const incorrectValues = computed(() => summarizeIncorrectValues(readHistory.value))
const hasIncorrectReads = computed(() => incorrectValues.value.length > 0)

const correctReadAverageSharpness = computed(() => {
  if (correctSharpnessValues.value.length === 0) {
    return null
  }

  return Math.round(
    correctSharpnessValues.value.reduce((sum, value) => sum + value, 0) / correctSharpnessValues.value.length,
  )
})

const incorrectReadAverageSharpness = computed(() => {
  if (incorrectSharpnessValues.value.length === 0) {
    return null
  }

  return Math.round(
    incorrectSharpnessValues.value.reduce((sum, value) => sum + value, 0) / incorrectSharpnessValues.value.length,
  )
})

const notFoundAverageSharpness = computed(() => {
  if (notFoundSharpnessValues.value.length === 0) {
    return null
  }

  return Math.round(
    notFoundSharpnessValues.value.reduce((sum, value) => sum + value, 0) / notFoundSharpnessValues.value.length,
  )
})

const conclusion = computed(() => buildFocusSharpnessConclusion({
  stats: stats.value,
  sharpness: sharpnessStats.value,
  incorrectValues: incorrectValues.value,
  aggregates: profileAggregates.value,
}))

const overlayStyle = computed(() => {
  const box = lastBoundingBox.value

  if (!box || videoWidth.value <= 0 || videoHeight.value <= 0) {
    return null
  }

  return {
    left: `${(box.x / videoWidth.value) * 100}%`,
    top: `${(box.y / videoHeight.value) * 100}%`,
    width: `${(box.width / videoWidth.value) * 100}%`,
    height: `${(box.height / videoHeight.value) * 100}%`,
  }
})

const trackState = computed(() => getVideoTrack()?.readyState ?? '—')

const canStartDetection = computed(() =>
  cameraState.value === 'active'
  && environment.value.barcodeDetectorAvailable
  && detectorRef.value != null
  && videoWidth.value > 0
  && videoHeight.value > 0
  && settlingState.value === 'idle',
)

function isCameraSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
}

function isDetectionSessionActive(sessionId: number): boolean {
  return sessionId === detectionSessionId
}

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

function stopSharpnessPolling(): void {
  if (sharpnessTimer !== null) {
    window.clearInterval(sharpnessTimer)
    sharpnessTimer = null
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
  trackSettings.value = readTrackSettingsDetails(track)
  trackCapabilities.value = readTrackCapabilitiesDetails(track)
  videoOrientation.value = getVideoOrientationLabel(videoWidth.value, videoHeight.value)
}

function sampleSharpness(): number | null {
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!video || !canvas) {
    return null
  }

  const score = measureVideoSharpness(video, canvas)

  if (score != null) {
    sharpnessSamples.value = [...sharpnessSamples.value, score]
    sharpnessStats.value = computeSharpnessStats(sharpnessSamples.value)
  }

  return score
}

function updateTestDurations(): void {
  const now = Date.now()

  if (stats.value.testStartedAt != null) {
    stats.value = { ...stats.value, testDurationMs: now - stats.value.testStartedAt }
  }
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    refreshDiagnostics()
    updateTestDurations()
  }, 500)
}

function startSharpnessPolling(): void {
  stopSharpnessPolling()
  sharpnessTimer = window.setInterval(() => {
    if (cameraState.value === 'active' && settlingState.value === 'idle') {
      sampleSharpness()
    }
  }, SHARPNESS_SAMPLE_INTERVAL_MS)
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
    cameraError.value = serializeConstraintError(error)
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

function pushConstraintLog(entry: ConstraintLogEntry): void {
  constraintLog.value = [entry, ...constraintLog.value].slice(0, 20)
}

function updateAggregateFromSession(classification: ReadClassification, sharpness: number | null): void {
  profileAggregates.value = profileAggregates.value.map((aggregate) => {
    if (
      aggregate.focusProfileId !== activeFocusProfileId.value
      || aggregate.zoom !== activeZoom.value
      || aggregate.testSubject !== testSubjectLabel.value
    ) {
      return aggregate
    }

    const key = buildProfileKey(aggregate.focusProfileId, aggregate.zoom, testSubject.value)
    const next = { ...aggregate }
    next.attempts = stats.value.attempts
    next.correctReads = stats.value.correctReads
    next.incorrectReads = stats.value.incorrectReads
    next.unexpectedFormat = stats.value.unexpectedFormat
    next.expectedFormatWrongValue = stats.value.expectedFormatWrongValue
    next.duplicateCorrectReads = stats.value.duplicateCorrectReads
    next.notFound = stats.value.notFound
    next.errors = stats.value.errors
    next.actualFocus = trackSettings.value.focusMode
    next.actualZoom = trackSettings.value.zoom
    next.requestedFocus = requestedControls.value.focus
    next.requestedZoom = requestedControls.value.zoom

    if (sharpness != null) {
      const values = [...(aggregateSharpnessValues.get(key) ?? []), sharpness]
      aggregateSharpnessValues.set(key, values)
      next.averageSharpness = values.reduce((sum, value) => sum + value, 0) / values.length
    }

    if (classification === 'CORRECT_READ' && sharpness != null) {
      const values = [...(aggregateCorrectSharpness.get(key) ?? []), sharpness]
      aggregateCorrectSharpness.set(key, values)
      next.correctReadAverageSharpness = values.reduce((sum, value) => sum + value, 0) / values.length
    }

    if (
      (classification === 'INCORRECT_READ'
        || classification === 'EXPECTED_FORMAT_BUT_WRONG_VALUE'
        || classification === 'UNEXPECTED_FORMAT')
      && sharpness != null
    ) {
      const values = [...(aggregateIncorrectSharpness.get(key) ?? []), sharpness]
      aggregateIncorrectSharpness.set(key, values)
      next.incorrectReadAverageSharpness = values.reduce((sum, value) => sum + value, 0) / values.length
    }

    if (classification === 'NOT_FOUND' && sharpness != null) {
      const values = [...(aggregateNotFoundSharpness.get(key) ?? []), sharpness]
      aggregateNotFoundSharpness.set(key, values)
      next.notFoundAverageSharpness = values.reduce((sum, value) => sum + value, 0) / values.length
    }

    return next
  })
}

async function applyCurrentProfile(sessionId: number): Promise<void> {
  const track = getVideoTrack()

  if (!track || !activeFocusProfile.value) {
    return
  }

  stopDetectionLoop()

  const focusResult = await applyFocusProfileToTrack(track, activeFocusProfile.value)
  pushConstraintLog(focusResult)

  requestedControls.value = {
    focus: activeFocusProfile.value.constraintFocusMode ?? 'none',
    zoom: `${activeZoom.value}`,
    focusDistance: activeFocusProfile.value.constraintFocusDistance != null
      ? String(activeFocusProfile.value.constraintFocusDistance)
      : '—',
  }

  const zoomResult = await applyZoomToTrack(track, activeZoom.value, trackCapabilities.value)
  pushConstraintLog(zoomResult)

  refreshDiagnostics()
  await waitForCameraSettling(sessionId)
}

function recordRead(entry: ReadHistoryEntry, classification: ReadClassification): void {
  lastRead.value = entry
  readHistory.value = [entry, ...readHistory.value].slice(0, 30)

  if (
    classification === 'INCORRECT_READ'
    || classification === 'EXPECTED_FORMAT_BUT_WRONG_VALUE'
    || classification === 'UNEXPECTED_FORMAT'
  ) {
    lastIncorrectRead.value = entry
  }
}

function applyClassificationToStats(classification: ReadClassification): void {
  switch (classification) {
    case 'CORRECT_READ':
      stats.value = { ...stats.value, correctReads: stats.value.correctReads + 1 }
      break
    case 'DUPLICATE_CORRECT_READ':
      stats.value = { ...stats.value, duplicateCorrectReads: stats.value.duplicateCorrectReads + 1 }
      break
    case 'INCORRECT_READ':
      stats.value = { ...stats.value, incorrectReads: stats.value.incorrectReads + 1 }
      break
    case 'EXPECTED_FORMAT_BUT_WRONG_VALUE':
      stats.value = { ...stats.value, expectedFormatWrongValue: stats.value.expectedFormatWrongValue + 1 }
      break
    case 'UNEXPECTED_FORMAT':
      stats.value = { ...stats.value, unexpectedFormat: stats.value.unexpectedFormat + 1 }
      break
    case 'NOT_FOUND':
      stats.value = { ...stats.value, notFound: stats.value.notFound + 1 }
      break
    case 'ERROR':
      stats.value = { ...stats.value, errors: stats.value.errors + 1 }
      break
  }
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
  const sharpness = sampleSharpness() ?? sharpnessStats.value.current

  try {
    stats.value = { ...stats.value, attempts: stats.value.attempts + 1 }

    const results = await detector.detect(video)
    const durationMs = performance.now() - startedAt
    const best = pickBestNativeBarcode(results)
    const now = Date.now()
    const classification = classifyDetectionResult({
      barcode: best,
      expectedBarcode: expectedBarcode.value,
      expectedFormat: EXPECTED_FORMAT,
      lastCorrectValue,
      lastCorrectAt,
      now,
    })

    completedDetectionDurations.push(durationMs)
    stats.value = {
      ...stats.value,
      lastDetectionMs: durationMs,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations),
      minDetectionMs: Math.min(...completedDetectionDurations),
      maxDetectionMs: Math.max(...completedDetectionDurations),
    }

    const box = best ? extractBoundingBoxDetails(best, video.videoWidth, video.videoHeight) : null
    lastBoundingBox.value = box
    drawBoundingBoxOverlay(video, box)

    if (classification === 'CORRECT_READ') {
      lastCorrectValue = best?.rawValue ?? null
      lastCorrectAt = now

      if (sharpness != null) {
        correctSharpnessValues.value = [...correctSharpnessValues.value, sharpness]
      }
    } else if (
      classification === 'INCORRECT_READ'
      || classification === 'EXPECTED_FORMAT_BUT_WRONG_VALUE'
      || classification === 'UNEXPECTED_FORMAT'
    ) {
      if (sharpness != null) {
        incorrectSharpnessValues.value = [...incorrectSharpnessValues.value, sharpness]
      }
    } else if (classification === 'NOT_FOUND' && sharpness != null) {
      notFoundSharpnessValues.value = [...notFoundSharpnessValues.value, sharpness]
    }

    applyClassificationToStats(classification)

    const entry: ReadHistoryEntry = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      timestamp: new Date().toLocaleTimeString('fr-FR'),
      focusLabel: activeFocusProfile.value.label,
      zoomLabel: `${activeZoom.value}×`,
      rawValue: best?.rawValue ?? '',
      format: formatNativeBarcodeFormat(best?.format),
      classification,
      durationMs,
      sharpness,
      boundingBox: box?.label ?? '—',
      expectedBarcode: expectedBarcode.value,
    }

    recordRead(entry, classification)
    updateAggregateFromSession(classification, sharpness)
  } catch {
    const durationMs = performance.now() - startedAt
    applyClassificationToStats('ERROR')
    stats.value = {
      ...stats.value,
      errors: stats.value.errors,
      lastDetectionMs: durationMs,
    }
    updateAggregateFromSession('ERROR', sharpness ?? null)
  } finally {
    detectionInProgress = false
  }
}

function drawBoundingBoxOverlay(video: HTMLVideoElement, box: BoundingBoxDetails | null): void {
  const canvas = overlayCanvasRef.value

  if (!canvas) {
    return
  }

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d')

  if (!context) {
    return
  }

  context.clearRect(0, 0, canvas.width, canvas.height)

  if (!box) {
    return
  }

  context.strokeStyle = 'rgba(255, 193, 7, 0.95)'
  context.lineWidth = 3
  context.strokeRect(box.x, box.y, box.width, box.height)
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
  stats.value = { ...stats.value, testStartedAt: Date.now(), testDurationMs: 0 }
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
  stopDiagnosticsPolling()
  stopSharpnessPolling()
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
    profileAggregates.value = createInitialProfileAggregates(readTrackCapabilitiesDetails(getVideoTrack()), testSubject.value)

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
      throw new Error('La vidéo n\'est pas devenue active.')
    }

    refreshDiagnostics()
    await applyCurrentProfile(sessionId)

    cameraState.value = 'active'
    startDiagnosticsPolling()
    startSharpnessPolling()
    sampleSharpness()
  } catch (error) {
    if (isCameraSessionActive(sessionId)) {
      cameraError.value = serializeConstraintError(error)
      cameraState.value = 'error'
      stopTracks(activeStream.value)
      activeStream.value = null
    }
  }
}

async function selectFocusProfile(profileId: FocusProfileId): Promise<void> {
  const profile = availableFocusProfiles.value.find((item) => item.id === profileId)

  if (!profile || !isFocusProfileAvailable(profile, trackCapabilities.value)) {
    return
  }

  activeFocusProfileId.value = profileId
  stats.value = createEmptySessionStats()
  completedDetectionDurations.length = 0
  sharpnessSamples.value = []
  sharpnessStats.value = computeSharpnessStats([])
  correctSharpnessValues.value = []
  incorrectSharpnessValues.value = []
  notFoundSharpnessValues.value = []

  if (cameraState.value === 'active') {
    await applyCurrentProfile(cameraSessionId)
  }
}

async function selectZoom(zoom: number): Promise<void> {
  if (!availableZoomLevels.value.includes(zoom)) {
    return
  }

  activeZoom.value = zoom
  stats.value = createEmptySessionStats()
  completedDetectionDurations.length = 0
  sharpnessSamples.value = []
  sharpnessStats.value = computeSharpnessStats([])

  if (cameraState.value === 'active') {
    await applyCurrentProfile(cameraSessionId)
  }
}

function selectTestSubject(subject: TestSubjectId): void {
  testSubject.value = subject
  profileAggregates.value = createInitialProfileAggregates(trackCapabilities.value, subject)
  stats.value = createEmptySessionStats()
  completedDetectionDurations.length = 0
}

function resetStatistics(): void {
  stopDetectionLoop()
  stats.value = createEmptySessionStats()
  profileAggregates.value = createInitialProfileAggregates(trackCapabilities.value, testSubject.value)
  readHistory.value = []
  constraintLog.value = []
  lastRead.value = null
  lastIncorrectRead.value = null
  lastBoundingBox.value = null
  completedDetectionDurations.length = 0
  sharpnessSamples.value = []
  sharpnessStats.value = computeSharpnessStats([])
  correctSharpnessValues.value = []
  incorrectSharpnessValues.value = []
  notFoundSharpnessValues.value = []
  aggregateDetectionDurations.clear()
  aggregateSharpnessValues.clear()
  aggregateCorrectSharpness.clear()
  aggregateIncorrectSharpness.clear()
  aggregateNotFoundSharpness.clear()
  lastCorrectValue = null
  lastCorrectAt = null
}

function captureFrame(): void {
  const video = videoRef.value
  const canvas = document.createElement('canvas')

  if (!video || video.videoWidth <= 0 || video.videoHeight <= 0) {
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

  if (lastBoundingBox.value) {
    context.strokeStyle = 'rgba(255, 193, 7, 0.95)'
    context.lineWidth = 3
    context.strokeRect(
      lastBoundingBox.value.x,
      lastBoundingBox.value.y,
      lastBoundingBox.value.width,
      lastBoundingBox.value.height,
    )
    capturedFrameWithBoxUrl.value = canvas.toDataURL('image/jpeg', 0.85)
  } else {
    capturedFrameWithBoxUrl.value = null
  }
}

async function copyDiagnostic(): Promise<void> {
  const text = buildFocusSharpnessDiagnosticClipboard({
    environment: environment.value,
    supportedFormats: supportedFormats.value,
    detectorCreationNote: detectorCreationNote.value,
    requestedWidth: 1280,
    requestedHeight: 720,
    trackSettings: trackSettings.value,
    trackDiagnostics: {
      trackState: getVideoTrack()?.readyState ?? '—',
      resolution: `${trackSettings.value.width ?? '—'} × ${trackSettings.value.height ?? '—'}`,
      frameRate: trackSettings.value.frameRate,
      facingMode: trackSettings.value.facingMode,
    },
    capabilities: trackCapabilities.value,
    requestedControls: requestedControls.value,
    expectedBarcode: expectedBarcode.value,
    testSubject: testSubjectLabel.value,
    stats: stats.value,
    sharpness: sharpnessStats.value,
    correctReadAverageSharpness: correctReadAverageSharpness.value,
    incorrectReadAverageSharpness: incorrectReadAverageSharpness.value,
    notFoundAverageSharpness: notFoundAverageSharpness.value,
    aggregates: profileAggregates.value,
    history: readHistory.value,
    constraintLog: constraintLog.value,
    incorrectValues: incorrectValues.value,
    conclusion: conclusion.value,
  })

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
  <Head title="Test Focus + Netteté BarcodeDetector" />

  <div class="barcode-reader-test-page barcode-detector-focus-sharpness-test">
    <div class="barcode-reader-test-page__container barcode-detector-focus-sharpness-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Focus + Netteté + Lectures</h1>
          <p class="barcode-reader-test-page__subtitle">
            Laboratoire DEV — focus réel, zoom matériel, netteté et exactitude des lectures BarcodeDetector.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
      </header>

      <section class="barcode-detector-focus-sharpness-test__section barcode-detector-focus-sharpness-test__section--video">
        <div class="barcode-detector-focus-sharpness-test__video-wrap">
          <video ref="videoRef" class="barcode-detector-focus-sharpness-test__video" autoplay muted playsinline />
          <canvas ref="overlayCanvasRef" class="barcode-detector-focus-sharpness-test__overlay-canvas" />
          <div
            v-if="overlayStyle"
            class="barcode-detector-focus-sharpness-test__bbox-indicator"
            :style="overlayStyle"
          />
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-detector-focus-sharpness-test__hidden-canvas" aria-hidden="true" />

        <div class="barcode-detector-focus-sharpness-test__actions">
          <button type="button" class="btn btn-sm btn-primary" :disabled="cameraState === 'starting'" @click="startCamera">Start camera</button>
          <button type="button" class="btn btn-sm btn-outline-danger" :disabled="cameraState === 'idle'" @click="stopCamera">Stop camera</button>
          <button type="button" class="btn btn-sm btn-success" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="startDetectionLoop">Start detection</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="detectionLoopState !== 'running'" @click="stopDetectionLoop">Stop detection</button>
          <button type="button" class="btn btn-sm btn-outline-primary" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="detectNow">Detect now</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="resetStatistics">Reset statistics</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="cameraState !== 'active'" @click="captureFrame">Capture frame</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="copyDiagnostic">Copier diagnostic</button>
        </div>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">BarcodeDetector</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div><dt>Available</dt><dd>{{ environment.barcodeDetectorAvailable ? 'YES' : 'NO' }}</dd></div>
          <div><dt>CPU-friendly</dt><dd>YES</dd></div>
          <div><dt>Interval</dt><dd>{{ DETECTION_INTERVAL_MS }} ms</dd></div>
          <div class="barcode-detector-focus-sharpness-test__grid-full"><dt>Formats</dt><dd>{{ supportedFormats.join(', ') || '—' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Camera</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div><dt>Camera</dt><dd>{{ cameraState === 'active' ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Stream</dt><dd>{{ streamActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Track</dt><dd>{{ trackState }}</dd></div>
          <div><dt>Requested</dt><dd>1280 × 720</dd></div>
          <div><dt>Actual</dt><dd>{{ trackSettings.width ?? '—' }} × {{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>FPS</dt><dd>{{ trackSettings.frameRate }}</dd></div>
          <div><dt>Facing</dt><dd>{{ trackSettings.facingMode }}</dd></div>
          <div><dt>Orientation</dt><dd>{{ videoOrientation }}</dd></div>
          <div><dt>Camera settling</dt><dd>{{ settlingState === 'waiting' ? `${settlingElapsedMs} ms` : 'idle' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Capabilities vs Settings vs Requested</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div class="barcode-detector-focus-sharpness-test__grid-full"><dt>Capabilities focus</dt><dd>{{ trackCapabilities.focus.modes.join(', ') || 'Non supporté par le navigateur/appareil' }}</dd></div>
          <div class="barcode-detector-focus-sharpness-test__grid-full"><dt>Settings focus</dt><dd>{{ trackSettings.focusMode }}</dd></div>
          <div class="barcode-detector-focus-sharpness-test__grid-full"><dt>Requested focus</dt><dd>{{ requestedControls.focus }}</dd></div>
          <div><dt>Zoom supported</dt><dd>{{ trackCapabilities.zoom.supported ? 'supported' : 'unavailable' }}</dd></div>
          <div><dt>Zoom min/max/step</dt><dd>{{ trackCapabilities.zoom.min ?? '—' }} / {{ trackCapabilities.zoom.max ?? '—' }} / {{ trackCapabilities.zoom.step ?? '—' }}</dd></div>
          <div><dt>Settings zoom</dt><dd>{{ trackSettings.zoom }}</dd></div>
          <div><dt>Requested zoom</dt><dd>{{ requestedControls.zoom }}×</dd></div>
          <div class="barcode-detector-focus-sharpness-test__grid-full">
            <dt>Focus distance</dt>
            <dd>
              <template v-if="trackCapabilities.focus.distance.supported">
                min {{ trackCapabilities.focus.distance.min }} / max {{ trackCapabilities.focus.distance.max }} / step {{ trackCapabilities.focus.distance.step }} — applied {{ trackSettings.focusDistance }}
              </template>
              <template v-else>Focus manuel disponible mais aucune distance de focus exploitable exposée par cet appareil.</template>
            </dd>
          </div>
        </dl>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Focus</h2>
        <div class="barcode-detector-focus-sharpness-test__actions">
          <button
            v-for="profile in availableFocusProfiles"
            :key="profile.id"
            type="button"
            class="btn btn-sm"
            :class="activeFocusProfileId === profile.id ? 'btn-warning' : 'btn-outline-warning'"
            :disabled="!isFocusProfileAvailable(profile, trackCapabilities)"
            @click="selectFocusProfile(profile.id)"
          >
            {{ profile.label }}
          </button>
        </div>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Zoom matériel</h2>
        <div class="barcode-detector-focus-sharpness-test__actions">
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

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Netteté</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div><dt>Sharpness score</dt><dd>{{ sharpnessStats.current ?? '—' }}</dd></div>
          <div><dt>Average</dt><dd>{{ sharpnessStats.average ?? '—' }}</dd></div>
          <div><dt>Min</dt><dd>{{ sharpnessStats.min ?? '—' }}</dd></div>
          <div><dt>Max</dt><dd>{{ sharpnessStats.max ?? '—' }}</dd></div>
          <div><dt>Focus stability</dt><dd>{{ sharpnessStats.stability }}</dd></div>
          <div><dt>Std dev</dt><dd>{{ sharpnessStats.standardDeviation ?? '—' }}</dd></div>
        </dl>
        <p class="barcode-detector-focus-sharpness-test__muted mb-0">
          Le score de netteté est relatif et dépend de l'image, de la caméra et des conditions de prise de vue.
        </p>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Code attendu</h2>
        <div class="barcode-detector-focus-sharpness-test__actions mb-2">
          <button type="button" class="btn btn-sm" :class="testSubject === 'small-ean' ? 'btn-primary' : 'btn-outline-primary'" @click="selectTestSubject('small-ean')">Small EAN-13</button>
          <button type="button" class="btn btn-sm" :class="testSubject === 'standard-ean' ? 'btn-primary' : 'btn-outline-primary'" @click="selectTestSubject('standard-ean')">Standard EAN-13</button>
          <button type="button" class="btn btn-sm" :class="testSubject === 'custom' ? 'btn-primary' : 'btn-outline-primary'" @click="selectTestSubject('custom')">Custom</button>
        </div>
        <div v-if="testSubject === 'small-ean'" class="mb-2">
          <label class="form-label">Expected barcode (petit EAN imprimé)</label>
          <input v-model="expectedBarcodeInput" type="text" class="form-control form-control-sm font-monospace" placeholder="Saisir le code imprimé">
        </div>
        <div v-else-if="testSubject === 'custom'" class="mb-2">
          <label class="form-label">Expected barcode</label>
          <input v-model="customExpectedBarcode" type="text" class="form-control form-control-sm font-monospace">
        </div>
        <p v-else class="barcode-detector-focus-sharpness-test__muted mb-0">
          Expected barcode : <span class="font-monospace">{{ REFERENCE_EAN_VALUE }}</span> ({{ REFERENCE_EAN_FORMAT }})
        </p>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Statistiques</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div><dt>Attempts</dt><dd>{{ stats.attempts }}</dd></div>
          <div><dt>Correct reads</dt><dd>{{ stats.correctReads }}</dd></div>
          <div><dt>Incorrect reads</dt><dd>{{ stats.incorrectReads + stats.expectedFormatWrongValue + stats.unexpectedFormat }}</dd></div>
          <div><dt>Not found</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Correct rate</dt><dd>{{ computeCorrectRate(stats) }}</dd></div>
          <div><dt>Incorrect rate</dt><dd>{{ computeIncorrectRate(stats) }}</dd></div>
          <div><dt>Not found rate</dt><dd>{{ computeNotFoundRate(stats) }}</dd></div>
        </dl>
      </section>

      <section v-if="hasIncorrectReads" class="barcode-detector-focus-sharpness-test__section barcode-detector-focus-sharpness-test__alert">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">⚠️ Lectures incorrectes</h2>
        <p class="mb-2">Des valeurs différentes du code attendu ont été détectées. Ne pas utiliser ces lectures comme preuve de réussite.</p>
        <p v-if="lastIncorrectRead" class="mb-1"><strong>Expected:</strong> <span class="font-monospace">{{ expectedBarcode || '—' }}</span></p>
        <p v-if="lastIncorrectRead" class="mb-2"><strong>Detected:</strong> <span class="font-monospace">{{ lastIncorrectRead.rawValue }}</span></p>
        <p class="mb-0">Unique incorrect values: {{ incorrectValues.length }}</p>
        <ul class="mb-0">
          <li v-for="item in incorrectValues" :key="item.value" class="font-monospace">{{ item.value }} ({{ item.count }})</li>
        </ul>
      </section>

      <section v-if="lastBoundingBox" class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Taille apparente du code</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div><dt>Barcode box</dt><dd>{{ lastBoundingBox.label }}</dd></div>
          <div><dt>Relative width</dt><dd>{{ lastBoundingBox.relativeWidthPercent }} % of frame</dd></div>
          <div><dt>Relative height</dt><dd>{{ lastBoundingBox.relativeHeightPercent }} % of frame</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Corrélation netteté / lecture</h2>
        <dl class="barcode-detector-focus-sharpness-test__grid">
          <div><dt>Correct read avg sharpness</dt><dd>{{ correctReadAverageSharpness ?? '—' }}</dd></div>
          <div><dt>Incorrect read avg sharpness</dt><dd>{{ incorrectReadAverageSharpness ?? '—' }}</dd></div>
          <div><dt>Not found avg sharpness</dt><dd>{{ notFoundAverageSharpness ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Protocole recommandé</h2>
        <pre class="barcode-detector-focus-sharpness-test__pre mb-0">Petit EAN-13 :
1. Distance, éclairage et position fixes.
2. DEFAULT + 1×, 2×, 4× (20–30 s chacun).
3. CONTINUOUS + 1×, 2×, 4× si disponible.
4. SINGLE-SHOT si disponible.

Ensuite répéter avec 6202312030117.</pre>
      </section>

      <section class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Matrice de test</h2>
        <div class="barcode-detector-focus-sharpness-test__cards">
          <article v-for="row in comparisonRows" :key="`${row.focusLabel}-${row.zoomLabel}-${row.testSubject}`" class="barcode-detector-focus-sharpness-test__card">
            <h3>{{ row.focusLabel }} + {{ row.zoomLabel }}</h3>
            <p>{{ row.testSubject }}</p>
            <dl>
              <div><dt>Correct</dt><dd>{{ row.correct }}</dd></div>
              <div><dt>Incorrect</dt><dd>{{ row.incorrect }}</dd></div>
              <div><dt>Not found</dt><dd>{{ row.notFound }}</dd></div>
              <div><dt>Sharpness</dt><dd>{{ row.averageSharpness }}</dd></div>
            </dl>
          </article>
        </div>
      </section>

      <section v-if="readHistory.length > 0" class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Historique (30 dernières lectures)</h2>
        <div class="barcode-detector-focus-sharpness-test__history">
          <div v-for="entry in readHistory" :key="entry.id" class="barcode-detector-focus-sharpness-test__history-item font-monospace">
            {{ entry.timestamp }} — {{ entry.focusLabel }} {{ entry.zoomLabel }} — {{ entry.rawValue || '—' }} — {{ entry.format }} — {{ entry.classification }} — {{ formatDurationMs(entry.durationMs) }} — Sharpness: {{ entry.sharpness ?? '—' }}
          </div>
        </div>
      </section>

      <section v-if="capturedFrameUrl" class="barcode-detector-focus-sharpness-test__section">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Capture frame</h2>
        <p class="barcode-detector-focus-sharpness-test__muted">Capture visuelle uniquement — non utilisée pour le décodage.</p>
        <p>Sharpness: {{ capturedSharpness ?? '—' }}</p>
        <img :src="capturedFrameUrl" alt="Original camera frame" class="barcode-detector-focus-sharpness-test__preview-image">
        <img v-if="capturedFrameWithBoxUrl" :src="capturedFrameWithBoxUrl" alt="Frame with bounding box" class="barcode-detector-focus-sharpness-test__preview-image">
      </section>

      <section class="barcode-detector-focus-sharpness-test__section barcode-detector-focus-sharpness-test__conclusion">
        <h2 class="barcode-detector-focus-sharpness-test__section-title">Conclusion</h2>
        <pre class="barcode-detector-focus-sharpness-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-detector-focus-sharpness-test__muted mb-0">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-detector-focus-sharpness-test__warning mb-0">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
