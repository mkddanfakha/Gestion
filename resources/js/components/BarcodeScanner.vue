<template>
  <div class="barcode-scanner">
    <label v-if="label" :for="inputId" class="form-label barcode-scanner__label">
      <i class="bi bi-upc-scan me-1 text-muted" aria-hidden="true"></i>
      {{ label }}
    </label>

    <div class="input-group barcode-scanner__input-group">
      <span class="input-group-text barcode-scanner__icon" aria-hidden="true">
        <i class="bi bi-upc-scan"></i>
      </span>
      <input
        :id="inputId"
        ref="scanInputRef"
        v-model="scanBuffer"
        type="text"
        class="form-control barcode-scanner__input"
        :placeholder="placeholder"
        :disabled="disabled || processing"
        inputmode="text"
        autocomplete="off"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="done"
        aria-label="Scanner ou saisir un code-barres"
        @keydown.enter.prevent="submitHardwareScan"
      />
      <button
        type="button"
        class="btn btn-outline-primary barcode-scanner__submit-btn"
        :disabled="disabled || processing || !scanBuffer.trim()"
        aria-label="Valider le code-barres saisi"
        @click="submitHardwareScan"
      >
        <i class="bi bi-check2" aria-hidden="true"></i>
        <span class="barcode-scanner__btn-label ms-1">Valider</span>
      </button>
      <button
        v-if="showCameraButton"
        type="button"
        class="btn btn-outline-secondary barcode-scanner__camera-btn"
        :disabled="disabled || processing"
        aria-label="Ouvrir le scanner caméra"
        @click="openCamera"
      >
        <i class="bi bi-camera" aria-hidden="true"></i>
        <span class="barcode-scanner__btn-label ms-1">Scanner</span>
      </button>
    </div>

    <div
      v-if="capabilityNotice"
      class="alert alert-info barcode-scanner__capability-notice mt-2 mb-0"
      role="status"
      aria-live="polite"
    >
      {{ capabilityNotice }}
    </div>

    <div
      v-if="statusMessage"
      class="barcode-scanner__status mt-2"
      :class="`barcode-scanner__status--${statusVariant}`"
      role="status"
      aria-live="polite"
    >
      {{ statusMessage }}
    </div>

    <div
      v-if="notFoundBarcode"
      class="alert alert-warning barcode-scanner__not-found mt-2 mb-0"
      role="alert"
    >
      <div class="fw-semibold">Produit introuvable</div>
      <div class="small mt-1">Code-barres : <span class="font-monospace">{{ notFoundBarcode }}</span></div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-sm btn-outline-primary" @click="emitSearchRequest">
          Rechercher le produit
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="emitDismissNotFound">
          Fermer
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="cameraOpen"
        class="barcode-scanner-modal-root"
        role="dialog"
        aria-modal="true"
        aria-labelledby="barcode-scanner-modal-title"
      >
        <div class="barcode-scanner-modal-root__backdrop" @click="closeCamera"></div>
        <div class="barcode-scanner-modal">
          <div class="barcode-scanner-modal__header">
            <h2 id="barcode-scanner-modal-title" class="h6 mb-0">Scanner un code-barres</h2>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              aria-label="Fermer le scanner"
              @click="closeCamera"
            >
              <i class="bi bi-x-lg" aria-hidden="true"></i>
              Fermer
            </button>
          </div>

          <div class="barcode-scanner-modal__body">
            <div v-if="cameraPreviewActive" class="barcode-scanner-modal__viewport">
              <video
                ref="videoRef"
                class="barcode-scanner-modal__video"
                playsinline
                muted
                aria-hidden="true"
              ></video>
              <div class="barcode-scanner-modal__frame" aria-hidden="true"></div>
            </div>

            <p class="barcode-scanner-modal__hint mb-0" :class="{ 'mt-3': !cameraPreviewActive }">
              {{ cameraStatus }}
            </p>

            <div
              v-if="cameraError"
              class="alert alert-danger barcode-scanner-modal__error mt-3 mb-0"
              role="alert"
            >
              {{ cameraError }}
            </div>

            <div v-if="cameraError" class="d-flex justify-content-end mt-3">
              <button type="button" class="btn btn-outline-secondary" @click="closeCamera">
                Retour au champ manuel
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import type { BrowserMultiFormatReader, IScannerControls } from '@zxing/browser'
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getBarcodeCameraAvailability, getBarcodeCameraErrorMessage } from '@/utils/barcodeCamera'
import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'

const props = withDefaults(
  defineProps<{
    disabled?: boolean
    processing?: boolean
    placeholder?: string
    label?: string
    showCameraButton?: boolean
    notFoundBarcode?: string | null
    statusMessage?: string
    statusVariant?: 'info' | 'success' | 'warning' | 'danger'
    autofocus?: boolean
  }>(),
  {
    disabled: false,
    processing: false,
    placeholder: 'Scanner ou saisir un code-barres...',
    label: 'Code-barres',
    showCameraButton: true,
    notFoundBarcode: null,
    statusMessage: '',
    statusVariant: 'info',
    autofocus: true,
  },
)

const emit = defineEmits<{
  'barcode-detected': [barcode: string]
  'search-request': [barcode: string]
  'dismiss-not-found': []
}>()

const DEBUG = import.meta.env.DEV

const inputId = `barcode-scanner-${Math.random().toString(36).slice(2, 9)}`
const scanInputRef = ref<HTMLInputElement | null>(null)
const scanBuffer = ref('')
const cameraOpen = ref(false)
const cameraPreviewActive = ref(false)
const cameraError = ref('')
const cameraStatus = ref('Placez le code-barres dans le cadre')
const capabilityNotice = ref('')
const videoRef = ref<HTMLVideoElement | null>(null)

