<script setup lang="ts">
import { getEnvironmentDiagnostics } from '@/utils/barcodeDistanceFocusExperiment'
import {
  DEFAULT_QUAGGA2_DEV_CONFIG,
  getQuagga2CameraSnapshot,
  isQuagga2Running,
  startQuagga2Scanner,
  stopQuagga2Scanner,
  type Quagga2CameraSnapshot,
  type Quagga2DevConfig,
  type Quagga2DetectionPayload,
  type Quagga2PatchSize,
} from '@/utils/quagga2/quagga2Scanner'
import {
  classifyQuagga2Detection,
  normalizeQuaggaFormat,
  type Quagga2DetectionKind,
} from '@/utils/quagga2/quagga2Validation'
import { Head, Link } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dashboard } from '@/routes'

const MAX_DEBUG_DETECTIONS = 20
const DEFAULT_EXPECTED_BARCODE = '6043000070493'
const DEFAULT_EXPECTED_FORMAT = 'ean_13'

type ScannerUiState = 'IDLE' | 'STARTING' | 'RUNNING' | 'ERROR' | 'STOPPED'

interface DebugDetection {
  id: string
  timestamp: string
  rawValue: string
  format: string
  normalizedFormat: string
  classification: Quagga2DetectionKind
  widthRatio: string
}

const scannerRef = ref<HTMLElement | null>(null)
const environment = ref(getEnvironmentDiagnostics())
const scannerState = ref<ScannerUiState>('IDLE')
const scannerError = ref<string | null>(null)

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const expectedFormat = ref(DEFAULT_EXPECTED_FORMAT)

const devConfig = ref<Quagga2DevConfig>({ ...DEFAULT_QUAGGA2_DEV_CONFIG })
const cameraSnapshot = ref<Quagga2CameraSnapshot | null>(null)

const lastResult = ref<Quagga2DetectionPayload | null>(null)
const lastClassification = ref<Quagga2DetectionKind>('NO_DETECTION')
const debugDetections = ref<DebugDetection[]>([])

const stats = ref({
  totalDetections: 0,
  correct: 0,
  incorrect: 0,
  invalidChecksum: 0,
  falsePositive: 0,
})

const patchSizeOptions: Quagga2PatchSize[] = ['x-small', 'small', 'medium', 'large', 'x-large']

let diagnosticsTimer: number | null = null
let activeStop: (() => Promise<void>) | null = null

const resolutionLabel = computed(() => {
  const cam = cameraSnapshot.value

  if (!cam) {
    return '—'
  }

  return `${cam.actualWidth ?? '—'}×${cam.actualHeight ?? '—'} (demandé ${cam.requestedWidth}×${cam.requestedHeight})`
})

const fpsLabel = computed(() =>
  cameraSnapshot.value?.actualFps != null
    ? String(Math.round(cameraSnapshot.value.actualFps))
    : '—',
)

const canStart = computed(() =>
  scannerState.value !== 'STARTING'
  && scannerState.value !== 'RUNNING'
  && scannerRef.value != null,
)

function pushDebugDetection(payload: Quagga2DetectionPayload, classification: Quagga2DetectionKind): void {
  const entry: DebugDetection = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    rawValue: payload.rawValue,
    format: payload.format,
    normalizedFormat: normalizeQuaggaFormat(payload.format),
    classification,
    widthRatio: payload.widthRatio != null ? `${(payload.widthRatio * 100).toFixed(1)}%` : '—',
  }

  debugDetections.value = [entry, ...debugDetections.value].slice(0, MAX_DEBUG_DETECTIONS)
}

function updateStats(classification: Quagga2DetectionKind): void {
  stats.value.totalDetections += 1

  if (classification === 'CORRECT') {
    stats.value.correct += 1
  } else if (classification === 'INVALID_CHECKSUM' || classification === 'WRONG_VALID_CHECKSUM') {
    stats.value.invalidChecksum += 1
    stats.value.incorrect += 1
  } else if (classification === 'FALSE_POSITIVE') {
    stats.value.falsePositive += 1
    stats.value.incorrect += 1
  } else if (classification !== 'NO_DETECTION') {
    stats.value.incorrect += 1
  }
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    if (isQuagga2Running()) {
      cameraSnapshot.value = getQuagga2CameraSnapshot(devConfig.value)
    }
  }, 500)
}

function stopDiagnosticsPolling(): void {
  if (diagnosticsTimer !== null) {
    window.clearInterval(diagnosticsTimer)
    diagnosticsTimer = null
  }
}

