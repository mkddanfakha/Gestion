<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Activité des utilisateurs"
        subtitle="Journal commercial des opérations par utilisateur"
        icon="bi-people-fill"
      />

      <div class="row page-stats g-3 mb-3">
        <div class="col-6 col-lg-3">
          <div class="card bg-primary text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Total activités</h6>
              <h4 class="mb-0">{{ stats.total_activities }}</h4>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="card bg-success text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Ventes</h6>
              <h4 class="mb-0">{{ stats.sales_count }}</h4>
              <small>{{ formatCurrency(stats.sales_amount) }}</small>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="card bg-danger text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Dépenses</h6>
              <h4 class="mb-0">{{ stats.expenses_count }}</h4>
              <small>{{ formatCurrency(stats.expenses_amount) }}</small>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="card bg-info text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Devis / BC / BL</h6>
              <h4 class="mb-0">
                {{ stats.quotes_count }} / {{ stats.purchase_orders_count }} / {{ stats.delivery_notes_count }}
              </h4>
            </div>
          </div>
        </div>
      </div>

      <section class="page-filters card mb-3">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <button
              v-for="(label, key) in periodPresets"
              :key="key"
              type="button"
              class="btn btn-sm"
              :class="filters.period === key ? 'btn-primary' : 'btn-outline-secondary'"
              @click="setPeriod(String(key))"
            >
              {{ label }}
            </button>
          </div>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Recherche</label>
              <input
                v-model="localFilters.search"
                type="text"
                class="form-control"
                placeholder="Référence, client, fournisseur, utilisateur..."
                @input="searchDebounced"
              />
            </div>
            <div class="col-md-2">
              <label class="form-label">Utilisateur</label>
              <select v-model="localFilters.user_id" class="form-select" @change="applyFilters">
                <option value="">Tous les utilisateurs</option>
                <option v-for="user in users" :key="user.id" :value="String(user.id)">
                  {{ user.name }}
                </option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Type d'activité</label>
              <select v-model="localFilters.activity_type" class="form-select" @change="applyFilters">
                <option value="">Toutes les activités</option>
                <option v-for="(label, key) in activityTypes" :key="key" :value="key">
                  {{ label }}
                </option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Du</label>
              <input
                v-model="localFilters.date_from"
                type="date"
                class="form-control"
                :disabled="localFilters.period !== 'custom'"
                @change="onCustomDateChange"
              />
            </div>
            <div class="col-md-2">
              <label class="form-label">Au</label>
              <input
                v-model="localFilters.date_to"
                type="date"
                class="form-control"
                :disabled="localFilters.period !== 'custom'"
                @change="onCustomDateChange"
              />
            </div>
            <div class="col-md-1 d-flex align-items-end">
              <button type="button" class="btn btn-outline-secondary w-100" @click="clearFilters">
                <i class="bi bi-x-circle"></i>
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="card mb-3">
        <div class="card-header fw-semibold">Résumé par utilisateur</div>
        <div class="table-responsive d-none d-md-block">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Utilisateur</th>
                <th class="text-end">Ventes</th>
                <th class="text-end">CA ventes</th>
                <th class="text-end">Dépenses</th>
                <th class="text-end">Devis</th>
                <th class="text-end">BC</th>
                <th class="text-end">BL</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="userSummary.length === 0">
                <td colspan="8" class="text-center text-muted py-3">Aucune activité pour cette période</td>
              </tr>
              <tr v-for="row in userSummary" :key="row.user_id ?? 'unassigned'">
                <td class="fw-medium">{{ row.user_name }}</td>
                <td class="text-end">{{ row.sales_count }}</td>
                <td class="text-end">{{ formatCurrency(row.sales_amount) }}</td>
                <td class="text-end">{{ row.expenses_count }}</td>
                <td class="text-end">{{ row.quotes_count }}</td>
                <td class="text-end">{{ row.purchase_orders_count }}</td>
                <td class="text-end">{{ row.delivery_notes_count }}</td>
                <td class="text-end fw-semibold">{{ row.total_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="d-md-none p-3">
          <div
            v-for="row in userSummary"
            :key="`m-${row.user_id ?? 'unassigned'}`"
            class="border rounded p-3 mb-2"
          >
            <div class="fw-semibold mb-2">{{ row.user_name }}</div>
            <div class="small text-muted">
              Ventes {{ row.sales_count }} ({{ formatCurrency(row.sales_amount) }}) ·
              Dépenses {{ row.expenses_count }} · Devis {{ row.quotes_count }} ·
              BC {{ row.purchase_orders_count }} · BL {{ row.delivery_notes_count }}
            </div>
          </div>
          <div v-if="userSummary.length === 0" class="text-muted text-center">Aucune activité</div>
        </div>
      </section>

      <section class="page-table card">
        <div class="table-responsive d-none d-lg-block">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Heure</th>
                <th>Utilisateur</th>
                <th>Activité</th>
                <th>Référence</th>
                <th>Client / Fournisseur</th>
                <th class="text-end">Montant</th>
                <th>Statut</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="activities.data.length === 0">
                <td colspan="9" class="text-center text-muted py-4">Aucune activité trouvée</td>
              </tr>
              <tr v-for="item in activities.data" :key="item.id">
                <td>{{ item.date || '—' }}</td>
                <td>{{ item.time || '—' }}</td>
                <td class="fw-medium">{{ item.user_name }}</td>
                <td><span class="badge bg-secondary">{{ item.activity_label }}</span></td>
                <td><code class="small">{{ item.reference || '—' }}</code></td>
                <td>{{ item.party_name || '—' }}</td>
                <td class="text-end">
                  <span v-if="item.amount !== null">{{ formatCurrency(item.amount) }}</span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td>{{ formatUserActivityStatus(item.activity_type, item.status) }}</td>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    @click="openActivityDetail(item)"
                  >
                    Voir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="d-lg-none p-3">
          <div
            v-for="item in activities.data"
            :key="`card-${item.id}`"
            class="border rounded p-3 mb-2"
          >
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
              <div class="fw-semibold">{{ item.user_name }}</div>
              <span class="badge bg-secondary">{{ item.activity_label }}</span>
            </div>
            <div class="small text-muted mb-1">
              {{ item.date }} — {{ item.time }}
            </div>
            <div class="mb-1">
              <code>{{ item.reference || '—' }}</code>
              <span v-if="item.party_name"> · {{ item.party_name }}</span>
            </div>
            <div class="small text-muted mb-2">
              Statut : {{ formatUserActivityStatus(item.activity_type, item.status) }}
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <div class="fw-medium">
                <template v-if="item.amount !== null">{{ formatCurrency(item.amount) }}</template>
                <template v-else>—</template>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                @click="openActivityDetail(item)"
              >
                Voir
              </button>
            </div>
          </div>
          <div v-if="activities.data.length === 0" class="text-center text-muted py-3">
            Aucune activité trouvée
          </div>
        </div>

        <PagePagination
          :links="activities.links"
          :from="activities.from ?? activities.meta?.from"
          :to="activities.to ?? activities.meta?.to"
          :total="activities.total ?? activities.meta?.total"
        />
      </section>

      <UserActivityDetailModal
        :open="detailOpen"
        :loading="detailLoading"
        :error="detailError"
        :detail="detailPayload"
        @update:open="onDetailOpenChange"
      />
    </IndexPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import PagePagination from '@/components/page/PagePagination.vue'
import UserActivityDetailModal from '@/components/userActivities/UserActivityDetailModal.vue'
import { router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { route } from '@/lib/routes'
import { debounce } from 'lodash-es'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatUserActivityStatus } from '@/utils/userActivityStatus'
import { useUserActivityDetail } from '@/composables/useUserActivityDetail'

interface ActivityRow {
  id: string
  subject_id: number
  activity_type: string
  activity_label: string
  user_id: number | null
  user_name: string
  reference: string | null
  party_name: string | null
  amount: number | null
  status: string | null
  date: string | null
  time: string | null
  show_route: { name: string; params: Record<string, number> } | null
}

interface UserSummaryRow {
  user_id: number | null
  user_name: string
  sales_count: number
  sales_amount: number
  expenses_count: number
  expenses_amount: number
  quotes_count: number
  purchase_orders_count: number
  delivery_notes_count: number
  inventory_count: number
  total_count: number
}

const props = defineProps<{
  activities: {
    data: ActivityRow[]
    links: unknown
    from?: number
    to?: number
    total?: number
    meta?: { from?: number; to?: number; total?: number }
  }
  stats: Record<string, number>
  userSummary: UserSummaryRow[]
  users: { id: number; name: string }[]
  activityTypes: Record<string, string>
  periodPresets: Record<string, string>
  filters: {
    user_id: number | null
    activity_type: string | null
    period: string
    date_from: string | null
    date_to: string | null
    search: string | null
  }
}>()

const localFilters = reactive({
  search: props.filters.search ?? '',
  user_id: props.filters.user_id ? String(props.filters.user_id) : '',
  activity_type: props.filters.activity_type ?? '',
  period: props.filters.period ?? 'this_month',
  date_from: props.filters.date_from ?? '',
  date_to: props.filters.date_to ?? '',
})

const {
  open: detailOpen,
  loading: detailLoading,
  error: detailError,
  detail: detailPayload,
  openDetail,
  close: closeDetail,
} = useUserActivityDetail()

function openActivityDetail(item: ActivityRow) {
  void openDetail(item.activity_type, item.subject_id)
}

function onDetailOpenChange(value: boolean) {
  if (!value) {
    closeDetail()
  }
}

function applyFilters() {
  router.get(
    route('user-activities.index'),
    {
      search: localFilters.search || undefined,
      user_id: localFilters.user_id || undefined,
      activity_type: localFilters.activity_type || undefined,
      period: localFilters.period || undefined,
      date_from: localFilters.period === 'custom' ? localFilters.date_from || undefined : undefined,
      date_to: localFilters.period === 'custom' ? localFilters.date_to || undefined : undefined,
    },
    { preserveState: true, replace: true },
  )
}

const searchDebounced = debounce(applyFilters, 350)

function setPeriod(period: string) {
  localFilters.period = period
  applyFilters()
}

function onCustomDateChange() {
  localFilters.period = 'custom'
  applyFilters()
}

function clearFilters() {
  localFilters.search = ''
  localFilters.user_id = ''
  localFilters.activity_type = ''
  localFilters.period = 'this_month'
  localFilters.date_from = ''
  localFilters.date_to = ''
  applyFilters()
}
</script>
