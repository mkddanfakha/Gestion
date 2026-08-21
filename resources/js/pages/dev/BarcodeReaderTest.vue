<script setup lang="ts">
import BarcodeVueQrcodeReaderDiagnostic from '@/components/dev/BarcodeVueQrcodeReaderDiagnostic.vue'
import { Head, Link } from '@inertiajs/vue3'
import { dashboard } from '@/routes'
</script>

<template>
  <Head title="DEV — vue-qrcode-reader" />

  <div class="barcode-reader-test-page">
    <div class="barcode-reader-test-page__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <p class="barcode-reader-test-page__badge mb-2">DEV / DIAGNOSTIC UNIQUEMENT</p>
          <h1 class="barcode-reader-test-page__title">Vue QRCode Reader — Diagnostic</h1>
          <p class="barcode-reader-test-page__intro mb-0">
            Test caméra indépendant. N'affecte pas le scanner principal de MKD-Pro.
          </p>
        </div>
        <div class="d-flex gap-2">
          <Link href="/dev/barcode-scanner-lab" class="btn btn-sm btn-outline-secondary">Scanner Lab</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">
            Retour
          </Link>
        </div>
      </header>

      <BarcodeVueQrcodeReaderDiagnostic embedded />
    </div>
  </div>
</template>
