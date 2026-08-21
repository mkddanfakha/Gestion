export const VUE_QRCODE_READER_VERSION = '5.7.3'

export interface DiagnosticErrorDetails {
  code: string
  type: string
  name: string
  message: string
  constraint: string
  stack: string
  rawJson: string
}

export interface SecureContextInfo {
  secureContext: boolean
  protocol: string
  hostname: string
  mediaDevices: 'available' | 'unavailable'
  getUserMedia: 'available' | 'unavailable'
}

export interface VideoProbeInfo {
  videoElement: 'YES' | 'NO'
  readyState: string
  paused: string
  videoWidth: string
  videoHeight: string
  currentTime: string
  srcObject: 'YES' | 'NO'
  streamActive: 'YES' | 'NO'
  videoTracks: string
  trackState: string
  trackReadyState: string
  facingMode: string
  resolution: string
}

export interface IndependentMediaTestResult {
  status: 'SUCCESS' | 'ERROR' | 'BLOCKED'
  message: string
  streamActive: 'active' | 'inactive' | '—'
  tracks: string
  videoTrack: string
  facingMode: string
  width: string
  height: string
  error: DiagnosticErrorDetails | null
}

export interface RawVideoTestResult {
  status: 'SUCCESS' | 'ERROR' | 'BLOCKED'
  message: string
  videoWidth: string
  videoHeight: string
  readyState: string
  trackReadyState: string
  facingMode: string
  error: DiagnosticErrorDetails | null
}

function displayValue(value: unknown): string {
  if (value == null || value === '') {
    return '—'
  }

  return String(value)
}

function safeJson(value: unknown): string {
  try {
    if (value instanceof Error) {
      return JSON.stringify({
        name: errorField(value, 'name'),
        message: errorField(value, 'message'),
        stack: value.stack ?? '—',
        ...(collectExtraErrorFields(value)),
      })
    }

    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

function errorField(error: Error, field: 'name' | 'message'): string {
  return error[field] || '—'
}

function collectExtraErrorFields(error: unknown): Record<string, unknown> {
  if (typeof error !== 'object' || error === null) {
    return {}
  }

  const record = error as Record<string, unknown>
  const extras: Record<string, unknown> = {}

  for (const key of ['code', 'constraint', 'constraintName']) {
    if (record[key] != null) {
      extras[key] = record[key]
    }
  }

  return extras
}

export function serializeUnknownError(error: unknown): DiagnosticErrorDetails {
  if (typeof error !== 'object' || error === null) {
    return {
      code: '—',
      type: typeof error,
      name: '—',
      message: displayValue(error),
      constraint: '—',
      stack: '—',
      rawJson: safeJson(error),
    }
  }

  const record = error as Record<string, unknown>

  return {
    code: displayValue(record.code),
    type: typeof error,
    name: error instanceof Error ? error.name : displayValue(record.name),
    message: error instanceof Error ? error.message : displayValue(record.message),
    constraint: displayValue(record.constraint ?? record.constraintName),
    stack: error instanceof Error && error.stack ? error.stack : '—',
    rawJson: safeJson(error),
  }
}

export function logVueQrcodeReaderCameraError(error: unknown): void {
  if (!import.meta.env.DEV) {
    return
  }

  console.error('[vue-qrcode-reader] camera init error', error)

  const details = serializeUnknownError(error)
  console.error('[vue-qrcode-reader] error name', details.name)
  console.error('[vue-qrcode-reader] error message', details.message)
  console.error('[vue-qrcode-reader] error constraint', details.constraint)
  console.error('[vue-qrcode-reader] error stack', details.stack)
  console.error('[vue-qrcode-reader] error code', details.code)
  console.error('[vue-qrcode-reader] error raw', details.rawJson)
}

export function getSecureContextInfo(): SecureContextInfo {
  const mediaDevicesAvailable = typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'

  const getUserMediaAvailable = mediaDevicesAvailable
    && typeof navigator.mediaDevices.getUserMedia === 'function'

  return {
    secureContext: typeof window !== 'undefined' ? window.isSecureContext : false,
    protocol: typeof window !== 'undefined' ? window.location.protocol : '—',
    hostname: typeof window !== 'undefined' ? window.location.hostname : '—',
    mediaDevices: mediaDevicesAvailable ? 'available' : 'unavailable',
    getUserMedia: getUserMediaAvailable ? 'available' : 'unavailable',
  }
}

export function probeVideoInContainer(container: HTMLElement | null | undefined): VideoProbeInfo {
  const empty: VideoProbeInfo = {
    videoElement: 'NO',
    readyState: '—',
    paused: '—',
    videoWidth: '—',
    videoHeight: '—',
    currentTime: '—',
    srcObject: 'NO',
    streamActive: 'NO',
    videoTracks: '—',
    trackState: '—',
    trackReadyState: '—',
    facingMode: '—',
    resolution: '—',
  }

  if (!container) {
    return empty
  }

  const video = container.querySelector('video')

  if (!(video instanceof HTMLVideoElement)) {
    return empty
  }

  const stream = video.srcObject instanceof MediaStream ? video.srcObject : null
  const videoTrack = stream?.getVideoTracks()[0]
  const settings = videoTrack?.getSettings?.()

  return {
    videoElement: 'YES',
    readyState: displayValue(video.readyState),
    paused: video.paused ? 'true' : 'false',
    videoWidth: displayValue(video.videoWidth),
    videoHeight: displayValue(video.videoHeight),
    currentTime: displayValue(video.currentTime),
    srcObject: stream ? 'YES' : 'NO',
    streamActive: stream?.active ? 'YES' : 'NO',
    videoTracks: displayValue(stream?.getVideoTracks().length ?? 0),
    trackState: displayValue(videoTrack?.readyState),
    trackReadyState: displayValue(videoTrack?.readyState),
    facingMode: displayValue(settings?.facingMode),
    resolution: settings?.width && settings?.height
      ? `${settings.width} × ${settings.height}`
      : '—',
  }
}

export function stopMediaStreams(streams: MediaStream[]): void {
  for (const stream of streams) {
    for (const track of stream.getTracks()) {
      try {
        if (track.readyState === 'live') {
          track.stop()
        }
      } catch {
        // Ignore cleanup errors during intentional shutdown.
      }
    }
  }
}

export function hasLiveTracks(streams: MediaStream[]): boolean {
  return streams.some((stream) => stream.getTracks().some((track) => track.readyState === 'live'))
}

export async function runIndependentGetUserMediaTest(): Promise<{
  stream: MediaStream | null
  result: IndependentMediaTestResult
}> {
  if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
    const error = serializeUnknownError(new Error('navigator.mediaDevices.getUserMedia indisponible.'))

    return {
      stream: null,
      result: {
        status: 'ERROR',
        message: 'getUserMedia indisponible.',
        streamActive: '—',
        tracks: '—',
        videoTrack: '—',
        facingMode: '—',
        width: '—',
        height: '—',
        error,
      },
    }
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: {
        facingMode: { ideal: 'environment' },
      },
    })

    const videoTrack = stream.getVideoTracks()[0]
    const settings = videoTrack?.getSettings?.()

    return {
      stream,
      result: {
        status: 'SUCCESS',
        message: 'getUserMedia SUCCESS',
        streamActive: stream.active ? 'active' : 'inactive',
        tracks: displayValue(stream.getTracks().length),
        videoTrack: displayValue(videoTrack?.label || videoTrack?.id),
        facingMode: displayValue(settings?.facingMode),
        width: displayValue(settings?.width),
        height: displayValue(settings?.height),
        error: null,
      },
    }
  } catch (error) {
    logVueQrcodeReaderCameraError(error)

    return {
      stream: null,
      result: {
        status: 'ERROR',
        message: 'getUserMedia ERROR',
        streamActive: 'inactive',
        tracks: '—',
        videoTrack: '—',
        facingMode: '—',
        width: '—',
        height: '—',
        error: serializeUnknownError(error),
      },
    }
  }
}

