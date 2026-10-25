<template>
  <AppLayout>
    <FormPageLayout>
      <FormPageHeader
        title="Nouvelle facture fournisseur"
        subtitle="La facture sera enregistrée en brouillon"
        :back-href="route('supplier-invoices.index')"
        back-label="Retour à la liste"
      />

      <form @submit.prevent="submit">
        <div class="form-page__body">
          <FormSection title="Informations générales" icon="bi bi-info-circle">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label">
                  Fournisseur <span class="text-danger">*</span>
                </label>
                <SupplierCombobox
                  ref="supplierComboboxRef"
                  v-model="form.supplier_id"
                  :suppliers="localSuppliers"
                  placeholder="Rechercher un fournisseur..."
                  :is-invalid="!!form.errors.supplier_id"
                  :allow-create="canCreateSupplier"
                  @created="handleSupplierCreated"
                />
                <div v-if="form.errors.supplier_id" class="invalid-feedback d-block">
                  {{ form.errors.supplier_id }}
                </div>
              </div>
              <div class="col-12 col-md-6">
                <label for="invoice_number" class="form-label">
                  Numéro de facture <span class="text-danger">*</span>
                </label>
                <input
                  id="invoice_number"
                  v-model="form.invoice_number"
                  type="text"
                  class="form-control"
                  :class="{ 'is-invalid': form.errors.invoice_number }"
                  maxlength="255"
                  required
                />
                <div v-if="form.errors.invoice_number" class="invalid-feedback">
                  {{ form.errors.invoice_number }}
                </div>
              </div>
              <div class="col-12 col-md-4">
                <label for="invoice_date" class="form-label">
                  Date de facture <span class="text-danger">*</span>
                </label>
                <input
                  id="invoice_date"
                  v-model="form.invoice_date"
                  type="date"
                  class="form-control"
                  :class="{ 'is-invalid': form.errors.invoice_date }"
                  required
                />
                <div v-if="form.errors.invoice_date" class="invalid-feedback">
                  {{ form.errors.invoice_date }}
                </div>
              </div>
              <div class="col-12 col-md-4">
                <label for="due_date" class="form-label">Date d'échéance</label>
                <input
                  id="due_date"
                  v-model="form.due_date"
                  type="date"
                  class="form-control"
                  :class="{ 'is-invalid': form.errors.due_date }"
                />
                <div v-if="form.errors.due_date" class="invalid-feedback">
                  {{ form.errors.due_date }}
                </div>
              </div>
              <div class="col-12 col-md-4">
                <label for="total_amount" class="form-label">
                  Montant total (FCFA) <span class="text-danger">*</span>
                </label>
                <input
                  id="total_amount"
                  v-model.number="form.total_amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  class="form-control"
                  :class="{ 'is-invalid': form.errors.total_amount }"
                  required
                />
                <div v-if="form.errors.total_amount" class="invalid-feedback">
                  {{ form.errors.total_amount }}
                </div>
              </div>
              <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea
                  id="notes"
                  v-model="form.notes"
                  rows="3"
                  maxlength="5000"
                  class="form-control"
                  :class="{ 'is-invalid': form.errors.notes }"
                ></textarea>
                <div v-if="form.errors.notes" class="invalid-feedback">
                  {{ form.errors.notes }}
                </div>
              </div>
            </div>
          </FormSection>

          <FormSection title="Pièces jointes" icon="bi bi-paperclip">
            <p class="text-muted small mb-3">
              Formats acceptés : PDF, JPG, JPEG, PNG, WEBP (max. {{ attachmentConfig.maxFiles }} fichiers).
            </p>
            <AttachmentUploader
              v-model="pendingFiles"
              :show-existing="false"
              :max-files="attachmentConfig.maxFiles"
              :max-size-kb="attachmentConfig.maxSizeKb"
              :accept="attachmentConfig.accept"
              hint="Les fichiers seront enregistrés à la création de la facture."
            />
            <div v-if="form.errors.attachments" class="text-danger small mt-2">
              {{ form.errors.attachments }}
            </div>
          </FormSection>

          <FormActionsBar class="form-actions-bar--split">
            <Link :href="route('supplier-invoices.index')" class="btn btn-outline-secondary">
              Annuler
            </Link>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">
              <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ form.processing ? 'Enregistrement...' : 'Enregistrer en brouillon' }}
            </button>
          </FormActionsBar>
        </div>
      </form>
    </FormPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import FormPageLayout from '@/components/page/FormPageLayout.vue'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import FormSection from '@/components/page/FormSection.vue'
import FormActionsBar from '@/components/page/FormActionsBar.vue'
import SupplierCombobox from '@/components/forms/SupplierCombobox.vue'
import AttachmentUploader from '@/components/attachments/AttachmentUploader.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { ref, withDefaults } from 'vue'
import { getTodayForInput } from '@/utils/dateFormatter'
import { usePermissions } from '@/composables/usePermissions'
import type { SupplierOption } from '@/types/supplier'
import type { AttachmentConfig } from '@/types/attachment'
import { DEFAULT_ATTACHMENT_CONFIG } from '@/types/attachment'

interface FormSupplier {
  id: number
  name: string
  email?: string | null
  phone?: string | null
  mobile?: string | null
}

const props = withDefaults(
  defineProps<{
    suppliers: FormSupplier[]
    attachmentConfig?: AttachmentConfig
  }>(),
  {
    attachmentConfig: () => DEFAULT_ATTACHMENT_CONFIG,
  },
)

const attachmentConfig = props.attachmentConfig
const pendingFiles = ref<File[]>([])

const { canCreate } = usePermissions()
const canCreateSupplier = canCreate('suppliers')

const localSuppliers = ref<FormSupplier[]>([...props.suppliers])
const supplierComboboxRef = ref<InstanceType<typeof SupplierCombobox> | null>(null)

const form = useForm({
  supplier_id: null as number | null,
  invoice_number: '',
  invoice_date: getTodayForInput(),
  due_date: '' as string | null,
  total_amount: '' as number | string,
  notes: '' as string | null,
})

function handleSupplierCreated(supplier: SupplierOption): void {
  const normalized: FormSupplier = {
    id: supplier.id,
    name: supplier.name,
    email: supplier.email ?? null,
    phone: supplier.phone ?? null,
    mobile: supplier.mobile ?? null,
  }

  if (!localSuppliers.value.some((s) => s.id === normalized.id)) {
    localSuppliers.value.push(normalized)
  }

  form.supplier_id = normalized.id
}

function submit(): void {
  form
    .transform((data) => {
      const formData = new FormData()
      const payload = data as Record<string, unknown>

      if (payload.supplier_id != null && payload.supplier_id !== '') {
        formData.append('supplier_id', String(payload.supplier_id))
      }
      formData.append('invoice_number', String(payload.invoice_number ?? ''))
      formData.append('invoice_date', String(payload.invoice_date ?? ''))
      if (payload.due_date) {
        formData.append('due_date', String(payload.due_date))
      }
      formData.append('total_amount', String(payload.total_amount ?? ''))
      if (payload.notes) {
        formData.append('notes', String(payload.notes))
      }

      pendingFiles.value.forEach((file) => {
        formData.append('attachments[]', file)
      })

      return formData
    })
    .post(route('supplier-invoices.store'), {
      forceFormData: true,
    })
}
</script>
