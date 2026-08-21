<script setup lang="ts">
import {
  analyzeMultiFrameStrategies,
  applyExperimentConfiguration,
  buildDecodePatternAnalysis,
  buildReliabilityConclusion,
  buildReliabilityConfigurations,
  buildReliabilityCsv,
  buildReliabilityExportJson,
  buildReliabilityReport,
  buildResolutionConstraints,
  calculateBarcodeSizeRatio,
  captureFrameWithCrop,
  createComparisonBarcodeDetector,
  createEmptyReliabilityResult,
  DEFAULT_DURATION_SECONDS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
  DEFAULT_SETTLE_MS,
  DETECTION_INTERVAL_MS,
  DURATION_OPTIONS,
  EXPERIMENTAL_FOCUS,
  finalizeReliabilityResult,
  FIXED_ZOOM,
  getEnvironmentDiagnostics,
  isCorrectRead,
  isManualFocusSupported,
  isNativeBarcodeDetectorAvailable,
  isValidEan13,
  isWidthRatioInTarget,
  measureVideoSharpness,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  RESOLUTION_PRESETS,
  SETTLE_OPTIONS,
  SIZE_TARGET_SPECS,
  type AppliedExperimentSnapshot,
  type CapturedFrameRecord,
  type InputMode,
  type OrderMode,
  type ReliabilityConfiguration,
  type ReliabilityConfigurationResult,
  type ReliabilityRawDetection,
  type SizeTargetSpec,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import type { BarcodeDetectorLike } from '@/utils/barcodeSizeZoomComparison'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type RunUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED'

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const decodeCanvasRef = ref<HTMLCanvasElement | null>(null)
const debugCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const runState = ref<RunUiState>('IDLE')
const cameraError = ref<string | null>(null)
const copyMessage = ref<string | null>(null)

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const expectedFormat = ref(DEFAULT_EXPECTED_FORMAT)
const physicalConfirmed = ref(false)
const inputMode = ref<InputMode>('VIDEO')
const orderMode = ref<OrderMode>('RANDOMIZED')
const randomSeed = ref<number | null>(null)
const durationSeconds = ref<number>(DEFAULT_DURATION_SECONDS)
const settleMs = ref<number>(DEFAULT_SETTLE_MS)
const debugVisual = ref(false)

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<ReliabilityConfiguration[]>([])
const results = ref<ReliabilityConfigurationResult[]>([])
const rawDetections = ref<ReliabilityRawDetection[]>([])
const capturedFrames = ref<CapturedFrameRecord[]>([])
const lastApplied = ref<AppliedExperimentSnapshot | null>(null)

const currentConfig = ref<ReliabilityConfiguration | null>(null)
const currentConfigIndex = ref(0)
const elapsedSeconds = ref(0)
const remainingSeconds = ref(0)

const liveStatus = ref({
  rawValue: '—',
  format: '—',
  sharpness: '—',
  bboxWidth: '—',
  bboxHeight: '—',
  widthRatio: '—',
  heightRatio: '—',
  checkDigit: '—',
  status: '—',
})

const lastDetectionForCapture = ref<{
  rawValue: string
  format: string
  sharpness: number | null
  boundingBox: { x: number; y: number; width: number; height: number } | null
  widthRatio: number | null
} | null>(null)

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let countdownTimer: number | null = null

const manualFocusSupported = computed(() => isManualFocusSupported(capabilities.value))
const configCount = computed(() => configurations.value.length)

const decodePatterns = computed(() => buildDecodePatternAnalysis(rawDetections.value, expectedBarcode.value))

const multiFrameAnalysis = computed(() =>
  analyzeMultiFrameStrategies(
    rawDetections.value.map((item) => ({
      rawValue: item.rawValue,
      classification: item.classification,
    })),
    expectedBarcode.value,
  ),
)

const conclusion = computed(() => buildReliabilityConclusion(results.value, physicalConfirmed.value))

const activeSizeMatch = computed(() => {
  const ratio = lastDetectionForCapture.value?.widthRatio ?? null
  return SIZE_TARGET_SPECS.filter((target) => isWidthRatioInTarget(ratio, target))
})

const progressPercent = computed(() =>
  configCount.value > 0 ? Math.round((currentConfigIndex.value / configCount.value) * 100) : 0,
)

const canStartExperiment = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && manualFocusSupported.value
  && expectedBarcode.value.trim().length > 0
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
  if (countdownTimer !== null) {
    window.clearInterval(countdownTimer)
    countdownTimer = null
  }
}

