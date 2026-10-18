<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Trésorerie"
        subtitle="Mouvements enregistrés (lecture seule)"
        icon="bi-cash-stack"
      />

      <div class="alert alert-secondary mb-3" role="note">
        <i class="bi bi-info-circle me-2"></i>
        {{ disclaimer }}
      </div>

      <div class="row page-stats g-3 mb-3">
        <div class="col-md-4">
          <div class="card bg-success text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Entrées (période)</h6>
              <h4 class="mb-0">{{ formatCurrency(periodTotals.total_inflows) }}</h4>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card bg-danger text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Sorties (période)</h6>
              <h4 class="mb-0">{{ formatCurrency(periodTotals.total_outflows) }}</h4>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card bg-primary text-white h-100">
            <div class="card-body">
              <h6 class="card-title mb-1">Net (période)</h6>
              <h4 class="mb-0">{{ formatCurrency(periodTotals.net) }}</h4>
            </div>
          </div>
        </div>
      </div>

      <section class="page-filters card mb-3">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-md-3">
              <label class="form-label">Du</label>
              <input v-model="localFilters.date_from" type="date" class="form-control" @change="applyFilters" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Au</label>
              <input v-model="localFilters.date_to" type="date" class="form-control" @change="applyFilters" />
            </div>
            <div class="col-md-4">
              <label class="form-label">Moyen de paiement</label>
              <select v-model="localFilters.payment_method" class="form-select" @change="applyFilters">
                <option v-for="opt in paymentMethods" :key="opt.value || 'all'" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>
            <div class="col-md-2">
              <button type="button" class="btn btn-outline-secondary w-100" @click="clearFilters">
                Réinitialiser
              </button>
            </div>
          </div>
        </div>
      </section>

      <section v-if="totalsByPaymentMethod.length > 0" class="card mb-3">
        <div class="card-header fw-semibold">Totaux par moyen de paiement</div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Moyen</th>
                <th class="text-end">Entrées</th>
                <th class="text-end">Sorties</th>
                <th class="text-end">Net</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in totalsByPaymentMethod" :key="row.method">
                <td>
                  {{ row.label }}
                  <span v-if="!row.known" class="badge bg-warning text-dark ms-1">Non reconnu</span>
                </td>
                <td class="text-end text-success">{{ formatCurrency(row.inflows) }}</td>
                <td class="text-end text-danger">{{ formatCurrency(row.outflows) }}</td>
                <td class="text-end fw-semibold">{{ formatCurrency(row.net) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="page-table card">
        <div class="card-header fw-semibold">Mouvements</div>
        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Libellé</th>
                <th>Moyen</th>
                <th class="text-end">Montant</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="movements.data.length === 0">
                <td colspan="5" class="text-center text-muted py-4">Aucun mouvement pour cette période</td>
              </tr>
              <tr v-for="item in movements.data" :key="item.movement_key">
                <td>{{ formatDate(item.occurred_at) }}</td>
                <td>
                  <span
                    class="badge"
                    :class="badgeClass(item)"
                  >
                    {{ item.type_label }}
                  </span>
                </td>
                <td>{{ item.label }}</td>
                <td>{{ item.payment_method_label }}</td>
                <td class="text-end fw-medium" :class="item.amount >= 0 ? 'text-success' : 'text-danger'">
                  {{ formatSignedAmount(item.amount) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="d-md-none p-3">
          <div
            v-for="item in movements.data"
            :key="`m-${item.movement_key}`"
            class="border rounded p-3 mb-2"
          >
            <div class="d-flex justify-content-between align-items-start mb-1">
              <span class="badge" :class="badgeClass(item)">{{ item.type_label }}</span>
              <span class="fw-semibold" :class="item.amount >= 0 ? 'text-success' : 'text-danger'">
                {{ formatSignedAmount(item.amount) }}
              </span>
            </div>
            <div class="small text-muted mb-1">{{ formatDate(item.occurred_at) }} · {{ item.payment_method_label }}</div>
            <div>{{ item.label }}</div>
          </div>
          <div v-if="movements.data.length === 0" class="text-center text-muted py-3">
            Aucun mouvement
          </div>
        </div>

        <PagePagination
          :links="movements.links"
          :from="movements.from ?? movements.meta?.from"
          :to="movements.to ?? movements.meta?.to"
          :total="movements.total ?? movements.meta?.total"
        />
      </section>
    </IndexPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import PagePagination from '@/components/page/PagePagination.vue'
import { router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { route } from '@/lib/routes'
import { formatCurrency } from '@/utils/currencyFormatter'
import { formatPaymentDateTime } from '@/utils/paymentDateTime'

interface MovementRow {
  movement_key: string
  source_type: string
  type_label: string
  occurred_at: string
  label: string
  payment_method_label: string
  amount: number
  is_historical_backfill: boolean
}

interface MethodTotalRow {
  method: string
  label: string
  inflows: number
  outflows: number
  net: number
  known: boolean
}

const props = defineProps<{
  movements: {
    data: MovementRow[]
    links: unknown
    from?: number
    to?: number
    total?: number
    meta?: { from?: number; to?: number; total?: number }
  }
  periodTotals: {
    total_inflows: number
    total_outflows: number
    net: number
  }
  totalsByPaymentMethod: MethodTotalRow[]
  paymentMethods: { value: string; label: string }[]
  filters: {
    date_from: string
    date_to: string
    payment_method: string
  }
  disclaimer: string
}>()

const localFilters = reactive({
  date_from: props.filters.date_from,
  date_to: props.filters.date_to,
  payment_method: props.filters.payment_method ?? '',
})

const applyFilters = () => {
  router.get(
    route('treasury.index'),
    {
      date_from: localFilters.date_from || undefined,
      date_to: localFilters.date_to || undefined,
      payment_method: localFilters.payment_method || undefined,
    },
    { preserveState: true, replace: true },
  )
}

const clearFilters = () => {
  router.get(route('treasury.index'), {}, { preserveState: true, replace: true })
}

const formatDate = (iso: string): string => {
  if (!iso) {
    return '—'
  }

  return formatPaymentDateTime(iso)
}

const formatSignedAmount = (amount: number): string => {
  const prefix = amount > 0 ? '+' : ''
  return prefix + formatCurrency(Math.abs(amount))
}

const badgeClass = (item: MovementRow): string => {
  if (item.is_historical_backfill) {
    return 'bg-info text-dark'
  }
  if (item.source_type === 'expense') {
    return 'bg-danger'
  }
  return 'bg-success'
}
</script>
