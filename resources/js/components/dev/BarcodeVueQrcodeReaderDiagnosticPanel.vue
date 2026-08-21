<template>
  <div class="barcode-vue-qrcode-reader-diagnostic__panel">
    <div class="barcode-vue-qrcode-reader-diagnostic__header">
      <h2 id="barcode-vue-qrcode-reader-diagnostic-title" class="barcode-vue-qrcode-reader-diagnostic__title">
        Test vue-qrcode-reader
      </h2>
      <div class="barcode-vue-qrcode-reader-diagnostic__actions">
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          @click="$emit('clear')"
        >
          Effacer le diagnostic
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          @click="$emit('stop-page-cameras')"
        >
          Arrêter caméra de test
        </button>
        <button
          v-if="!testRunning"
          type="button"
          class="btn btn-sm btn-outline-secondary"
          @click="$emit('restart')"
        >
          Relancer le test
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          @click="$emit('stop')"
        >
          Arrêter le test
        </button>
      </div>
    </div>

    <div class="barcode-vue-qrcode-reader-diagnostic__section">Environnement</div>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid barcode-vue-qrcode-reader-diagnostic__grid--full">
      <div><dt>vue-qrcode-reader version</dt><dd>{{ vueQrcodeReaderVersion }}</dd></div>
      <div><dt>Secure context</dt><dd>{{ secureContext.secureContext ? 'true' : 'false' }}</dd></div>
      <div><dt>Protocol</dt><dd>{{ secureContext.protocol }}</dd></div>
      <div><dt>Hostname</dt><dd>{{ secureContext.hostname }}</dd></div>
      <div><dt>mediaDevices</dt><dd>{{ secureContext.mediaDevices }}</dd></div>
      <div><dt>getUserMedia</dt><dd>{{ secureContext.getUserMedia }}</dd></div>
    </dl>

    <div class="barcode-vue-qrcode-reader-diagnostic__section">Tests caméra indépendants</div>

    <div class="barcode-vue-qrcode-reader-diagnostic__actions mb-2">
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        :disabled="pageCameraBusy"
        @click="$emit('run-get-user-media-test')"
      >
        Test getUserMedia indépendant
      </button>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        :disabled="pageCameraBusy"
        @click="$emit('run-raw-video-test')"
      >
        Test vidéo caméra brut
      </button>
    </div>

    <p v-if="pageCameraBusyMessage" class="barcode-vue-qrcode-reader-diagnostic__notice mb-2">
      {{ pageCameraBusyMessage }}
    </p>

    <template v-if="getUserMediaTest">
      <div class="barcode-vue-qrcode-reader-diagnostic__section">getUserMedia</div>
      <dl class="barcode-vue-qrcode-reader-diagnostic__grid">
        <div><dt>Status</dt><dd>{{ getUserMediaTest.status }}</dd></div>
        <div><dt>Stream</dt><dd>{{ getUserMediaTest.streamActive }}</dd></div>
        <div><dt>Tracks</dt><dd>{{ getUserMediaTest.tracks }}</dd></div>
        <div><dt>Video track</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ getUserMediaTest.videoTrack }}</dd></div>
        <div><dt>Facing mode</dt><dd>{{ getUserMediaTest.facingMode }}</dd></div>
        <div><dt>Width</dt><dd>{{ getUserMediaTest.width }}</dd></div>
        <div><dt>Height</dt><dd>{{ getUserMediaTest.height }}</dd></div>
      </dl>
      <ErrorDetailsBlock v-if="getUserMediaTest.error" title="Erreur getUserMedia" :details="getUserMediaTest.error" />
    </template>

    <div ref="rawVideoMountRef" class="barcode-vue-qrcode-reader-diagnostic__raw-video-host"></div>

    <template v-if="rawVideoTest">
      <div class="barcode-vue-qrcode-reader-diagnostic__section">Test vidéo brut</div>
      <dl class="barcode-vue-qrcode-reader-diagnostic__grid">
        <div><dt>Status</dt><dd>{{ rawVideoTest.status }}</dd></div>
        <div><dt>VideoWidth</dt><dd>{{ rawVideoTest.videoWidth }}</dd></div>
        <div><dt>VideoHeight</dt><dd>{{ rawVideoTest.videoHeight }}</dd></div>
        <div><dt>ReadyState</dt><dd>{{ rawVideoTest.readyState }}</dd></div>
        <div><dt>Track readyState</dt><dd>{{ rawVideoTest.trackReadyState }}</dd></div>
        <div><dt>Facing mode</dt><dd>{{ rawVideoTest.facingMode }}</dd></div>
      </dl>
      <ErrorDetailsBlock v-if="rawVideoTest.error" title="Erreur vidéo brut" :details="rawVideoTest.error" />
    </template>

    <div class="barcode-vue-qrcode-reader-diagnostic__section">vue-qrcode-reader</div>

    <div
      ref="viewportRef"
      class="barcode-vue-qrcode-reader-diagnostic__viewport"
    >
      <slot />
    </div>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid">
      <div><dt>Status</dt><dd>{{ statusLabel }}</dd></div>
      <div><dt>Camera init</dt><dd>{{ cameraInitStatus }}</dd></div>
      <div><dt>Camera</dt><dd>{{ cameraActive ? 'ACTIVE' : 'inactive' }}</dd></div>
      <div><dt>Detection</dt><dd>{{ detectionStatusLabel }}</dd></div>
    </dl>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid">
      <div><dt>Result</dt><dd class="font-monospace">{{ lastAnalysis?.rawValue || '—' }}</dd></div>
      <div><dt>Format</dt><dd>{{ lastAnalysis ? formatBarcodeFormatLabel(lastAnalysis.format) : '—' }}</dd></div>
      <div><dt>Attempts</dt><dd>{{ attemptCount }}</dd></div>
      <div><dt>Last event</dt><dd>{{ lastEvent || '—' }}</dd></div>
    </dl>

    <div class="barcode-vue-qrcode-reader-diagnostic__section">Erreur caméra</div>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid">
      <div><dt>Erreurs caméra</dt><dd>{{ cameraErrorCount }}</dd></div>
      <div><dt>Erreurs lib.</dt><dd>{{ libraryErrorCount }}</dd></div>
      <div><dt>Message lib.</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ lastLibraryError || '—' }}</dd></div>
      <div><dt>Dernière détection</dt><dd>{{ lastDetectionAt || '—' }}</dd></div>
    </dl>

    <ErrorDetailsBlock
      v-if="lastErrorDetails"
      title="Détail erreur vue-qrcode-reader"
      :details="lastErrorDetails"
    />

    <div class="barcode-vue-qrcode-reader-diagnostic__section">Video element (vue-qrcode-reader)</div>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid barcode-vue-qrcode-reader-diagnostic__grid--full">
      <div><dt>Video element</dt><dd>{{ videoProbe.videoElement }}</dd></div>
      <div><dt>Video readyState</dt><dd>{{ videoProbe.readyState }}</dd></div>
      <div><dt>Video paused</dt><dd>{{ videoProbe.paused }}</dd></div>
      <div><dt>VideoWidth</dt><dd>{{ videoProbe.videoWidth }}</dd></div>
      <div><dt>VideoHeight</dt><dd>{{ videoProbe.videoHeight }}</dd></div>
      <div><dt>CurrentTime</dt><dd>{{ videoProbe.currentTime }}</dd></div>
      <div><dt>srcObject</dt><dd>{{ videoProbe.srcObject }}</dd></div>
      <div><dt>Stream active</dt><dd>{{ videoProbe.streamActive }}</dd></div>
      <div><dt>Video tracks</dt><dd>{{ videoProbe.videoTracks }}</dd></div>
      <div><dt>Track state</dt><dd>{{ videoProbe.trackState }}</dd></div>
      <div><dt>Track readyState</dt><dd>{{ videoProbe.trackReadyState }}</dd></div>
      <div><dt>Facing mode</dt><dd>{{ videoProbe.facingMode }}</dd></div>
      <div><dt>Resolution</dt><dd>{{ videoProbe.resolution }}</dd></div>
    </dl>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid">
      <div><dt>Résolution lib.</dt><dd>{{ cameraResolutionLabel }}</dd></div>
      <div><dt>Facing mode lib.</dt><dd>{{ cameraFacingMode || '—' }}</dd></div>
      <div><dt>Track state lib.</dt><dd>{{ cameraTrackState || '—' }}</dd></div>
      <div><dt>Frame rate</dt><dd>{{ cameraFrameRateLabel }}</dd></div>
    </dl>

    <div class="barcode-vue-qrcode-reader-diagnostic__section">Détection brute</div>

    <template v-if="lastAnalysis">
      <pre class="barcode-vue-qrcode-reader-diagnostic__pre">{{ rawValueBlock }}</pre>

      <dl class="barcode-vue-qrcode-reader-diagnostic__grid barcode-vue-qrcode-reader-diagnostic__grid--full">
        <div><dt>Type</dt><dd>{{ lastAnalysis.valueType }}</dd></div>
        <div><dt>Longueur</dt><dd>{{ lastAnalysis.length }}</dd></div>
        <div><dt>JSON</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ lastAnalysis.json }}</dd></div>
        <div><dt>Codes Unicode</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ lastAnalysis.unicodeCodes }}</dd></div>
      </dl>

      <button
        type="button"
        class="btn btn-sm btn-outline-secondary mb-2"
        @click="$emit('validate-field')"
      >
        Tester le résultat dans le champ
      </button>

      <p v-if="fieldValidationResult" class="barcode-vue-qrcode-reader-diagnostic__notice mb-2">
        {{ fieldValidationResult }}
      </p>
    </template>

    <p v-else-if="testRunning" class="barcode-vue-qrcode-reader-diagnostic__notice mb-2">
      Aucun code détecté.
    </p>

    <div class="barcode-vue-qrcode-reader-diagnostic__section">Résumé opérationnel</div>

    <dl class="barcode-vue-qrcode-reader-diagnostic__grid barcode-vue-qrcode-reader-diagnostic__grid--full">
      <div><dt>Camera initialization</dt><dd>{{ cameraInitStatus }}</dd></div>
      <div><dt>Video</dt><dd>{{ videoProbe.resolution !== '—' ? videoProbe.resolution : '—' }}</dd></div>
      <div><dt>vue-qrcode-reader</dt><dd>{{ statusLabel === 'running' || statusLabel === 'detected' ? 'RUNNING' : statusLabel.toUpperCase() }}</dd></div>
      <div><dt>Barcode detection</dt><dd>{{ detectionCount > 0 ? 'SUCCESS' : 'NOT FOUND' }}</dd></div>
      <div><dt>Raw result</dt><dd class="font-monospace">{{ lastAnalysis?.rawValue || '—' }}</dd></div>
    </dl>

    <p class="barcode-vue-qrcode-reader-diagnostic__conclusion mb-2">
      {{ operationalSummary }}
    </p>

    <p class="barcode-vue-qrcode-reader-diagnostic__conclusion mb-0">
      {{ diagnosticConclusion }}
    </p>
  </div>
