<template>
  <div>
    <PageHeader
      title="Inventaire"
      subtitle="Contrôlez et fiabilisez votre stock."
      icon="bi-clipboard-check"
    >
      <template #actions-primary>
        <button
          v-if="permissions.create"
          type="button"
          class="btn btn-primary"
          @click="showCreateModal = true"
        >
          <i class="bi bi-plus-circle me-1"></i>
          Nouvel inventaire
        </button>
      </template>
    </PageHeader>

    <div class="row g-3 mb-4 page-stats">
      <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">Inventaires en cours</div>
            <div class="fs-3 fw-semibold">{{ listStats.active_count }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">À compter</div>
            <div class="fs-3 fw-semibold text-primary">{{ listStats.counting_count }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">À valider / appliquer</div>
            <div class="fs-3 fw-semibold text-warning">{{ listStats.to_validate_count }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">Dernier inventaire</div>
            <div class="fw-semibold">{{ listStats.last_reference ?? '—' }}</div>
            <div class="small text-muted">{{ formatInventoryShortDate(listStats.last_date) }}</div>
          </div>
        </div>
      </div>
    </div>

    <section class="page-filters card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="row g-3 align-items-end">
          <div class="col-12">
            <label class="form-label" for="inventory-list-search">Rechercher</label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-search" aria-hidden="true"></i>
              </span>
              <input
                id="inventory-list-search"
                v-model="filters.search"
                type="search"
                class="form-control"
                placeholder="Rechercher un inventaire..."
                @input="debouncedSearch"
                @keydown.enter.prevent="applyFilters"
              >
            </div>
          </div>

          <div class="col-12 col-md-6 col-lg">
            <label class="form-label" for="inventory-list-status">Statut</label>
            <select
              id="inventory-list-status"
              v-model="filters.status"
              class="form-select"
              @change="applyFilters"
            >
              <option
                v-for="option in INVENTORY_LIST_STATUS_FILTER_OPTIONS"
                :key="`status-${option.value || 'all'}`"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
          </div>

          <div class="col-12 col-md-6 col-lg">
            <label class="form-label" for="inventory-list-scope">Périmètre</label>
            <select
              id="inventory-list-scope"
              v-model="filters.scope_type"
              class="form-select"
              @change="applyFilters"
            >
              <option
                v-for="option in INVENTORY_LIST_SCOPE_FILTER_OPTIONS"
                :key="`scope-${option.value || 'all'}`"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
          </div>

          <div class="col-12 col-md-6 col-lg">
            <label class="form-label" for="inventory-list-category">Catégorie</label>
            <select
              id="inventory-list-category"
              v-model="filters.category_id"
              class="form-select"
              @change="applyFilters"
            >
              <option value="">Toutes</option>
              <option v-for="category in categories" :key="category.id" :value="String(category.id)">
                {{ category.name }}
              </option>
            </select>
          </div>

          <div class="col-12 col-md-6 col-lg">
            <label class="form-label" for="inventory-list-date-from">Date de début</label>
            <input
              id="inventory-list-date-from"
              v-model="filters.date_from"
              type="date"
              class="form-control"
              @change="applyFilters"
            >
          </div>

          <div class="col-12 col-md-6 col-lg">
            <label class="form-label" for="inventory-list-date-to">Date de fin</label>
            <input
              id="inventory-list-date-to"
              v-model="filters.date_to"
              type="date"
              class="form-control"
              @change="applyFilters"
            >
          </div>

          <div class="col-12 col-md-6 col-lg d-grid">
            <button
              v-if="filtersActive"
              type="button"
              class="btn btn-outline-secondary"
              @click="resetFilters"
            >
              <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
              Réinitialiser
            </button>
          </div>
        </div>
      </div>
    </section>

    <div v-if="filtersActive && activeFilterChips.length > 0" class="inventory-list__chips d-flex flex-wrap gap-2 mb-3">
      <button
        v-for="chip in activeFilterChips"
        :key="`${chip.key}-${chip.value}`"
        type="button"
        class="badge rounded-pill inventory-list__chip"
        @click="removeFilter(chip.key)"
      >
        {{ chip.label }} : {{ chip.value }}
        <i class="bi bi-x ms-1" aria-hidden="true"></i>
      </button>
    </div>

    <div v-if="sessions.total != null" class="small text-muted mb-3">
      {{ resultsLabel }}
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <div v-if="!hasSessions" class="text-center text-muted py-5 px-3">
          <i class="bi bi-clipboard2-data fs-1 d-block mb-2"></i>
          Vous n'avez encore aucun inventaire.
        </div>

        <div v-else-if="sessions.data.length === 0" class="text-center text-muted py-5 px-3">
          <i class="bi bi-search fs-1 d-block mb-2"></i>
          Aucun inventaire ne correspond à vos critères.
          <div class="mt-3">
            <button type="button" class="btn btn-outline-secondary" @click="resetFilters">
              Réinitialiser les filtres
            </button>
          </div>
        </div>

        <template v-else>
          <div class="d-none d-lg-block table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Référence</th>
                  <th>Nom</th>
                  <th>Magasin</th>
                  <th>Périmètre</th>
                  <th>Progression</th>
                  <th>Statut</th>
                  <th>Date</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="listSession in sessions.data" :key="listSession.id">
                  <td class="font-monospace">{{ listSession.reference ?? '—' }}</td>
                  <td>
                    <div>{{ listSession.name ?? '—' }}</div>
                    <div
                      v-if="listSessionDescription(listSession)"
                      class="small text-secondary inventory-list__description"
                    >
                      {{ listSessionDescription(listSession) }}
                    </div>
                  </td>
                  <td>{{ listSession.store?.name ?? '—' }}</td>
                  <td>{{ getInventoryScopeLabel(listSession.scope_type) }}</td>
                  <td>
                    <div class="small">{{ listSession.items_counted ?? 0 }} / {{ listSession.items_total ?? 0 }}</div>
                    <div class="text-muted small">
                      {{ formatInventoryListProgress(listSession.items_counted ?? 0, listSession.items_total ?? 0) }}
                    </div>
                  </td>
                  <td><InventoryStatusBadge :status="listSession.status" /></td>
                  <td>{{ formatInventoryShortDate(listSession.created_at) }}</td>
                  <td class="text-end">
                    <Link
                      :href="sessionShowUrl(listSession.id)"
                      class="btn btn-sm"
                      :class="primaryActionClass(listSession.status)"
                    >
                      {{ getInventoryListActionLabel(listSession.status) }}
                    </Link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-lg-none p-3">
            <div
              v-for="listSession in sessions.data"
              :key="`mobile-${listSession.id}`"
              class="border rounded p-3 mb-3"
            >
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                  <div class="fw-semibold">{{ listSession.name ?? listSession.reference }}</div>
                  <div
                    v-if="listSessionDescription(listSession)"
                    class="small text-secondary inventory-list__description"
                  >
                    {{ listSessionDescription(listSession) }}
                  </div>
                  <div class="small text-muted font-monospace">{{ listSession.reference }}</div>
                </div>
                <InventoryStatusBadge :status="listSession.status" />
              </div>
              <div class="small mb-2">
                {{ listSession.store?.name ?? '—' }} · {{ getInventoryScopeLabel(listSession.scope_type) }}
              </div>
              <div class="small text-muted mb-3">
                {{ listSession.items_counted ?? 0 }} / {{ listSession.items_total ?? 0 }} produits ·
                {{ formatInventoryListProgress(listSession.items_counted ?? 0, listSession.items_total ?? 0) }}
              </div>
              <Link
                :href="sessionShowUrl(listSession.id)"
                class="btn w-100"
                :class="primaryActionClass(listSession.status)"
              >
                {{ getInventoryListActionLabel(listSession.status) }}
              </Link>
            </div>
          </div>

          <PagePagination
            :links="sessions.links"
            :from="sessions.from"
            :to="sessions.to"
            :total="sessions.total"
          />
        </template>
      </div>
    </div>

    <InventoryCreateModal
      :show="showCreateModal"
      :categories="categories"
      @close="showCreateModal = false"
    />
  </div>
</template>

<script setup lang="ts">
import InventoryCreateModal from '@/components/inventory/InventoryCreateModal.vue'
import InventoryStatusBadge from '@/components/inventory/InventoryStatusBadge.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import PagePagination from '@/components/page/PagePagination.vue'
import { route } from '@/lib/routes'
import {
  buildInventoryListQueryParams,
  formatInventoryListResultsLabel,
  getInventoryListFilterChips,
  hasActiveInventoryListFilters,
  INVENTORY_LIST_SCOPE_FILTER_OPTIONS,
  INVENTORY_LIST_SEARCH_DEBOUNCE_MS,
  INVENTORY_LIST_STATUS_FILTER_OPTIONS,
  normalizeInventoryListFilters,
  removeInventoryListFilter,
  type InventoryListFilters,
} from '@/utils/inventoryListFilters'
import {
  formatInventoryListProgress,
  formatInventoryShortDate,
  getInventoryListActionLabel,
  getInventoryScopeLabel,
  normalizeInventoryDescription,
  type InventoryListStats,
} from '@/utils/inventoryUi'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

type ListSession = {
  id: number
  reference: string | null
  name: string | null
  description?: string | null
  status: string
  scope_type?: string | null
  created_at: string
  items_total?: number
  items_counted?: number
  store?: { name: string } | null
}

const props = defineProps<{
  sessions: {
    data: ListSession[]
    links?: Array<{ url: string | null; label: string; active: boolean }> | null
    from?: number | null
    to?: number | null
    total?: number | null
  }
  listStats: InventoryListStats
  hasSessions: boolean
  categories: Array<{ id: number; name: string }>
  filters: InventoryListFilters
  permissions: { create: boolean; count: boolean }
}>()

const showCreateModal = ref(false)
const filters = ref<InventoryListFilters>({
  search: '',
  status: '',
  scope_type: '',
  category_id: '',
  date_from: '',
  date_to: '',
})

watch(
  () => props.filters,
  (nextFilters) => {
    filters.value = {
      search: nextFilters.search ?? '',
      status: nextFilters.status ?? '',
      scope_type: nextFilters.scope_type ?? '',
      category_id: nextFilters.category_id ?? '',
      date_from: nextFilters.date_from ?? '',
      date_to: nextFilters.date_to ?? '',
    }
  },
  { immediate: true, deep: true },
)

const filtersActive = computed(() => hasActiveInventoryListFilters(filters.value))
const activeFilterChips = computed(() => getInventoryListFilterChips(filters.value, props.categories))
const resultsLabel = computed(() => formatInventoryListResultsLabel(
  props.sessions.total ?? props.sessions.data.length,
  filtersActive.value,
))

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function applyFilters(): void {
  router.get(route('inventory.index'), buildInventoryListQueryParams(filters.value), {
    preserveState: true,
    replace: true,
  })
}

function debouncedSearch(): void {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }

  searchTimeout = setTimeout(() => {
    applyFilters()
  }, INVENTORY_LIST_SEARCH_DEBOUNCE_MS)
}

function resetFilters(): void {
  filters.value = {
    search: '',
    status: '',
    scope_type: '',
    category_id: '',
    date_from: '',
    date_to: '',
  }
  applyFilters()
}

function removeFilter(key: keyof InventoryListFilters): void {
  filters.value = {
    search: filters.value.search ?? '',
    status: filters.value.status ?? '',
    scope_type: filters.value.scope_type ?? '',
    category_id: filters.value.category_id ?? '',
    date_from: filters.value.date_from ?? '',
    date_to: filters.value.date_to ?? '',
    ...removeInventoryListFilter(filters.value, key),
  }
  applyFilters()
}

function sessionShowUrl(sessionId: number): string {
  return route('inventory.show', {
    session: sessionId,
    ...buildInventoryListQueryParams(filters.value),
  })
}

function primaryActionClass(status: string): string {
  return ['counting', 'review', 'validated', 'draft'].includes(status)
    ? 'btn-primary'
    : 'btn-outline-secondary'
}

function listSessionDescription(listSession: ListSession): string | null {
  return normalizeInventoryDescription(listSession.description)
}
</script>

<style scoped>
.inventory-list__description {
  display: -webkit-box;
  overflow: hidden;
  white-space: pre-wrap;
  word-break: break-word;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}

.inventory-list__chip {
  background-color: var(--color-surface-hover);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border-subtle);
  font-weight: 500;
  padding: 0.5rem 0.75rem;
}

@media (min-width: 992px) {
  .inventory-list__description {
    max-width: 24rem;
  }
}
</style>
