<script setup lang="ts">
import {
  DURATION_OPTIONS,
  SETTLE_OPTIONS,
} from '@/utils/barcodeDecodeReliabilityExperiment'
import { getEnvironmentDiagnostics } from '@/utils/barcodeDistanceFocusExperiment'
import {
  buildLibraryComparisonTable,
  loadBarcodeDetectorSnapshot,
  loadHtml5QrcodeSnapshot,
  loadQuagga2Snapshot,
  saveEngineSnapshot,
  snapshotFromHtml5QrcodeBenchmark,
  STORAGE_KEY_HTML5_QRCODE,
} from '@/utils/quagga2/engineComparisonStorage'
import {
  buildHtml5QrcodeBenchmarkCsv,
  buildHtml5QrcodeBenchmarkReport,
  DEFAULT_BENCHMARK_DURATION_SECONDS,
  DEFAULT_BENCHMARK_SETTLE_MS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
  finalizeHtml5QrcodeBenchmarkResult,
  formatResolution,
  HTML5_QRCODE_BENCHMARK_CONFIGURATIONS,
  pickRankedConfigurations,
  recordHtml5QrcodeDetection,
  startHtml5QrcodeScanner,
  stopHtml5QrcodeScanner,
  type Html5QrcodeBenchmarkConfiguration,
  type Html5QrcodeBenchmarkDetection,
  type Html5QrcodeBenchmarkResult,
} from '@/utils/html5QrcodeBenchmark'
import { Head, Link } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dashboard } from '@/routes'

type RunUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED'

const scannerRef = ref<HTMLElement | null>(null)
const environment = ref(getEnvironmentDiagnostics())
const runState = ref<RunUiState>('IDLE')
const runError = ref<string | null>(null)
const copyMessage = ref<string | null>(null)
const cameraStatus = ref<'idle' | 'starting' | 'active' | 'error'>('idle')

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const expectedFormat = ref(DEFAULT_EXPECTED_FORMAT)
const durationSeconds = ref(DEFAULT_BENCHMARK_DURATION_SECONDS)
const settleMs = ref(DEFAULT_BENCHMARK_SETTLE_MS)

const results = ref<Html5QrcodeBenchmarkResult[]>([])
const rawDetections = ref<Html5QrcodeBenchmarkDetection[]>([])
const currentConfig = ref<Html5QrcodeBenchmarkConfiguration | null>(null)
const currentConfigIndex = ref(0)
const elapsedSeconds = ref(0)
const remainingSeconds = ref(0)
const liveDetections = ref(0)
const liveCorrect = ref(0)
const liveIncorrect = ref(0)
const liveScore = ref(0)
const benchmarkStartedAt = ref<string | null>(null)
const benchmarkFinishedAt = ref<string | null>(null)

let runSessionId = 0
let runAbort = false
let countdownTimer: number | null = null

const configCount = computed(() => HTML5_QRCODE_BENCHMARK_CONFIGURATIONS.length)

const progressPercent = computed(() =>
  configCount.value > 0 ? Math.round((currentConfigIndex.value / configCount.value) * 100) : 0,
)

const markedResults = computed(() =>
  [...results.value].sort((a, b) => b.score.total - a.score.total),
)

const rankings = computed(() => pickRankedConfigurations(results.value))

const bestResult = computed(() => rankings.value.bestOverall)

const libraryComparison = computed(() =>
  buildLibraryComparisonTable(
    loadBarcodeDetectorSnapshot(),
    loadQuagga2Snapshot(),
    loadHtml5QrcodeSnapshot(),
  ),
)

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

function createEmptyResults(): Html5QrcodeBenchmarkResult[] {
  return HTML5_QRCODE_BENCHMARK_CONFIGURATIONS.map((config) => ({
    configurationId: config.id,
    label: config.label,
    requestedWidth: config.config.requestedWidth,
    requestedHeight: config.config.requestedHeight,
    actualWidth: null,
    actualHeight: null,
    actualFps: null,
    qrBoxRatio: config.config.qrBoxRatio,
    frames: 0,
    detections: 0,
    rawDetectionEvents: 0,
    correct: 0,
    incorrect: 0,
    invalidCheckDigitDetections: 0,
    validButWrongEan13Detections: 0,
    correctRate: '0.0%',
    detectionRate: '0.0%',
    distinctValues: 0,
    mostFrequent: null,
    checkDigitValidDetections: 0,
    checkDigitInvalidDetections: 0,
    correctStability: '0.0%',
    temporalStability: '0.0%',
    falsePositiveRate: '0.0%',
    timeToFirstDetectionMs: null,
    timeToFirstCorrectMs: null,
    timeToFirstStableCorrectMs: null,
    multiFrameLevels: [],
    score: { total: 0, accuracy: 0, stability: 0, detection: 0, falsePositiveResistance: 0, speed: 0 },
    status: 'NO_DETECTION',
    errorMessage: null,
  }))
}