async function startScanner(): Promise<void> {
  const target = scannerRef.value

  if (!target) {
    scannerError.value = 'Conteneur scanner indisponible.'
    return
  }

  scannerState.value = 'STARTING'
  scannerError.value = null

  try {
    const session = await startQuagga2Scanner({
      target,
      config: { ...devConfig.value },
      onDetected: (payload) => {
        const classification = classifyQuagga2Detection(
          payload.rawValue,
          payload.format,
          expectedBarcode.value,
          expectedFormat.value,
        )

        lastResult.value = payload
        lastClassification.value = classification
        updateStats(classification)
        pushDebugDetection(payload, classification)
      },
      onError: (error) => {
        scannerError.value = error.message
      },
    })

    activeStop = session.stop
    cameraSnapshot.value = session.camera
    scannerState.value = 'RUNNING'
    startDiagnosticsPolling()
  } catch (error) {
    scannerState.value = 'ERROR'
    scannerError.value = error instanceof Error ? error.message : 'Échec démarrage Quagga2'
  }
}

async function stopScanner(): Promise<void> {
  stopDiagnosticsPolling()

  if (activeStop) {
    await activeStop()
    activeStop = null
  } else {
    await stopQuagga2Scanner()
  }

  scannerState.value = 'STOPPED'
  window.setTimeout(() => {
    if (scannerState.value === 'STOPPED') {
      scannerState.value = 'IDLE'
    }
  }, 300)
}

function resetScanner(): void {
  void stopScanner()
  lastResult.value = null
  lastClassification.value = 'NO_DETECTION'
  debugDetections.value = []
  stats.value = {
    totalDetections: 0,
    correct: 0,
    incorrect: 0,
    invalidChecksum: 0,
    falsePositive: 0,
  }
  cameraSnapshot.value = null
  scannerError.value = null
}

function classificationClass(classification: Quagga2DetectionKind): string {
  if (classification === 'CORRECT') {
    return 'text-success'
  }

  if (classification === 'INVALID_CHECKSUM' || classification === 'WRONG_VALID_CHECKSUM' || classification === 'INCORRECT') {
    return 'text-danger'
  }

  if (classification === 'FALSE_POSITIVE') {
    return 'text-warning'
  }

  return ''
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
})

onBeforeUnmount(() => {
  stopDiagnosticsPolling()
  void stopQuagga2Scanner()
})
</script>

