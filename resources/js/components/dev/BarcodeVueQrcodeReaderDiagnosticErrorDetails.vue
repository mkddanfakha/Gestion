<template>
  <div class="barcode-vue-qrcode-reader-diagnostic__error-block">
    <div class="barcode-vue-qrcode-reader-diagnostic__section">{{ title }}</div>
    <dl class="barcode-vue-qrcode-reader-diagnostic__grid barcode-vue-qrcode-reader-diagnostic__grid--full">
      <div><dt>Code</dt><dd>{{ details.code }}</dd></div>
      <div><dt>Type</dt><dd>{{ details.type }}</dd></div>
      <div><dt>Name</dt><dd>{{ details.name }}</dd></div>
      <div><dt>Message</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ details.message }}</dd></div>
      <div><dt>Constraint</dt><dd>{{ details.constraint }}</dd></div>
      <div><dt>Stack</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ details.stack }}</dd></div>
      <div><dt>Raw JSON</dt><dd class="barcode-vue-qrcode-reader-diagnostic__break">{{ details.rawJson }}</dd></div>
    </dl>
  </div>
</template>

<script setup lang="ts">
import type { DiagnosticErrorDetails } from '@/utils/barcodeReaderTestDiagnostics'

defineProps<{
  title: string
  details: DiagnosticErrorDetails
}>()
</script>
