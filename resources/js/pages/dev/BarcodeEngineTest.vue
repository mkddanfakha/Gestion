<script setup lang="ts">
import {
  buildDiagnosticClipboardText,
  buildDiagnosticConclusion,
  buildHistoryEntry,
  captureReferenceImageFromVideo,
  ENGINE_DEFINITIONS,
  ENGINE_VERSIONS,
  getVariantDefinitions,
  loadReferenceImageFromFile,
  runAllEnginesOnCanvas,
  runEngineOnCanvas,
  statusLabel,
  type EngineId,
  type EngineTestResult,
  type HistoryEntry,
  type ReferenceImageInfo,
  type VariantId,
} from '@/utils/barcodeEngineDiagnostics'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const DEBUG = import.meta.env.DEV

type CameraState = 'idle' | 'starting' | 'active' | 'stopping' | 'error'

const FULL_CONSTRAINTS: MediaStreamConstraints = {
  video: {
    facingMode: { ideal: 'environment' },
    width: { ideal: 1280 },
    height: { ideal: 720 },
  },
  audio: false,
}

const SIMPLE_CONSTRAINTS: MediaStreamConstraints = {
  video: true,
  audio: false,
}

const referenceImage = ref<ReferenceImageInfo | null>(null)
const originalResults = ref<EngineTestResult[]>([])
const variantResults = ref<EngineTestResult[]>([])
const history = ref<HistoryEntry[]>([])
const testRunning = ref(false)
const variantTestRunning = ref(false)
const copyMessage = ref<string | null>(null)

const videoRef = ref<HTMLVideoElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const cameraState = ref<CameraState>('idle')
const cameraError = ref<string | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

let cameraSessionId = 0

const userAgent = typeof navigator !== 'undefined' ? navigator.userAgent : '—'
const variantDefinitions = getVariantDefinitions()

const detectedResult = computed(() => {
  return [...originalResults.value, ...variantResults.value].find((result) => result.status === 'success') ?? null
})

const conclusion = computed(() => buildDiagnosticConclusion([...originalResults.value, ...variantResults.value]))

const canRunTests = computed(() => referenceImage.value != null && !testRunning.value && !variantTestRunning.value)

async function handleFileUpload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) {
    return
  }

  try {
    referenceImage.value = await loadReferenceImageFromFile(file)
    originalResults.value = []
    variantResults.value = []
  } catch (error) {
    cameraError.value = error instanceof Error ? error.message : String(error)
  } finally {
    input.value = ''
  }
}

function isSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
}

function stopTracks(stream: MediaStream | null): void {
  stream?.getTracks().forEach((track) => {
    try {
      track.stop()
    } catch {
      // ignorer
    }
  })
}

async function waitForVideoReady(video: HTMLVideoElement, sessionId: number): Promise<boolean> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < 10_000) {
    if (!isSessionActive(sessionId)) {
      return false
    }

    if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
      return true
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return false
}

async function stopTestCamera(): Promise<void> {
  cameraSessionId += 1
  cameraState.value = 'stopping'

  stopTracks(activeStream.value)
  activeStream.value = null

  if (videoRef.value) {
    try {
      videoRef.value.pause()
    } catch {
      // ignorer
    }

    videoRef.value.srcObject = null
  }

  cameraState.value = 'idle'
}

async function openTestCamera(): Promise<void> {
  if (!DEBUG || cameraState.value === 'starting' || cameraState.value === 'active') {
    return
  }

  await stopTestCamera()

  const sessionId = ++cameraSessionId
  cameraState.value = 'starting'
  cameraError.value = null

  try {
    let stream = await navigator.mediaDevices.getUserMedia(FULL_CONSTRAINTS).catch(() => null)

    if (!stream) {
      stream = await navigator.mediaDevices.getUserMedia(SIMPLE_CONSTRAINTS)
    }

    if (!isSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      stopTracks(stream)
      activeStream.value = null
      cameraState.value = 'error'
      cameraError.value = 'Élément vidéo introuvable.'
      return
    }

    video.srcObject = stream
    await video.play()

    const ready = await waitForVideoReady(video, sessionId)

    if (!isSessionActive(sessionId)) {
      stopTracks(stream)
      video.srcObject = null
      activeStream.value = null
      return
    }

    if (!ready) {
      stopTracks(stream)
      video.pause()
      video.srcObject = null
      activeStream.value = null
      cameraState.value = 'error'
      cameraError.value = 'La caméra a été ouverte mais le flux vidéo n\'a pas fourni d\'image.'
      return
    }

    cameraState.value = 'active'
  } catch (error) {
    if (isSessionActive(sessionId)) {
      cameraState.value = 'error'
      cameraError.value = error instanceof Error ? `${error.name}: ${error.message}` : String(error)
    }
  }
}