let barcodeReader: BrowserMultiFormatReader | null = null
let scannerControls: IScannerControls | null = null
let lastHardwareEmit: { barcode: string; at: number } | null = null
let isClosingCamera = false

const DUPLICATE_EVENT_WINDOW_MS = 150

const debugLog = (...args: unknown[]) => {
  if (DEBUG) {
    console.info('[BarcodeScanner]', ...args)
  }
}

const refocusScanInput = () => {
  if (!props.autofocus || props.disabled || cameraOpen.value) {
    return
  }

  nextTick(() => {
    scanInputRef.value?.focus()
    scanInputRef.value?.select()
  })
}

const emitBarcode = (rawBarcode: string) => {
  const barcode = normalizeBarcode(rawBarcode)

  if (!isValidBarcode(barcode)) {
    capabilityNotice.value = 'Code-barres invalide. Utilisez uniquement des caractères alphanumériques.'
    debugLog('invalid barcode', rawBarcode)
    return
  }

  const now = Date.now()
  if (
    lastHardwareEmit.value?.barcode === barcode
    && now - lastHardwareEmit.value.at < DUPLICATE_EVENT_WINDOW_MS
  ) {
    refocusScanInput()
    return
  }

  lastHardwareEmit.value = { barcode, at: now }
  capabilityNotice.value = ''
  debugLog('barcode detected', barcode)
  emit('barcode-detected', barcode)
  refocusScanInput()
}

const submitHardwareScan = () => {
  emitBarcode(scanBuffer.value)
  scanBuffer.value = ''
}

const emitSearchRequest = () => {
  if (props.notFoundBarcode) {
    emit('search-request', props.notFoundBarcode)
  }
}

const emitDismissNotFound = () => {
  emit('dismiss-not-found')
}

const stopCamera = async () => {
  scannerControls?.stop()
  scannerControls = null

  if (barcodeReader) {
    barcodeReader.reset()
  }

  const stream = videoRef.value?.srcObject
  if (stream instanceof MediaStream) {
    stream.getTracks().forEach((track) => track.stop())
  }

  if (videoRef.value) {
    videoRef.value.srcObject = null
  }

  cameraPreviewActive.value = false
}

const closeCamera = async () => {
  if (isClosingCamera) {
    return
  }

  isClosingCamera = true

  await stopCamera()
  cameraOpen.value = false
  cameraError.value = ''
  cameraStatus.value = 'Placez le code-barres dans le cadre'
  isClosingCamera = false
  refocusScanInput()
}

const openCamera = async () => {
  if (props.disabled || props.processing || cameraOpen.value) {
    return
  }

  debugLog('scanner button clicked')

  capabilityNotice.value = ''
  cameraError.value = ''

  const availability = getBarcodeCameraAvailability()
  debugLog('camera capability detected', availability)

  if (availability.state !== 'ready') {
    capabilityNotice.value = availability.message
    refocusScanInput()
    return
  }

  cameraOpen.value = true
  cameraPreviewActive.value = true
  cameraStatus.value = 'Initialisation de la caméra...'

  await nextTick()

  if (!videoRef.value) {
    cameraPreviewActive.value = false
    cameraError.value = 'Impossible d’ouvrir le scanner caméra.'
    cameraStatus.value = 'Scanner indisponible'
    return
  }

  try {
    debugLog('getUserMedia started')

    const { BrowserMultiFormatReader } = await import('@zxing/browser')
    barcodeReader ??= new BrowserMultiFormatReader()

    scannerControls = await barcodeReader.decodeFromVideoDevice(
      undefined,
      videoRef.value,
      (result, error, controls) => {
        if (result) {
          controls.stop()
          scannerControls = null
          emitBarcode(result.getText())
          void closeCamera()
          return
        }

        if (error && error.name !== 'NotFoundException') {
          cameraError.value = 'Erreur lors de la lecture du code-barres.'
          debugLog('camera error', error)
        } else {
          cameraStatus.value = 'Placez le code-barres dans le cadre'
        }
      },
    )

    debugLog('permission result', 'camera stream started')
    cameraStatus.value = 'Placez le code-barres dans le cadre'
  } catch (error) {
    cameraPreviewActive.value = false
    cameraError.value = getBarcodeCameraErrorMessage(error)
    cameraStatus.value = 'Scanner indisponible'
    debugLog('camera error', error)
  }
}

watch(
  () => props.processing,
  (isProcessing, wasProcessing) => {
    if (wasProcessing && !isProcessing) {
      refocusScanInput()
    }
  },
)

watch(
  () => props.disabled,
  (isDisabled) => {
    if (isDisabled) {
      void closeCamera()
      return
    }

    refocusScanInput()
  },
)

watch(cameraOpen, (isOpen, _oldValue, onCleanup) => {
  if (!isOpen) {
    return
  }

  const handleEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
      event.preventDefault()
      void closeCamera()
    }
  }

  document.addEventListener('keydown', handleEscape)
  onCleanup(() => {
    document.removeEventListener('keydown', handleEscape)
  })
})

onBeforeUnmount(() => {
  void stopCamera()
})

onMounted(() => {
  debugLog('component mounted', getBarcodeCameraAvailability())
  refocusScanInput()
})

const focus = () => {
  refocusScanInput()
}

defineExpose({
  focus,
})
</script>