<template>
  <Head title="DEV — Quagga2 Scanner" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix barcode-reliability-phase2">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / EXPÉRIMENTAL</p>
          <h1 class="barcode-reader-test-page__title">Quagga2 — Scanner live</h1>
          <p class="barcode-reader-test-page__subtitle">
            Test caméra Quagga2 indépendant — aucune logique métier MKD-Pro
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link href="/dev/barcode-quagga2-benchmark" class="btn btn-sm btn-outline-secondary">Benchmark</Link>
          <Link href="/dev/barcode/html5-qrcode-benchmark" class="btn btn-sm btn-outline-secondary">Benchmark html5-qrcode</Link>
          <Link href="/dev/barcode-engines-comparison" class="btn btn-sm btn-outline-secondary">Comparaison</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Phase 1</Link>
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-secondary">Phase 2</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">
          Quagga2 (@ericblade/quagga2) — page DEV isolée, résultats non applicables à la production.
        </p>
        <p class="mb-1 barcode-decode-reliability-matrix__muted">
          Navigateur : {{ environment.browserLabel }} · Contexte sécurisé : {{ environment.secureContext ? 'oui' : 'non' }}
        </p>
        <p class="mb-0 barcode-decode-reliability-matrix__warning">
          EXPERIMENTAL — NOT PRODUCTION
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Code-barres attendu</h2>
        <div class="barcode-decode-reliability-matrix__grid-form">
          <div>
            <label>Valeur</label>
            <input v-model="expectedBarcode" type="text" class="form-control form-control-sm font-monospace" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
          </div>
          <div>
            <label>Format</label>
            <input v-model="expectedFormat" type="text" class="form-control form-control-sm font-monospace" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
          </div>
        </div>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Paramètres Quagga2</h2>
        <div class="barcode-decode-reliability-matrix__grid-form">
          <div>
            <label>Largeur</label>
            <input v-model.number="devConfig.width" type="number" min="320" max="3840" step="1" class="form-control form-control-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
          </div>
          <div>
            <label>Hauteur</label>
            <input v-model.number="devConfig.height" type="number" min="240" max="2160" step="1" class="form-control form-control-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
          </div>
          <div>
            <label>Patch size</label>
            <select v-model="devConfig.patchSize" class="form-select form-select-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
              <option v-for="option in patchSizeOptions" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>
          <div>
            <label>Half sample</label>
            <select v-model="devConfig.halfSample" class="form-select form-select-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
              <option :value="true">Oui</option>
              <option :value="false">Non</option>
            </select>
          </div>
          <div>
            <label>Locate</label>
            <select v-model="devConfig.locate" class="form-select form-select-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
              <option :value="true">Oui</option>
              <option :value="false">Non</option>
            </select>
          </div>
          <div>
            <label>Fréquence (Hz)</label>
            <input v-model.number="devConfig.frequency" type="number" min="1" max="30" step="1" class="form-control form-control-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
          </div>
          <div>
            <label>Workers</label>
            <input v-model.number="devConfig.numOfWorkers" type="number" min="0" max="8" step="1" class="form-control form-control-sm" :disabled="scannerState === 'RUNNING' || scannerState === 'STARTING'">
          </div>
        </div>
      </section>

      <section class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__section--video">
        <h2 class="barcode-decode-reliability-matrix__section-title">Scanner live</h2>
        <div
          id="quagga2-scanner"
          ref="scannerRef"
          class="barcode-decode-reliability-matrix__video-wrap barcode-quagga2-scanner"
        />

        <div class="barcode-decode-reliability-matrix__actions mt-3">
          <button type="button" class="btn btn-primary btn-sm" :disabled="!canStart" @click="startScanner">Démarrer</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="scannerState !== 'RUNNING'" @click="stopScanner">Arrêter</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="scannerState === 'STARTING'" @click="resetScanner">Réinitialiser</button>
        </div>

        <p v-if="scannerError" class="barcode-decode-reliability-matrix__warning mb-0 mt-2">{{ scannerError }}</p>

        <dl class="barcode-decode-reliability-matrix__grid mt-3">
          <div><dt>État caméra</dt><dd>{{ scannerState }}</dd></div>
          <div><dt>Résolution</dt><dd>{{ resolutionLabel }}</dd></div>
          <div><dt>FPS</dt><dd>{{ fpsLabel }}</dd></div>
          <div><dt>Dernière valeur</dt><dd class="font-monospace">{{ lastResult?.rawValue ?? '—' }}</dd></div>
          <div><dt>Format</dt><dd>{{ lastResult?.format ?? '—' }}</dd></div>
          <div><dt>Classification</dt><dd :class="classificationClass(lastClassification)">{{ lastClassification }}</dd></div>
          <div><dt>Width ratio</dt><dd>{{ lastResult?.widthRatio != null ? `${(lastResult.widthRatio * 100).toFixed(1)}%` : '—' }}</dd></div>
          <div><dt>Total détections</dt><dd>{{ stats.totalDetections }}</dd></div>
          <div><dt>Correctes</dt><dd class="text-success">{{ stats.correct }}</dd></div>
          <div><dt>Incorrectes</dt><dd class="text-danger">{{ stats.incorrect }}</dd></div>
          <div><dt>Checksum invalide</dt><dd>{{ stats.invalidChecksum }}</dd></div>
          <div><dt>Faux positifs</dt><dd>{{ stats.falsePositive }}</dd></div>
        </dl>
      </section>

      <section v-if="debugDetections.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">DEBUG — {{ MAX_DEBUG_DETECTIONS }} dernières détections</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Heure</th>
                <th>Valeur</th>
                <th>Format</th>
                <th>Normalisé</th>
                <th>Classification</th>
                <th>Width</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in debugDetections" :key="row.id">
                <td>{{ row.timestamp }}</td>
                <td class="font-monospace">{{ row.rawValue }}</td>
                <td>{{ row.format }}</td>
                <td>{{ row.normalizedFormat }}</td>
                <td :class="classificationClass(row.classification)">{{ row.classification }}</td>
                <td>{{ row.widthRatio }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.barcode-quagga2-scanner {
  min-height: 16rem;
  max-height: 70vh;
  overflow: hidden;
  background: #000;
}

.barcode-quagga2-scanner :deep(video),
.barcode-quagga2-scanner :deep(canvas) {
  width: 100%;
  height: auto;
  display: block;
}
</style>