async function captureFromTestCamera(): Promise<void> {
  if (!videoRef.value || cameraState.value !== 'active') {
    return
  }

  try {
    referenceImage.value = captureReferenceImageFromVideo(videoRef.value)
    originalResults.value = []
    variantResults.value = []
    await stopTestCamera()
  } catch (error) {
    cameraError.value = error instanceof Error ? error.message : String(error)
  }
}

function appendHistory(results: EngineTestResult[]): void {
  history.value = [
    ...results.map((result) => buildHistoryEntry(result)),
    ...history.value,
  ].slice(0, 50)
}

async function runAllEngines(): Promise<void> {
  if (!referenceImage.value) {
    return
  }

  testRunning.value = true

  try {
    const results = await runAllEnginesOnCanvas(referenceImage.value.canvas, 'A')
    originalResults.value = results
    appendHistory(results)
  } finally {
    testRunning.value = false
  }
}

async function runSingleEngine(engineId: EngineId): Promise<void> {
  if (!referenceImage.value) {
    return
  }

  testRunning.value = true

  try {
    const result = await runEngineOnCanvas(engineId, referenceImage.value.canvas, 'A')
    originalResults.value = [
      result,
      ...originalResults.value.filter((entry) => entry.engine !== engineId || entry.variantId !== 'A'),
    ]
    appendHistory([result])
  } finally {
    testRunning.value = false
  }
}

async function runVariantTests(): Promise<void> {
  if (!referenceImage.value) {
    return
  }

  variantTestRunning.value = true

  try {
    const variants = await buildVariantCanvases(referenceImage.value.canvas)
    const results: EngineTestResult[] = []

    for (const variant of variantDefinitions.filter((entry) => entry.complementary)) {
      for (const engine of ENGINE_DEFINITIONS) {
        const canvas = variants[variant.id]
        const result = await runEngineOnCanvas(engine.id, canvas, variant.id)
        results.push(result)
      }
    }

    variantResults.value = results
    appendHistory(results)
  } finally {
    variantTestRunning.value = false
  }
}

function clearHistory(): void {
  history.value = []
}

