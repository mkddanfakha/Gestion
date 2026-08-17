export type BarcodeCameraState = 'ready' | 'insecure' | 'unsupported'

export interface BarcodeCameraAvailability {
  state: BarcodeCameraState
  message: string
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
    return 'Autorisation caméra refusée. Vérifiez les permissions du navigateur.'
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
