<template>
  <div class="inventory-detail">
    <PageHeader
      :title="sessionTitle"
      icon="bi-clipboard-check"
    >
      <template #intro>
        <h1 class="h2 mb-1">
          <i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>
          {{ sessionTitle }}
        </h1>
        <p class="page-header__subtitle text-muted mb-0">{{ sessionSubtitle }}</p>
      </template>
      <template #actions-primary>
        <InventoryStatusBadge :status="session.status" />
      </template>
      <template #actions-secondary>
        <Link :href="inventoryIndexUrl" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>
          Retour
        </Link>
      </template>
    </PageHeader>

    <div v-if="flashMessage" class="alert" :class="flashMessageClass" role="alert">
      {{ flashMessage }}
    </div>

    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="row g-3 align-items-start">
          <div class="col-md-8">
            <div class="small inventory-detail__meta-label">Référence</div>
            <div class="fw-semibold font-monospace inventory-detail__meta-value">{{ session.reference ?? '—' }}</div>

            <div class="mt-3 small inventory-detail__meta-label">Magasin</div>
            <div class="inventory-detail__meta-value">{{ session.store?.name ?? '—' }}</div>

            <div class="mt-3 small inventory-detail__meta-label">Périmètre</div>
            <div class="inventory-detail__meta-value">{{ scopeLabel }}</div>

            <div v-if="sessionDescription" class="mt-3">
              <div class="small inventory-detail__meta-label">Description</div>
              <div class="inventory-detail__description inventory-detail__meta-value">{{ sessionDescription }}</div>
            </div>
          </div>
          <div v-if="session.history" class="col-md-4 small">
            <div><span class="text-muted">Créateur :</span> {{ session.history.created_by ?? '—' }}</div>
            <div v-if="session.history.validated_by"><span class="text-muted">Validateur :</span> {{ session.history.validated_by }}</div>
            <div v-if="session.history.applied_by"><span class="text-muted">Applicateur :</span> {{ session.history.applied_by }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 inventory-detail__progress-sticky">
      <div class="card-body">
        <InventoryProgress
          :counted="progress.counted"
          :total="progress.total"
          :percentage="progress.percentage"
          :uncounted="progress.uncounted"
          :with-variance="summary.positive_variances + summary.negative_variances"
          :conforme="summary.zero_variances"
          :total-units="summary.total_units"
        />
      </div>
    </div>

    <div v-if="statusBanner" class="alert mb-4" :class="statusBanner.className">
      <strong>{{ statusBanner.title }}</strong>
      <div class="small mt-1">{{ statusBanner.text }}</div>
    </div>

    <div v-if="showReviewSummary" class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <h2 class="h5 mb-3">Résumé</h2>
        <div class="row g-3">
          <div class="col-6 col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Produits</div><div class="fs-4 fw-semibold">{{ progress.total }}</div></div></div>
          <div class="col-6 col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Comptés</div><div class="fs-4 fw-semibold">{{ progress.counted }}</div></div></div>
          <div class="col-6 col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Conformes</div><div class="fs-4 fw-semibold text-success">{{ summary.zero_variances }}</div></div></div>
          <div class="col-6 col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Manques / Surplus</div><div class="fs-6 fw-semibold"><span class="text-danger">{{ summary.negative_variances }}</span> / <span class="text-warning-emphasis">{{ summary.positive_variances }}</span></div></div></div>
        </div>
      </div>
    </div>

    <div v-if="session.status === 'counting' && session.permissions?.count" class="row g-4 mb-4">
      <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h6 mb-0">Scanner</h2>
              <span class="badge" :class="scanning ? 'bg-warning text-dark' : 'bg-success'">
                {{ scannerReadyMessage }}
              </span>
            </div>
            <BarcodeInput
              ref="barcodeInputRef"
              v-model="barcodeValue"
              label="Scanner le code-barres"
              placeholder="Scanner ou saisir un code-barres…"
              :loading="scanning"
              :disabled="scanning || workflowLoading"
              :feedback-state="feedbackState"
              :feedback-message="feedbackMessage"
              autofocus
              @submit="handleBarcodeScan"
            />
            <InventoryLastScan :scan="lastScanDisplay" class="mt-3" />
          </div>
        </div>
      </div>
      <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body d-flex flex-column">
            <h2 class="h6">Actions de comptage</h2>
            <p class="small text-muted">
              Enchaînez vos scans sans cliquer. Terminez lorsque tous les produits sont comptés (0 est valide).
            </p>
            <div class="mt-auto d-flex flex-wrap gap-2">
              <button
                type="button"
                class="btn btn-primary btn-lg"
                :disabled="!canSubmitNow || workflowLoading || !session.permissions?.submit || !session.can_submit"
                @click="handleSubmit"
              >
                <span v-if="workflowLoading" class="spinner-border spinner-border-sm me-2"></span>
                Terminer le comptage
              </button>
              <button
                v-if="session.permissions?.cancel"
                type="button"
                class="btn btn-outline-danger"
                :disabled="workflowLoading"
                @click="handleCancel"
              >
                Annuler
              </button>
            </div>
            <div v-if="progress.uncounted > 0" class="alert alert-warning mt-3 mb-0 small">
              {{ progress.uncounted }} produit(s) non compté(s). Vous devez compter tous les produits avant de terminer l'inventaire.
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <button v-if="session.status === 'draft' && session.permissions?.create" type="button" class="btn btn-primary" :disabled="workflowLoading" @click="handleStart">
        Démarrer l'inventaire
      </button>
      <button v-if="session.status === 'review' && session.permissions?.validate && session.can_validate" type="button" class="btn btn-success" :disabled="workflowLoading" @click="handleValidate">
        Valider l'inventaire
      </button>
      <button v-if="session.status === 'review' && session.permissions?.review" type="button" class="btn btn-outline-secondary" :disabled="workflowLoading" @click="handleReopen">
        Rouvrir le comptage
      </button>
      <button v-if="showApplyButton" type="button" class="btn btn-warning" :disabled="workflowLoading" @click="handleApply">
        Appliquer au stock
      </button>
      <button v-if="showCloseButton" type="button" class="btn btn-outline-secondary" :disabled="workflowLoading" @click="handleClose">
        Clôturer l'inventaire
      </button>
      <button
        v-if="canCancel && session.permissions?.cancel"
        type="button"
        class="btn btn-outline-danger ms-auto"
        :disabled="workflowLoading"
        @click="handleCancel"
      >
        Annuler
      </button>
    </div>

    <div v-if="session.application_preview && session.status === 'validated'" class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <h2 class="h6 mb-3">Ajustements estimés (stock courant)</h2>
        <div class="row g-3 small">
          <div class="col-6 col-md-3">Produits concernés : <strong>{{ session.application_preview.adjusted_items }}</strong></div>
          <div class="col-6 col-md-3">Inchangés : <strong>{{ session.application_preview.unchanged_items }}</strong></div>
          <div class="col-6 col-md-3">Entrées : <strong class="text-success">+{{ session.application_preview.total_positive_quantity }}</strong></div>
          <div class="col-6 col-md-3">Net : <strong>{{ session.application_preview.net_adjustment >= 0 ? '+' : '' }}{{ session.application_preview.net_adjustment }}</strong></div>
        </div>
      </div>
    </div>

    <div v-if="session.application_summary && ['applied', 'closed'].includes(session.status)" class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <h2 class="h6 mb-3">Résultat de l'application</h2>
        <ul class="mb-0">
          <li>{{ session.application_summary.adjusted_items }} produit(s) ajusté(s)</li>
          <li>{{ session.application_summary.unchanged_items }} inchangé(s)</li>
          <li>+{{ session.application_summary.total_positive_quantity }} / −{{ session.application_summary.total_negative_quantity }} unités</li>
          <li>Net : {{ session.application_summary.net_adjustment >= 0 ? '+' : '' }}{{ session.application_summary.net_adjustment }}</li>
        </ul>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <InventoryProductList
          :items="displayItems"
          :search-query="searchQuery"
          :filter-value="isReviewLike ? reviewFilter : countingFilter"
          :filter-options="filterOptions"
          :highlighted-item-id="lastScannedItemId"
          :show-manual-edit="canManualEdit"
          :show-variance-hint="true"
          @update:search-query="searchQuery = $event"
          @update:filter-value="updateFilter"
          @edit-quantity="openQuantityModal"
          @search-focus="searchInputFocused = true"
          @search-blur="onSearchBlur"
        />
      </div>
    </div>

    <InventoryQuantityModal
      :show="quantityModalOpen"
      :item="quantityModalItem"
      :saving="quantitySaving"
      :error-message="quantityError"
      @close="handleQuantityModalClose"
      @save="saveManualQuantity"
    />
  </div>
</template>

<script setup lang="ts">
import BarcodeInput from '@/components/BarcodeInput.vue'
import InventoryLastScan from '@/components/inventory/InventoryLastScan.vue'
import InventoryProductList from '@/components/inventory/InventoryProductList.vue'
import InventoryProgress from '@/components/inventory/InventoryProgress.vue'
import InventoryQuantityModal from '@/components/inventory/InventoryQuantityModal.vue'
import InventoryStatusBadge from '@/components/inventory/InventoryStatusBadge.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { route } from '@/lib/routes'
import {
  inventoryApplicationConfirmMessage,
  shouldShowApplyButton,
  shouldShowCloseButton,
} from '@/utils/inventoryApplication'
import {
  applyScanToItems,
  filterInventoryItems,
  formatScanSuccessMessage,
  inventoryCountProgress,
  inventorySessionSummary,
  shouldRefocusInventoryScanner,
  type InventoryCountingItem,
  type InventoryItemFilter,
} from '@/utils/inventoryCounting'
import {
  buildApplyConfirmDetails,
  buildValidateConfirmDetails,
  canShowManualQuantityEdit,
  filterInventoryReviewItems,
  getInventoryScopeLabel,
  getScannerReadyMessage,
  mapInventoryNetworkScanError,
  mapInventoryScanError,
  formatInventorySessionHeaderSubtitle,
  formatInventorySessionTitle,
  normalizeInventoryDescription,
  resolveInventoryInertiaFlashMessage,
  scrollInventoryViewToTop,
  type InventoryReviewFilter,
} from '@/utils/inventoryUi'
import { waitForInventoryModalHidden } from '@/utils/inventoryModalFocus'
import { buildInventoryListQueryParams, type InventoryListFilters } from '@/utils/inventoryListFilters'
import type { InventoryApplicationSummary } from '@/utils/inventoryApplication'
import type { InventorySessionSummary } from '@/utils/inventoryCounting'
import { Link, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

type SessionPermissions = {
  count: boolean
  submit: boolean
  review: boolean
  validate: boolean
  apply: boolean
  close: boolean
  cancel: boolean
  create: boolean
}

type InventorySessionDetail = {
  id: number
  reference: string | null
  name: string | null
  description?: string | null
  status: string
  scope_type: string
  store: { id: number; name: string }
  items: InventoryCountingItem[]
  progress: { total: number; counted: number; uncounted: number; percentage: number }
  summary: InventorySessionSummary
  application_preview?: InventoryApplicationSummary | null
  application_summary?: InventoryApplicationSummary | null
  can_submit: boolean
  can_validate: boolean
  can_apply: boolean
  can_close: boolean
  history?: Record<string, string | null | undefined>
  permissions: SessionPermissions
}

const props = defineProps<{
  session: InventorySessionDetail
  listFilters?: InventoryListFilters
}>()

const inventoryIndexUrl = computed(() => route('inventory.index', buildInventoryListQueryParams(props.listFilters)))

const page = usePage()
const session = ref(props.session)
const items = ref<InventoryCountingItem[]>(props.session.items)
const barcodeInputRef = ref<InstanceType<typeof BarcodeInput> | null>(null)
const barcodeValue = ref('')
const scanning = ref(false)
const workflowLoading = ref(false)
const feedbackState = ref<'idle' | 'loading' | 'success' | 'not-found' | 'error'>('idle')
const feedbackMessage = ref('')
const searchQuery = ref('')
const searchInputFocused = ref(false)
const countingFilter = ref<InventoryItemFilter>('all')
const reviewFilter = ref<InventoryReviewFilter>('all')
const lastScannedItemId = ref<number | null>(null)
const lastScanDisplay = ref<{ product: { name: string; barcode: string | null; image_url?: string | null }; quantityCounted: number; increment: number | null; scannedAt?: string } | null>(null)
const flashMessage = ref('')
const flashMessageClass = ref('alert-success')
const quantityModalOpen = ref(false)
const quantityModalItem = ref<InventoryCountingItem | null>(null)
const quantitySaving = ref(false)
const quantityError = ref('')

const { confirmWithDetails, confirm, success, error } = useSweetAlert()

watch(() => props.session, (value) => {
  session.value = value
  items.value = value.items
})

onMounted(async () => {
  const resolved = resolveInventoryInertiaFlashMessage(
    page.props.flash as { success?: string | null; error?: string | null } | undefined,
  )

  if (!resolved) {
    return
  }

  flashMessage.value = resolved.message
  flashMessageClass.value = resolved.className
  await nextTick()
  scrollInventoryViewToTop()
})

const sessionTitle = computed(() => formatInventorySessionTitle(session.value.name, session.value.reference))
const sessionDescription = computed(() => normalizeInventoryDescription(session.value.description))
const scopeLabel = computed(() => getInventoryScopeLabel(session.value.scope_type))
const sessionSubtitle = computed(() => formatInventorySessionHeaderSubtitle(
  session.value.reference,
  session.value.store?.name,
  scopeLabel.value,
))
const progress = computed(() => inventoryCountProgress(items.value))
const summary = computed(() => inventorySessionSummary(items.value))
const scannerReadyMessage = computed(() => getScannerReadyMessage(scanning.value, workflowLoading.value))
const canSubmitNow = computed(() => progress.value.uncounted === 0 && session.value.status === 'counting')
const canManualEdit = computed(() => canShowManualQuantityEdit(session.value.status, session.value.permissions?.count ?? false))
const canCancel = computed(() => ['draft', 'counting', 'review', 'validated'].includes(session.value.status))
const showReviewSummary = computed(() => ['review', 'validated', 'applied', 'closed'].includes(session.value.status))
const showApplyButton = computed(() => shouldShowApplyButton(session.value.status, session.value.can_apply, session.value.permissions?.apply ?? false))
const showCloseButton = computed(() => shouldShowCloseButton(session.value.status, session.value.can_close, session.value.permissions?.close ?? false))
const isReviewLike = computed(() => ['review', 'validated', 'applied', 'closed'].includes(session.value.status))
const filterOptions = computed(() => isReviewLike.value
  ? [
      { value: 'all', label: 'Tous' },
      { value: 'conforme', label: 'Conformes' },
      { value: 'manque', label: 'Manques' },
      { value: 'surplus', label: 'Surplus' },
    ]
  : [
      { value: 'all', label: 'Tous' },
      { value: 'uncounted', label: 'Non comptés' },
      { value: 'counted', label: 'Comptés' },
      { value: 'with_variance', label: 'Avec écart' },
      { value: 'without_variance', label: 'Sans écart' },
    ])

function updateFilter(value: string): void {
  if (isReviewLike.value) {
    reviewFilter.value = value as InventoryReviewFilter
  } else {
    countingFilter.value = value as InventoryItemFilter
  }
}
const displayItems = computed(() => isReviewLike.value
  ? filterInventoryReviewItems(items.value, searchQuery.value, reviewFilter.value)
  : filterInventoryItems(items.value, searchQuery.value, countingFilter.value))

const statusBanner = computed(() => {
  switch (session.value.status) {
    case 'validated':
      return { className: 'alert-info', title: 'Inventaire prêt à être appliqué', text: 'Le stock réel n\'a pas encore été modifié.' }
    case 'applied':
      return { className: 'alert-success', title: 'Inventaire appliqué', text: 'Le stock réel a été mis à jour.' }
    case 'closed':
      return { className: 'alert-secondary', title: 'Inventaire clôturé', text: 'Consultation en lecture seule.' }
    default:
      return null
  }
})

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
}

function refocusScannerIfAllowed(): void {
  if (!shouldRefocusInventoryScanner({
    status: session.value.status,
    canCount: session.value.permissions?.count ?? false,
    quantityModalOpen: quantityModalOpen.value,
    searchInputFocused: searchInputFocused.value,
    workflowLoading: workflowLoading.value,
    scanning: scanning.value,
  })) {
    return
  }

  void nextTick(() => {
    barcodeInputRef.value?.focus()
  })
}

function onSearchBlur(): void {
  searchInputFocused.value = false
  refocusScannerIfAllowed()
}

async function postWorkflow(url: string, successText?: string): Promise<boolean> {
  if (workflowLoading.value) return false
  workflowLoading.value = true
  flashMessage.value = ''
  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
      credentials: 'same-origin',
    })
    const payload = await response.json() as { message?: string; session?: InventorySessionDetail; errors?: Record<string, string[]> }
    if (!response.ok || !payload.session) {
      flashMessageClass.value = 'alert-danger'
      flashMessage.value = payload.message ?? payload.errors?.message?.[0] ?? 'Action impossible.'
      await error(flashMessage.value)
      return false
    }
    session.value = payload.session
    items.value = payload.session.items
    flashMessageClass.value = 'alert-success'
    flashMessage.value = payload.message ?? successText ?? 'Action réussie.'
    scrollInventoryViewToTop()
    return true
  } catch {
    flashMessageClass.value = 'alert-danger'
    flashMessage.value = 'Erreur réseau.'
    await error(flashMessage.value)
    return false
  } finally {
    workflowLoading.value = false
  }
}