function rebuildConfigurations(): void {
  configurations.value = buildReliabilityConfigurations({
    resolutionPresets: RESOLUTION_PRESETS,
    sizeTargets: SIZE_TARGET_SPECS,
    focusRequested: EXPERIMENTAL_FOCUS,
    expectedBarcode: expectedBarcode.value,
    expectedFormat: expectedFormat.value,
    orderMode: orderMode.value,
    randomSeed: randomSeed.value ?? Date.now(),
    preserveOrder: orderMode.value === 'FIXED' ? configurations.value : undefined,
  })

  if (orderMode.value === 'RANDOMIZED' && randomSeed.value == null) {
    randomSeed.value = Date.now()
  }

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyReliabilityResult(config)
  })
}

function resetExperiment(): void {
  stopRun()
  rawDetections.value = []
  capturedFrames.value = []
  results.value = configurations.value.map((config) => createEmptyReliabilityResult(config))
  currentConfig.value = null
  currentConfigIndex.value = 0
  randomSeed.value = orderMode.value === 'RANDOMIZED' ? Date.now() : null
  rebuildConfigurations()
}

function refreshDiagnostics(): void {
  const track = getVideoTrack()
  capabilities.value = readTrackCapabilitiesSnapshot(track)
  trackSettings.value = readTrackSettingsSnapshot(track)
}

async function startCameraWithPreset(preset = RESOLUTION_PRESETS[0]!): Promise<void> {
  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'STARTING'
  cameraError.value = null

  try {
    stopTracks(activeStream.value)

    const stream = await navigator.mediaDevices.getUserMedia(buildResolutionConstraints(preset))

    if (sessionId !== cameraSessionId) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Video element unavailable')
    }

    video.srcObject = stream
    video.playsInline = true
    video.muted = true

    await Promise.race([
      video.play(),
      new Promise<never>((_, reject) => {
        window.setTimeout(() => reject(new Error('Video play timeout')), START_TIMEOUT_MS)
      }),
    ])

    await waitForVideoDimensions(video)

    refreshDiagnostics()

    if (!detectorRef.value) {
      const detectorResult = await createComparisonBarcodeDetector()
      detectorRef.value = detectorResult.detector
    }

    cameraState.value = 'READY'
  } catch (error) {
    cameraState.value = 'ERROR'

    if (error instanceof DOMException && error.name === 'NotAllowedError') {
      cameraError.value = 'Camera permission denied'
    } else {
      cameraError.value = error instanceof Error ? error.message : 'Camera stream failed'
    }
  }
}