</template>

<script setup lang="ts">
import ErrorDetailsBlock from '@/components/dev/BarcodeVueQrcodeReaderDiagnosticErrorDetails.vue'
import {
  formatBarcodeFormatLabel,
  type BarcodeRawAnalysis,
} from '@/utils/barcodeVueQrcodeReaderDiagnostic'
import type {
  DiagnosticErrorDetails,
  IndependentMediaTestResult,
  RawVideoTestResult,
  SecureContextInfo,
  VideoProbeInfo,
} from '@/utils/barcodeReaderTestDiagnostics'
import { computed, ref } from 'vue'

const props = defineProps<{
  attemptCount: number
  cameraActive: boolean
  cameraErrorCount: number
  cameraFacingMode: string
  cameraFrameRateLabel: string
  cameraInitStatus: 'SUCCESS' | 'ERROR' | 'WAITING'
  cameraResolutionLabel: string
  cameraTrackState: string
  detectionCount: number
  diagnosticConclusion: string
  fieldValidationResult: string | null
  getUserMediaTest: IndependentMediaTestResult | null
  lastAnalysis: BarcodeRawAnalysis | null
  lastDetectionAt: string | null
  lastErrorDetails: DiagnosticErrorDetails | null
  lastEvent: string
  lastLibraryError: string | null
  libraryErrorCount: number
  operationalSummary: string
  pageCameraBusy: boolean
  pageCameraBusyMessage: string | null
  rawVideoTest: RawVideoTestResult | null
  rawValueBlock: string
  secureContext: SecureContextInfo
  statusLabel: string
  streamActive: boolean
  testRunning: boolean
  videoProbe: VideoProbeInfo
  vueQrcodeReaderVersion: string
}>()

defineEmits<{
  clear: []
  restart: []
  stop: []
  'stop-page-cameras': []
  'run-get-user-media-test': []
  'run-raw-video-test': []
  'validate-field': []
}>()

const viewportRef = ref<HTMLElement | null>(null)
const rawVideoMountRef = ref<HTMLElement | null>(null)

const detectionStatusLabel = computed(() => {
  if (props.detectionCount > 0) {
    return 'SUCCESS'
  }

  if (props.testRunning) {
    return 'WAITING'
  }

  return 'stopped'
})

defineExpose({
  viewportRef,
  rawVideoMountRef,
})
</script>