async function handleStart(): Promise<void> {
  const ok = await postWorkflow(route('inventory.start', { session: session.value.id }), 'Inventaire démarré.')
  if (ok) {
    refocusScannerIfAllowed()
  }
}

async function handleSubmit(): Promise<void> {
  if (!canSubmitNow.value) {
    await error(`${progress.value.uncounted} produit(s) ne sont pas encore comptés.`)
    refocusScannerIfAllowed()
    return
  }
  const ok = await confirm('Terminer le comptage et passer en revue ?')
  if (!ok) {
    refocusScannerIfAllowed()
    return
  }
  await postWorkflow(route('inventory.submit', { session: session.value.id }), 'Inventaire soumis.')
}

async function handleValidate(): Promise<void> {
  const ok = await confirmWithDetails(
    'Cette action prépare l\'application des écarts au stock.',
    'Valider cet inventaire ?',
    buildValidateConfirmDetails({ total: progress.value.total, ...summary.value }),
  )
  if (!ok) return
  await postWorkflow(route('inventory.validate', { session: session.value.id }), 'Inventaire validé.')
}

async function handleApply(): Promise<void> {
  const preview = session.value.application_preview
  const ok = await confirmWithDetails(
    'Cette action modifiera le stock réel. Les écarts seront enregistrés dans l\'historique du stock.',
    'Appliquer cet inventaire ?',
    preview ? buildApplyConfirmDetails(preview) : inventoryApplicationConfirmMessage(0),
  )
  if (!ok) return
  await postWorkflow(route('inventory.apply', { session: session.value.id }), 'Inventaire appliqué avec succès.')
}

