<template>
  <AppLayout>
    <Head :title="pageTitle" />
    <IndexPageLayout>
      <InventoryDetailView
        v-if="countingSession"
        :session="countingSession"
        :list-filters="listFilters ?? {}"
      />
      <InventoryListView
        v-else
        :sessions="sessions ?? { data: [] }"
        :list-stats="listStats ?? defaultListStats"
        :has-sessions="hasSessions ?? false"
        :categories="categories ?? []"
        :filters="filters ?? {}"
        :permissions="permissions ?? { create: false, count: false }"
      />
    </IndexPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import InventoryDetailView from '@/pages/Inventory/InventoryDetailView.vue'
import InventoryListView from '@/pages/Inventory/InventoryListView.vue'
import type { InventoryApplicationSummary } from '@/utils/inventoryApplication'
import type { InventoryCountingItem, InventorySessionSummary } from '@/utils/inventoryCounting'
import type { InventoryListStats } from '@/utils/inventoryUi'
import type { InventoryListFilters } from '@/utils/inventoryListFilters'
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

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
  permissions: {
    count: boolean
    submit: boolean
    review: boolean
    validate: boolean
    apply: boolean
    close: boolean
    cancel: boolean
    create: boolean
  }
}

const props = defineProps<{
  sessions?: { data: Array<Record<string, unknown>> }
  listStats?: InventoryListStats
  hasSessions?: boolean
  categories?: Array<{ id: number; name: string }>
  filters?: InventoryListFilters
  listFilters?: InventoryListFilters
  permissions?: { create: boolean; count: boolean }
  countingSession?: InventorySessionDetail
}>()

const defaultListStats: InventoryListStats = {
  active_count: 0,
  counting_count: 0,
  to_validate_count: 0,
}

const pageTitle = computed(() =>
  props.countingSession
    ? `Inventaire ${props.countingSession.reference ?? ''}`
    : 'Inventaire',
)
</script>
