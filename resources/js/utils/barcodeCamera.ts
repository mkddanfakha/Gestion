import { BrowserCodeReader } from '@zxing/browser'
import {
  ChecksumException,
  FormatException,
  NotFoundException,
} from '@zxing/library'

export type BarcodeCameraState = 'ready' | 'insecure' | 'unsupported'

export interface BarcodeCameraAvailability {
  state: BarcodeCameraState
  message: string
}

const TRANSIENT_SCAN_ERROR_NAMES = new Set([
  'NotFoundException',
  'ChecksumException',
  'FormatException',
])

/**
 * Erreurs normales pendant le scan frame par frame (aucun code visible, frame partielle, etc.).
 * Ne doivent jamais déclencher un message d'erreur utilisateur.
 */
export function isTransientBarcodeScanError(error: unknown): boolean {
  if (error == null) {
    return true
  }

  if (
    error instanceof NotFoundException
    || error instanceof ChecksumException
    || error instanceof FormatException
  ) {
    return true
  }

  if (error instanceof Error && TRANSIENT_SCAN_ERROR_NAMES.has(error.name)) {
    return true
  }

  return false
}

/**
 * Erreur réellement bloquante émise par le moteur de détection pendant une session active.
 */
export function isFatalBarcodeScanError(error: unknown): boolean {
  return error != null && !isTransientBarcodeScanError(error)
}

export function getBarcodeCameraVideoConstraints(): MediaTrackConstraints {
  return {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280, min: 640 },
    height: { ideal: 720, min: 480 },
  }
}

export function getBarcodeCameraConstraints(): MediaStreamConstraints {
  return {
    audio: false,
    video: getBarcodeCameraVideoConstraints(),
  }
}

export function stopMediaStreamTracks(stream: MediaStream | null | undefined): void {
  if (!stream) {
    return
  }

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

export function hasLiveMediaStreamTracks(stream: MediaStream | null | undefined): boolean {
  if (!stream) {
    return false
  }

  return stream.getTracks().some((track) => track.readyState === 'live')
}

export function releaseAllBarcodeCameraStreams(): void {
  BrowserCodeReader.releaseAllStreams()
}

export async function applyPreferredCameraTrackSettings(stream: MediaStream | null | undefined): Promise<void> {
  if (!stream) {
    return
  }

  for (const track of stream.getVideoTracks()) {
    const capabilities = track.getCapabilities?.()

    if (!capabilities || !('focusMode' in capabilities)) {
      continue
    }

    const focusModes = capabilities.focusMode as string[] | undefined

    if (!Array.isArray(focusModes) || !focusModes.includes('continuous')) {
      continue
    }

    try {
      await track.applyConstraints({
        advanced: [{ focusMode: 'continuous' }],
      })
    } catch {
      // Autofocus continu non supporté sur cet appareil.
    }
  }
}

export async function waitForVideoFrameReady(
  video: HTMLVideoElement | null | undefined,
  timeoutMs = 8000,
): Promise<boolean> {
  if (!video) {
    return false
  }

  if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && video.videoWidth > 0 && video.videoHeight > 0) {
    return true
  }

  return await new Promise((resolve) => {
    let settled = false

    const finish = (isReady: boolean) => {
      if (settled) {
        return
      }

      settled = true
      video.removeEventListener('loadedmetadata', onReady)
      video.removeEventListener('canplay', onReady)
      window.clearTimeout(timeoutId)
      resolve(isReady)
    }

    const onReady = () => {
      finish(video.videoWidth > 0 && video.videoHeight > 0)
    }

    const timeoutId = window.setTimeout(() => {
      finish(video.videoWidth > 0 && video.videoHeight > 0)
    }, timeoutMs)

    video.addEventListener('loadedmetadata', onReady)
    video.addEventListener('canplay', onReady)
  })
}

/**
 * Caméra : nécessite en général un contexte sécurisé (HTTPS ou localhost).
 * http://192.168.x.x:8000 depuis un téléphone n'est pas un contexte sécurisé :
 * getUserMedia() sera bloqué, mais le champ manuel et le lecteur USB restent utilisables.
 */
export function getBarcodeCameraAvailability(): BarcodeCameraAvailability {
  if (typeof window === 'undefined') {
    return {
      state: 'unsupported',
      message: 'La caméra n’est pas disponible dans cet environnement.',
    }
  }

  if (!window.isSecureContext) {
    return {
      state: 'insecure',
      message:
        'La caméra nécessite HTTPS ou localhost sur ce navigateur. Utilisez le champ code-barres ou un lecteur USB.',
    }
  }

  if (!navigator.mediaDevices?.getUserMedia) {
    return {
      state: 'unsupported',
      message: 'La caméra n’est pas disponible sur ce navigateur ou cet appareil.',
    }
  }

  return {
    state: 'ready',
    message: '',
  }
}

export function getBarcodeCameraErrorMessage(error: unknown): string {
  if (!(error instanceof Error)) {
    return 'Impossible d’accéder à la caméra sur cet appareil.'
  }

  const name = error.name.toLowerCase()
  const message = error.message.toLowerCase()

  if (name === 'notallowederror' || message.includes('permission') || message.includes('notallowed')) {
    return 'Autorisation caméra refusée. Autorisez l’accès à la caméra dans les paramètres du navigateur.'
  }

  if (name === 'notfounderror' || message.includes('requested device not found')) {
    return 'Aucune caméra détectée sur cet appareil.'
  }

  if (name === 'notreadableerror' || message.includes('could not start video source')) {
    return 'La caméra est utilisée par une autre application.'
  }

  if (name === 'securityerror' || message.includes('secure')) {
    return 'La caméra nécessite HTTPS ou localhost sur ce navigateur.'
  }

  return 'Impossible d’accéder à la caméra sur cet appareil.'
}
