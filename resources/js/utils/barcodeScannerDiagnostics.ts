import { BrowserMultiFormatReader } from '@zxing/browser'
import { isTransientBarcodeScanError } from '@/utils/barcodeCamera'

export interface BarcodeDecodeStats {
  decodeCallbackCount: number
  notFoundCount: number
  resultCount: number
  fatalErrorCount: number
  ignoredCallbackCount: number
}

export interface BarcodeVideoDiagnostics {
  videoWidth: number
  videoHeight: number
  readyState: number
  currentTime: number
  paused: boolean
  hasSrcObject: boolean
  tracks: Array<{
    kind: string
    readyState: MediaStreamTrackState
    settings?: MediaTrackSettings
  }>
}

export interface DiagnosticPanelState {
  cameraState: string
  videoWidth: number
  videoHeight: number
  readyState: number
  paused: boolean
  frameCount: number
  callbacks: number
  notFound: number
  results: number
  fatalErrors: number
  lastResult: string
  lastFormat: string
  lastError: string
  trackCount: number
  videoTrackCount: number
  trackReadyState: string
  facingMode: string
  streamActive: boolean
  trackWidth: number
  trackHeight: number
}

export function createInitialDiagnosticPanelState(): DiagnosticPanelState {
  return {
    cameraState: 'idle',
    videoWidth: 0,
    videoHeight: 0,
    readyState: 0,
    paused: true,
    frameCount: 0,
    callbacks: 0,
    notFound: 0,
    results: 0,
    fatalErrors: 0,
    lastResult: '',
    lastFormat: '',
    lastError: '',
    trackCount: 0,
    videoTrackCount: 0,
    trackReadyState: '',
    facingMode: '',
    streamActive: false,
    trackWidth: 0,
    trackHeight: 0,
  }
}

export function resetDiagnosticPanelCounters(state: DiagnosticPanelState): void {
  state.frameCount = 0
  state.callbacks = 0
  state.notFound = 0
  state.results = 0
  state.fatalErrors = 0
  state.lastResult = ''
  state.lastFormat = ''
  state.lastError = ''
}

export function syncDiagnosticPanelFromMedia(
  state: DiagnosticPanelState,
  video: HTMLVideoElement | null | undefined,
  stream: MediaStream | null | undefined,
  cameraState: string,
): void {
  state.cameraState = cameraState

  if (!video) {
    state.streamActive = false
    return
  }

  const activeStream = stream ?? (video.srcObject instanceof MediaStream ? video.srcObject : null)
  const tracks = activeStream?.getTracks() ?? []
  const videoTracks = activeStream?.getVideoTracks() ?? []
  const primaryVideoTrack = videoTracks[0]
  const settings = primaryVideoTrack?.getSettings?.()

  state.videoWidth = video.videoWidth
  state.videoHeight = video.videoHeight
  state.readyState = video.readyState
  state.paused = video.paused
  state.trackCount = tracks.length
  state.videoTrackCount = videoTracks.length
  state.trackReadyState = primaryVideoTrack?.readyState ?? ''
  state.facingMode = settings?.facingMode ?? ''
  state.trackWidth = settings?.width ?? 0
  state.trackHeight = settings?.height ?? 0
  state.streamActive = tracks.some((track) => track.readyState === 'live')
}

export function syncDiagnosticPanelFromStats(
  state: DiagnosticPanelState,
  stats: BarcodeDecodeStats,
): void {
  state.callbacks = stats.decodeCallbackCount
  state.notFound = stats.notFoundCount
  state.results = stats.resultCount
  state.fatalErrors = stats.fatalErrorCount
}

