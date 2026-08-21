<script setup lang="ts">
import {
  buildOperationalConclusion,
  canvasToDataUrl,
  captureVideoFrame,
  createNativeBarcodeDetector,
  detectFromImageSource,
  getEnvironmentInfo,
  isNativeBarcodeDetectorSupported,
  logNativeBarcodeTest,
  logNativeBarcodeTestError,
  mapCameraError,
  NATIVE_CAMERA_CONSTRAINTS,
  readCameraInfo,
  runVariantTests,
  waitForVideoReady,
  type BarcodeDetectorLike,
  type CameraInfo,
  type DetectionOutcome,
  type EnvironmentInfo,
  type LiveDetectionStats,
  type OperationalConclusion,
  type SingleDetectionResult,
  type VariantResult,
} from '@/utils/nativeBarcodeDetectorTest'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const DEBUG = import.meta.env.DEV

const environment = ref<EnvironmentInfo>(getEnvironmentInfo())
const cameraInfo = ref<CameraInfo>(readCameraInfo(null))
const cameraError = ref<string | null>(null)
const cameraStarting = ref(false)
const cameraActive = ref(false)

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const variantResults = ref<VariantResult[]>([])
const variantTestRunning = ref(false)

const liveStats = ref<LiveDetectionStats>({
  framesTested: 0,
  detectionAttempts: 0,
  successfulDetections: 0,
  notFound: 0,
  errors: 0,
})
const liveDetectionRunning = ref(false)
const liveDetectionResult = ref<SingleDetectionResult | null>(null)
const isDetecting = ref(false)

const capturedImageUrl = ref<string | null>(null)
const capturedImageResult = ref<SingleDetectionResult | null>(null)
const capturedImageRunning = ref(false)

const importedImageUrl = ref<string | null>(null)
const importedImageResult = ref<SingleDetectionResult | null>(null)
const importedImageRunning = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)

let liveDetectionTimer: number | null = null
let cameraInfoTimer: number | null = null

const conclusion = computed<OperationalConclusion>(() => buildOperationalConclusion({
  cameraActive: cameraActive.value,
  barcodeDetectorSupported: environment.value.barcodeDetectorNative === 'SUPPORTED',
  liveDetection: liveDetectionResult.value?.status ?? (liveStats.value.detectionAttempts > 0 ? 'NOT_FOUND' : 'IDLE'),
  capturedImage: capturedImageResult.value?.status ?? 'IDLE',
  importedImage: importedImageResult.value?.status ?? 'IDLE',
}))

const barcodeDetectorLabel = computed(() => {
  return environment.value.barcodeDetectorNative === 'SUPPORTED'
    ? 'BarcodeDetector native: SUPPORTED'
    : 'BarcodeDetector native: NOT SUPPORTED'
})

function refreshEnvironment(): void {
  environment.value = getEnvironmentInfo()
}

function refreshCameraInfo(): void {
  cameraInfo.value = readCameraInfo(videoRef.value)
}

function startCameraInfoPolling(): void {
  stopCameraInfoPolling()
  cameraInfoTimer = window.setInterval(() => {
    refreshCameraInfo()
  }, 500)
}

function stopCameraInfoPolling(): void {
  if (cameraInfoTimer !== null) {
    window.clearInterval(cameraInfoTimer)
    cameraInfoTimer = null
  }
}

async function ensureDetector(): Promise<BarcodeDetectorLike | null> {
  if (!DEBUG) {
    cameraError.value = 'Page disponible uniquement en développement.'
    return null
  }

  if (!isNativeBarcodeDetectorSupported()) {
    cameraError.value = 'BarcodeDetector non supporté par ce navigateur.'
    return null
  }

  if (detectorRef.value) {
    return detectorRef.value
  }

  try {
    detectorRef.value = await createNativeBarcodeDetector()
    return detectorRef.value
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    cameraError.value = message
    logNativeBarcodeTestError('detector init failed', error)
    return null
  }
}

