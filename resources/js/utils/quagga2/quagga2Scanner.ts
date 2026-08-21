/**
 * Wrapper DEV Quagga2 — aucune logique métier MKD-Pro.
 */

import Quagga, {
  type QuaggaJSCodeReader,
  type QuaggaJSConfigObject,
  type QuaggaJSResultCallbackFunction,
  type QuaggaJSResultObject,
} from '@ericblade/quagga2'

export type Quagga2PatchSize = 'x-small' | 'small' | 'medium' | 'large' | 'x-large'

export interface Quagga2DevConfig {
  width: number
  height: number
  patchSize: Quagga2PatchSize
  halfSample: boolean
  locate: boolean
  frequency: number
  numOfWorkers: number
  readers: QuaggaJSCodeReader[]
}

export interface Quagga2DetectionPayload {
  rawValue: string
  format: string
  box: number[][] | null
  frameWidth: number | null
  frameHeight: number | null
  widthRatio: number | null
}

export interface Quagga2CameraSnapshot {
  requestedWidth: number
  requestedHeight: number
  actualWidth: number | null
  actualHeight: number | null
  actualFps: number | null
  facingMode: string | null
}

export const DEFAULT_QUAGGA2_READERS: QuaggaJSCodeReader[] = [
  'ean_reader',
  'ean_8_reader',
  'upc_reader',
]

export const DEFAULT_QUAGGA2_DEV_CONFIG: Quagga2DevConfig = {
  width: 640,
  height: 480,
  patchSize: 'medium',
  halfSample: true,
  locate: true,
  frequency: 10,
  numOfWorkers: typeof navigator !== 'undefined' && navigator.hardwareConcurrency
    ? Math.min(4, navigator.hardwareConcurrency)
    : 2,
  readers: DEFAULT_QUAGGA2_READERS,
}

let activeSessionId = 0
let detectedHandler: QuaggaJSResultCallbackFunction | null = null
let isRunning = false

function computeWidthRatio(box: number[][] | null | undefined, frameWidth: number | null): number | null {
  if (!box || box.length === 0 || !frameWidth || frameWidth <= 0) {
    return null
  }

  const xs = box.map((point) => point[0]!).filter((value) => Number.isFinite(value))
  const ys = box.map((point) => point[1]!).filter((value) => Number.isFinite(value))

  if (xs.length === 0 || ys.length === 0) {
    return null
  }

  const width = Math.max(...xs) - Math.min(...xs)

  return Number((width / frameWidth).toFixed(4))
}

function readCameraSnapshot(config: Quagga2DevConfig): Quagga2CameraSnapshot {
  const video = document.querySelector('#quagga2-scanner video') as HTMLVideoElement | null
  const track = video?.srcObject instanceof MediaStream
    ? video.srcObject.getVideoTracks()[0] ?? null
    : null
  const settings = track?.getSettings()

  return {
    requestedWidth: config.width,
    requestedHeight: config.height,
    actualWidth: settings?.width ?? video?.videoWidth ?? null,
    actualHeight: settings?.height ?? video?.videoHeight ?? null,
    actualFps: settings?.frameRate ?? null,
    facingMode: settings?.facingMode ?? 'environment',
  }
}

export function buildQuagga2ConfigObject(
  target: HTMLElement,
  config: Quagga2DevConfig,
): QuaggaJSConfigObject {
  return {
    inputStream: {
      name: 'Live',
      type: 'LiveStream',
      target,
      constraints: {
        width: { ideal: config.width },
        height: { ideal: config.height },
        facingMode: 'environment',
      },
      area: {
        top: '0%',
        right: '0%',
        left: '0%',
        bottom: '0%',
      },
    },
    locator: {
      patchSize: config.patchSize,
      halfSample: config.halfSample,
    },
    locate: config.locate,
    frequency: config.frequency,
    numOfWorkers: config.numOfWorkers,
    decoder: {
      readers: config.readers,
      multiple: false,
    },
  }
}

export function mapQuaggaResult(result: QuaggaJSResultObject): Quagga2DetectionPayload | null {
  const code = result.codeResult?.code

  if (!code) {
    return null
  }

  const video = document.querySelector('#quagga2-scanner video') as HTMLVideoElement | null
  const frameWidth = video?.videoWidth ?? null
  const frameHeight = video?.videoHeight ?? null

  return {
    rawValue: code,
    format: result.codeResult?.format ?? 'unknown',
    box: result.box ?? null,
    frameWidth,
    frameHeight,
    widthRatio: computeWidthRatio(result.box, frameWidth),
  }
}

export async function stopQuagga2Scanner(): Promise<void> {
  if (detectedHandler) {
    Quagga.offDetected(detectedHandler)
    detectedHandler = null
  }

  if (isRunning) {
    await Quagga.stop()
    isRunning = false
  }

  activeSessionId += 1
}

export async function startQuagga2Scanner(options: {
  target: HTMLElement
  config?: Partial<Quagga2DevConfig>
  onDetected: (detection: Quagga2DetectionPayload) => void
  onError?: (error: Error) => void
}): Promise<{ sessionId: number; camera: Quagga2CameraSnapshot; stop: () => Promise<void> }> {
  await stopQuagga2Scanner()

  const sessionId = activeSessionId + 1
  activeSessionId = sessionId
  const config: Quagga2DevConfig = { ...DEFAULT_QUAGGA2_DEV_CONFIG, ...options.config }

  options.target.innerHTML = ''
  options.target.id = options.target.id || 'quagga2-scanner'

  const configObject = buildQuagga2ConfigObject(options.target, config)

  detectedHandler = (result: QuaggaJSResultObject) => {
    if (sessionId !== activeSessionId) {
      return
    }

    const mapped = mapQuaggaResult(result)

    if (mapped) {
      options.onDetected(mapped)
    }
  }

  try {
    await new Promise<void>((resolve, reject) => {
      Quagga.init(configObject, (error) => {
        if (error) {
          reject(error instanceof Error ? error : new Error(String(error)))
          return
        }

        resolve()
      })
    })

    Quagga.onDetected(detectedHandler)
    Quagga.start()
    isRunning = true

    await new Promise((resolve) => window.setTimeout(resolve, 500))

    return {
      sessionId,
      camera: readCameraSnapshot(config),
      stop: async () => {
        if (sessionId === activeSessionId) {
          await stopQuagga2Scanner()
        }
      },
    }
  } catch (error) {
    await stopQuagga2Scanner()
    const wrapped = error instanceof Error ? error : new Error(String(error))
    options.onError?.(wrapped)
    throw wrapped
  }
}

export function getQuagga2CameraSnapshot(config: Quagga2DevConfig = DEFAULT_QUAGGA2_DEV_CONFIG): Quagga2CameraSnapshot {
  return readCameraSnapshot(config)
}

export function isQuagga2Running(): boolean {
  return isRunning
}