async function handleClose(): Promise<void> {
  if (!await confirm('Clôturer définitivement cet inventaire ?')) return
  await postWorkflow(route('inventory.close', { session: session.value.id }), 'Inventaire clôturé.')
}

async function handleReopen(): Promise<void> {
  if (!await confirm('Rouvrir le comptage ?')) return
  const ok = await postWorkflow(route('inventory.reopen', { session: session.value.id }), 'Comptage rouvert.')
  if (ok) {
    refocusScannerIfAllowed()
  }
}

async function handleCancel(): Promise<void> {
  const ok = await confirmWithDetails(
    'Les données de comptage seront conservées dans l\'historique mais aucun stock ne sera modifié.',
    'Annuler cet inventaire ?',
  )
  if (!ok) return
  await postWorkflow(route('inventory.cancel', { session: session.value.id }), 'Inventaire annulé.')
}

async function handleBarcodeScan(barcode: string): Promise<void> {
  if (!session.value || scanning.value || session.value.status !== 'counting') return
  scanning.value = true
  feedbackState.value = 'loading'
  feedbackMessage.value = 'Enregistrement…'
  try {
    const response = await fetch(route('inventory.scan', { session: session.value.id }), {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
      credentials: 'same-origin',
      body: JSON.stringify({ barcode }),
    })
    const payload = await response.json() as { success?: boolean; product?: { name: string; barcode: string | null; image_url?: string | null }; item?: InventoryCountingItem; message?: string; errors?: Record<string, string[]> }
    if (!response.ok || !payload.success || !payload.product || !payload.item) {
      feedbackState.value = 'error'
      feedbackMessage.value = mapInventoryScanError(payload)
      refocusScannerIfAllowed()
      return
    }
    const previousItem = items.value.find((item) => item.id === payload.item!.id)
    const previousQuantity = previousItem?.quantity_counted
    items.value = applyScanToItems(items.value, payload.item)
    lastScannedItemId.value = payload.item.id
    lastScanDisplay.value = {
      product: payload.product,
      quantityCounted: payload.item.quantity_counted ?? 0,
      increment:
        previousQuantity !== null && previousQuantity !== undefined
          ? (payload.item.quantity_counted ?? 0) - previousQuantity
          : null,
      scannedAt: new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }),
    }
    feedbackState.value = 'success'
    feedbackMessage.value = `✓ ${formatScanSuccessMessage(payload.product.name, payload.item.quantity_counted ?? 0)}`
    refocusScannerIfAllowed()
  } catch {
    feedbackState.value = 'error'
    feedbackMessage.value = mapInventoryNetworkScanError()
    refocusScannerIfAllowed()
  } finally {
    scanning.value = false
  }
}

