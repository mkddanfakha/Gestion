<template>
  <Teleport to="body">
    <div v-if="open" class="user-activity-detail-modal-root">
      <div class="modal-backdrop fade show" @click="close"></div>
      <div
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        @keydown.escape.prevent="close"
      >
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 :id="titleId" class="modal-title">
                {{ detail?.type_label || 'Détail de l\'activité' }}
              </h5>
              <button type="button" class="btn-close" aria-label="Fermer" :disabled="loading" @click="close"></button>
            </div>

            <div class="modal-body">
              <div v-if="loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                  <span class="visually-hidden">Chargement…</span>
                </div>
                <div class="mt-2 text-muted">Chargement des détails…</div>
              </div>

              <div v-else-if="error" class="alert alert-danger mb-0" role="alert">
                {{ error }}
              </div>

              <div v-else-if="detail">
                <dl class="row mb-0">
                  <template v-for="(field, index) in detail.fields" :key="`f-${index}`">
                    <dt class="col-sm-4 text-muted">{{ field.label }}</dt>
                    <dd class="col-sm-8">{{ formatFieldValue(detail.type, field.label, field.value) }}</dd>
                  </template>
                </dl>

                <div v-if="detail.amounts.length" class="mt-3">
                  <h6 class="fw-semibold">Montants</h6>
                  <dl class="row mb-0">
                    <template v-for="(amount, index) in detail.amounts" :key="`a-${index}`">
                      <dt class="col-sm-4 text-muted">{{ amount.label }}</dt>
                      <dd class="col-sm-8">
                        {{ amount.value !== null ? formatCurrency(amount.value) : '—' }}
                      </dd>
                    </template>
                  </dl>
                </div>

                <div v-if="detail.items.length" class="mt-3">
                  <h6 class="fw-semibold">Lignes</h6>
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Article</th>
                          <th class="text-end">Qté</th>
                          <th class="text-end">P.U.</th>
                          <th class="text-end">Total</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(item, index) in detail.items" :key="`i-${index}`">
                          <td>{{ item.label }}</td>
                          <td class="text-end">{{ item.quantity ?? '—' }}</td>
                          <td class="text-end">
                            {{ item.unit_price !== null ? formatCurrency(item.unit_price) : '—' }}
                          </td>
                          <td class="text-end">
                            {{ item.total !== null ? formatCurrency(item.total) : '—' }}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div v-if="detail.notes" class="mt-3">
                  <h6 class="fw-semibold">Notes</h6>
                  <p class="mb-0 text-break">{{ detail.notes }}</p>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="close">
                Fermer
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onUnmounted, watch } from 'vue'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatUserActivityStatus } from '@/utils/userActivityStatus'
import { lockModalScroll, unlockModalScroll } from '@/composables/useModalScrollLock'
import type { UserActivityDetailPayload } from '@/composables/useUserActivityDetail'

const props = defineProps<{
  open: boolean
  loading: boolean
  error: string | null
  detail: UserActivityDetailPayload | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const titleId = 'user-activity-detail-modal-title'

const open = computed(() => props.open)

function close() {
  if (props.loading) {
    return
  }
  emit('update:open', false)
}

function formatFieldValue(type: string, label: string, value: string | null): string {
  if (value === null || value === '') {
    return '—'
  }

  const statusLike = ['Statut', 'Catégorie'].includes(label)
  if (statusLike) {
    return formatUserActivityStatus(type, value)
  }

  if (label === 'Paiement' || label === 'Mode de paiement') {
    const paymentLabels: Record<string, string> = {
      paid: 'Payé',
      partial: 'Partiel',
      pending: 'En attente',
      cash: 'Espèces',
      bank_transfer: 'Virement bancaire',
      credit_card: 'Carte de crédit',
      mobile_money: 'Mobile Money',
      orange_money: 'Orange Money',
      wave: 'Wave',
      check: 'Chèque',
    }
    return paymentLabels[value] ?? value
  }

  return value
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      lockModalScroll()
    } else {
      unlockModalScroll()
    }
  },
)

onUnmounted(() => {
  if (props.open) {
    unlockModalScroll()
  }
})
</script>
