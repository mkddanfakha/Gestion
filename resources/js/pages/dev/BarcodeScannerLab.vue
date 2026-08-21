<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { dashboard } from '@/routes'

interface LabSection {
  title: string
  description: string
  links: Array<{ label: string; href: string; badge?: string }>
}

const sections: LabSection[] = [
  {
    title: 'Fiabilité & décodage',
    description: 'Benchmarks de fiabilité, matrices et comparaison de moteurs.',
    links: [
      { label: 'Decode Reliability (Phase 1)', href: '/dev/barcode-detector-decode-reliability' },
      { label: 'Reliability Matrix', href: '/dev/barcode-detector-decode-reliability-matrix' },
      { label: 'Reliability Phase 2', href: '/dev/barcode-detector-reliability-phase-2' },
      { label: 'Comparaison moteurs', href: '/dev/barcode-engines-comparison', badge: '3 libs' },
    ],
  },
  {
    title: 'Focus, zoom & résolution',
    description: 'Expériences caméra — focus, distance, taille code-barres, ROI.',
    links: [
      { label: 'Résolution', href: '/dev/barcode-detector-resolution-test' },
      { label: 'ROI', href: '/dev/barcode-detector-roi-test' },
      { label: 'Contrôles caméra', href: '/dev/barcode-detector-camera-controls-test' },
      { label: 'Focus sharpness', href: '/dev/barcode-detector-focus-sharpness-test' },
      { label: 'Manual focus test', href: '/dev/barcode-detector-manual-focus-test' },
      { label: 'Manual focus experiment', href: '/dev/barcode-detector-manual-focus-experiment' },
      { label: 'Focus distance mapping', href: '/dev/barcode-detector-focus-distance-mapping' },
      { label: 'Focus × zoom benchmark', href: '/dev/barcode-detector-focus-zoom-benchmark' },
      { label: 'Size × zoom comparison', href: '/dev/barcode-detector-size-zoom-comparison' },
      { label: 'Distance focus', href: '/dev/barcode-detector-distance-focus' },
      { label: 'Fine focus sweep', href: '/dev/barcode-detector-fine-focus' },
      { label: 'Stability focus repeatability', href: '/dev/barcode-detector-stability-focus-repeatability' },
    ],
  },
  {
    title: 'Moteurs alternatifs',
    description: 'Quagga2 et html5-qrcode — benchmarks automatisés.',
    links: [
      { label: 'Quagga2 live', href: '/dev/barcode-quagga2' },
      { label: 'Quagga2 benchmark', href: '/dev/barcode-quagga2-benchmark' },
      { label: 'html5-qrcode benchmark', href: '/dev/barcode/html5-qrcode-benchmark' },
    ],
  },
  {
    title: 'Diagnostics & tests natifs',
    description: 'Pipeline, moteurs, caméra, vue-qrcode-reader.',
    links: [
      { label: 'Barcode reader test', href: '/dev/barcode-reader-test' },
      { label: 'Barcode engine test', href: '/dev/barcode-engine-test' },
      { label: 'Native BarcodeDetector test', href: '/dev/native-barcode-detector-test' },
      { label: 'Native live test', href: '/dev/native-barcode-detector-live-test' },
      { label: 'Camera minimal test', href: '/dev/native-camera-minimal-test' },
      { label: 'Camera visual test', href: '/dev/native-camera-visual-test' },
      { label: 'Camera stream diagnostic', href: '/dev/native-camera-stream-diagnostic' },
    ],
  },
]
</script>

<template>
  <Head title="DEV — Barcode Scanner Lab" />

  <div class="barcode-reader-test-page barcode-decode-reliability-matrix">
    <div class="barcode-reader-test-page__container barcode-decode-reliability-matrix__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / EXPÉRIMENTAL</p>
          <h1 class="barcode-reader-test-page__title">Barcode Scanner Lab</h1>
          <p class="barcode-reader-test-page__subtitle">
            Point d'entrée vers toutes les expérimentations caméra — NOT PRODUCTION
          </p>
        </div>
        <div class="barcode-decode-reliability-matrix__header-links">
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-decode-reliability-matrix__banner barcode-decode-reliability-matrix__banner--warning">
        <p class="mb-1">
          La lecture caméra téléphone n'est <strong>pas</strong> utilisée en production MKD-Pro V1.
        </p>
        <p class="mb-0 barcode-decode-reliability-matrix__muted">
          Production : douchette USB/Bluetooth HID — voir
          <code>docs/barcode-strategy.md</code>
        </p>
      </section>

      <section
        v-for="section in sections"
        :key="section.title"
        class="barcode-decode-reliability-matrix__section"
      >
        <h2 class="barcode-decode-reliability-matrix__section-title">{{ section.title }}</h2>
        <p class="barcode-decode-reliability-matrix__muted">{{ section.description }}</p>
        <div class="d-flex flex-wrap gap-2">
          <Link
            v-for="link in section.links"
            :key="link.href"
            :href="link.href"
            class="btn btn-sm btn-outline-secondary"
          >
            {{ link.label }}
            <span v-if="link.badge" class="badge text-bg-secondary ms-1">{{ link.badge }}</span>
          </Link>
        </div>
      </section>

      <section class="barcode-decode-reliability-matrix__section">
        <h2 class="barcode-decode-reliability-matrix__section-title">Documentation</h2>
        <ul class="mb-0">
          <li><code>docs/barcode-scanner-strategy.md</code> — stratégie production vs expérimental</li>
          <li><code>docs/barcode-experiments.md</code> — historique et conclusion</li>
          <li><code>docs/barcode-strategy.md</code> — synthèse technique V1</li>
        </ul>
      </section>
    </div>
  </div>
</template>