function openQuantityModal(item: InventoryCountingItem): void {
  quantityModalItem.value = item
  quantityError.value = ''
  quantityModalOpen.value = true
}

function closeQuantityModal(): void {
  quantityModalOpen.value = false
  quantityModalItem.value = null
}

async function finalizeQuantityModalClose(): Promise<void> {
  closeQuantityModal()
  await waitForInventoryModalHidden()
  refocusScannerIfAllowed()
}

function handleQuantityModalClose(): void {
  void finalizeQuantityModalClose()
}

async function saveManualQuantity(quantity: number): Promise<void> {
  if (!quantityModalItem.value || quantitySaving.value) return
  quantitySaving.value = true
  quantityError.value = ''
  try {
    const response = await fetch(route('inventory.items.count', { session: session.value.id, item: quantityModalItem.value.id }), {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
      credentials: 'same-origin',
      body: JSON.stringify({ quantity_counted: quantity }),
    })
    const payload = await response.json() as { item?: InventoryCountingItem; message?: string; errors?: Record<string, string[]> }
    if (!response.ok || !payload.item) {
      quantityError.value = payload.message ?? payload.errors?.quantity_counted?.[0] ?? 'Enregistrement impossible.'
      return
    }
    items.value = applyScanToItems(items.value, payload.item)
    lastScannedItemId.value = payload.item.id
    closeQuantityModal()
    await waitForInventoryModalHidden()
    await success('Produit compté')
    refocusScannerIfAllowed()
  } catch {
    quantityError.value = mapInventoryNetworkScanError()
  } finally {
    quantitySaving.value = false
  }
}
</script>

<style scoped>
.inventory-detail__meta-label {
  color: var(--color-text-muted);
}

.inventory-detail__meta-value {
  color: var(--color-text-primary);
}

.inventory-detail__description {
  white-space: pre-wrap;
  word-break: break-word;
}

.inventory-detail__progress-sticky {
  position: sticky;
  top: 0.75rem;
  z-index: 20;
}

@media (max-width: 991.98px) {
  .inventory-detail__progress-sticky {
    position: static;
  }
}
</style>
