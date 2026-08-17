<template>
  <AppLayout>
    <FormPageLayout>
      <FormPageHeader
        title="Modifier la dépense"
        subtitle="Modifiez les informations de la dépense"
        :back-href="route('expenses.index')"
        back-label="Retour à la liste"
      >
        <template #meta>
          <DraftSaveStatus :status="draft.status" :last-saved-at="draft.lastSavedAt" />
        </template>
      </FormPageHeader>

      <DraftRestoreDialog
        :visible="draft.showRestoreDialog"
        mode="edit"
        :config="draft.config"
        :draft="draft.pendingDraft"
        @restore="draft.restoreDraft()"
        @dismiss="draft.dismissDraft()"
      />

      <form>
        <div class="form-page__body">
          <FormSection title="Informations générales" icon="bi bi-info-circle">
            <div class="row g-3">
              <div class="col-12 col-md-8">
                <label for="title" class="form-label">
                  Titre de la dépense <span class="text-danger">*</span>
                </label>
                <input
                  v-model="form.title"
                  type="text"
                  class="form-control"
                  :class="{ 'is-invalid': errors.title || clientErrors.title }"
                  id="title"
                  placeholder="Ex: Achat de fournitures de bureau"
                  @input="validateField('title')"
                  @blur="validateField('title')"
                />
                <div v-if="errors.title" class="invalid-feedback">
                  {{ errors.title }}
                </div>
                <div v-if="clientErrors.title" class="invalid-feedback">
                  {{ clientErrors.title }}
                </div>
              </div>
              <div class="col-12 col-md-4">
                <label for="amount" class="form-label">
                  Montant <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                  <input
                    v-model="form.amount"
                    type="number"
                    step="0.01"
                    min="0"
                    class="form-control"
                    :class="{ 'is-invalid': errors.amount || clientErrors.amount }"
                    id="amount"
                    placeholder="0.00"
                    @input="validateField('amount')"
                    @blur="validateField('amount')"
                  />
                  <span class="input-group-text">FCFA</span>
                </div>
                <div v-if="errors.amount" class="invalid-feedback">
                  {{ errors.amount }}
                </div>
                <div v-if="clientErrors.amount" class="invalid-feedback">
                  {{ clientErrors.amount }}
                </div>
              </div>
            </div>
            <div class="row g-3 mt-2">
              <div class="col-12 col-md-6">
                <label for="category" class="form-label">
                  Catégorie <span class="text-danger">*</span>
                </label>
                <select
                  v-model="form.category"
                  class="form-select"
                  :class="{ 'is-invalid': errors.category || clientErrors.category }"
                  id="category"
                  @change="validateField('category')"
                  @blur="validateField('category')"
                >
                  <option value="">Sélectionner une catégorie</option>
                  <option value="fournitures">Fournitures</option>
                  <option value="equipement">Équipement</option>
                  <option value="marketing">Marketing</option>
                  <option value="transport">Transport</option>
                  <option value="formation">Formation</option>
                  <option value="maintenance">Maintenance</option>
                  <option value="utilities">Services publics</option>
                  <option value="autres">Autres</option>
                </select>
                <div v-if="errors.category" class="invalid-feedback">
                  {{ errors.category }}
                </div>
                <div v-if="clientErrors.category" class="invalid-feedback">
                  {{ clientErrors.category }}
                </div>
              </div>
              <div class="col-12 col-md-6">
                <label for="payment_method" class="form-label">
                  Méthode de paiement <span class="text-danger">*</span>
                </label>
                <select
                  v-model="form.payment_method"
                  class="form-select"
                  :class="{ 'is-invalid': errors.payment_method || clientErrors.payment_method }"
                  id="payment_method"
                  @change="validateField('payment_method')"
                  @blur="validateField('payment_method')"
                >
                  <option value="">Sélectionner une méthode</option>
                  <option value="cash">Espèces</option>
                  <option value="bank_transfer">Virement bancaire</option>
                  <option value="credit_card">Carte de crédit</option>
                  <option value="mobile_money">Mobile Money</option>
                  <option value="orange_money">Orange Money</option>
                  <option value="wave">Wave</option>
                  <option value="check">Chèque</option>
                </select>
                <div v-if="errors.payment_method" class="invalid-feedback">
                  {{ errors.payment_method }}
                </div>
                <div v-if="clientErrors.payment_method" class="invalid-feedback">
                  {{ clientErrors.payment_method }}
                </div>
              </div>
            </div>
            <div class="row g-3 mt-2">
              <div class="col-12 col-md-6">
                <label for="expense_date" class="form-label">
                  Date de la dépense <span class="text-danger">*</span>
                </label>
                <input
                  v-model="form.expense_date"
                  type="date"
                  class="form-control"
                  :class="{ 'is-invalid': errors.expense_date || clientErrors.expense_date }"
                  id="expense_date"
                  @change="validateField('expense_date')"
                  @blur="validateField('expense_date')"
                />
                <div v-if="errors.expense_date" class="invalid-feedback">
                  {{ errors.expense_date }}
                </div>
                <div v-if="clientErrors.expense_date" class="invalid-feedback">
                  {{ clientErrors.expense_date }}
                </div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">
                  <i class="bi bi-building me-1 text-muted"></i>
                  Fournisseur <span class="text-muted fw-normal">(facultatif)</span>
                </label>
                <SupplierCombobox
                  ref="supplierComboboxRef"
                  v-model="form.supplier_id"
                  :suppliers="localSuppliers"
                  placeholder="Rechercher ou ajouter un fournisseur..."
                  :is-invalid="!!errors.supplier_id"
                  :allow-create="canCreateSupplier"
                  @created="handleSupplierCreated"
                />
                <div v-if="errors.supplier_id" class="invalid-feedback d-block">
                  {{ errors.supplier_id }}
                </div>
              </div>
            </div>
          </FormSection>

          <FormSection title="Détails supplémentaires" icon="bi bi-file-text">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label for="receipt_number" class="form-label">Numéro de reçu/facture</label>
                <input
                  v-model="form.receipt_number"
                  type="text"
                  class="form-control"
                  :class="{ 'is-invalid': errors.receipt_number || clientErrors.receipt_number }"
                  id="receipt_number"
                  placeholder="Ex: FAC-2025-001"
                  @input="validateField('receipt_number')"
                  @blur="validateField('receipt_number')"
                />
                <div v-if="errors.receipt_number" class="invalid-feedback">
                  {{ errors.receipt_number }}
                </div>
                <div v-if="clientErrors.receipt_number" class="invalid-feedback">
                  {{ clientErrors.receipt_number }}
                </div>
              </div>
            </div>
            <div class="row g-3 mt-2">
              <div class="col-12">
                <label for="description" class="form-label">Description</label>
                <textarea
                  v-model="form.description"
                  class="form-control"
                  :class="{ 'is-invalid': errors.description || clientErrors.description }"
                  id="description"
                  rows="3"
                  placeholder="Description détaillée de la dépense..."
                  @input="validateField('description')"
                  @blur="validateField('description')"
                ></textarea>
                <div v-if="errors.description" class="invalid-feedback">
                  {{ errors.description }}
                </div>
                <div v-if="clientErrors.description" class="invalid-feedback">
                  {{ clientErrors.description }}
                </div>
              </div>
            </div>
            <div class="row g-3 mt-2">
              <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea
                  v-model="form.notes"
                  class="form-control"
                  :class="{ 'is-invalid': errors.notes || clientErrors.notes }"
                  id="notes"
                  rows="2"
                  placeholder="Notes additionnelles..."
                  @input="validateField('notes')"
                  @blur="validateField('notes')"
                ></textarea>
                <div v-if="errors.notes" class="invalid-feedback">
                  {{ errors.notes }}
                </div>
                <div v-if="clientErrors.notes" class="invalid-feedback">
                  {{ clientErrors.notes }}
                </div>
              </div>
            </div>
          </FormSection>

          <FormSection title="Justificatifs" icon="bi bi-paperclip">
            <AttachmentUploader
              v-model="pendingFiles"
              :attachments="expense.attachments || []"
              :max-files="attachmentConfig.maxFiles"
              :max-size-kb="attachmentConfig.maxSizeKb"
              :accept="attachmentConfig.accept"
              hint="Les nouveaux fichiers seront ajoutés à l'enregistrement. Ils ne sont pas sauvegardés dans le brouillon."
              @preview="openPreview"
            />
            <div v-if="errors.attachments" class="text-danger small mt-2">
              {{ errors.attachments }}
            </div>
          </FormSection>

          <FormActionsBar class="form-actions-bar--split">
            <Link
              :href="route('expenses.index')"
              class="btn btn-outline-secondary"
            >
              Annuler
            </Link>
            <button
              type="button"
              @click="submit"
              class="btn btn-primary"
              :disabled="processing"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Modification...' : 'Modifier la dépense' }}
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
import { Link, useForm } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import { route } from '@/lib/routes'
import { useDocumentPreview } from '@/composables/useDocumentPreview'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useFormDraft } from '@/composables/useFormDraft'
import { formatDateForInput } from '@/utils/dateFormatter'
import DraftSaveStatus from '@/components/drafts/DraftSaveStatus.vue'
import DraftRestoreDialog from '@/components/drafts/DraftRestoreDialog.vue'
import AttachmentUploader from '@/components/attachments/AttachmentUploader.vue'
import SupplierCombobox from '@/components/forms/SupplierCombobox.vue'
import type { SupplierOption } from '@/types/supplier'
import { usePermissions } from '@/composables/usePermissions'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'
import type { AttachmentConfig, AttachmentRecord } from '@/types/attachment'

