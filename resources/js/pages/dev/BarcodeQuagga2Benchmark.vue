<script setup lang="ts">
import {
  DURATION_OPTIONS,
  SETTLE_OPTIONS,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import { getEnvironmentDiagnostics } from '@/utils/barcodeDistanceFocusExperiment'
import {
  saveEngineSnapshot,
  snapshotFromQuagga2Benchmark,
  STORAGE_KEY_QUAGGA2,
} from '@/utils/quagga2/engineComparisonStorage'
import {
  buildQuagga2BenchmarkCsv,
  buildQuagga2BenchmarkReport,
  DEFAULT_BENCHMARK_DURATION_SECONDS,
  DEFAULT_BENCHMARK_SETTLE_MS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
  finalizeQuagga2BenchmarkResult,
  QUAGGA2_BENCHMARK_CONFIGURATIONS,
  recordQuagga2Detection,
  type Quagga2BenchmarkConfiguration,
  type Quagga2BenchmarkDetection,
  type Quagga2BenchmarkResult,
} from '@/utils/quagga2/quagga2Benchmark'
import { startQuagga2Scanner, stopQuagga2Scanner } from '@/utils/quagga2/quagga2Scanner'
import { Head, Link } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dashboard } from '@/routes'

type RunUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED'

const scannerRef = ref<HTMLElement | null>(null)
const environment = ref(getEnvironmentDiagnostics())
const runState = ref<RunUiState>('IDLE')
const runError = ref<string | null>(null)
const copyMessage = ref<string | null>(null)

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const expectedFormat = ref(DEFAULT_EXPECTED_FORMAT)
const durationSeconds = ref(DEFAULT_BENCHMARK_DURATION_SECONDS)
const settleMs = ref(DEFAULT_BENCHMARK_SETTLE_MS)

const results = ref<Quagga2BenchmarkResult[]>([])
const rawDetections = ref<Quagga2BenchmarkDetection[]>([])
const currentConfig = ref<Quagga2BenchmarkConfiguration | null>(null)
const currentConfigIndex = ref(0)
const elapsedSeconds = ref(0)
const remainingSeconds = ref(0)
const benchmarkStartedAt = ref<string | null>(null)
const benchmarkFinishedAt = ref<string | null>(null)

let runSessionId = 0
let runAbort = false
let countdownTimer: number | null = null

const configCount = computed(() => QUAGGA2_BENCHMARK_CONFIGURATIONS.length)

const progressPercent = computed(() =>
  configCount.value > 0 ? Math.round((currentConfigIndex.value / configCount.value) * 100) : 0,
)

const markedResults = computed(() =>
  [...results.value].sort((a, b) => b.score.total - a.score.total),
)

const bestResult = computed(() => markedResults.value[0] ?? null)

const canStart = computed(() =>
  runState.value !== 'RUNNING'
  && scannerRef.value != null
  && expectedBarcode.value.trim().length > 0,
)

function stopTimers(): void {
  if (countdownTimer !== null) {
    window.clearInterval(countdownTimer)
    countdownTimer = null
  }
}

function createEmptyResults(): Quagga2BenchmarkResult[] {
  return QUAGGA2_BENCHMARK_CONFIGURATIONS.map((config) => ({
    configurationId: config.id,
    label: config.label,
    requestedWidth: config.config.width,
    requestedHeight: config.config.height,
    actualWidth: null,
    actualHeight: null,
    actualFps: null,
    frames: 0,
    detections: 0,
    correct: 0,
    incorrect: 0,
    correctRate: '0.0%',
    detectionRate: '0.0%',
    distinctValues: 0,
    mostFrequent: null,
    checkDigitValidDetections: 0,
    checkDigitInvalidDetections: 0,
    correctStability: '0.0%',
    temporalStability: '0.0%',
    falsePositiveRate: '0.0%',
    averageWidthRatio: null,
    timeToFirstDetectionMs: null,
    timeToFirstCorrectMs: null,
    multiFrameLevels: [],
    score: { total: 0, accuracy: 0, stability: 0, detection: 0, falsePositiveResistance: 0, speed: 0 },
    status: 'NO_DETECTION',
    errorMessage: null,
  }))
}