async function waitForVideoDimensions(video: HTMLVideoElement, timeoutMs = 5000): Promise<void> {
  const startedAt = performance.now()

  while (video.videoWidth <= 0 || video.videoHeight <= 0) {
    if (performance.now() - startedAt > timeoutMs) {
      throw new Error('Video dimensions timeout')
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }
}

async function applyConfigurationResolution(preset: typeof RESOLUTION_PRESETS[number]): Promise<void> {
  const track = getVideoTrack()
  const video = videoRef.value

  if (!track || !video) {
    return
  }

  try {
    await track.applyConstraints({
      width: { ideal: preset.width },
      height: { ideal: preset.height },
      facingMode: 'environment',
    })
  } catch {
    stopTracks(activeStream.value)
    await startCameraWithPreset(preset)
    return
  }

  await new Promise((resolve) => window.setTimeout(resolve, 1000))
  await waitForVideoDimensions(video)
  refreshDiagnostics()
}

function drawDebugOverlay(
  video: HTMLVideoElement,
  detections: Array<{ boundingBox: { x: number; y: number; width: number; height: number } | null }>,
): void {
  const canvas = debugCanvasRef.value

  if (!canvas || !debugVisual.value) {
    return
  }

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d')

  if (!context) {
    return
  }

  context.clearRect(0, 0, canvas.width, canvas.height)

  for (const target of SIZE_TARGET_SPECS) {
    const width = video.videoWidth * target.guideWidthRatio
    const height = width * 0.5
    const x = (video.videoWidth - width) / 2
    const y = (video.videoHeight - height) / 2
    context.strokeStyle = 'rgba(255,255,255,0.35)'
    context.strokeRect(x, y, width, height)
  }

  for (const detection of detections) {
    if (!detection.boundingBox) {
      continue
    }

    const { x, y, width, height } = detection.boundingBox
    context.strokeStyle = '#00ff88'
    context.lineWidth = 2
    context.strokeRect(x, y, width, height)
    context.fillStyle = '#00ff88'
    context.fillText(`${width}×${height}`, x, Math.max(12, y - 4))
  }

  context.fillStyle = '#ffffff'
  context.font = '12px monospace'
  context.fillText(`video ${video.videoWidth}×${video.videoHeight}`, 8, 16)
  context.fillText(`focus ${lastApplied.value?.actualFocusDistance ?? '—'}`, 8, 32)
  context.fillText(`zoom ${lastApplied.value?.actualZoom ?? '—'}×`, 8, 48)
}

async function detectFromInput(
  video: HTMLVideoElement,
  detector: BarcodeDetectorLike,
  mode: InputMode,
): Promise<ReturnType<typeof normalizeDetections>> {
  if (mode === 'VIDEO') {
    return normalizeDetections(await detector.detect(video))
  }

  const canvas = decodeCanvasRef.value

  if (!canvas) {
    return []
  }

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d', { willReadFrequently: true })

  if (!context) {
    return []
  }

  context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight)

  return normalizeDetections(await detector.detect(canvas))
}