interface User {
  id: number
  name: string
  email: string
}

interface ExpenseFormSupplier {
  id: number
  name: string
  email?: string | null
  phone?: string | null
  mobile?: string | null
}

interface Expense {
  id: number
  expense_number: string
  title: string
  description?: string
  amount: number
  category: string
  payment_method: string
  expense_date: string
  receipt_number?: string
  vendor?: string
  supplier_id?: number | null
  supplier?: ExpenseFormSupplier | null
  notes?: string
  user: User
  attachments?: AttachmentRecord[]
  created_at: string
  updated_at: string
}

interface Props {
  expense: Expense
  suppliers: ExpenseFormSupplier[]
  attachmentConfig: AttachmentConfig
}

const props = defineProps<Props>()
const { canCreate } = usePermissions()
const canCreateSupplier = canCreate('suppliers')
const { success, error } = useSweetAlert()

const localSuppliers = ref<ExpenseFormSupplier[]>([...props.suppliers])
const supplierComboboxRef = ref<InstanceType<typeof SupplierCombobox> | null>(null)

const handleSupplierCreated = (supplier: SupplierOption) => {
  const normalizedSupplier: ExpenseFormSupplier = {
    id: supplier.id,
    name: supplier.name,
    email: supplier.email ?? null,
    phone: supplier.phone ?? supplier.mobile ?? null,
  }

  const exists = localSuppliers.value.some((item) => item.id === normalizedSupplier.id)
  if (!exists) {
    localSuppliers.value = [...localSuppliers.value, normalizedSupplier].sort((left, right) =>
      left.name.localeCompare(right.name, 'fr'),
    )
  }

  form.supplier_id = normalizedSupplier.id
  supplierComboboxRef.value?.selectSupplier(normalizedSupplier)
}

