<script setup lang="ts">
import { getEnvironmentDiagnostics } from '@/utils/barcodeDistanceFocusExperiment'
import {
  buildComparisonRows,
  clearEngineSnapshots,
  loadBarcodeDetectorSnapshot,
  loadQuagga2Snapshot,
  saveEngineSnapshot,
  STORAGE_KEY_BARCODE_DETECTOR,
  type EngineComparisonRow,
  type EngineComparisonSnapshot,
} from '@/utils/quagga2/engineComparisonStorage'
import { Head, Link } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import { dashboard } from '@/routes'

const environment = ref(getEnvironmentDiagnostics())
const barcodeDetectorSnapshot = ref<EngineComparisonSnapshot | null>(null)
const quagga2Snapshot = ref<EngineComparisonSnapshot | null>(null)
const importText = ref('')
const importError = ref<string | null>(null)
const actionMessage = ref<string | null>(null)

const comparisonRows = computed<EngineComparisonRow[]>(() =>
  buildComparisonRows(barcodeDetectorSnapshot.value, quagga2Snapshot.value),
)

const bothSnapshotsPresent = computed(() =>
  barcodeDetectorSnapshot.value != null && quagga2Snapshot.value != null,
)

const sameDeviceWarning = computed(() => {
  const bd = barcodeDetectorSnapshot.value
  const qg = quagga2Snapshot.value

  if (!bd || !qg) {
    return false
  }

  return bd.device.userAgent !== qg.device.userAgent
})

function refreshSnapshots(): void {
  barcodeDetectorSnapshot.value = loadBarcodeDetectorSnapshot()
  quagga2Snapshot.value = loadQuagga2Snapshot()
}

function importBarcodeDetectorJson(): void {
  importError.value = null
  actionMessage.value = null

  try {
    const parsed = JSON.parse(importText.value) as EngineComparisonSnapshot

    if (!parsed || typeof parsed !== 'object') {
      throw new Error('Objet JSON invalide')
    }

    if (parsed.engine && parsed.engine !== 'barcode_detector') {
      throw new Error('Le champ engine doit être "barcode_detector"')
    }

    const snapshot: EngineComparisonSnapshot = {
      ...parsed,
      engine: 'barcode_detector',
    }

    saveEngineSnapshot(STORAGE_KEY_BARCODE_DETECTOR, snapshot)
    refreshSnapshots()
    actionMessage.value = 'Snapshot BarcodeDetector importé.'
    importText.value = ''
  } catch (error) {
    importError.value = error instanceof Error ? error.message : 'JSON invalide'
  }
}

function handleClearSnapshots(): void {
  if (!window.confirm('Effacer les deux snapshots moteurs du localStorage ?')) {
    return
  }

  clearEngineSnapshots()
  refreshSnapshots()
  actionMessage.value = 'Snapshots effacés.'
}

function winnerLabel(winner: EngineComparisonRow['winner']): string {
  if (winner === 'barcode_detector') {
    return 'BarcodeDetector'
  }

  if (winner === 'quagga2') {
    return 'Quagga2'
  }

  if (winner === 'tie') {
    return 'Égalité'
  }

  return '—'
}

function winnerClass(winner: EngineComparisonRow['winner']): string {
  if (winner === 'barcode_detector') {
    return 'text-primary'
  }

  if (winner === 'quagga2') {
    return 'text-success'
  }

  if (winner === 'tie') {
    return 'text-warning'
  }

  return ''
}

function formatSavedAt(iso: string | undefined): string {
  if (!iso) {
    return '—'
  }

  try {
    return new Date(iso).toLocaleString('fr-FR')
  } catch {
    return iso
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  refreshSnapshots()
})
</script>