function resetBenchmark(): void {
  stopRun()
  results.value = createEmptyResults()
  rawDetections.value = []
  currentConfig.value = null
  currentConfigIndex.value = 0
  benchmarkStartedAt.value = null
  benchmarkFinishedAt.value = null
  runError.value = null
  copyMessage.value = null
}

function clearResults(): void {
  stopRun()
  results.value = createEmptyResults()
  rawDetections.value = []
  benchmarkStartedAt.value = null
  benchmarkFinishedAt.value = null
  copyMessage.value = null
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1
  stopTimers()
  currentConfig.value = null
  void stopQuagga2Scanner()

  if (runState.value === 'RUNNING') {
    runState.value = 'STOPPED'
  }
}

async function runConfiguration(
  config: Quagga2BenchmarkConfiguration,
  index: number,
  sessionId: number,
): Promise<Quagga2BenchmarkResult> {
  const target = scannerRef.value

  if (!target || runAbort || sessionId !== runSessionId) {
    return finalizeQuagga2BenchmarkResult({
      configuration: config,
      camera: {
        requestedWidth: config.config.width,
        requestedHeight: config.config.height,
        actualWidth: null,
        actualHeight: null,
        actualFps: null,
        facingMode: 'environment',
      },
      frames: 0,
      detections: [],
      expectedBarcode: expectedBarcode.value,
      durationMs: durationSeconds.value * 1000,
      errorMessage: 'Scanner indisponible',
    })
  }

  currentConfig.value = config
  currentConfigIndex.value = index + 1

  const configDetections: Quagga2BenchmarkDetection[] = []
  let frames = 0
  let measuring = false
  let measureStart = 0

  try {
    const session = await startQuagga2Scanner({
      target,
      config: config.config,
      onDetected: (payload) => {
        if (!measuring || runAbort || sessionId !== runSessionId) {
          return
        }

        const elapsedMs = Math.round(performance.now() - measureStart)
        const entry = recordQuagga2Detection({
          configurationId: config.id,
          elapsedMs,
          payload,
          config: config.config,
          expectedBarcode: expectedBarcode.value,
          expectedFormat: expectedFormat.value,
        })

        configDetections.push(entry)
        rawDetections.value = [entry, ...rawDetections.value]
      },
    })

    // Période de stabilisation — détections ignorées
    measuring = false
    await new Promise((resolve) => window.setTimeout(resolve, settleMs.value))

    if (runAbort || sessionId !== runSessionId) {
      await session.stop()
      return finalizeQuagga2BenchmarkResult({
        configuration: config,
        camera: session.camera,
        frames: 0,
        detections: [],
        expectedBarcode: expectedBarcode.value,
        durationMs: durationSeconds.value * 1000,
      })
    }

    // Période de mesure
    measuring = true
    measureStart = performance.now()
    const endAt = measureStart + durationSeconds.value * 1000
    remainingSeconds.value = durationSeconds.value
    elapsedSeconds.value = 0

    stopTimers()
    countdownTimer = window.setInterval(() => {
      const elapsed = performance.now() - measureStart
      elapsedSeconds.value = Math.floor(elapsed / 1000)
      remainingSeconds.value = Math.max(0, Math.ceil((endAt - performance.now()) / 1000))
    }, 200)

    await new Promise<void>((resolve) => {
      const tick = (): void => {
        if (runAbort || sessionId !== runSessionId || performance.now() >= endAt) {
          resolve()
          return
        }

        frames += 1
        window.requestAnimationFrame(tick)
      }

      window.requestAnimationFrame(tick)
    })

    stopTimers()
    await session.stop()

    return finalizeQuagga2BenchmarkResult({
      configuration: config,
      camera: session.camera,
      frames,
      detections: configDetections,
      expectedBarcode: expectedBarcode.value,
      durationMs: durationSeconds.value * 1000,
    })
  } catch (error) {
    await stopQuagga2Scanner()

    return finalizeQuagga2BenchmarkResult({
      configuration: config,
      camera: {
        requestedWidth: config.config.width,
        requestedHeight: config.config.height,
        actualWidth: null,
        actualHeight: null,
        actualFps: null,
        facingMode: 'environment',
      },
      frames,
      detections: configDetections,
      expectedBarcode: expectedBarcode.value,
      durationMs: durationSeconds.value * 1000,
      errorMessage: error instanceof Error ? error.message : 'Configuration failed',
    })
  }
}

