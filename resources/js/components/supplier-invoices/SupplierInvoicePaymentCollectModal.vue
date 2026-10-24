<template>
  <div
    v-if="show"
    ref="modalRootRef"
    class="modal fade show d-block"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
    aria-labelledby="supplier-invoice-payment-modal-title"
  >
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form @submit.prevent="submit">
          <div class="modal-header">
            <h5 id="supplier-invoice-payment-modal-title" class="modal-title">Enregistrer un règlement</h5>
            <button type="button" class="btn-close" aria-label="Fermer" :disabled="form.processing" @click="requestClose"></button>
          </div>
          <div class="modal-body">
            <p class="text-muted small mb-3">
              Solde restant dû :
              <span class="fw-semibold text-warning">{{ formatCurrency(maxAmount) }}</span>
            </p>

            <div class="mb-3">
              <label class="form-label" for="supplier-invoice-payment-amount">Montant réglé <span class="text-danger">*</span></label>
              <div class="input-group">
                <input
                  id="supplier-invoice-payment-amount"
                  ref="amountInputRef"
                  v-model.number="form.amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  :max="maxAmount"
                  class="form-control"
                  required
                  :class="{ 'is-invalid': form.errors.amount }"
                  :disabled="form.processing"
                >
                <span class="input-group-text">FCFA</span>
              </div>
              <div v-if="form.errors.amount" class="invalid-feedback d-block">{{ form.errors.amount }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="supplier-invoice-payment-method">Mode de paiement <span class="text-danger">*</span></label>
              <select
                id="supplier-invoice-payment-method"
                v-model="form.payment_method"
                class="form-select"
                required
                :class="{ 'is-invalid': form.errors.payment_method }"
                :disabled="form.processing"
              >
                <option value="" disabled>Sélectionner…</option>
                <option v-for="option in paymentMethodOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <div v-if="form.errors.payment_method" class="invalid-feedback d-block">{{ form.errors.payment_method }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="supplier-invoice-payment-paid-at">Date et heure du règlement (UTC)</label>
              <input
                id="supplier-invoice-payment-paid-at"
                v-model="form.paid_at"
                type="datetime-local"
                class="form-control"
                :class="{ 'is-invalid': form.errors.paid_at }"
                :disabled="form.processing"
              >
              <div v-if="form.errors.paid_at" class="invalid-feedback d-block">{{ form.errors.paid_at }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="supplier-invoice-payment-reference">Référence</label>
              <input
                id="supplier-invoice-payment-reference"
                v-model="form.reference"
                type="text"
                maxlength="255"
                class="form-control"
                :class="{ 'is-invalid': form.errors.reference }"
                :disabled="form.processing"
              >
              <div v-if="form.errors.reference" class="invalid-feedback d-block">{{ form.errors.reference }}</div>
            </div>

            <div class="mb-0">
              <label class="form-label" for="supplier-invoice-payment-note">Note</label>
              <textarea
                id="supplier-invoice-payment-note"
                v-model="form.note"
                rows="2"
                maxlength="1000"
                class="form-control"
                :class="{ 'is-invalid': form.errors.note }"
                :disabled="form.processing"
              ></textarea>
              <div v-if="form.errors.note" class="invalid-feedback d-block">{{ form.errors.note }}</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="requestClose">
              Annuler
            </button>
            <button type="submit" class="btn btn-success" :disabled="form.processing || !canSubmit">
              <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              Enregistrer le règlement
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div v-if="show" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatPaymentDateTimeForInput } from '@/utils/paymentDateTime'
import { SALE_PAYMENT_METHOD_LABELS } from '@/utils/salePaymentLabels'

const props = defineProps<{
  show: boolean
  supplierInvoiceId: number
  maxAmount: number
}>()

const emit = defineEmits<{
  close: []
  success: []
}>()

const paymentMethodOptions = Object.entries(SALE_PAYMENT_METHOD_LABELS).map(([value, label]) => ({
  value,
  label,
}))

const amountInputRef = ref<HTMLInputElement | null>(null)
const modalRootRef = ref<HTMLElement | null>(null)
const idempotencyKey = ref('')

const form = useForm({
  amount: 0,
  payment_method: '',
  paid_at: '',
  reference: '',
  note: '',
})

const canSubmit = computed(() => {
  const amount = Number(form.amount)

  return (
    Number.isFinite(amount)
    && amount > 0
    && amount <= props.maxAmount + 0.009
    && form.payment_method !== ''
  )
})

function defaultPaidAtUtc(): string {
  return formatPaymentDateTimeForInput(new Date())
}

function resetFormState(): void {
  idempotencyKey.value = crypto.randomUUID()
  form.clearErrors()
  form.amount = props.maxAmount > 0 ? props.maxAmount : 0
  form.payment_method = ''
  form.paid_at = defaultPaidAtUtc()
  form.reference = ''
  form.note = ''
}

watch(
  () => [props.show, props.maxAmount] as const,
  async ([visible]) => {
    if (!visible) {
      return
    }

    resetFormState()

    await nextTick()
    amountInputRef.value?.focus()
    amountInputRef.value?.select()
  },
)

function requestClose(): void {
  if (form.processing) {
    return
  }

  emit('close')
}

function submit(): void {
  if (form.processing || !canSubmit.value) {
    return
  }

  form
    .transform((data) => ({
      ...data,
      idempotency_key: idempotencyKey.value,
      paid_at: data.paid_at || null,
      reference: data.reference || null,
      note: data.note || null,
    }))
    .post(route('supplier-invoices.payments.store', { supplierInvoice: props.supplierInvoiceId }), {
      preserveScroll: true,
      onSuccess: () => {
        emit('success')
        emit('close')
      },
    })
}
</script>
