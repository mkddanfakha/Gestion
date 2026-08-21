const DEBUG = import.meta.env.DEV

export const REFERENCE_EAN_VALUE = '5012345678900'
export const MIN_DETECTION_INTERVAL_MS = 150
export const NOT_FOUND_MILESTONE = 50
export const MAX_SUCCESS_HISTORY = 20

export const FULL_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
  },
  audio: false,
}

export const SIMPLE_CAMERA_CONSTRAINTS: MediaStreamConstraints = {
  video: true,
  audio: false,
}

export const REQUESTED_FORMATS = [
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
  'code_128',
  'code_39',
] as const

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

export interface EnvironmentDiagnostics {
  browserLabel: string
  userAgent: string
  secureContext: boolean
  platform: string
  barcodeDetectorAvailable: boolean
}

export interface CameraTrackDiagnostics {
  trackCount: number
  videoTrackCount: number
  trackState: string
  facingMode: string
  resolution: string
  frameRate: string
}

export interface LiveStats {
  framesSeen: number
  detectionAttempts: number
  successfulDetections: number
  notFound: number
  errors: number
  lastDetectionMs: number | null
  averageDetectionMs: number | null
}

export interface SuccessHistoryEntry {
  id: string
  timestamp: string
  rawValue: string
  format: string
  durationMs: number
}

export type CameraState = 'idle' | 'starting' | 'active' | 'stopping' | 'error'
export type DetectionLoopState = 'stopped' | 'running'

function display(value: unknown): string {
  if (value == null || value === '') {
    return '—'
  }

  return String(value)
}