<template>
  <Head title="DEV — Comparaison moteurs barcode" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix barcode-reliability-phase2">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / EXPÉRIMENTAL</p>
          <h1 class="barcode-reader-test-page__title">Comparaison BarcodeDetector vs Quagga2</h1>
          <p class="barcode-reader-test-page__subtitle">
            Snapshots localStorage — comparaison indicative entre moteurs
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link href="/dev/barcode-quagga2" class="btn btn-sm btn-outline-secondary">Scanner live</Link>
          <Link href="/dev/barcode-quagga2-benchmark" class="btn btn-sm btn-outline-secondary">Benchmark Quagga2</Link>
          <Link href="/dev/barcode/html5-qrcode-benchmark" class="btn btn-sm btn-outline-secondary">Benchmark html5-qrcode</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Phase 1</Link>
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-secondary">Phase 2</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">
          <strong>Comparaison équitable : même appareil requis.</strong>
          Les benchmarks doivent être exécutés sur le même téléphone/navigateur/caméra.
        </p>
        <p class="mb-1 barcode-decode-reliability-matrix__muted">
          Navigateur actuel : {{ environment.browserLabel }}
        </p>
        <p class="mb-0 barcode-decode-reliability-matrix__warning">
          EXPERIMENTAL ONLY — NOT PRODUCTION
        </p>
      </section>

      <section v-if="sameDeviceWarning" class="barcode-decode-reliability-matrix__section">
        <p class="barcode-decode-reliability-matrix__warning mb-0">
          ⚠ Les userAgent des deux snapshots diffèrent — la comparaison n'est probablement pas fiable.
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Lancer les benchmarks</h2>
        <div class="barcode-decode-reliability-matrix__actions">
          <Link href="/dev/barcode-detector-reliability-phase-2" class="btn btn-sm btn-outline-primary">
            Benchmark BarcodeDetector (Phase 2)
          </Link>
          <Link href="/dev/barcode-quagga2-benchmark" class="btn btn-sm btn-outline-success">
            Benchmark Quagga2
          </Link>
        </div>
        <p class="barcode-decode-reliability-matrix__muted mb-0 mt-2">
          Chaque benchmark enregistre automatiquement le meilleur résultat dans localStorage.
        </p>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Snapshots chargés</h2>
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="barcode-decode-reliability-matrix__result-card h-100">
              <div class="barcode-decode-reliability-matrix__result-title">BarcodeDetector</div>
              <template v-if="barcodeDetectorSnapshot">
                <dl class="barcode-decode-reliability-matrix__grid mb-0">
                  <div><dt>Enregistré</dt><dd>{{ formatSavedAt(barcodeDetectorSnapshot.savedAt) }}</dd></div>
                  <div><dt>Navigateur</dt><dd>{{ barcodeDetectorSnapshot.device.browser }}</dd></div>
                  <div><dt>DPR</dt><dd>{{ barcodeDetectorSnapshot.device.devicePixelRatio }}</dd></div>
                  <div><dt>Caméra</dt><dd>{{ barcodeDetectorSnapshot.camera.actualResolution ?? '—' }}</dd></div>
                  <div><dt>FPS</dt><dd>{{ barcodeDetectorSnapshot.camera.fps ?? '—' }}</dd></div>
                  <div><dt>Source</dt><dd>{{ barcodeDetectorSnapshot.sourceLabel }}</dd></div>
                  <div><dt>Attendu</dt><dd class="font-monospace">{{ barcodeDetectorSnapshot.expectedBarcode }}</dd></div>
                  <div><dt>Score</dt><dd>{{ barcodeDetectorSnapshot.metrics.overallScore }}</dd></div>
                </dl>
              </template>
              <p v-else class="barcode-decode-reliability-matrix__muted mb-0">Aucun snapshot — lancer Phase 2 ou importer JSON.</p>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="barcode-decode-reliability-matrix__result-card h-100">
              <div class="barcode-decode-reliability-matrix__result-title">Quagga2</div>
              <template v-if="quagga2Snapshot">
                <dl class="barcode-decode-reliability-matrix__grid mb-0">
                  <div><dt>Enregistré</dt><dd>{{ formatSavedAt(quagga2Snapshot.savedAt) }}</dd></div>
                  <div><dt>Navigateur</dt><dd>{{ quagga2Snapshot.device.browser }}</dd></div>
                  <div><dt>DPR</dt><dd>{{ quagga2Snapshot.device.devicePixelRatio }}</dd></div>
                  <div><dt>Caméra</dt><dd>{{ quagga2Snapshot.camera.actualResolution ?? '—' }}</dd></div>
                  <div><dt>FPS</dt><dd>{{ quagga2Snapshot.camera.fps ?? '—' }}</dd></div>
                  <div><dt>Source</dt><dd>{{ quagga2Snapshot.sourceLabel }}</dd></div>
                  <div><dt>Attendu</dt><dd class="font-monospace">{{ quagga2Snapshot.expectedBarcode }}</dd></div>
                  <div><dt>Score</dt><dd>{{ quagga2Snapshot.metrics.overallScore }}</dd></div>
                </dl>
              </template>
              <p v-else class="barcode-decode-reliability-matrix__muted mb-0">Aucun snapshot — lancer le benchmark Quagga2.</p>
            </div>
          </div>
        </div>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Importer BarcodeDetector (JSON)</h2>
        <textarea
          v-model="importText"
          class="form-control form-control-sm font-monospace"
          rows="6"
          placeholder='Coller un EngineComparisonSnapshot JSON (engine: "barcode_detector")…'
        />
        <div class="barcode-decode-reliability-matrix__actions mt-2">
          <button type="button" class="btn btn-sm btn-primary" :disabled="!importText.trim()" @click="importBarcodeDetectorJson">
            Importer
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger" @click="handleClearSnapshots">
            Effacer snapshots
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="refreshSnapshots">
            Recharger
          </button>
        </div>
        <p v-if="importError" class="barcode-decode-reliability-matrix__warning mb-0 mt-2">{{ importError }}</p>
        <p v-if="actionMessage" class="barcode-decode-reliability-matrix__muted mb-0 mt-2">{{ actionMessage }}</p>
      </section>

      <section v-if="bothSnapshotsPresent || comparisonRows.some((row) => row.barcodeDetector !== '—' || row.quagga2 !== '—')" class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Tableau comparatif</h2>
        <div class="barcode-decode-reliability-matrix__table-wrap">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Métrique</th>
                <th>BarcodeDetector</th>
                <th>Quagga2</th>
                <th>Gagnant</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in comparisonRows" :key="row.metric">
                <td>{{ row.metric }}</td>
                <td>{{ row.barcodeDetector }}</td>
                <td>{{ row.quagga2 }}</td>
                <td :class="winnerClass(row.winner)">{{ winnerLabel(row.winner) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="!bothSnapshotsPresent" class="barcode-decode-reliability-matrix__section">
        <p class="barcode-decode-reliability-matrix__muted mb-0">
          Les deux snapshots sont requis pour une comparaison complète.
          BarcodeDetector : Phase 2 ou import JSON · Quagga2 : benchmark automatisé.
        </p>
      </section>
    </div>
  </div>
</template>