async function startBenchmark(): Promise<void> {
  if (!canStart.value) {
    return
  }

  const minutes = Math.ceil((configCount.value * (durationSeconds.value + settleMs.value / 1000)) / 60)
  const confirmed = window.confirm(
    `Lancer ${configCount.value} configurations Quagga2 (~${minutes} min) ?`,
  )

  if (!confirmed) {
    return
  }

  runAbort = false
  runSessionId += 1
  const sessionId = runSessionId
  runState.value = 'RUNNING'
  runError.value = null
  copyMessage.value = null
  benchmarkStartedAt.value = new Date().toISOString()
  benchmarkFinishedAt.value = null
  rawDetections.value = []
  results.value = createEmptyResults()
  currentConfigIndex.value = 0

  for (let index = 0; index < QUAGGA2_BENCHMARK_CONFIGURATIONS.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = QUAGGA2_BENCHMARK_CONFIGURATIONS[index]!
    const result = await runConfiguration(config, index, sessionId)
    results.value = results.value.map((item) =>
      item.configurationId === config.id ? result : item,
    )
  }

  benchmarkFinishedAt.value = new Date().toISOString()

  if (runAbort || sessionId !== runSessionId) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
  currentConfig.value = null

  const best = bestResult.value

  if (best && best.score.total > 0) {
    saveEngineSnapshot(STORAGE_KEY_QUAGGA2, snapshotFromQuagga2Benchmark({
      environmentBrowser: environment.value.browserLabel,
      expectedBarcode: expectedBarcode.value,
      expectedFormat: expectedFormat.value,
      durationSeconds: durationSeconds.value,
      bestResult: best,
    }))
    copyMessage.value = `Meilleure config enregistrée (${best.label}, score ${best.score.total}).`
  }
}

function buildReportPayload(): Record<string, unknown> {
  return {
    metadata: {
      exportedAt: new Date().toISOString(),
      experiment: 'QUAGGA2_BENCHMARK',
      benchmarkStartedAt: benchmarkStartedAt.value,
      benchmarkFinishedAt: benchmarkFinishedAt.value,
    },
    environment: environment.value,
    testParameters: {
      expectedBarcode: expectedBarcode.value,
      expectedFormat: expectedFormat.value,
      durationSeconds: durationSeconds.value,
      settleMs: settleMs.value,
    },
    configurations: QUAGGA2_BENCHMARK_CONFIGURATIONS,
    results: markedResults.value,
    rawDetections: rawDetections.value,
    bestConfiguration: bestResult.value,
  }
}

function buildFullReport(): string {
  return buildQuagga2BenchmarkReport({
    expectedBarcode: expectedBarcode.value,
    expectedFormat: expectedFormat.value,
    durationSeconds: durationSeconds.value,
    settleMs: settleMs.value,
    results: results.value,
    detections: rawDetections.value,
  })
}

