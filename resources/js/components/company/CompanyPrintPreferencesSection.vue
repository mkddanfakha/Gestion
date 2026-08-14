<template>
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="card-title mb-1">Préférences d'impression</h5>
      <p class="text-muted small mb-0">
        Choisissez où afficher la signature et le cachet sur vos documents PDF
      </p>
    </div>
    <div class="card-body">
      <div class="row g-4">
        <div
          v-for="group in preferenceGroups"
          :key="group.key"
          class="col-md-6"
        >
          <div class="company-print-prefs-group">
            <h6 class="company-print-prefs-group__title">{{ group.label }}</h6>
            <div class="form-check mb-2">
              <input
                :id="`${group.key}-signature`"
                v-model="form[group.signatureField]"
                class="form-check-input"
                type="checkbox"
                :disabled="disabled"
              >
              <label class="form-check-label" :for="`${group.key}-signature`">
                Afficher la signature
              </label>
            </div>
            <div class="form-check">
              <input
                :id="`${group.key}-stamp`"
                v-model="form[group.stampField]"
                class="form-check-input"
                type="checkbox"
                :disabled="disabled"
              >
              <label class="form-check-label" :for="`${group.key}-stamp`">
                Afficher le cachet
              </label>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3'

type PrintPreferenceForm = {
  print_signature_on_invoice: boolean
  print_stamp_on_invoice: boolean
  print_signature_on_quote: boolean
  print_stamp_on_quote: boolean
  print_signature_on_purchase_order: boolean
  print_stamp_on_purchase_order: boolean
  print_signature_on_delivery_note: boolean
  print_stamp_on_delivery_note: boolean
}

defineProps<{
  form: InertiaForm<PrintPreferenceForm>
  disabled?: boolean
}>()

const preferenceGroups = [
  {
    key: 'invoice',
    label: 'Factures',
    signatureField: 'print_signature_on_invoice' as const,
    stampField: 'print_stamp_on_invoice' as const,
  },
  {
    key: 'quote',
    label: 'Devis',
    signatureField: 'print_signature_on_quote' as const,
    stampField: 'print_stamp_on_quote' as const,
  },
  {
    key: 'purchase_order',
    label: 'Bons de commande',
    signatureField: 'print_signature_on_purchase_order' as const,
    stampField: 'print_stamp_on_purchase_order' as const,
  },
  {
    key: 'delivery_note',
    label: 'Bons de livraison',
    signatureField: 'print_signature_on_delivery_note' as const,
    stampField: 'print_stamp_on_delivery_note' as const,
  },
]
</script>

<style scoped>
.company-print-prefs-group {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 1rem;
  background: var(--color-surface-hover);
  height: 100%;
}

.company-print-prefs-group__title {
  margin: 0 0 0.75rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--color-text-primary);
}
</style>