async function startCamera(): Promise<void> {
  if (!DEBUG) {
    return
  }

  if (cameraStarting.value || cameraActive.value) {
    return
  }

  refreshEnvironment()
  cameraError.value = null
  cameraStarting.value = true

  try {
    if (environment.value.getUserMedia !== 'available') {
      throw new Error('getUserMedia indisponible dans ce navigateur.')
    }

    const stream = await navigator.mediaDevices.getUserMedia(NATIVE_CAMERA_CONSTRAINTS)
    activeStream.value = stream

    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo introuvable.')
    }

    video.srcObject = stream
    await video.play()
    await waitForVideoReady(video)

    cameraActive.value = true
    refreshCameraInfo()
    startCameraInfoPolling()

    logNativeBarcodeTest('camera started', {
      width: video.videoWidth,
      height: video.videoHeight,
      readyState: video.readyState,
    })
  } catch (error) {
    cameraError.value = mapCameraError(error)
    logNativeBarcodeTestError('camera start failed', error)
    stopCamera()
  } finally {
    cameraStarting.value = false
  }
}

function stopLiveDetection(): void {
  liveDetectionRunning.value = false

  if (liveDetectionTimer !== null) {
    window.clearInterval(liveDetectionTimer)
    liveDetectionTimer = null
  }
}

function stopCamera(): void {
  stopLiveDetection()
  stopCameraInfoPolling()
  isDetecting.value = false

  activeStream.value?.getTracks().forEach((track) => track.stop())
  activeStream.value = null

  if (videoRef.value) {
    videoRef.value.srcObject = null
  }

  cameraActive.value = false
  refreshCameraInfo()

  logNativeBarcodeTest('camera stopped')
}

async function runVariantDetection(): Promise<void> {
  if (!cameraActive.value || !videoRef.value || variantTestRunning.value) {
    return
  }

  const detector = await ensureDetector()

  if (!detector) {
    return
  }

  variantTestRunning.value = true
  cameraError.value = null

  try {
    const frame = captureVideoFrame(videoRef.value)
    variantResults.value = await runVariantTests(detector, frame)
    logNativeBarcodeTest('variant tests completed', variantResults.value)
  } catch (error) {
    cameraError.value = error instanceof Error ? error.message : String(error)
    logNativeBarcodeTestError('variant tests failed', error)
  } finally {
    variantTestRunning.value = false
  }
}

async function performLiveDetectionTick(): Promise<void> {
  if (!liveDetectionRunning.value || !cameraActive.value || !videoRef.value || isDetecting.value) {
    return
  }

  const detector = await ensureDetector()

  if (!detector) {
    stopLiveDetection()
    return
  }

  liveStats.value.framesTested += 1
  isDetecting.value = true
  liveStats.value.detectionAttempts += 1

  try {
    const frame = captureVideoFrame(videoRef.value)
    const result = await detectFromImageSource(detector, frame)

    if (result.status === 'SUCCESS') {
      liveStats.value.successfulDetections += 1
      liveDetectionResult.value = result
      logNativeBarcodeTest('live detection success', result)
    } else if (result.status === 'NOT_FOUND') {
      liveStats.value.notFound += 1
    } else {
      liveStats.value.errors += 1
      logNativeBarcodeTestError('live detection error', result.error)
    }
  } catch (error) {
    liveStats.value.errors += 1
    logNativeBarcodeTestError('live detection tick failed', error)
  } finally {
    isDetecting.value = false
  }
}

function startLiveDetection(): void {
  if (!cameraActive.value || liveDetectionRunning.value) {
    return
  }

  liveDetectionRunning.value = true
  liveDetectionTimer = window.setInterval(() => {
    void performLiveDetectionTick()
  }, 200)

  logNativeBarcodeTest('live detection started')
}

async function captureAndTestImage(): Promise<void> {
  if (!cameraActive.value || !videoRef.value || capturedImageRunning.value) {
    return
  }

  const detector = await ensureDetector()

  if (!detector) {
    return
  }

  capturedImageRunning.value = true
  cameraError.value = null

  try {
    const frame = captureVideoFrame(videoRef.value)
    capturedImageUrl.value = canvasToDataUrl(frame)
    capturedImageResult.value = await detectFromImageSource(detector, frame)

    if (capturedImageResult.value.status === 'NOT_FOUND') {
      logNativeBarcodeTest('captured image not found')
    } else {
      logNativeBarcodeTest('captured image result', capturedImageResult.value)
    }
  } catch (error) {
    cameraError.value = error instanceof Error ? error.message : String(error)
    logNativeBarcodeTestError('capture test failed', error)
  } finally {
    capturedImageRunning.value = false
  }
}