export function buildOperationalSummary(input: {
  cameraInitStatus: 'SUCCESS' | 'ERROR' | 'WAITING'
  cameraActive: boolean
  videoProbe: VideoProbeInfo | null
  statusLabel: string
  detectionCount: number
  getUserMediaTest: IndependentMediaTestResult | null
  lastErrorDetails: DiagnosticErrorDetails | null
}): string {
  const videoActive = input.videoProbe?.videoElement === 'YES'
    && input.videoProbe.srcObject === 'YES'
    && input.videoProbe.streamActive === 'YES'

  const getUserMediaSuccess = input.getUserMediaTest?.status === 'SUCCESS'
  const getUserMediaError = input.getUserMediaTest?.status === 'ERROR'
  const vqrRunning = input.statusLabel === 'running' || input.statusLabel === 'detected'
  const vqrError = input.statusLabel === 'error'
  const detectionSuccess = input.detectionCount > 0

  if (detectionSuccess && (vqrRunning || input.statusLabel === 'detected')) {
    return 'Cas 4 — getUserMedia: SUCCESS (si testé) · Video: ACTIVE · vue-qrcode-reader: RUNNING · Detection: SUCCESS. Conclusion : vue-qrcode-reader fonctionne correctement sur ce téléphone.'
  }

  if (getUserMediaSuccess && videoActive && vqrError) {
    return 'Cas 3 — getUserMedia: SUCCESS · Video: ACTIVE · vue-qrcode-reader: ERROR. Conclusion : la caméra fonctionne mais vue-qrcode-reader rencontre un problème lors de son initialisation.'
  }

  if (input.cameraInitStatus === 'ERROR' || getUserMediaError || vqrError) {
    if (getUserMediaError || input.cameraInitStatus === 'ERROR') {
      return 'Cas 2 — Camera: ERROR · getUserMedia: ERROR. Conclusion : le problème vient de l\'accès caméra/navigateur, pas du décodage du code-barres.'
    }
  }

  if ((input.cameraInitStatus === 'SUCCESS' || vqrRunning) && videoActive && !detectionSuccess) {
    return 'Cas 1 — Camera: SUCCESS · Video: ACTIVE · vue-qrcode-reader: RUNNING · Detection: NOT FOUND. Conclusion : la caméra fonctionne et vue-qrcode-reader est actif, mais aucun code n\'a été détecté.'
  }

  if (input.lastErrorDetails) {
    return `En attente de diagnostic complet. Dernière erreur : ${input.lastErrorDetails.name} — ${input.lastErrorDetails.message}`
  }

  return 'En attente des résultats caméra et vue-qrcode-reader...'
}
