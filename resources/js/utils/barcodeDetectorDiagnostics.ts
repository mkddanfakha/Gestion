const DEBUG = import.meta.env.DEV

const REQUESTED_FORMATS = [
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
  'code_128',
  'code_39',
] as const

const FORMAT_LABELS: Record<string, string> = {
  ean_13: 'EAN-13',
  ean_8: 'EAN-8',
  upc_a: 'UPC-A',
  upc_e: 'UPC-E',
  code_128: 'Code 128',
  code_39: 'Code 39',
}

export type BarcodeDetectorDiagnosticStatus = 'SUCCESS' | 'NOT_FOUND' | 'ERROR' | 'UNSUPPORTED'

export type BarcodeDetectorEngineMode = 'auto' | 'native' | 'polyfill'

export interface BarcodeDetectorDiagnosticResult {
  supported: boolean
  native: boolean
  polyfill: boolean
  status: BarcodeDetectorDiagnosticStatus
  engineLabel: string
  result?: string
  format?: string
  error?: string
  imageWidth?: number
  imageHeight?: number
  durationMs?: number
  supportedFormats: string[]
  videoWidth?: number
  videoHeight?: number
  videoReadyState?: number
  videoPaused?: boolean
  cameraFacingMode?: string
  cameraWidth?: number
  cameraHeight?: number
  cameraFrameRate?: number
}

interface DetectedBarcodeLike {
  rawValue?: string
  format?: string
}

interface BarcodeDetectorLike {
  detect(source: ImageBitmapSource): Promise<DetectedBarcodeLike[]>
}

interface BarcodeDetectorConstructorLike {
  new (options?: { formats?: string[] }): BarcodeDetectorLike
  getSupportedFormats?: () => Promise<string[]>
}

function logDiagnostic(message: string, payload?: unknown): void {
  if (!DEBUG) {
    return
  }

  if (payload === undefined) {
    console.info(`[BarcodeDetectorDiagnostic] ${message}`)
    return
  }

  console.info(`[BarcodeDetectorDiagnostic] ${message}`, payload)
}

function logDiagnosticError(message: string, payload?: unknown): void {
  if (!DEBUG) {
    return
  }

  console.error(`[BarcodeDetectorDiagnostic] ${message}`, payload)
}

export function isNativeBarcodeDetectorAvailable(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

export function formatBarcodeDetectorSupportedFormatLabel(format: string): string {
  return FORMAT_LABELS[format] ?? format
}

function captureCanvasFromVideo(video: HTMLVideoElement): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight)
  return canvas
}

function readCameraTrackSettings(video: HTMLVideoElement): {
  facingMode: string
  width: number
  height: number
  frameRate: number
} {
  const stream = video.srcObject instanceof MediaStream ? video.srcObject : null
  const track = stream?.getVideoTracks()[0]
  const settings = track?.getSettings?.()

  return {
    facingMode: settings?.facingMode ?? '',
    width: settings?.width ?? 0,
    height: settings?.height ?? 0,
    frameRate: settings?.frameRate ?? 0,
  }
}

async function resolveDetectorFormats(
  DetectorClass: BarcodeDetectorConstructorLike,
): Promise<string[]> {
  if (typeof DetectorClass.getSupportedFormats !== 'function') {
    return [...REQUESTED_FORMATS]
  }

  try {
    const supportedFormats = await DetectorClass.getSupportedFormats()
    const filtered = REQUESTED_FORMATS.filter((format) => supportedFormats.includes(format))

    return filtered.length > 0 ? filtered : [...REQUESTED_FORMATS]
  } catch {
    return [...REQUESTED_FORMATS]
  }
}

async function loadNativeDetectorClass(): Promise<BarcodeDetectorConstructorLike | null> {
  if (!isNativeBarcodeDetectorAvailable()) {
    return null
  }

  return window.BarcodeDetector as BarcodeDetectorConstructorLike
}

async function loadPolyfillDetectorClass(): Promise<BarcodeDetectorConstructorLike> {
  const module = await import('barcode-detector/ponyfill')
  return module.BarcodeDetector as BarcodeDetectorConstructorLike
}

async function resolveDetectorClass(
  engine: BarcodeDetectorEngineMode,
): Promise<{ DetectorClass: BarcodeDetectorConstructorLike; native: boolean; polyfill: boolean; engineLabel: string }> {
  if (engine === 'native') {
    const nativeClass = await loadNativeDetectorClass()

    if (!nativeClass) {
      throw new Error('BarcodeDetector natif indisponible sur ce navigateur.')
    }

    return {
      DetectorClass: nativeClass,
      native: true,
      polyfill: false,
      engineLabel: 'Native',
    }
  }

  if (engine === 'polyfill') {
    return {
      DetectorClass: await loadPolyfillDetectorClass(),
      native: false,
      polyfill: true,
      engineLabel: 'Polyfill',
    }
  }

  const nativeClass = await loadNativeDetectorClass()

  if (nativeClass) {
    return {
      DetectorClass: nativeClass,
      native: true,
      polyfill: false,
      engineLabel: 'Native',
    }
  }

  return {
    DetectorClass: await loadPolyfillDetectorClass(),
    native: false,
    polyfill: true,
    engineLabel: 'Polyfill',
  }
}