async function handleImportedImage(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) {
    return
  }

  importedImageRunning.value = true
  importedImageResult.value = null
  cameraError.value = null

  const detector = await ensureDetector()

  if (!detector) {
    importedImageRunning.value = false
    input.value = ''
    return
  }

  try {
    const bitmap = await createImageBitmap(file)
    importedImageUrl.value = URL.createObjectURL(file)
    importedImageResult.value = await detectFromImageSource(detector, bitmap)
    bitmap.close()

    logNativeBarcodeTest('imported image result', {
      dimensions: `${importedImageResult.value.imageWidth} × ${importedImageResult.value.imageHeight}`,
      status: importedImageResult.value.status,
    })
  } catch (error) {
    cameraError.value = error instanceof Error ? error.message : String(error)
    logNativeBarcodeTestError('imported image failed', error)
  } finally {
    importedImageRunning.value = false
    input.value = ''
  }
}

function openFilePicker(): void {
  fileInputRef.value?.click()
}

function outcomeLabel(status: DetectionOutcome | 'IDLE'): string {
  if (status === 'IDLE') {
    return '—'
  }

  if (status === 'SUCCESS') {
    return 'SUCCESS'
  }

  if (status === 'NOT_FOUND') {
    return 'NOT FOUND'
  }

  return 'ERROR'
}

onBeforeUnmount(() => {
  stopCamera()
})
</script>

