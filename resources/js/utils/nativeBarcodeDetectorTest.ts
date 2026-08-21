const DEBUG = import.meta.env.DEV

export const NATIVE_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
  },
  audio: false,
}

export const REQUESTED_BARCODE_FORMATS = [
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
  'code_128',
  'code_39',
] as const

export type VariantId = 'A' | 'B' | 'C' | 'D' | 'E'

export type DetectionOutcome = 'SUCCESS' | 'NOT_FOUND' | 'ERROR'

export interface DetectedBarcodeLike {
  rawValue?: string
  format?: string
}

export interface BarcodeDetectorConstructorLike {
  new (options?: { formats?: string[] }): BarcodeDetectorLike
  getSupportedFormats?: () => Promise<string[]>
}

export interface BarcodeDetectorLike {
  detect(source: ImageBitmapSource): Promise<DetectedBarcodeLike[]>
}

export interface EnvironmentInfo {
  secureContext: boolean
  protocol: string
  hostname: string
  browserLabel: string
  userAgent: string
  mediaDevices: 'available' | 'unavailable'
  getUserMedia: 'available' | 'unavailable'
  barcodeDetectorNative: 'SUPPORTED' | 'NOT SUPPORTED'
}

export interface CameraInfo {
  videoWidth: number
  videoHeight: number
  readyState: number
  paused: boolean
  trackCount: number
  videoTrackCount: number
  trackState: string
  trackReadyState: string
  facingMode: string
  resolution: string
  frameRate: string
}

export interface VariantResult {
  id: VariantId
  label: string
  status: DetectionOutcome
  resultCount: number
  rawValue: string
  format: string
  error: string
  canvasWidth: number
  canvasHeight: number
}

export interface SingleDetectionResult {
  status: DetectionOutcome
  rawValue: string
  format: string
  resultCount: number
  error: string
  imageWidth: number
  imageHeight: number
}

export interface LiveDetectionStats {
  framesTested: number
  detectionAttempts: number
  successfulDetections: number
  notFound: number
  errors: number
}

export interface OperationalConclusion {
  camera: string
  barcodeDetector: string
  liveDetection: string
  capturedImage: string
  importedImage: string
  interpretation: string
}

const VARIANT_LABELS: Record<VariantId, string> = {
  A: 'Image complète',
  B: 'Recadrage central',
  C: 'Centre 80 %',
  D: 'Centre 60 %',
  E: 'Agrandie 2×',
}

function display(value: unknown): string {
  if (value == null || value === '') {
    return '—'
  }

  return String(value)
}

export function isNativeBarcodeDetectorSupported(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

export function getEnvironmentInfo(): EnvironmentInfo {
  const mediaDevicesAvailable = typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'

  return {
    secureContext: typeof window !== 'undefined' ? window.isSecureContext : false,
    protocol: typeof location !== 'undefined' ? location.protocol : '—',
    hostname: typeof location !== 'undefined' ? location.hostname : '—',
    browserLabel: inferBrowserLabel(typeof navigator !== 'undefined' ? navigator.userAgent : ''),
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '—',
    mediaDevices: mediaDevicesAvailable ? 'available' : 'unavailable',
    getUserMedia: mediaDevicesAvailable && typeof navigator.mediaDevices.getUserMedia === 'function'
      ? 'available'
      : 'unavailable',
    barcodeDetectorNative: isNativeBarcodeDetectorSupported() ? 'SUPPORTED' : 'NOT SUPPORTED',
  }
}

function inferBrowserLabel(userAgent: string): string {
  if (/Edg\//.test(userAgent)) {
    return 'Microsoft Edge'
  }

  if (/OPR\//.test(userAgent) || /Opera/.test(userAgent)) {
    return 'Opera'
  }

  if (/SamsungBrowser/.test(userAgent)) {
    return 'Samsung Internet'
  }

  if (/CriOS/.test(userAgent)) {
    return 'Chrome iOS'
  }

  if (/Chrome\//.test(userAgent) && !/Edg\//.test(userAgent)) {
    return 'Chrome'
  }

  if (/FxiOS/.test(userAgent)) {
    return 'Firefox iOS'
  }

  if (/Firefox\//.test(userAgent)) {
    return 'Firefox'
  }

  if (/Safari/.test(userAgent) && !/Chrome/.test(userAgent)) {
    return 'Safari'
  }

  return 'Navigateur inconnu'
}

export function readCameraInfo(video: HTMLVideoElement | null): CameraInfo {
  if (!video) {
    return {
      videoWidth: 0,
      videoHeight: 0,
      readyState: 0,
      paused: true,
      trackCount: 0,
      videoTrackCount: 0,
      trackState: '—',
      trackReadyState: '—',
      facingMode: '—',
      resolution: '—',
      frameRate: '—',
    }
  }

  const stream = video.srcObject instanceof MediaStream ? video.srcObject : null
  const tracks = stream?.getTracks() ?? []
  const videoTracks = stream?.getVideoTracks() ?? []
  const track = videoTracks[0]
  const settings = track?.getSettings?.()

  return {
    videoWidth: video.videoWidth,
    videoHeight: video.videoHeight,
    readyState: video.readyState,
    paused: video.paused,
    trackCount: tracks.length,
    videoTrackCount: videoTracks.length,
    trackState: display(track?.readyState),
    trackReadyState: display(track?.readyState),
    facingMode: display(settings?.facingMode),
    resolution: video.videoWidth > 0 && video.videoHeight > 0
      ? `${video.videoWidth} × ${video.videoHeight}`
      : '—',
    frameRate: settings?.frameRate ? `${settings.frameRate}` : '—',
  }
}

export async function waitForVideoReady(
  video: HTMLVideoElement,
  timeoutMs = 15000,
): Promise<void> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < timeoutMs) {
    if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
      return
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  throw new Error('La vidéo n\'a pas atteint un état prêt (readyState ≥ 2 et dimensions > 0).')
}