function downloadBlob(content: string, filename: string, mime: string): void {
  const blob = new Blob([content], { type: mime })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

async function copyReport(): Promise<void> {
  try {
    await navigator.clipboard.writeText(buildFullReport())
    copyMessage.value = 'Rapport copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function exportJson(): void {
  downloadBlob(
    JSON.stringify(buildReportPayload(), null, 2),
    `quagga2-benchmark-${Date.now()}.json`,
    'application/json',
  )
  copyMessage.value = 'JSON exporté.'
}

function exportCsv(): void {
  downloadBlob(
    buildQuagga2BenchmarkCsv(results.value),
    `quagga2-benchmark-${Date.now()}.csv`,
    'text/csv',
  )
  copyMessage.value = 'CSV exporté.'
}

function exportReport(): void {
  downloadBlob(
    buildFullReport(),
    `quagga2-benchmark-report-${Date.now()}.txt`,
    'text/plain',
  )
  copyMessage.value = 'Rapport exporté.'
}

function statusBadgeClass(status: string): string {
  if (status === 'REPEATABLE_CORRECT' || status === 'CORRECT_ONCE') {
    return 'text-success'
  }

  if (status === 'CONFIGURATION_ERROR' || status === 'INCORRECT_DECODING') {
    return 'text-danger'
  }

  return ''
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopRun()
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  results.value = createEmptyResults()
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  stopTimers()
  void stopQuagga2Scanner()
})
</script>

<template>
  <Head title="DEV — Quagga2 Benchmark" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix barcode-reliability-phase2">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / EXPÉRIMENTAL</p>
          <h1 class="barcode-reader-test-page__title">Quagga2 — Benchmark automatisé</h1>
          <p class="barcode-reader-test-page__subtitle">
            {{ configCount }} configurations séquentielles — score expérimental
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link href="/dev/barcode-quagga2" class="btn btn-sm btn-outline-secondary">Scanner live</Link>
          <Link href="/dev/barcode/html5-qrcode-benchmark" class="btn btn-sm btn-outline-secondary">Benchmark html5-qrcode</Link>
          <Link href="/dev/barcode-engines-comparison" class="btn btn-sm btn-outline-secondary">Comparaison</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Phase 1</Link>
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-secondary">Phase 2</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">Benchmark Quagga2 — settle puis mesure par configuration.</p>
        <p class="mb-1 barcode-decode-reliability-matrix__muted">
          Le meilleur résultat est enregistré dans localStorage pour la comparaison moteurs.
        </p>
        <p class="mb-0 barcode-decode-reliability-matrix__warning">
          EXPERIMENTAL ONLY — NOT PRODUCTION
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Configuration</h2>
        <div class="barcode-decode-reliability-matrix__grid-form">
          <div>
            <label>Code-barres attendu</label>
            <input v-model="expectedBarcode" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          </div>
          <div>
            <label>Format</label>
            <input v-model="expectedFormat" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          </div>
          <div>
            <label>Durée (s)</label>
            <select v-model.number="durationSeconds" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in DURATION_OPTIONS" :key="option" :value="option">{{ option }} s</option>
            </select>
          </div>
          <div>
            <label>Settle (ms)</label>
            <select v-model.number="settleMs" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in SETTLE_OPTIONS" :key="option" :value="option">{{ option }} ms</option>
            </select>
          </div>
        </div>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Configurations ({{ configCount }})</h2>
        <ol class="mb-0 ps-3 barcode-decode-reliability-matrix__muted">
          <li v-for="config in QUAGGA2_BENCHMARK_CONFIGURATIONS" :key="config.id">
            {{ config.id }} — {{ config.label }}
          </li>
        </ol>
      </section>

      <section class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__section--video">
        <h2 class="barcode-decode-reliability-matrix__section-title">Scanner benchmark</h2>
        <div
          id="quagga2-scanner"
          ref="scannerRef"
          class="barcode-decode-reliability-matrix__video-wrap barcode-quagga2-scanner"
        />

        <div v-if="currentConfig && runState === 'RUNNING'" class="barcode-decode-reliability-matrix__live-panel mt-2">
          <div>{{ currentConfig.label }}</div>
          <div>Config {{ currentConfigIndex }} / {{ configCount }} · {{ elapsedSeconds }} s · reste {{ remainingSeconds }} s</div>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-3">
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStart" @click="startBenchmark">Démarrer</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING'" @click="stopRun">Arrêter</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING'" @click="resetBenchmark">Réinitialiser</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING'" @click="clearResults">Effacer résultats</button>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Copier rapport</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportJson">Export JSON</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportCsv">Export CSV</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportReport">Export rapport</button>
        </div>

        <p v-if="copyMessage" class="barcode-decode-reliability-matrix__muted mb-0 mt-2">{{ copyMessage }}</p>
        <p v-if="runError" class="barcode-decode-reliability-matrix__warning mb-0 mt-2">{{ runError }}</p>

        <div v-if="runState === 'RUNNING' || runState === 'COMPLETED'" class="mt-3">
          <div class="progress mb-2" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" :style="{ width: `${progressPercent}%` }">{{ progressPercent }}%</div>
          </div>
          <p class="mb-0 barcode-decode-reliability-matrix__muted">{{ currentConfigIndex }} / {{ configCount }} · {{ runState }}</p>
        </div>
      </section>

      <section v-if="bestResult && runState === 'COMPLETED'" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Meilleure configuration</h2>
        <div class="barcode-decode-reliability-matrix__result-card">
          <div class="barcode-decode-reliability-matrix__result-title">{{ bestResult.label }}</div>
          <p class="mb-1 barcode-decode-reliability-matrix__muted">
            Score {{ bestResult.score.total }} · Correct {{ bestResult.correctRate }} · Détection {{ bestResult.detectionRate }}
          </p>
          <dl class="barcode-decode-reliability-matrix__grid mb-0">
            <div><dt>Précision</dt><dd>{{ bestResult.score.accuracy }}</dd></div>
            <div><dt>Stabilité</dt><dd>{{ bestResult.score.stability }}</dd></div>
            <div><dt>Détection</dt><dd>{{ bestResult.score.detection }}</dd></div>
            <div><dt>Anti-FP</dt><dd>{{ bestResult.score.falsePositiveResistance }}</dd></div>
            <div><dt>Vitesse</dt><dd>{{ bestResult.score.speed }}</dd></div>
            <div><dt>Status</dt><dd :class="statusBadgeClass(bestResult.status)">{{ bestResult.status }}</dd></div>
          </dl>
        </div>
      </section>

      <section v-if="results.some((item) => item.frames > 0 || item.errorMessage)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Résultats</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Config</th>
                <th>Frames</th>
                <th>Det</th>
                <th>Correct</th>
                <th>Correct %</th>
                <th>Det %</th>
                <th>FP %</th>
                <th>Stabilité</th>
                <th>1ère correcte</th>
                <th>Score</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in markedResults" :key="item.configurationId">
                <td>{{ item.configurationId }} — {{ item.label }}</td>
                <td>{{ item.frames }}</td>
                <td>{{ item.detections }}</td>
                <td>{{ item.correct }}</td>
                <td>{{ item.correctRate }}</td>
                <td>{{ item.detectionRate }}</td>
                <td>{{ item.falsePositiveRate }}</td>
                <td>{{ item.temporalStability }}</td>
                <td>{{ item.timeToFirstCorrectMs ?? '—' }}</td>
                <td>{{ item.score.total }}</td>
                <td :class="statusBadgeClass(item.status)">{{ item.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="results.some((item) => item.score.total > 0)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Détail scores (meilleure config)</h2>
        <div v-if="bestResult" class="barcode-decode-reliability-matrix__chart">
          <div class="barcode-decode-reliability-matrix__chart-row">
            <span>Précision (40%)</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${bestResult.score.accuracy}%` }" />
            </div>
            <span>{{ bestResult.score.accuracy }}</span>
          </div>
          <div class="barcode-decode-reliability-matrix__chart-row">
            <span>Stabilité (20%)</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${bestResult.score.stability}%` }" />
            </div>
            <span>{{ bestResult.score.stability }}</span>
          </div>
          <div class="barcode-decode-reliability-matrix__chart-row">
            <span>Détection (15%)</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${bestResult.score.detection}%` }" />
            </div>
            <span>{{ bestResult.score.detection }}</span>
          </div>
          <div class="barcode-decode-reliability-matrix__chart-row">
            <span>Anti-FP (15%)</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${bestResult.score.falsePositiveResistance}%` }" />
            </div>
            <span>{{ bestResult.score.falsePositiveResistance }}</span>
          </div>
          <div class="barcode-decode-reliability-matrix__chart-row">
            <span>Vitesse (10%)</span>
            <div class="barcode-decode-reliability-matrix__chart-bar-wrap">
              <div class="barcode-decode-reliability-matrix__chart-bar" :style="{ width: `${bestResult.score.speed}%` }" />
            </div>
            <span>{{ bestResult.score.speed }}</span>
          </div>
        </div>
      </section>

      <section v-if="runState === 'COMPLETED'" class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__conclusion">
        <h2 class="barcode-decode-reliability-matrix__section-title">Rapport</h2>
        <pre class="barcode-decode-reliability-matrix__pre mb-0">{{ buildFullReport() }}</pre>
      </section>
    </div>
  </div>
</template>

<style scoped>
.barcode-quagga2-scanner {
  min-height: 16rem;
  max-height: 50vh;
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