export function isNativeBarcodeDetectorAvailable(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

export function inferBrowserLabel(userAgent: string): string {
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

export function getEnvironmentDiagnostics(): EnvironmentDiagnostics {
  return {
    browserLabel: inferBrowserLabel(typeof navigator !== 'undefined' ? navigator.userAgent : ''),
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '—',
    secureContext: typeof window !== 'undefined' ? window.isSecureContext : false,
    platform: typeof navigator !== 'undefined' ? navigator.platform : '—',
    barcodeDetectorAvailable: isNativeBarcodeDetectorAvailable(),
  }
}

export function readCameraTrackDiagnostics(stream: MediaStream | null): CameraTrackDiagnostics {
  const tracks = stream?.getTracks() ?? []
  const videoTracks = stream?.getVideoTracks() ?? []
  const track = videoTracks[0]
  const settings = track?.getSettings?.()

  return {
    trackCount: tracks.length,
    videoTrackCount: videoTracks.length,
    trackState: display(track?.readyState),
    facingMode: display(settings?.facingMode),
    resolution: settings?.width && settings?.height
      ? `${settings.width} × ${settings.height}`
      : '—',
    frameRate: settings?.frameRate ? `${settings.frameRate}` : '—',
  }
}

async function resolveSupportedFormats(
  DetectorClass: BarcodeDetectorConstructorLike,
): Promise<string[]> {
  if (typeof DetectorClass.getSupportedFormats !== 'function') {
    return [...REQUESTED_FORMATS]
  }

  try {
    const supported = await DetectorClass.getSupportedFormats()
    const filtered = REQUESTED_FORMATS.filter((format) => supported.includes(format))

    return filtered.length > 0 ? filtered : [...REQUESTED_FORMATS]
  } catch {
    return [...REQUESTED_FORMATS]
  }
}

export async function createNativeLiveDetector(): Promise<{
  detector: BarcodeDetectorLike
  formatsUsed: string[]
}> {
  if (!DEBUG) {
    throw new Error('Test BarcodeDetector live disponible uniquement en développement.')
  }

  if (!isNativeBarcodeDetectorAvailable()) {
    throw new Error('BarcodeDetector natif non disponible sur ce navigateur.')
  }

  const DetectorClass = window.BarcodeDetector as BarcodeDetectorConstructorLike

  try {
    const formats = await resolveSupportedFormats(DetectorClass)

    return {
      detector: new DetectorClass({ formats }),
      formatsUsed: formats,
    }
  } catch {
    try {
      return {
        detector: new DetectorClass(),
        formatsUsed: [],
      }
    } catch (error) {
      if (error instanceof Error) {
        throw error
      }

      throw new Error(String(error))
    }
  }
}

export function pickBestBarcode(barcodes: DetectedBarcodeLike[]): DetectedBarcodeLike | null {
  if (barcodes.length === 0) {
    return null
  }

  const retailFormats = new Set(['ean_13', 'ean_8', 'upc_a', 'upc_e'])
  const retailMatch = barcodes.find((barcode) => retailFormats.has(barcode.format ?? ''))

  return retailMatch ?? barcodes[0] ?? null
}

export function formatNativeBarcodeFormat(format: string | undefined): string {
  if (!format) {
    return '—'
  }

  const labels: Record<string, string> = {
    ean_13: 'EAN-13',
    ean_8: 'EAN-8',
    upc_a: 'UPC-A',
    upc_e: 'UPC-E',
    code_128: 'Code 128',
    code_39: 'Code 39',
  }

  return labels[format] ?? format
}

export function computeAverageDetectionMs(durations: number[]): number | null {
  if (durations.length === 0) {
    return null
  }

  const total = durations.reduce((sum, value) => sum + value, 0)

  return Math.round(total / durations.length)
}

export function buildLiveConclusion(input: {
  barcodeDetectorAvailable: boolean
  successfulDetections: number
  detectionAttempts: number
  errors: number
  lastDetectedValue: string | null
  lastDetectedFormat: string | null
  lastErrorName: string | null
  lastErrorMessage: string | null
}): string {
  if (!input.barcodeDetectorAvailable) {
    return 'BarcodeDetector natif n\'est pas disponible sur ce navigateur/appareil.'
  }

  if (input.lastErrorName || input.lastErrorMessage) {
    return [
      'ERREUR TECHNIQUE',
      '',
      `Name: ${input.lastErrorName ?? '—'}`,
      `Message: ${input.lastErrorMessage ?? '—'}`,
    ].join('\n')
  }

  if (input.successfulDetections > 0 && input.lastDetectedValue) {
    return [
      'SUCCÈS',
      '',
      'BarcodeDetector natif est capable de détecter',
      'un code-barres directement depuis le flux vidéo.',
      '',
      'La chaîne :',
      '',
      'Caméra → MediaStream → Video → BarcodeDetector',
      '',
      'fonctionne sur cet appareil.',
      '',
      `Dernier résultat : ${input.lastDetectedValue} (${input.lastDetectedFormat ?? '—'})`,
    ].join('\n')
  }

  if (input.detectionAttempts >= NOT_FOUND_MILESTONE) {
    return [
      'NOT FOUND',
      '',
      `${input.detectionAttempts} tentatives effectuées sans détection.`,
      '',
      'BarcodeDetector fonctionne mais aucun code-barres',
      'n\'a été détecté dans le flux vidéo.',
      '',
      'Vérifier le cadrage, la distance et l\'éclairage.',
    ].join('\n')
  }

  return 'En attente de résultats BarcodeDetector sur le flux vidéo...'
}

export function buildLiveDiagnosticClipboard(input: {
  environment: EnvironmentDiagnostics
  cameraState: CameraState
  streamActive: boolean
  videoWidth: number
  videoHeight: number
  readyState: number
  trackDiagnostics: CameraTrackDiagnostics
  stats: LiveStats
  lastDetectedValue: string | null
  lastDetectedFormat: string | null
  formatsUsed: string[]
  constraintLabel: string
  conclusion: string
}): string {
  return [
    'BarcodeDetector Live Diagnostic',
    '',
    `Browser: ${input.environment.browserLabel}`,
    `User agent: ${input.environment.userAgent}`,
    `Secure context: ${input.environment.secureContext}`,
    `Platform: ${input.environment.platform}`,
    `BarcodeDetector: ${input.environment.barcodeDetectorAvailable ? 'available' : 'unavailable'}`,
    `Formats used: ${input.formatsUsed.length > 0 ? input.formatsUsed.join(', ') : 'default'}`,
    `Constraint: ${input.constraintLabel}`,
    '',
    `Camera: ${input.cameraState}`,
    `Stream: ${input.streamActive ? 'active' : 'inactive'}`,
    `Resolution: ${input.videoWidth > 0 ? `${input.videoWidth}x${input.videoHeight}` : '—'}`,
    `ReadyState: ${input.readyState}`,
    `Facing mode: ${input.trackDiagnostics.facingMode}`,
    `Track resolution: ${input.trackDiagnostics.resolution}`,
    '',
    `Frames: ${input.stats.framesSeen}`,
    `Detection attempts: ${input.stats.detectionAttempts}`,
    `Success: ${input.stats.successfulDetections}`,
    `Not found: ${input.stats.notFound}`,
    `Errors: ${input.stats.errors}`,
    '',
    `Last result: ${input.lastDetectedValue ?? '—'}`,
    `Last format: ${input.lastDetectedFormat ?? '—'}`,
    `Last detection: ${input.stats.lastDetectionMs ?? '—'} ms`,
    `Average detection: ${input.stats.averageDetectionMs ?? '—'} ms`,
    '',
    input.conclusion,
  ].join('\n')
}

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructorLike
  }
}