// Form data
const form = useForm({
  title: props.expense.title,
  description: props.expense.description || '',
  amount: props.expense.amount,
  category: props.expense.category,
  payment_method: props.expense.payment_method,
  expense_date: formatDateForInput(props.expense.expense_date),
  receipt_number: props.expense.receipt_number || '',
  supplier_id: props.expense.supplier_id ?? null,
  notes: props.expense.notes || ''
})

const expenseEditBaseline = { ...form.data() } as Record<string, unknown>

const draft = useFormDraft({
  formType: 'expense',
  mode: 'edit',
  entityId: props.expense.id,
  watchSource: form,
  getData: () => form.data() as Record<string, unknown>,
  restoreData: (data) => {
    restoreInertiaFormData(form as unknown as Record<string, unknown>, data)
    form.expense_date = formatDateForInput(form.expense_date as string)
    if (form.supplier_id) {
      const supplier = localSuppliers.value.find((item) => item.id === form.supplier_id)
      if (supplier) {
        supplierComboboxRef.value?.selectSupplier(supplier)
      }
    } else {
      supplierComboboxRef.value?.clear()
    }
  },
  getBaseline: () => expenseEditBaseline,
})

// Client-side validation errors
const clientErrors = ref<Record<string, string>>({})
const pendingFiles = ref<File[]>([])
const { openAttachment } = useDocumentPreview()