export async function createNativeBarcodeDetector(): Promise<BarcodeDetectorLike> {
  if (!DEBUG) {
    throw new Error('Test BarcodeDetector natif disponible uniquement en développement.')
  }

  if (!isNativeBarcodeDetectorSupported()) {
    throw new Error('BarcodeDetector non supporté par ce navigateur.')
  }

  const DetectorClass = window.BarcodeDetector as BarcodeDetectorConstructorLike
  const formats = await resolveDetectorFormats(DetectorClass)

  return new DetectorClass({ formats })
}

async function resolveDetectorFormats(
  DetectorClass: BarcodeDetectorConstructorLike,
): Promise<string[]> {
  if (typeof DetectorClass.getSupportedFormats !== 'function') {
    return [...REQUESTED_BARCODE_FORMATS]
  }

  try {
    const supportedFormats = await DetectorClass.getSupportedFormats()
    const filtered = REQUESTED_BARCODE_FORMATS.filter((format) => supportedFormats.includes(format))

    return filtered.length > 0 ? filtered : [...REQUESTED_BARCODE_FORMATS]
  } catch {
    return [...REQUESTED_BARCODE_FORMATS]
  }
}

export function captureVideoFrame(video: HTMLVideoElement): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(video, 0, 0, canvas.width, canvas.height)

  return canvas
}

function cropCenterCanvas(source: HTMLCanvasElement, ratio: number): HTMLCanvasElement {
  const cropWidth = Math.max(1, Math.round(source.width * ratio))
  const cropHeight = Math.max(1, Math.round(source.height * ratio))
  const offsetX = Math.round((source.width - cropWidth) / 2)
  const offsetY = Math.round((source.height - cropHeight) / 2)

  const canvas = document.createElement('canvas')
  canvas.width = cropWidth
  canvas.height = cropHeight

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(
    source,
    offsetX,
    offsetY,
    cropWidth,
    cropHeight,
    0,
    0,
    cropWidth,
    cropHeight,
  )

  return canvas
}

function scaleCanvas(source: HTMLCanvasElement, scale: number): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(source.width * scale))
  canvas.height = Math.max(1, Math.round(source.height * scale))

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.imageSmoothingEnabled = true
  context.drawImage(source, 0, 0, canvas.width, canvas.height)

  return canvas
}

function cropCenterSquare(source: HTMLCanvasElement): HTMLCanvasElement {
  const size = Math.min(source.width, source.height)
  const offsetX = Math.round((source.width - size) / 2)
  const offsetY = Math.round((source.height - size) / 2)

  const canvas = document.createElement('canvas')
  canvas.width = size
  canvas.height = size

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(
    source,
    offsetX,
    offsetY,
    size,
    size,
    0,
    0,
    size,
    size,
  )

  return canvas
}