function resetLiveCounters(): void {
  liveDetections.value = 0
  liveCorrect.value = 0
  liveIncorrect.value = 0
  liveScore.value = 0
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
  cameraStatus.value = 'idle'
  resetLiveCounters()
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1
  stopTimers()
  currentConfig.value = null
  void stopHtml5QrcodeScanner()
  cameraStatus.value = 'idle'

  if (runState.value === 'RUNNING') {
    runState.value = 'STOPPED'
  }
}

async function runConfiguration(
  config: Html5QrcodeBenchmarkConfiguration,
  index: number,
  sessionId: number,
): Promise<Html5QrcodeBenchmarkResult> {
  const target = scannerRef.value

  if (!target || runAbort || sessionId !== runSessionId) {
    return finalizeHtml5QrcodeBenchmarkResult({
      configuration: config,
      camera: {
        requestedWidth: config.config.requestedWidth,
        requestedHeight: config.config.requestedHeight,
        actualWidth: null,
        actualHeight: null,
        actualFps: null,
        facingMode: config.config.facingMode,
        qrBoxRatio: config.config.qrBoxRatio,
        html5Config: config.config,
      },
      frames: 0,
      allDetections: [],
      expectedBarcode: expectedBarcode.value,
      durationMs: durationSeconds.value * 1000,
      errorMessage: 'Scanner indisponible',
    })
  }

  currentConfig.value = config
  currentConfigIndex.value = index + 1
  resetLiveCounters()

  const configDetections: Html5QrcodeBenchmarkDetection[] = []
  let lastCounted: Html5QrcodeBenchmarkDetection | null = null
  let frames = 0
  let measuring = false
  let measureStart = 0

  try {
    cameraStatus.value = 'starting'
    let cameraSnapshot = {
      requestedWidth: config.config.requestedWidth,
      requestedHeight: config.config.requestedHeight,
      actualWidth: null as number | null,
      actualHeight: null as number | null,
      actualFps: null as number | null,
      facingMode: config.config.facingMode,
      qrBoxRatio: config.config.qrBoxRatio,
      html5Config: config.config,
    }

    const session = await startHtml5QrcodeScanner({
      target,
      config: config.config,
      onDetected: (payload) => {
        if (!measuring || runAbort || sessionId !== runSessionId) {
          return
        }

        const elapsedMs = Math.round(performance.now() - measureStart)
        const entry = recordHtml5QrcodeDetection({
          configurationId: config.id,
          configurationLabel: config.label,
          elapsedMs,
          payload,
          config: config.config,
          camera: cameraSnapshot,
          expectedBarcode: expectedBarcode.value,
          expectedFormat: expectedFormat.value,
          previousCounted: lastCounted,
        })

        configDetections.push(entry)
        rawDetections.value = [entry, ...rawDetections.value]

        if (entry.countedForMetrics) {
          lastCounted = entry
          liveDetections.value += 1

          if (entry.classification === 'CORRECT') {
            liveCorrect.value += 1
          } else {
            liveIncorrect.value += 1
          }
        }
      },
    })

    cameraSnapshot = session.camera
    cameraStatus.value = 'active'

    measuring = false
    await new Promise((resolve) => window.setTimeout(resolve, settleMs.value))

    if (runAbort || sessionId !== runSessionId) {
      await session.stop()
      cameraStatus.value = 'idle'
      return finalizeHtml5QrcodeBenchmarkResult({
        configuration: config,
        camera: session.camera,
        frames: 0,
        allDetections: [],
        expectedBarcode: expectedBarcode.value,
        durationMs: durationSeconds.value * 1000,
      })
    }

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
    cameraStatus.value = 'idle'

    const result = finalizeHtml5QrcodeBenchmarkResult({
      configuration: config,
      camera: session.camera,
      frames,
      allDetections: configDetections,
      expectedBarcode: expectedBarcode.value,
      durationMs: durationSeconds.value * 1000,
    })

    liveScore.value = result.score.total

    return result
  } catch (error) {
    await stopHtml5QrcodeScanner()
    cameraStatus.value = 'error'

    return finalizeHtml5QrcodeBenchmarkResult({
      configuration: config,
      camera: {
        requestedWidth: config.config.requestedWidth,
        requestedHeight: config.config.requestedHeight,
        actualWidth: null,
        actualHeight: null,
        actualFps: null,
        facingMode: config.config.facingMode,
        qrBoxRatio: config.config.qrBoxRatio,
        html5Config: config.config,
      },
      frames,
      allDetections: configDetections,
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
    `Lancer ${configCount.value} configurations html5-qrcode (~${minutes} min) ?`,
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

  for (let index = 0; index < HTML5_QRCODE_BENCHMARK_CONFIGURATIONS.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = HTML5_QRCODE_BENCHMARK_CONFIGURATIONS[index]!
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
  cameraStatus.value = 'idle'

  const best = bestResult.value

  if (best) {
    saveEngineSnapshot(STORAGE_KEY_HTML5_QRCODE, snapshotFromHtml5QrcodeBenchmark({
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
      experiment: 'HTML5_QRCODE_BENCHMARK',
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
    configurations: HTML5_QRCODE_BENCHMARK_CONFIGURATIONS,
    results: markedResults.value,
    rawDetections: rawDetections.value,
    bestConfiguration: bestResult.value,
    libraryComparison: libraryComparison.value,
  }
}

function buildFullReport(): string {
  return buildHtml5QrcodeBenchmarkReport({
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
    `html5-qrcode-benchmark-${Date.now()}.json`,
    'application/json',
  )
  copyMessage.value = 'JSON exporté.'
}

function exportReport(): void {
  downloadBlob(
    buildFullReport(),
    `html5-qrcode-benchmark-report-${Date.now()}.txt`,
    'text/plain',
  )
  copyMessage.value = 'Rapport TXT exporté.'
}

function statusBadgeClass(status: string): string {
  if (status === 'REPEATABLE_CORRECT' || status === 'CORRECT_ONCE') {
    return 'text-success'
  }

  if (status === 'CONFIGURATION_ERROR' || status === 'INCORRECT_DECODING' || status === 'STABLE_INCORRECT') {
    return 'text-danger'
  }

  if (status === 'UNSTABLE_DECODING') {
    return 'text-warning'
  }

  return ''
}

function detectionLabel(item: Html5QrcodeBenchmarkDetection): string {
  return item.classification === 'CORRECT' ? 'CORRECT' : 'INCORRECT'
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
  void stopHtml5QrcodeScanner()
})
</script>

<template>
  <Head title="DEV — html5-qrcode Benchmark" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix barcode-reliability-phase2">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / EXPÉRIMENTAL</p>
          <h1 class="barcode-reader-test-page__title">HTML5-QRCODE BENCHMARK</h1>
          <p class="barcode-reader-test-page__subtitle">
            {{ configCount }} configurations séquentielles — comparaison objective
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link href="/dev/barcode-quagga2-benchmark" class="btn btn-sm btn-outline-secondary">Benchmark Quagga2</Link>
          <Link href="/dev/barcode-engines-comparison" class="btn btn-sm btn-outline-secondary">Comparaison</Link>
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-secondary">Phase 2</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">Benchmark html5-qrcode isolé — settle puis mesure par configuration.</p>
        <p class="mb-1 barcode-decode-reliability-matrix__muted">
          Résolution demandée ≠ résolution réelle — seule la résolution mesurée est affichée comme « actual ».
        </p>
        <p class="mb-0 barcode-decode-reliability-matrix__warning">
          EXPERIMENTAL ONLY — NOT PRODUCTION
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Paramètres</h2>
        <dl class="barcode-decode-reliability-matrix__grid mb-3">
          <div><dt>Expected barcode</dt><dd class="font-monospace">{{ expectedBarcode }}</dd></div>
          <div><dt>Expected format</dt><dd class="font-monospace">{{ expectedFormat }}</dd></div>
          <div><dt>Duration</dt><dd>{{ durationSeconds }} s / config</dd></div>
          <div><dt>Configurations</dt><dd>{{ configCount }}</dd></div>
          <div><dt>Camera status</dt><dd>{{ cameraStatus }}</dd></div>
        </dl>

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
          <li v-for="config in HTML5_QRCODE_BENCHMARK_CONFIGURATIONS" :key="config.id">
            {{ config.id }} — {{ config.label }}
          </li>
        </ol>
      </section>

      <section class="barcode-decode-reliability-matrix__section barcode-decode-reliability-matrix__section--video">
        <h2 class="barcode-decode-reliability-matrix__section-title">Scanner benchmark</h2>
        <div
          id="html5-qrcode-benchmark-scanner"
          ref="scannerRef"
          class="barcode-decode-reliability-matrix__video-wrap html5-qrcode-benchmark-scanner"
        />

        <div v-if="currentConfig && runState === 'RUNNING'" class="barcode-decode-reliability-matrix__live-panel mt-2">
          <div>{{ currentConfig.label }}</div>
          <div>Config {{ currentConfigIndex }} / {{ configCount }} · {{ elapsedSeconds }} s · reste {{ remainingSeconds }} s</div>
          <div>Det {{ liveDetections }} · Correct {{ liveCorrect }} · Incorrect {{ liveIncorrect }} · Score {{ liveScore }}</div>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-3">
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStart" @click="startBenchmark">START BENCHMARK</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING'" @click="stopRun">STOP</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING'" @click="resetBenchmark">RESET</button>
        </div>

        <div class="barcode-decode-reliability-matrix__actions mt-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">COPY REPORT</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportJson">EXPORT JSON</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportReport">EXPORT TXT</button>
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

      <section v-if="runState === 'COMPLETED' && bestResult" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Classement</h2>
        <ul class="mb-0">
          <li v-if="rankings.bestOverall">🥇 Best configuration : {{ rankings.bestOverall.label }} (score {{ rankings.bestOverall.score.total }})</li>
          <li v-if="rankings.secondOverall">🥈 Second : {{ rankings.secondOverall.label }} (score {{ rankings.secondOverall.score.total }})</li>
          <li v-if="rankings.thirdOverall">🥉 Third : {{ rankings.thirdOverall.label }} (score {{ rankings.thirdOverall.score.total }})</li>
          <li v-if="rankings.bestAccuracy">Best accuracy : {{ rankings.bestAccuracy.label }} ({{ rankings.bestAccuracy.score.accuracy }})</li>
          <li v-if="rankings.bestStability">Best stability : {{ rankings.bestStability.label }} ({{ rankings.bestStability.temporalStability }})</li>
          <li v-if="rankings.bestDetectionRate">Best detection rate : {{ rankings.bestDetectionRate.label }} ({{ rankings.bestDetectionRate.detectionRate }})</li>
          <li v-if="rankings.fastestCorrectRead">Fastest correct read : {{ rankings.fastestCorrectRead.label }} ({{ rankings.fastestCorrectRead.timeToFirstCorrectMs }} ms)</li>
        </ul>
      </section>

      <section v-if="results.some((item) => item.frames > 0 || item.errorMessage)" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Résultats comparatifs</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Configuration</th>
                <th>Actual resolution</th>
                <th>Detections</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Detection rate</th>
                <th>Correct rate</th>
                <th>Stability</th>
                <th>Check-digit valid</th>
                <th>First correct</th>
                <th>Score</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in markedResults" :key="item.configurationId">
                <td>{{ item.label }}</td>
                <td>{{ formatResolution(item.actualWidth, item.actualHeight) }}</td>
                <td>{{ item.detections }}</td>
                <td>{{ item.correct }}</td>
                <td>{{ item.incorrect }}</td>
                <td>{{ item.detectionRate }}</td>
                <td>{{ item.correctRate }}</td>
                <td>{{ item.temporalStability }}</td>
                <td>{{ item.checkDigitValidDetections }}</td>
                <td>{{ item.timeToFirstCorrectMs ?? '—' }}</td>
                <td>{{ item.score.total }}</td>
                <td :class="statusBadgeClass(item.status)">{{ item.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">LIBRARY COMPARISON</h2>
        <p class="barcode-decode-reliability-matrix__muted">
          Données lues depuis localStorage — uniquement les benchmarks réellement exécutés.
        </p>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Library</th>
                <th>Detections</th>
                <th>Correct</th>
                <th>Accuracy</th>
                <th>Stability</th>
                <th>Score</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in libraryComparison" :key="row.library">
                <td>{{ row.library }}</td>
                <td>{{ row.detections }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.accuracy }}</td>
                <td>{{ row.stability }}</td>
                <td>{{ row.score }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">RAW DETECTIONS</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Configuration</th>
                <th>rawValue</th>
                <th>Format</th>
                <th>Result</th>
                <th>Check digit</th>
                <th>Actual resolution</th>
                <th>Elapsed</th>
                <th>Counted</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in rawDetections.slice(0, 200)" :key="item.id">
                <td>{{ item.timestamp }}</td>
                <td>{{ item.configurationLabel }}</td>
                <td class="font-monospace">{{ item.rawValue }}</td>
                <td>{{ item.normalizedFormat }}</td>
                <td :class="statusBadgeClass(item.classification === 'CORRECT' ? 'CORRECT_ONCE' : 'INCORRECT_DECODING')">
                  {{ detectionLabel(item) }}
                </td>
                <td>{{ item.checkDigitValid ? 'VALID' : 'INVALID' }}</td>
                <td>{{ formatResolution(item.actualWidth, item.actualHeight) }}</td>
                <td>{{ (item.elapsedMs / 1000).toFixed(1) }}s</td>
                <td>{{ item.countedForMetrics ? 'yes' : 'raw' }}</td>
              </tr>
            </tbody>
          </table>
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
.html5-qrcode-benchmark-scanner {
  min-height: 16rem;
  max-height: 50vh;
  overflow: hidden;
  background: #000;
}

.html5-qrcode-benchmark-scanner :deep(video),
.html5-qrcode-benchmark-scanner :deep(canvas) {
  width: 100%;
  height: auto;
  display: block;
}
</style>