async function copyDiagnostic(): Promise<void> {
  const text = buildDiagnosticClipboardText({
    userAgent,
    referenceImage: referenceImage.value,
    results: [...originalResults.value, ...variantResults.value],
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié dans le presse-papiers.'
  } catch {
    copyMessage.value = 'Impossible de copier automatiquement le diagnostic.'
  }
}

function openFilePicker(): void {
  fileInputRef.value?.click()
}

function resultForEngine(engineId: EngineId, variantId: VariantId = 'A'): EngineTestResult | undefined {
  const pool = variantId === 'A' ? originalResults.value : variantResults.value

  return pool.find((result) => result.engine === engineId && result.variantId === variantId)
}

function handlePageHide(): void {
  void stopTestCamera()
}

onMounted(() => {
  window.addEventListener('pagehide', handlePageHide)
})

onBeforeUnmount(() => {
  window.removeEventListener('pagehide', handlePageHide)
  void stopTestCamera()
})
</script>

<template>
  <Head title="DEV — Banc moteurs code-barres" />

  <div class="barcode-reader-test-page barcode-engine-test">
    <div class="barcode-reader-test-page__container barcode-engine-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / BANC DE TEST UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">Test moteurs code-barres</h1>
          <p class="barcode-reader-test-page__intro mb-0">
            Compare plusieurs moteurs sur exactement la même image fixe. Aucun impact sur le scanner principal.
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
          Retour
        </Link>
      </header>

      <section class="barcode-engine-test__section">
        <h2 class="barcode-engine-test__section-title">Source de l'image</h2>

        <input
          ref="fileInputRef"
          type="file"
          accept="image/*"
          class="barcode-engine-test__file-input"
          @change="handleFileUpload"
        >

        <div class="barcode-engine-test__actions">
          <button type="button" class="btn btn-outline-primary" @click="openFilePicker">
            Importer une photo
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="cameraState === 'starting' || cameraState === 'active'"
            @click="openTestCamera"
          >
            Ouvrir caméra de test
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="cameraState === 'idle' || cameraState === 'stopping'"
            @click="stopTestCamera"
          >
            Fermer caméra de test
          </button>
          <button
            type="button"
            class="btn btn-outline-success"
            :disabled="cameraState !== 'active'"
            @click="captureFromTestCamera"
          >
            Capturer une image
          </button>
        </div>

        <p v-if="cameraError" class="barcode-engine-test__error mb-2">
          {{ cameraError }}
        </p>

        <div v-if="cameraState !== 'idle'" class="barcode-engine-test__video-wrap">
          <video
            ref="videoRef"
            class="barcode-engine-test__video"
            autoplay
            muted
            playsinline
          />
        </div>
      </section>

      <section v-if="referenceImage" class="barcode-engine-test__section">
        <h2 class="barcode-engine-test__section-title">Image de référence</h2>

        <img
          :src="referenceImage.dataUrl"
          alt="Image de référence pour les tests moteurs"
          class="barcode-engine-test__reference-image"
        >

        <dl class="barcode-engine-test__grid">
          <div><dt>Largeur</dt><dd>{{ referenceImage.width }}</dd></div>
          <div><dt>Hauteur</dt><dd>{{ referenceImage.height }}</dd></div>
          <div><dt>Ratio</dt><dd>{{ referenceImage.aspectRatio }}</dd></div>
          <div><dt>Source</dt><dd>{{ referenceImage.source }}</dd></div>
        </dl>
      </section>

      <section class="barcode-engine-test__section">
        <h2 class="barcode-engine-test__section-title">Test EAN-13</h2>
        <p class="barcode-engine-test__muted mb-2">
          Formats recherchés : EAN-13 / EAN-8 / UPC-A / UPC-E / Code 128 / Code 39
        </p>

        <template v-if="detectedResult">
          <p class="barcode-engine-test__success mb-1">CODE DÉTECTÉ</p>
          <dl class="barcode-engine-test__grid">
            <div class="barcode-engine-test__grid-full"><dt>Valeur brute</dt><dd class="font-monospace">{{ detectedResult.rawValue }}</dd></div>
            <div><dt>Format</dt><dd>{{ detectedResult.format }}</dd></div>
            <div><dt>Moteur</dt><dd>{{ detectedResult.engineLabel }}</dd></div>
            <div><dt>Temps</dt><dd>{{ detectedResult.durationMs }} ms</dd></div>
          </dl>
        </template>
        <p v-else class="barcode-engine-test__muted mb-0">
          Aucun code détecté pour l'instant sur les tests lancés.
        </p>
      </section>

      <section class="barcode-engine-test__section">
        <h2 class="barcode-engine-test__section-title">Versions</h2>
        <dl class="barcode-engine-test__grid">
          <div><dt>@zxing/browser</dt><dd>{{ ENGINE_VERSIONS.zxingBrowser }}</dd></div>
          <div><dt>@zxing/library</dt><dd>{{ ENGINE_VERSIONS.zxingLibrary }}</dd></div>
          <div><dt>barcode-detector</dt><dd>{{ ENGINE_VERSIONS.barcodeDetectorPolyfill }}</dd></div>
          <div><dt>vue-qrcode-reader</dt><dd>{{ ENGINE_VERSIONS.vueQrcodeReader }}</dd></div>
        </dl>
      </section>

      <section class="barcode-engine-test__section">
        <div class="barcode-engine-test__actions">
          <button
            type="button"
            class="btn btn-primary"
            :disabled="!canRunTests"
            @click="runAllEngines"
          >
            Tester tous les moteurs
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!canRunTests"
            @click="runSingleEngine('zxing')"
          >
            Tester ZXing
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!canRunTests"
            @click="runSingleEngine('barcode-detector-native')"
          >
            Tester BarcodeDetector
          </button>
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="!canRunTests"
            @click="runSingleEngine('barcode-detector-polyfill')"
          >
            Tester polyfill
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="!canRunTests"
            @click="runSingleEngine('vue-qrcode-reader')"
          >
            Tester vue-qrcode-reader
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="!referenceImage || variantTestRunning || testRunning"
            @click="runVariantTests"
          >
            Tester variantes (complémentaire)
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="originalResults.length === 0 && variantResults.length === 0"
            @click="copyDiagnostic"
          >
            Copier diagnostic
          </button>
        </div>

        <p v-if="copyMessage" class="barcode-engine-test__muted mt-2 mb-0">
          {{ copyMessage }}
        </p>
      </section>

      <section v-if="originalResults.length > 0" class="barcode-engine-test__section">
        <h2 class="barcode-engine-test__section-title">Résultats — image originale (variante A)</h2>

        <div class="barcode-engine-test__cards">
          <article
            v-for="engine in ENGINE_DEFINITIONS"
            :key="engine.id"
            class="barcode-engine-test__card"
          >
            <h3 class="barcode-engine-test__card-title">{{ engine.label }}</h3>
            <p class="barcode-engine-test__card-meta mb-2">{{ engine.description }}</p>

            <template v-if="resultForEngine(engine.id, 'A')">
              <p
                class="barcode-engine-test__status mb-1"
                :class="{
                  'barcode-engine-test__status--success': resultForEngine(engine.id, 'A')?.status === 'success',
                  'barcode-engine-test__status--muted': resultForEngine(engine.id, 'A')?.status === 'not_found',
                }"
              >
                {{ statusLabel(resultForEngine(engine.id, 'A')!.status) }}
              </p>

              <dl class="barcode-engine-test__grid">
                <template v-if="resultForEngine(engine.id, 'A')?.status === 'success'">
                  <div class="barcode-engine-test__grid-full"><dt>Valeur</dt><dd class="font-monospace">{{ resultForEngine(engine.id, 'A')?.rawValue }}</dd></div>
                  <div><dt>Format</dt><dd>{{ resultForEngine(engine.id, 'A')?.format }}</dd></div>
                </template>
                <div v-else-if="resultForEngine(engine.id, 'A')?.status === 'not_found'" class="barcode-engine-test__grid-full">
                  <dd class="barcode-engine-test__muted mb-0">Aucun code détecté.</dd>
                </div>
                <div v-else-if="resultForEngine(engine.id, 'A')?.errorMessage" class="barcode-engine-test__grid-full">
                  <dt>Message</dt><dd>{{ resultForEngine(engine.id, 'A')?.errorMessage }}</dd>
                </div>
                <div v-if="resultForEngine(engine.id, 'A')?.durationMs != null"><dt>Temps</dt><dd>{{ resultForEngine(engine.id, 'A')?.durationMs }} ms</dd></div>
              </dl>
            </template>
          </article>
        </div>
      </section>

      <section v-if="variantResults.length > 0" class="barcode-engine-test__section">
        <h2 class="barcode-engine-test__section-title">Résultats variantes complémentaires</h2>
        <p class="barcode-engine-test__muted mb-2">
          Le test principal reste l'image originale. Les variantes ne servent qu'à explorer le format d'entrée.
        </p>

        <div class="barcode-engine-test__variant-list">
          <article
            v-for="result in variantResults"
            :key="`${result.engine}-${result.variantId}-${result.durationMs}`"
            class="barcode-engine-test__variant-item"
          >
            <strong>{{ result.engineLabel }}</strong>
            <span>{{ result.variantLabel }}</span>
            <span>{{ statusLabel(result.status) }}</span>
            <span v-if="result.rawValue" class="font-monospace">{{ result.rawValue }}</span>
            <span v-if="result.durationMs != null">{{ result.durationMs }} ms</span>
          </article>
        </div>
      </section>

      <section v-if="conclusion" class="barcode-engine-test__section barcode-engine-test__conclusion">
        <h2 class="barcode-engine-test__section-title">Conclusion</h2>
        <pre class="barcode-engine-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <section v-if="history.length > 0" class="barcode-engine-test__section">
        <div class="barcode-engine-test__history-header">
          <h2 class="barcode-engine-test__section-title mb-0">Historique</h2>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearHistory">
            Effacer l'historique
          </button>
        </div>

        <div class="barcode-engine-test__history">
          <article
            v-for="entry in history"
            :key="entry.id"
            class="barcode-engine-test__history-item"
          >
            <div>{{ entry.timestamp }} — {{ entry.engine }} — {{ entry.status }}</div>
            <div v-if="entry.rawValue" class="font-monospace">{{ entry.format }} — {{ entry.rawValue }}</div>
            <div v-if="entry.durationMs != null">{{ entry.durationMs }} ms — variante {{ entry.variantId }}</div>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>