async function runConfiguration(
  config: ReliabilityConfiguration,
  configIndex: number,
  sessionId: number,
): Promise<ReliabilityConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyReliabilityResult(config)
  }

  currentConfig.value = config
  currentConfigIndex.value = configIndex + 1

  await applyConfigurationResolution(config.resolutionPreset)

  const applied = await applyExperimentConfiguration(track, {
    requestedFocusDistance: config.focusRequested,
    requestedZoom: config.requestedZoom,
    focusDistanceCapabilities: capabilities.value.focusDistance,
    zoomStep: capabilities.value.zoom.step,
  })

  lastApplied.value = applied

  if (applied.configurationStatus !== 'VALID') {
    return finalizeReliabilityResult(config, applied, {
      inputMode: inputMode.value,
      actualWidth: video.videoWidth,
      actualHeight: video.videoHeight,
      actualFrameRate: trackSettings.value.frameRate,
      frames: 0,
      detections: [],
      sharpnessValues: [],
      widthRatios: [],
    })
  }

  await new Promise((resolve) => window.setTimeout(resolve, settleMs.value))

  if (runAbort || sessionId !== runSessionId) {
    return createEmptyReliabilityResult(config)
  }

  let frames = 0
  const configDetections: ReliabilityRawDetection[] = []
  const sharpnessValues: number[] = []
  const widthRatios: number[] = []
  let detectionInProgress = false
  let lastDetectionTime = 0

  const startedAt = performance.now()
  const endAt = startedAt + durationSeconds.value * 1000
  remainingSeconds.value = durationSeconds.value
  elapsedSeconds.value = 0

  stopTimers()
  countdownTimer = window.setInterval(() => {
    const elapsed = performance.now() - startedAt
    elapsedSeconds.value = Math.floor(elapsed / 1000)
    remainingSeconds.value = Math.max(0, Math.ceil((endAt - performance.now()) / 1000))
  }, 200)

  await new Promise<void>((resolve) => {
    const tick = (timestamp: number): void => {
      if (runAbort || sessionId !== runSessionId || performance.now() >= endAt) {
        resolve()
        return
      }

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp

        void (async () => {
          const elapsedMs = Math.round(performance.now() - startedAt)
          const sharpness = measureVideoSharpness(video, canvas)

          if (sharpness != null) {
            sharpnessValues.push(sharpness)
          }

          try {
            frames += 1
            const rawResults = await detectFromInput(video, detector, inputMode.value)
            drawDebugOverlay(video, rawResults)

            if (rawResults.length === 0) {
              liveStatus.value.status = frames > 0 ? 'NO DETECTION' : '—'
            }

            for (const result of rawResults) {
              const rawValue = result.rawValue ?? ''
              const format = result.format ?? '—'

              if (!rawValue || !result.boundingBox) {
                continue
              }

              const ratios = calculateBarcodeSizeRatio(result.boundingBox, video.videoWidth, video.videoHeight)
              const classification = isCorrectRead(rawValue, format, config.expectedBarcode, config.expectedFormat)
                ? 'CORRECT'
                : 'INCORRECT'
              const checkDigitValid = isValidEan13(rawValue)
              const timestamp = new Date().toLocaleTimeString('fr-FR')

              const entry: ReliabilityRawDetection = {
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                timestamp,
                elapsedMs,
                configId: config.id,
                resolutionLabel: config.resolutionPreset.label,
                sizeTargetLabel: config.sizeTarget.label,
                inputMode: inputMode.value,
                focusRequested: config.focusRequested,
                focusActual: applied.actualFocusDistance,
                zoomActual: applied.actualZoom,
                format,
                rawValue,
                classification,
                checkDigitValid,
                hammingDistance: rawValue.length === config.expectedBarcode.length
                  ? rawValue.split('').reduce((acc, char, index) => acc + (char === config.expectedBarcode[index] ? 0 : 1), 0)
                  : null,
                matchingDigits: rawValue.split('').reduce((acc, char, index) => acc + (char === config.expectedBarcode[index] ? 1 : 0), 0),
                boundingBox: result.boundingBox,
                widthRatio: ratios.widthRatio,
                heightRatio: ratios.heightRatio,
                videoWidth: video.videoWidth,
                videoHeight: video.videoHeight,
                sharpness,
              }

              configDetections.push(entry)
              rawDetections.value = [entry, ...rawDetections.value]

              if (ratios.widthRatio != null) {
                widthRatios.push(ratios.widthRatio)
              }

              lastDetectionForCapture.value = {
                rawValue,
                format,
                sharpness,
                boundingBox: result.boundingBox,
                widthRatio: ratios.widthRatio,
              }

              liveStatus.value = {
                rawValue,
                format,
                sharpness: sharpness != null ? String(Math.round(sharpness)) : '—',
                bboxWidth: String(Math.round(result.boundingBox.width)),
                bboxHeight: String(Math.round(result.boundingBox.height)),
                widthRatio: ratios.widthRatio != null ? `${(ratios.widthRatio * 100).toFixed(1)}%` : '—',
                heightRatio: ratios.heightRatio != null ? `${(ratios.heightRatio * 100).toFixed(1)}%` : '—',
                checkDigit: checkDigitValid ? 'VALID' : 'INVALID',
                status: classification === 'CORRECT' ? 'CORRECT READ' : physicalConfirmed.value ? 'INCORRECT DECODING' : 'INCORRECT (physical not confirmed)',
              }
            }
          } catch {
            liveStatus.value.status = 'Detector error'
          } finally {
            detectionInProgress = false
          }
        })()
      }

      requestAnimationFrame(tick)
    }

    requestAnimationFrame(tick)
  })

  stopTimers()
  refreshDiagnostics()

  return finalizeReliabilityResult(config, applied, {
    inputMode: inputMode.value,
    actualWidth: video.videoWidth,
    actualHeight: video.videoHeight,
    actualFrameRate: trackSettings.value.frameRate,
    frames,
    detections: configDetections,
    sharpnessValues,
    widthRatios,
  })
}

async function startExperiment(): Promise<void> {
  if (!canStartExperiment.value) {
    return
  }

  rebuildConfigurations()

  const configs = [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex)
  const minutes = Math.ceil((configs.length * (durationSeconds.value + settleMs.value / 1000)) / 60)
  const confirmed = window.confirm(`Run ${configs.length} configurations ≈ ${minutes} min?\n\nFocus expérimental: ${EXPERIMENTAL_FOCUS} — NON VALIDÉ`)

  if (!confirmed) {
    return
  }

  stopRun()
  runSessionId += 1
  const sessionId = runSessionId
  runAbort = false
  runState.value = 'RUNNING'
  rawDetections.value = []
  capturedFrames.value = []
  results.value = configs.map((config) => createEmptyReliabilityResult(config))

  for (let index = 0; index < configs.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = configs[index]!
    const result = await runConfiguration(config, index, sessionId)
    results.value = results.value.map((item) => item.configId === config.id ? result : item)
  }

  currentConfig.value = null
  stopTimers()

  if (runAbort) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
}