export function buildVariantCanvases(source: HTMLCanvasElement): Record<VariantId, HTMLCanvasElement> {
  return {
    A: source,
    B: cropCenterSquare(source),
    C: cropCenterCanvas(source, 0.8),
    D: cropCenterCanvas(source, 0.6),
    E: scaleCanvas(source, 2),
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

export async function detectOnCanvas(
  detector: BarcodeDetectorLike,
  canvas: HTMLCanvasElement,
): Promise<SingleDetectionResult> {
  try {
    const barcodes = await detector.detect(canvas)
    const best = pickBestBarcode(barcodes)

    if (!best?.rawValue) {
      return {
        status: 'NOT_FOUND',
        rawValue: '—',
        format: '—',
        resultCount: barcodes.length,
        error: '—',
        imageWidth: canvas.width,
        imageHeight: canvas.height,
      }
    }

    return {
      status: 'SUCCESS',
      rawValue: best.rawValue,
      format: display(best.format),
      resultCount: barcodes.length,
      error: '—',
      imageWidth: canvas.width,
      imageHeight: canvas.height,
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)

    return {
      status: 'ERROR',
      rawValue: '—',
      format: '—',
      resultCount: 0,
      error: message,
      imageWidth: canvas.width,
      imageHeight: canvas.height,
    }
  }
}

export async function runVariantTests(
  detector: BarcodeDetectorLike,
  source: HTMLCanvasElement,
): Promise<VariantResult[]> {
  const canvases = buildVariantCanvases(source)
  const results: VariantResult[] = []

  for (const id of ['A', 'B', 'C', 'D', 'E'] as VariantId[]) {
    const canvas = canvases[id]
    const detection = await detectOnCanvas(detector, canvas)

    results.push({
      id,
      label: VARIANT_LABELS[id],
      status: detection.status,
      resultCount: detection.resultCount,
      rawValue: detection.rawValue,
      format: detection.format,
      error: detection.error,
      canvasWidth: canvas.width,
      canvasHeight: canvas.height,
    })
  }

  return results
}

export async function detectFromImageSource(
  detector: BarcodeDetectorLike,
  source: ImageBitmapSource,
): Promise<SingleDetectionResult> {
  if (source instanceof HTMLCanvasElement) {
    return detectOnCanvas(detector, source)
  }

  const canvas = document.createElement('canvas')
  const width = 'width' in source ? Number(source.width) : 0
  const height = 'height' in source ? Number(source.height) : 0

  canvas.width = width
  canvas.height = height

  const context = canvas.getContext('2d')

  if (!context) {
    throw new Error('Contexte canvas indisponible.')
  }

  context.drawImage(source, 0, 0, canvas.width, canvas.height)

  return detectOnCanvas(detector, canvas)
}

export function canvasToDataUrl(canvas: HTMLCanvasElement): string {
  return canvas.toDataURL('image/png')
}

export function mapCameraError(error: unknown): string {
  if (!(error instanceof DOMException)) {
    return error instanceof Error ? error.message : String(error)
  }

  switch (error.name) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
      return 'Permission caméra refusée.'
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return 'Caméra indisponible.'
    case 'NotReadableError':
    case 'TrackStartError':
      return 'Caméra indisponible.'
    case 'OverconstrainedError':
    case 'ConstraintNotSatisfiedError':
      return 'Contraintes caméra non satisfaites.'
    case 'SecurityError':
      return 'Accès caméra bloqué (contexte non sécurisé).'
    default:
      return error.message || 'Erreur caméra inconnue.'
  }
}

export function buildOperationalConclusion(input: {
  cameraActive: boolean
  barcodeDetectorSupported: boolean
  liveDetection: DetectionOutcome | 'IDLE'
  capturedImage: DetectionOutcome | 'IDLE'
  importedImage: DetectionOutcome | 'IDLE'
}): OperationalConclusion {
  const camera = input.cameraActive ? 'OK' : 'OFF'
  const barcodeDetector = input.barcodeDetectorSupported ? 'SUPPORTED' : 'NOT SUPPORTED'

  const liveDetection = input.liveDetection === 'IDLE'
    ? '—'
    : input.liveDetection === 'SUCCESS' ? 'SUCCESS' : 'NOT FOUND'

  const capturedImage = input.capturedImage === 'IDLE'
    ? '—'
    : input.capturedImage === 'SUCCESS' ? 'SUCCESS' : 'NOT FOUND'

  const importedImage = input.importedImage === 'IDLE'
    ? '—'
    : input.importedImage === 'SUCCESS' ? 'SUCCESS' : 'NOT FOUND'

  let interpretation = 'En attente des résultats de test...'

  if (!input.barcodeDetectorSupported) {
    interpretation = 'BarcodeDetector natif non disponible sur ce navigateur. Ce moteur ne constitue pas une alternative exploitable sans polyfill.'
  } else if (
    input.liveDetection === 'SUCCESS'
    || input.capturedImage === 'SUCCESS'
    || input.importedImage === 'SUCCESS'
  ) {
    if (input.importedImage === 'SUCCESS' && input.liveDetection !== 'SUCCESS' && input.capturedImage !== 'SUCCESS') {
      interpretation = 'BarcodeDetector fonctionne sur image importée mais pas sur la caméra. Le problème est probablement lié au flux vidéo, aux dimensions des frames ou aux contraintes caméra.'
    } else {
      interpretation = 'BarcodeDetector natif détecte le code. Le navigateur et le moteur natif savent lire l\'EAN-13. Le problème est probablement spécifique à l\'implémentation ZXing actuelle.'
    }
  } else if (
    input.importedImage === 'NOT_FOUND'
    || input.capturedImage === 'NOT_FOUND'
    || input.liveDetection === 'NOT_FOUND'
  ) {
    interpretation = 'BarcodeDetector ne détecte pas le code, même après plusieurs tentatives. Le navigateur/moteur BarcodeDetector ne constitue probablement pas une solution viable pour cet appareil.'
  }

  return {
    camera,
    barcodeDetector,
    liveDetection,
    capturedImage,
    importedImage,
    interpretation,
  }
}

export function logNativeBarcodeTest(message: string, payload?: unknown): void {
  if (!DEBUG) {
    return
  }

  if (payload === undefined) {
    console.info(`[NativeBarcodeDetectorTest] ${message}`)
    return
  }

  console.info(`[NativeBarcodeDetectorTest] ${message}`, payload)
}

export function logNativeBarcodeTestError(message: string, payload?: unknown): void {
  if (!DEBUG) {
    return
  }

  console.error(`[NativeBarcodeDetectorTest] ${message}`, payload)
}

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}