async function detectBarcodesFromVideo(
  detector: BarcodeDetectorLike,
  video: HTMLVideoElement,
): Promise<{ barcodes: DetectedBarcodeLike[]; imageWidth: number; imageHeight: number }> {
  try {
    const barcodes = await detector.detect(video)

    return {
      barcodes,
      imageWidth: video.videoWidth,
      imageHeight: video.videoHeight,
    }
  } catch {
    const canvas = captureCanvasFromVideo(video)
    const barcodes = await detector.detect(canvas)

    return {
      barcodes,
      imageWidth: canvas.width,
      imageHeight: canvas.height,
    }
  }
}

function pickBestBarcode(barcodes: DetectedBarcodeLike[]): DetectedBarcodeLike | null {
  if (barcodes.length === 0) {
    return null
  }

  const retailFormats = new Set(['ean_13', 'ean_8', 'upc_a', 'upc_e'])
  const retailMatch = barcodes.find((barcode) => retailFormats.has(barcode.format ?? ''))

  return retailMatch ?? barcodes[0] ?? null
}

export async function runBarcodeDetectorDiagnostic(
  video: HTMLVideoElement,
  engine: BarcodeDetectorEngineMode = 'auto',
): Promise<BarcodeDetectorDiagnosticResult> {
  const camera = readCameraTrackSettings(video)
  const baseResult: BarcodeDetectorDiagnosticResult = {
    supported: false,
    native: false,
    polyfill: false,
    status: 'UNSUPPORTED',
    engineLabel: '—',
    supportedFormats: [],
    videoWidth: video.videoWidth,
    videoHeight: video.videoHeight,
    videoReadyState: video.readyState,
    videoPaused: video.paused,
    cameraFacingMode: camera.facingMode,
    cameraWidth: camera.width,
    cameraHeight: camera.height,
    cameraFrameRate: camera.frameRate,
  }

  if (!DEBUG) {
    return {
      ...baseResult,
      error: 'Diagnostic BarcodeDetector disponible uniquement en développement.',
    }
  }

  logDiagnostic('START', {
    engine,
    video: `${video.videoWidth}x${video.videoHeight}`,
    readyState: video.readyState,
    paused: video.paused,
  })

  let detectorResolution: Awaited<ReturnType<typeof resolveDetectorClass>>

  try {
    detectorResolution = await resolveDetectorClass(engine)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)

    logDiagnosticError('ERROR', { stage: 'engine', error: message })

    return {
      ...baseResult,
      status: 'UNSUPPORTED',
      error: "BarcodeDetector n'est pas disponible sur ce navigateur.",
    }
  }

  const { DetectorClass, native, polyfill, engineLabel } = detectorResolution

  logDiagnostic(`engine: ${engineLabel.toLowerCase()}`, {
    native,
    polyfill,
  })

  logDiagnostic(`video: ${video.videoWidth}x${video.videoHeight}`, {
    readyState: video.readyState,
    paused: video.paused,
  })

  let supportedFormats: string[] = []

  try {
    supportedFormats = await resolveDetectorFormats(DetectorClass)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)

    logDiagnosticError('ERROR', { stage: 'formats', error: message })

    return {
      ...baseResult,
      supported: true,
      native,
      polyfill,
      engineLabel,
      status: 'ERROR',
      supportedFormats: [],
      error: message,
    }
  }

  const detector = new DetectorClass({ formats: supportedFormats })
  const start = performance.now()

  logDiagnostic('detection started', {
    formats: supportedFormats,
  })

  try {
    const detection = await detectBarcodesFromVideo(detector, video)
    const durationMs = Math.round(performance.now() - start)
    const bestBarcode = pickBestBarcode(detection.barcodes)

    if (!bestBarcode?.rawValue) {
      logDiagnostic('NOT FOUND', {
        durationMs,
        imageWidth: detection.imageWidth,
        imageHeight: detection.imageHeight,
      })

      return {
        ...baseResult,
        supported: true,
        native,
        polyfill,
        engineLabel,
        status: 'NOT_FOUND',
        supportedFormats,
        imageWidth: detection.imageWidth,
        imageHeight: detection.imageHeight,
        durationMs,
      }
    }

    logDiagnostic('result', {
      text: bestBarcode.rawValue,
      format: bestBarcode.format,
      durationMs,
    })

    logDiagnostic('SUCCESS', {
      text: bestBarcode.rawValue,
      format: bestBarcode.format,
    })

    return {
      ...baseResult,
      supported: true,
      native,
      polyfill,
      engineLabel,
      status: 'SUCCESS',
      result: bestBarcode.rawValue,
      format: bestBarcode.format,
      supportedFormats,
      imageWidth: detection.imageWidth,
      imageHeight: detection.imageHeight,
      durationMs,
    }
  } catch (error) {
    const durationMs = Math.round(performance.now() - start)
    const message = error instanceof Error ? error.message : String(error)

    logDiagnosticError('ERROR', {
      error: message,
      durationMs,
    })

    return {
      ...baseResult,
      supported: true,
      native,
      polyfill,
      engineLabel,
      status: 'ERROR',
      supportedFormats,
      durationMs,
      error: message,
    }
  }
}