function captureCurrentFrame(): void {
  const video = videoRef.value
  const config = currentConfig.value
  const detection = lastDetectionForCapture.value

  if (!video || !config || !detection) {
    copyMessage.value = 'Aucune détection à capturer.'
    return
  }

  const capture = captureFrameWithCrop(video, detection.boundingBox)

  capturedFrames.value = [{
    id: `${Date.now()}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    configId: config.id,
    fullFrameDataUrl: capture.fullFrameDataUrl,
    cropDataUrl: capture.cropDataUrl,
    resolutionLabel: config.resolutionPreset.label,
    focusRequested: config.focusRequested,
    focusActual: lastApplied.value?.actualFocusDistance ?? '—',
    zoomRequested: config.requestedZoom,
    zoomActual: lastApplied.value?.actualZoom ?? '—',
    rawValue: detection.rawValue,
    format: detection.format,
    sharpness: detection.sharpness,
    boundingBox: detection.boundingBox,
    widthRatio: detection.widthRatio,
  }, ...capturedFrames.value]

  copyMessage.value = 'Frame capturée.'
}

async function stopCamera(): Promise<void> {
  stopRun()
  stopTimers()
  cameraSessionId += 1

  stopTracks(activeStream.value)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  detectorRef.value = null
  refreshDiagnostics()
  cameraState.value = 'IDLE'
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1
  stopTimers()
  currentConfig.value = null

  if (runState.value === 'RUNNING') {
    runState.value = 'STOPPED'
  }
}

function buildReportPayload() {
  return {
    metadata: { exportedAt: new Date().toISOString(), experiment: 'BARCODE_DECODE_RELIABILITY' },
    environment: environment.value,
    camera: trackSettings.value,
    capabilities: capabilities.value,
    testParameters: {
      expectedBarcode: expectedBarcode.value,
      expectedFormat: expectedFormat.value,
      experimentalFocus: EXPERIMENTAL_FOCUS,
      inputMode: inputMode.value,
      durationSeconds: durationSeconds.value,
      settleMs: settleMs.value,
      orderMode: orderMode.value,
      randomSeed: randomSeed.value,
      physicalConfirmed: physicalConfirmed.value,
    },
    configurationOrder: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
    configurationResults: [...results.value].sort((a, b) => a.orderIndex - b.orderIndex),
    rawDetections: rawDetections.value,
    decodePatterns: decodePatterns.value,
    multiFrameAnalysis: multiFrameAnalysis.value,
    capturedFrames: capturedFrames.value.map(({ fullFrameDataUrl, cropDataUrl, ...rest }) => ({
      ...rest,
      hasFullFrame: Boolean(fullFrameDataUrl),
      hasCrop: Boolean(cropDataUrl),
    })),
    conclusion: conclusion.value,
  }
}

async function copyReport(): Promise<void> {
  try {
    await navigator.clipboard.writeText(buildReliabilityReport({
      environment: environment.value,
      trackSettings: trackSettings.value,
      capabilities: capabilities.value,
      expectedBarcode: expectedBarcode.value,
      expectedFormat: expectedFormat.value,
      inputMode: inputMode.value,
      durationSeconds: durationSeconds.value,
      settleMs: settleMs.value,
      orderMode: orderMode.value,
      randomSeed: randomSeed.value,
      physicalConfirmed: physicalConfirmed.value,
      configurationOrder: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
      results: [...results.value].sort((a, b) => a.orderIndex - b.orderIndex),
      rawDetections: rawDetections.value,
      decodePatterns: decodePatterns.value,
      multiFrameAnalysis: multiFrameAnalysis.value,
      capturedFrames: capturedFrames.value,
      conclusion: conclusion.value,
    }))
    copyMessage.value = 'Rapport copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function exportJson(): void {
  const blob = new Blob([buildReliabilityExportJson(buildReportPayload())], { type: 'application/json' })
  downloadBlob(blob, `barcode-decode-reliability-${Date.now()}.json`)
  copyMessage.value = 'JSON exporté.'
}

function exportCsv(): void {
  const blob = new Blob([buildReliabilityCsv(results.value)], { type: 'text/csv' })
  downloadBlob(blob, `barcode-decode-reliability-${Date.now()}.csv`)
  copyMessage.value = 'CSV exporté.'
}

function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopRun()
  }
}

watch([orderMode], () => {
  if (runState.value !== 'RUNNING') {
    randomSeed.value = orderMode.value === 'RANDOMIZED' ? Date.now() : null
    rebuildConfigurations()
  }
})

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  randomSeed.value = Date.now()
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
  <Head title="Barcode Decode Reliability Experiment" />

  <div class="barcode-reader-test-page barcode-decode-reliability">
    <div class="barcode-reader-test-page__container barcode-decode-reliability__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Decode Reliability Experiment</h1>
          <p class="barcode-reader-test-page__subtitle">Focus fixe expérimental × taille × résolution × pipeline image (DEV isolé)</p>
        </div>
        <div class="barcode-decode-reliability__header-links">
          <Link href="/dev/barcode-detector-decode-reliability-matrix" class="btn btn-sm btn-outline-secondary">Reliability Matrix</Link>
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-secondary">Phase 2 Benchmark</Link>
          <Link href="/dev/barcode-quagga2" class="btn btn-sm btn-outline-secondary">Quagga2</Link>
          <Link href="/dev/barcode-engines-comparison" class="btn btn-sm btn-outline-secondary">Engine Compare</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
          <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability__banner barcode-decode-reliability__banner--warning">
        <p class="mb-1"><strong>Focus expérimental : {{ EXPERIMENTAL_FOCUS }} — NON VALIDÉ</strong></p>
        <p class="mb-1">Ce focus n'est pas une recommandation. Il sert uniquement à isoler taille, résolution et décodage.</p>
        <p class="mb-0 barcode-decode-reliability__muted">KPI principal : CORRECT READ RATE — pas detection rate.</p>
      </section>

      <section class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Expected barcode</h2>
        <div class="barcode-decode-reliability__grid-form">
          <div>
            <label>EXPECTED</label>
            <input v-model="expectedBarcode" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          </div>
          <div>
            <label>FORMAT</label>
            <input v-model="expectedFormat" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          </div>
        </div>
        <div class="form-check mb-2">
          <input id="physical-confirmed" v-model="physicalConfirmed" class="form-check-input" type="checkbox">
          <label class="form-check-label" for="physical-confirmed">Je confirme le code physique devant la caméra</label>
        </div>
        <p v-if="!physicalConfirmed" class="barcode-decode-reliability__muted mb-0">
          Le benchmark ne peut pas conclure qu'une lecture est incorrecte tant que le code physique n'est pas confirmé.
        </p>
      </section>

      <section class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Test configuration</h2>
        <div class="barcode-decode-reliability__grid-form">
          <div>
            <label>Input mode</label>
            <select v-model="inputMode" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option value="VIDEO">VIDEO — flux direct</option>
              <option value="CANVAS">CANVAS — frame dessinée</option>
            </select>
          </div>
          <div>
            <label>Duration</label>
            <select v-model.number="durationSeconds" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in DURATION_OPTIONS" :key="option" :value="option">{{ option }} s</option>
            </select>
          </div>
          <div>
            <label>Settle time</label>
            <select v-model.number="settleMs" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in SETTLE_OPTIONS" :key="option" :value="option">{{ option }} ms</option>
            </select>
          </div>
        </div>
        <div class="barcode-decode-reliability__actions">
          <button type="button" class="btn btn-sm" :class="orderMode === 'FIXED' ? 'btn-primary' : 'btn-outline-secondary'" :disabled="runState === 'RUNNING'" @click="orderMode = 'FIXED'; rebuildConfigurations()">Fixed</button>
          <button type="button" class="btn btn-sm" :class="orderMode === 'RANDOMIZED' ? 'btn-primary' : 'btn-outline-secondary'" :disabled="runState === 'RUNNING'" @click="orderMode = 'RANDOMIZED'; randomSeed = Date.now(); rebuildConfigurations()">Randomized</button>
          <button type="button" class="btn btn-sm" :class="debugVisual ? 'btn-warning' : 'btn-outline-secondary'" @click="debugVisual = !debugVisual">Debug visual</button>
        </div>
        <p class="barcode-decode-reliability__muted mb-0 mt-2">
          Matrice : {{ RESOLUTION_PRESETS.length }} résolutions × {{ SIZE_TARGET_SPECS.length }} tailles = {{ configCount }} configurations
        </p>
      </section>

      <section class="barcode-decode-reliability__section barcode-decode-reliability__section--video">
        <h2 class="barcode-decode-reliability__section-title">Camera & live status</h2>
        <div class="barcode-decode-reliability__video-wrap">
          <video ref="videoRef" class="barcode-decode-reliability__video" autoplay muted playsinline />
          <canvas ref="debugCanvasRef" class="barcode-decode-reliability__debug-canvas" :class="{ 'barcode-decode-reliability__debug-canvas--visible': debugVisual }" />
          <div class="barcode-decode-reliability__guide-stack">
            <div
              v-for="target in SIZE_TARGET_SPECS"
              :key="target.id"
              class="barcode-decode-reliability__size-guide"
              :class="{ 'barcode-decode-reliability__size-guide--active': activeSizeMatch.some((item: SizeTargetSpec) => item.id === target.id) }"
              :style="{ width: `${target.guideWidthRatio * 100}%`, aspectRatio: '2 / 1' }"
            >
              <span>{{ target.label }}</span>
            </div>
          </div>
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-decode-reliability__hidden-canvas" aria-hidden="true" />
        <canvas ref="decodeCanvasRef" class="barcode-decode-reliability__hidden-canvas" aria-hidden="true" />

        <pre class="barcode-decode-reliability__live-panel mt-3 mb-0">--------------------------------
BARCODE RELIABILITY EXPERIMENT
--------------------------------

Expected:
{{ expectedBarcode }}

Format:
{{ expectedFormat }}

Focus:
{{ EXPERIMENTAL_FOCUS }} — NON VALIDÉ

Zoom:
1×

Resolution:
{{ trackSettings.width ?? '—' }}×{{ trackSettings.height ?? '—' }}

Target:
{{ currentConfig?.sizeTarget.label ?? '—' }}

Input:
{{ inputMode }}

Frames:
{{ results.find((item) => item.configId === currentConfig?.id)?.frames ?? '—' }}

Detections:
{{ results.find((item) => item.configId === currentConfig?.id)?.detections ?? '—' }}

Correct:
{{ results.find((item) => item.configId === currentConfig?.id)?.correct ?? '—' }}

Incorrect:
{{ results.find((item) => item.configId === currentConfig?.id)?.incorrect ?? '—' }}

Current rawValue:
{{ liveStatus.rawValue }}

Current format:
{{ liveStatus.format }}

Sharpness:
{{ liveStatus.sharpness }}

Bounding box:
{{ liveStatus.bboxWidth }} × {{ liveStatus.bboxHeight }}

Width ratio:
{{ liveStatus.widthRatio }}

Check digit:
{{ liveStatus.checkDigit }}

Status:
{{ liveStatus.status }}
--------------------------------</pre>

        <div class="barcode-decode-reliability__actions mt-3">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCameraWithPreset()">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStartExperiment" @click="startExperiment">Start Experiment</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING'" @click="resetExperiment">Reset</button>
          <button type="button" class="btn btn-outline-warning btn-sm" :disabled="!lastDetectionForCapture" @click="captureCurrentFrame">Capturer la frame</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Copy Report</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportJson">Export JSON</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportCsv">Export CSV</button>
        </div>

        <p v-if="!manualFocusSupported" class="barcode-decode-reliability__warning mt-2 mb-0">MANUAL FOCUS NOT SUPPORTED</p>
        <p v-if="cameraError" class="barcode-decode-reliability__warning mt-2 mb-0">{{ cameraError }}</p>
      </section>

      <section v-if="runState === 'RUNNING' || runState === 'COMPLETED'" class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Progress</h2>
        <p class="mb-1">Configuration {{ currentConfigIndex }} / {{ configCount }}</p>
        <p class="mb-2">Elapsed: {{ elapsedSeconds }} s — Remaining: {{ remainingSeconds }} s</p>
        <div class="progress mb-2">
          <div class="progress-bar" :style="{ width: `${progressPercent}%` }">{{ progressPercent }}%</div>
        </div>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Results table</h2>
        <div class="barcode-decode-reliability__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Resolution</th>
                <th>Target</th>
                <th>Actual res</th>
                <th>Frames</th>
                <th>Detections</th>
                <th>Correct</th>
                <th>Correct rate</th>
                <th>Distinct</th>
                <th>Most frequent</th>
                <th>Temporal stab.</th>
                <th>Sharpness</th>
                <th>Width</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in [...results].sort((a, b) => a.orderIndex - b.orderIndex)" :key="row.configId">
                <td>{{ row.resolutionLabel }}</td>
                <td>{{ row.sizeTargetLabel }}</td>
                <td>{{ row.actualWidth }}×{{ row.actualHeight }}</td>
                <td>{{ row.frames }}</td>
                <td>{{ row.detections }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.distinctValues }}</td>
                <td class="font-monospace">{{ row.mostFrequentValue ?? '—' }}</td>
                <td>{{ row.temporalStability ?? '—' }}</td>
                <td>{{ row.averageSharpness ?? '—' }}</td>
                <td>{{ row.averageBarcodeWidthRatio != null ? `${(row.averageBarcodeWidthRatio * 100).toFixed(1)}%` : '—' }}</td>
                <td>{{ row.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="decodePatterns.length > 0" class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Decode pattern analysis</h2>
        <div class="barcode-decode-reliability__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Value</th>
                <th>Occurrences</th>
                <th>Format</th>
                <th>Check digit valid</th>
                <th>Hamming</th>
                <th>Matching digits</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in decodePatterns" :key="row.rawValue">
                <td class="font-monospace">{{ row.rawValue }}</td>
                <td>{{ row.occurrences }}</td>
                <td>{{ row.format }}</td>
                <td>{{ row.checkDigitValid ? 'YES' : 'NO' }}</td>
                <td>{{ row.hammingDistance ?? '—' }}</td>
                <td>{{ row.matchingDigits }}/13</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="barcode-decode-reliability__muted mb-0 mt-2">Diagnostic uniquement — une valeur proche n'est PAS une lecture correcte.</p>
      </section>

      <section v-if="multiFrameAnalysis.length > 0" class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Multi-frame analysis (simulation)</h2>
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Strategy</th>
              <th>Confirmations</th>
              <th>Correct</th>
              <th>Incorrect</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in multiFrameAnalysis" :key="row.strategy">
              <td>{{ row.strategy }}</td>
              <td>{{ row.confirmations }}</td>
              <td>{{ row.correctConfirmations }}</td>
              <td>{{ row.incorrectConfirmations }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-if="capturedFrames.length > 0" class="barcode-decode-reliability__section">
        <h2 class="barcode-decode-reliability__section-title">Captured frames</h2>
        <div v-for="frame in capturedFrames" :key="frame.id" class="barcode-decode-reliability__capture-item">
          <p class="mb-1 font-monospace">{{ frame.timestamp }} — {{ frame.rawValue }} — focus {{ frame.focusActual }}</p>
          <div class="barcode-decode-reliability__capture-grid">
            <img v-if="frame.fullFrameDataUrl" :src="frame.fullFrameDataUrl" alt="Full frame" class="barcode-decode-reliability__capture-image">
            <img v-if="frame.cropDataUrl" :src="frame.cropDataUrl" alt="Crop" class="barcode-decode-reliability__capture-image">
          </div>
        </div>
      </section>

      <section class="barcode-decode-reliability__section barcode-decode-reliability__conclusion">
        <h2 class="barcode-decode-reliability__section-title">Benchmark conclusion</h2>
        <pre class="barcode-decode-reliability__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-decode-reliability__muted mb-0">{{ copyMessage }}</p>
    </div>
  </div>
</template>