const attachmentConfig = props.attachmentConfig

// Validation functions
const validateRequired = (value: any): boolean => {
  if (typeof value === 'string') {
    return value.trim() !== ''
  }
  if (typeof value === 'number') {
    return value !== null && value !== undefined && value !== 0
  }
  return value !== null && value !== undefined
}

const validateForm = (): Record<string, string> => {
  const errors: Record<string, string> = {}

  if (!validateRequired(form.title)) {
    errors.title = 'Le titre est requis'
  }

  if (!validateRequired(form.amount)) {
    errors.amount = 'Le montant est requis'
  } else if (form.amount < 0) {
    errors.amount = 'Le montant doit être positif'
  }

  if (!validateRequired(form.category)) {
    errors.category = 'La catégorie est requise'
  }

  if (!validateRequired(form.payment_method)) {
    errors.payment_method = 'La méthode de paiement est requise'
  }

  if (!validateRequired(form.expense_date)) {
    errors.expense_date = 'La date de dépense est requise'
  }

  return errors
}

const validateField = (fieldName: string) => {
  const value = form[fieldName as keyof typeof form]
  let errorMessage = ''

  switch (fieldName) {
    case 'title':
      if (!validateRequired(value)) {
        errorMessage = 'Le titre est requis'
      }
      break
    case 'amount':
      if (!validateRequired(value)) {
        errorMessage = 'Le montant est requis'
      } else if (form.amount < 0) {
        errorMessage = 'Le montant doit être positif'
      }
      break
    case 'category':
      if (!validateRequired(value)) {
        errorMessage = 'La catégorie est requise'
      }
      break
    case 'payment_method':
      if (!validateRequired(value)) {
        errorMessage = 'La méthode de paiement est requise'
      }
      break
    case 'expense_date':
      if (!validateRequired(value)) {
        errorMessage = 'La date de dépense est requise'
      }
      break
  }

  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  } else {
    delete clientErrors.value[fieldName]
  }
}

const submit = () => {
  // Clear previous client errors
  clientErrors.value = {}
  
  // Validate form
  const validationErrors = validateForm()
  
  if (Object.keys(validationErrors).length > 0) {
    clientErrors.value = validationErrors
    return
  }

  form.transform((data) => {
    const formData = new FormData()
    const formDataObj = data as Record<string, unknown>

    Object.keys(formDataObj).forEach((key) => {
      if (key === 'supplier_id') {
        return
      }

      const value = formDataObj[key]
      if (value !== null && value !== undefined && value !== '') {
        formData.append(key, String(value))
      }
    })

    formData.append(
      'supplier_id',
      formDataObj.supplier_id != null && formDataObj.supplier_id !== ''
        ? String(formDataObj.supplier_id)
        : '',
    )

    pendingFiles.value.forEach((file) => {
      formData.append('attachments[]', file)
    })

    formData.append('_method', 'PUT')

    return formData
  }).post(route('expenses.update', { id: props.expense.id }), {
    forceFormData: true,
    onSuccess: async () => {
      await draft.markSubmitted()
      success('Dépense modifiée avec succès !')
    },
    onError: () => {
      error('Erreur lors de la modification de la dépense.')
    }
  })
}

const openPreview = (attachment: AttachmentRecord) => {
  void openAttachment(attachment, `Aperçu — ${attachment.original_name}`)
}

const { errors, processing } = form

onMounted(() => {
  if (form.supplier_id) {
    const supplier = localSuppliers.value.find((item) => item.id === form.supplier_id)
    if (supplier) {
      supplierComboboxRef.value?.selectSupplier(supplier)
    }
  }
})
</script>