<template>
  <Head title="DEV — BarcodeDetector natif" />

  <div class="barcode-reader-test-page native-barcode-detector-test">
    <div class="barcode-reader-test-page__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / DIAGNOSTIC UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">BarcodeDetector natif</h1>
          <p class="barcode-reader-test-page__intro mb-0">
            Test caméra et détection EAN-13 via l'API native du navigateur. Sans ZXing, sans vue-qrcode-reader.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
          Retour
        </Link>
      </header>

      <section class="native-barcode-detector-test__section">
        <h2 class="native-barcode-detector-test__section-title">Native camera diagnostic</h2>

        <dl class="native-barcode-detector-test__grid">
          <div><dt>Secure context</dt><dd>{{ environment.secureContext ? 'YES' : 'NO' }}</dd></div>
          <div><dt>Protocol</dt><dd>{{ environment.protocol }}</dd></div>
          <div><dt>Hostname</dt><dd>{{ environment.hostname }}</dd></div>
          <div><dt>Navigateur</dt><dd>{{ environment.browserLabel }}</dd></div>
          <div><dt>mediaDevices</dt><dd>{{ environment.mediaDevices }}</dd></div>
          <div><dt>getUserMedia</dt><dd>{{ environment.getUserMedia }}</dd></div>
          <div class="native-barcode-detector-test__grid-full"><dt>User agent</dt><dd class="native-barcode-detector-test__break">{{ environment.userAgent }}</dd></div>
          <div class="native-barcode-detector-test__grid-full"><dt>{{ barcodeDetectorLabel.split(':')[0] }}</dt><dd>{{ barcodeDetectorLabel.split(': ')[1] }}</dd></div>
        </dl>
      </section>

      <section class="native-barcode-detector-test__section">
        <h2 class="native-barcode-detector-test__section-title">Caméra</h2>

        <dl class="native-barcode-detector-test__grid">
          <div><dt>État</dt><dd>{{ cameraActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Résolution</dt><dd>{{ cameraInfo.resolution }}</dd></div>
          <div><dt>ReadyState</dt><dd>{{ cameraInfo.readyState }}</dd></div>
          <div><dt>Paused</dt><dd>{{ cameraInfo.paused ? 'true' : 'false' }}</dd></div>
          <div><dt>Tracks</dt><dd>{{ cameraInfo.trackCount }}</dd></div>
          <div><dt>Video tracks</dt><dd>{{ cameraInfo.videoTrackCount }}</dd></div>
          <div><dt>Track state</dt><dd>{{ cameraInfo.trackState }}</dd></div>
          <div><dt>Facing</dt><dd>{{ cameraInfo.facingMode }}</dd></div>
          <div><dt>Frame rate</dt><dd>{{ cameraInfo.frameRate }}</dd></div>
        </dl>

        <p v-if="cameraError" class="native-barcode-detector-test__error">
          {{ cameraError }}
        </p>
      </section>

      <section class="native-barcode-detector-test__section">
        <div class="native-barcode-detector-test__video-wrap">
          <video
            ref="videoRef"
            class="native-barcode-detector-test__video"
            autoplay
            muted
            playsinline
          />
        </div>

        <div class="native-barcode-detector-test__actions">
          <button
            type="button"
            class="btn btn-primary"
            :disabled="cameraStarting || cameraActive"
            @click="startCamera"
          >
            Démarrer caméra
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="!cameraActive"
            @click="stopCamera"
          >
            Arrêter caméra
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!cameraActive || variantTestRunning || environment.barcodeDetectorNative !== 'SUPPORTED'"
            @click="runVariantDetection"
          >
            Tester variantes A–E
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!cameraActive || liveDetectionRunning || environment.barcodeDetectorNative !== 'SUPPORTED'"
            @click="startLiveDetection"
          >
            Démarrer détection native
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="!liveDetectionRunning"
            @click="stopLiveDetection"
          >
            Arrêter détection
          </button>
          <button
            type="button"
            class="btn btn-outline-success"
            :disabled="!cameraActive || capturedImageRunning || environment.barcodeDetectorNative !== 'SUPPORTED'"
            @click="captureAndTestImage"
          >
            Capturer et tester une image
          </button>
        </div>
      </section>

      <section
        v-if="variantResults.length > 0"
        class="native-barcode-detector-test__section"
      >
        <h2 class="native-barcode-detector-test__section-title">Variantes BarcodeDetector</h2>

        <div
          v-for="variant in variantResults"
          :key="variant.id"
          class="native-barcode-detector-test__variant"
        >
          <h3 class="native-barcode-detector-test__variant-title">
            Variant {{ variant.id }} — {{ variant.label }}
          </h3>
          <dl class="native-barcode-detector-test__grid">
            <div><dt>Status</dt><dd>{{ variant.status === 'SUCCESS' ? 'SUCCESS' : variant.status === 'NOT_FOUND' ? 'NOT FOUND' : 'ERROR' }}</dd></div>
            <div><dt>Results</dt><dd>{{ variant.resultCount }}</dd></div>
            <div><dt>Canvas</dt><dd>{{ variant.canvasWidth }} × {{ variant.canvasHeight }}</dd></div>
            <template v-if="variant.status === 'SUCCESS'">
              <div class="native-barcode-detector-test__grid-full"><dt>Result</dt><dd class="font-monospace">{{ variant.rawValue }}</dd></div>
              <div><dt>Format</dt><dd>{{ variant.format }}</dd></div>
            </template>
            <div v-else-if="variant.status === 'NOT_FOUND'" class="native-barcode-detector-test__grid-full">
              <dd class="native-barcode-detector-test__muted mb-0">Aucun code-barres détecté dans cette image.</dd>
            </div>
            <div v-else class="native-barcode-detector-test__grid-full">
              <dt>Erreur</dt><dd>{{ variant.error }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section class="native-barcode-detector-test__section">
        <h2 class="native-barcode-detector-test__section-title">Détection native en direct</h2>

        <dl class="native-barcode-detector-test__grid">
          <div><dt>État</dt><dd>{{ liveDetectionRunning ? 'RUNNING' : 'STOPPED' }}</dd></div>
          <div><dt>Frames tested</dt><dd>{{ liveStats.framesTested }}</dd></div>
          <div><dt>Detection attempts</dt><dd>{{ liveStats.detectionAttempts }}</dd></div>
          <div><dt>Successful detections</dt><dd>{{ liveStats.successfulDetections }}</dd></div>
          <div><dt>Not found</dt><dd>{{ liveStats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ liveStats.errors }}</dd></div>
        </dl>

        <template v-if="liveDetectionResult?.status === 'SUCCESS'">
          <p class="native-barcode-detector-test__success mb-1">SUCCESS</p>
          <dl class="native-barcode-detector-test__grid">
            <div class="native-barcode-detector-test__grid-full"><dt>Raw value</dt><dd class="font-monospace">{{ liveDetectionResult.rawValue }}</dd></div>
            <div><dt>Format</dt><dd>{{ liveDetectionResult.format }}</dd></div>
          </dl>
        </template>
        <p v-else-if="liveStats.detectionAttempts > 0" class="native-barcode-detector-test__muted mb-0">
          Aucun code-barres détecté pour l'instant.
        </p>
      </section>

      <section
        v-if="capturedImageUrl"
        class="native-barcode-detector-test__section"
      >
        <h2 class="native-barcode-detector-test__section-title">Image capturée</h2>

        <img
          :src="capturedImageUrl"
          alt="Frame capturée depuis la caméra"
          class="native-barcode-detector-test__preview-image"
        >

        <dl v-if="capturedImageResult" class="native-barcode-detector-test__grid">
          <div><dt>Status</dt><dd>{{ outcomeLabel(capturedImageResult.status) }}</dd></div>
          <div><dt>Dimensions</dt><dd>{{ capturedImageResult.imageWidth }} × {{ capturedImageResult.imageHeight }}</dd></div>
          <template v-if="capturedImageResult.status === 'SUCCESS'">
            <div class="native-barcode-detector-test__grid-full"><dt>Value</dt><dd class="font-monospace">{{ capturedImageResult.rawValue }}</dd></div>
            <div><dt>Format</dt><dd>{{ capturedImageResult.format }}</dd></div>
          </template>
          <div v-else-if="capturedImageResult.status === 'NOT_FOUND'" class="native-barcode-detector-test__grid-full">
            <dd class="native-barcode-detector-test__muted mb-0">Aucun code-barres détecté dans cette image.</dd>
          </div>
        </dl>
      </section>

      <section class="native-barcode-detector-test__section">
        <h2 class="native-barcode-detector-test__section-title">📷 Importer une photo d'un vrai code-barres</h2>

        <p class="native-barcode-detector-test__muted">
          Tester une image depuis le téléphone — photographiez ou importez un EAN-13 de produit du commerce.
        </p>

        <input
          ref="fileInputRef"
          type="file"
          accept="image/*"
          capture="environment"
          class="native-barcode-detector-test__file-input"
          @change="handleImportedImage"
        >

        <button
          type="button"
          class="btn btn-outline-primary"
          :disabled="importedImageRunning || environment.barcodeDetectorNative !== 'SUPPORTED'"
          @click="openFilePicker"
        >
          Tester une image depuis le téléphone
        </button>

        <img
          v-if="importedImageUrl"
          :src="importedImageUrl"
          alt="Image importée pour test BarcodeDetector"
          class="native-barcode-detector-test__preview-image"
        >

        <dl v-if="importedImageResult" class="native-barcode-detector-test__grid">
          <div><dt>Status</dt><dd>{{ outcomeLabel(importedImageResult.status) }}</dd></div>
          <div><dt>Image dimensions</dt><dd>{{ importedImageResult.imageWidth }} × {{ importedImageResult.imageHeight }}</dd></div>
          <template v-if="importedImageResult.status === 'SUCCESS'">
            <div class="native-barcode-detector-test__grid-full"><dt>Value</dt><dd class="font-monospace">{{ importedImageResult.rawValue }}</dd></div>
            <div><dt>Format</dt><dd>{{ importedImageResult.format }}</dd></div>
          </template>
          <div v-else-if="importedImageResult.status === 'NOT_FOUND'" class="native-barcode-detector-test__grid-full">
            <dd class="native-barcode-detector-test__muted mb-0">Aucun code-barres détecté dans cette image.</dd>
          </div>
        </dl>
      </section>

      <section class="native-barcode-detector-test__section native-barcode-detector-test__conclusion">
        <h2 class="native-barcode-detector-test__section-title">===== CONCLUSION =====</h2>

        <dl class="native-barcode-detector-test__grid">
          <div><dt>Camera</dt><dd>{{ conclusion.camera }}</dd></div>
          <div><dt>BarcodeDetector</dt><dd>{{ conclusion.barcodeDetector }}</dd></div>
          <div><dt>Live detection</dt><dd>{{ conclusion.liveDetection }}</dd></div>
          <div><dt>Captured image</dt><dd>{{ conclusion.capturedImage }}</dd></div>
          <div><dt>Imported image</dt><dd>{{ conclusion.importedImage }}</dd></div>
        </dl>

        <p class="native-barcode-detector-test__interpretation mb-0">
          {{ conclusion.interpretation }}
        </p>
      </section>
    </div>
  </div>
</template>