export function formatDiagnosticPanelForClipboard(state: DiagnosticPanelState): string {
  return [
    'BarcodeScanner diagnostic',
    '',
    `Camera: ${state.cameraState}`,
    `Stream: ${state.streamActive ? 'active' : 'inactive'}`,
    `Video: ${state.videoWidth}x${state.videoHeight}`,
    `ReadyState: ${state.readyState}`,
    `Frames: ${state.frameCount}`,
    `Callbacks: ${state.callbacks}`,
    `NotFound: ${state.notFound}`,
    `Results: ${state.results}`,
    `Fatal errors: ${state.fatalErrors}`,
    `Tracks: ${state.trackCount}`,
    `Video tracks: ${state.videoTrackCount}`,
    `Track state: ${state.trackReadyState || '—'}`,
    `Facing mode: ${state.facingMode || '—'}`,
    `Track resolution: ${state.trackWidth}x${state.trackHeight}`,
    `Last result: ${state.lastResult || '—'}`,
    `Last format: ${state.lastFormat || '—'}`,
    `Last error: ${state.lastError || '—'}`,
  ].join('\n')
}

export function formatBarcodeFormatValue(format: unknown): string {
  if (format == null) {
    return ''
  }

  if (typeof format === 'string' || typeof format === 'number') {
    return String(format)
  }

  if (typeof format === 'object' && format !== null && 'toString' in format) {
    return String(format)
  }

  return String(format)
}

export type DiagnosticVariantStatus = 'testing' | 'success' | 'not-found' | 'error'

export interface DiagnosticVariantResult {
  name: string
  label: string
  width: number
  height: number
  status: DiagnosticVariantStatus
  result: string | null
  format: string | null
  error: string | null
  previewUrl: string | null
}

export interface DiagnosticMultiTestSummary {
  tested: number
  success: number
  notFound: number
  errors: number
  bestVariant: DiagnosticVariantResult | null
}

function cloneCanvas(source: HTMLCanvasElement): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = source.width
  canvas.height = source.height

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(source, 0, 0)
  return canvas
}

function scaleCanvas(source: HTMLCanvasElement, scale: number): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = source.width * scale
  canvas.height = source.height * scale

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.imageSmoothingEnabled = true
  context.drawImage(source, 0, 0, canvas.width, canvas.height)
  return canvas
}

function toGrayscaleCanvas(source: HTMLCanvasElement): HTMLCanvasElement {
  const canvas = cloneCanvas(source)
  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  const imageData = context.getImageData(0, 0, canvas.width, canvas.height)
  const { data } = imageData

  for (let index = 0; index < data.length; index += 4) {
    const gray = (0.299 * data[index]) + (0.587 * data[index + 1]) + (0.114 * data[index + 2])
    data[index] = gray
    data[index + 1] = gray
    data[index + 2] = gray
  }

  context.putImageData(imageData, 0, 0)
  return canvas
}

function toContrastCanvas(source: HTMLCanvasElement, factor = 1.5): HTMLCanvasElement {
  const canvas = cloneCanvas(source)
  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  const imageData = context.getImageData(0, 0, canvas.width, canvas.height)
  const { data } = imageData

  for (let index = 0; index < data.length; index += 4) {
    data[index] = Math.max(0, Math.min(255, (((data[index] / 255) - 0.5) * factor + 0.5) * 255))
    data[index + 1] = Math.max(0, Math.min(255, (((data[index + 1] / 255) - 0.5) * factor + 0.5) * 255))
    data[index + 2] = Math.max(0, Math.min(255, (((data[index + 2] / 255) - 0.5) * factor + 0.5) * 255))
  }

  context.putImageData(imageData, 0, 0)
  return canvas
}

function cropCenterCanvas(source: HTMLCanvasElement, ratio = 0.6): HTMLCanvasElement {
  const cropWidth = Math.round(source.width * ratio)
  const cropHeight = Math.round(source.height * ratio)
  const cropX = Math.round((source.width - cropWidth) / 2)
  const cropY = Math.round((source.height - cropHeight) / 2)
  const canvas = document.createElement('canvas')
  canvas.width = cropWidth
  canvas.height = cropHeight

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(
    source,
    cropX,
    cropY,
    cropWidth,
    cropHeight,
    0,
    0,
    cropWidth,
    cropHeight,
  )

  return canvas
}

export function captureSourceCanvasFromVideo(video: HTMLVideoElement): HTMLCanvasElement {
  if (video.videoWidth <= 0 || video.videoHeight <= 0) {
    throw new Error('Dimensions vidéo invalides.')
  }

  const sourceCanvas = document.createElement('canvas')
  sourceCanvas.width = video.videoWidth
  sourceCanvas.height = video.videoHeight

  const sourceContext = sourceCanvas.getContext('2d')

  if (!sourceContext) {
    throw new Error('Contexte canvas indisponible.')
  }

  sourceContext.drawImage(
    video,
    0,
    0,
    video.videoWidth,
    video.videoHeight,
  )

  return sourceCanvas
}

export function buildDiagnosticVariantCanvases(
  sourceCanvas: HTMLCanvasElement,
): Array<{ name: string; label: string; canvas: HTMLCanvasElement }> {
  const cropCenter = cropCenterCanvas(sourceCanvas, 0.6)
  const grayscale = toGrayscaleCanvas(sourceCanvas)
  const grayscaleContrast = toContrastCanvas(grayscale)

  return [
    { name: 'original', label: 'Original', canvas: cloneCanvas(sourceCanvas) },
    { name: 'upscaled-2x', label: 'Upscaled ×2', canvas: scaleCanvas(sourceCanvas, 2) },
    { name: 'grayscale', label: 'Grayscale', canvas: grayscale },
    { name: 'contrast', label: 'Contrast', canvas: toContrastCanvas(sourceCanvas) },
    { name: 'crop-center', label: 'Crop central', canvas: cropCenter },
    { name: 'crop-center-2x', label: 'Crop ×2', canvas: scaleCanvas(cropCenter, 2) },
    { name: 'grayscale-contrast', label: 'Gray + contrast', canvas: grayscaleContrast },
    { name: 'grayscale-contrast-2x', label: 'Gray + contrast ×2', canvas: scaleCanvas(grayscaleContrast, 2) },
  ]
}

export type DiagnosticVariantDecodeOutcome =
  | { status: 'success'; result: string; format: string }
  | { status: 'not-found'; error: string }
  | { status: 'error'; error: string }

export function decodeDiagnosticVariantCanvas(canvas: HTMLCanvasElement): DiagnosticVariantDecodeOutcome {
  const reader = new BrowserMultiFormatReader()

  try {
    const result = reader.decodeFromCanvas(canvas)

    return {
      status: 'success',
      result: result.getText(),
      format: formatBarcodeFormatValue(result.getBarcodeFormat()),
    }
  } catch (error) {
    if (isTransientBarcodeScanError(error)) {
      return {
        status: 'not-found',
        error: 'Aucun code-barres détecté dans cette variante.',
      }
    }

    return {
      status: 'error',
      error: error instanceof Error ? error.message : String(error),
    }
  }
}

export function formatDiagnosticVariantStatus(status: DiagnosticVariantStatus): string {
  switch (status) {
    case 'testing':
      return 'TESTING'
    case 'success':
      return 'SUCCESS'
    case 'not-found':
      return 'NOT FOUND'
    case 'error':
      return 'ERROR'
    default:
      return '—'
  }
}

export function buildDiagnosticMultiTestSummary(
  results: DiagnosticVariantResult[],
): DiagnosticMultiTestSummary {
  const finished = results.filter((result) => result.status !== 'testing')

  return {
    tested: finished.length,
    success: finished.filter((result) => result.status === 'success').length,
    notFound: finished.filter((result) => result.status === 'not-found').length,
    errors: finished.filter((result) => result.status === 'error').length,
    bestVariant: finished.find((result) => result.status === 'success') ?? null,
  }
}

const DEBUG = import.meta.env.DEV

export function logBarcodeScannerDebug(message: string, payload?: unknown): void {
  if (!DEBUG) {
    return
  }

  if (payload === undefined) {
    console.info(`[BarcodeScanner] ${message}`)
    return
  }

  console.info(`[BarcodeScanner] ${message}`, payload)
}

export function createBarcodeDecodeStatsTracker(
  onStats: (stats: BarcodeDecodeStats) => void,
  isActive: () => boolean,
): {
  recordCallback: (error: unknown, hasResult: boolean) => void
  recordIgnored: () => void
  snapshot: () => BarcodeDecodeStats
  stop: () => void
} {
  const stats: BarcodeDecodeStats = {
    decodeCallbackCount: 0,
    notFoundCount: 0,
    resultCount: 0,
    fatalErrorCount: 0,
    ignoredCallbackCount: 0,
  }

  const intervalId = window.setInterval(() => {
    if (!isActive()) {
      return
    }

    onStats({ ...stats })
    logBarcodeScannerDebug('[DIAG] stats', { ...stats })
  }, 2000)

  return {
    recordCallback(error: unknown, hasResult: boolean) {
      stats.decodeCallbackCount += 1

      if (hasResult) {
        stats.resultCount += 1
        return
      }

      if (error == null || isTransientBarcodeScanError(error)) {
        stats.notFoundCount += 1
        return
      }

      stats.fatalErrorCount += 1
    },
    recordIgnored() {
      stats.ignoredCallbackCount += 1
    },
    snapshot() {
      return { ...stats }
    },
    stop() {
      window.clearInterval(intervalId)
    },
  }
}

export function collectVideoDiagnostics(
  video: HTMLVideoElement | null | undefined,
  stream: MediaStream | null | undefined,
): BarcodeVideoDiagnostics | null {
  if (!video) {
    return null
  }

  const tracks = (stream ?? (video.srcObject instanceof MediaStream ? video.srcObject : null))?.getTracks() ?? []

  return {
    videoWidth: video.videoWidth,
    videoHeight: video.videoHeight,
    readyState: video.readyState,
    currentTime: video.currentTime,
    paused: video.paused,
    hasSrcObject: video.srcObject instanceof MediaStream,
    tracks: tracks.map((track) => ({
      kind: track.kind,
      readyState: track.readyState,
      settings: track.getSettings?.(),
    })),
  }
}

export function logVideoDiagnostics(
  video: HTMLVideoElement | null | undefined,
  stream: MediaStream | null | undefined,
  label = 'video ready',
): BarcodeVideoDiagnostics | null {
  const diagnostics = collectVideoDiagnostics(video, stream)

  if (!diagnostics) {
    logBarcodeScannerDebug(`${label} — video element missing`)
    return null
  }

  logBarcodeScannerDebug('[DIAG] video ready', diagnostics)

  if (diagnostics.videoWidth === 0 || diagnostics.videoHeight === 0) {
    logBarcodeScannerDebug('video dimensions are zero — ZXing canvas would be empty before decode starts')
  }

  const videoTrack = diagnostics.tracks.find((track) => track.kind === 'video')

  if (videoTrack?.settings) {
    logBarcodeScannerDebug('track settings', {
      width: videoTrack.settings.width,
      height: videoTrack.settings.height,
      facingMode: videoTrack.settings.facingMode,
      frameRate: videoTrack.settings.frameRate,
    })
  }

  return diagnostics
}

export function startVideoFrameFlowMonitor(
  video: HTMLVideoElement,
  isActive: () => boolean,
  onFrameTick?: () => void,
): () => void {
  if (!DEBUG) {
    return () => {}
  }

  let logged = false
  let lastCurrentTime = video.currentTime
  let intervalId: number | null = null
  let rvfHandle = 0

  const checkFlow = () => {
    if (!isActive()) {
      return
    }

    onFrameTick?.()

    if (!logged && video.currentTime > lastCurrentTime) {
      logged = true
      logBarcodeScannerDebug('[DIAG] video frames flowing', {
        currentTime: video.currentTime,
        videoWidth: video.videoWidth,
        videoHeight: video.videoHeight,
      })
    }

    lastCurrentTime = video.currentTime
  }

  const stop = () => {
    if (intervalId !== null) {
      window.clearInterval(intervalId)
      intervalId = null
    }

    if (rvfHandle !== 0 && 'cancelVideoFrameCallback' in video) {
      video.cancelVideoFrameCallback(rvfHandle)
      rvfHandle = 0
    }
  }

  if ('requestVideoFrameCallback' in video) {
    const onFrame = () => {
      checkFlow()

      if (isActive()) {
        rvfHandle = video.requestVideoFrameCallback(onFrame)
      } else {
        stop()
      }
    }

    rvfHandle = video.requestVideoFrameCallback(onFrame)
  } else {
    intervalId = window.setInterval(checkFlow, 250)
  }

  return stop
}
