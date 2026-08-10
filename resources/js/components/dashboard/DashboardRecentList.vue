<template>
  <DashboardPanel :title="title">
    <div v-if="items.length === 0" class="dashboard-empty">
      <i :class="['bi', emptyIcon]"></i>
      <p>{{ emptyText }}</p>
    </div>

    <ul v-else class="dashboard-list">
      <li v-for="item in items" :key="item.id" class="dashboard-list__item">
        <Link :href="item.href" class="dashboard-list__link">
          <div>
            <span class="dashboard-list__title">{{ item.title }}</span>
            <span class="dashboard-list__meta">{{ item.meta }}</span>
          </div>
          <span class="dashboard-list__amount" :class="amountClass">{{ item.amount }}</span>
        </Link>
      </li>
    </ul>
  </DashboardPanel>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue'

withDefaults(defineProps<{
  title: string
  items: Array<{ id: number | string; title: string; meta: string; amount: string; href: string }>
  emptyText: string
  emptyIcon?: string
  amountClass?: string
}>(), {
  emptyIcon: 'bi-inbox',
})
</script>

<style scoped>
.dashboard-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.dashboard-list__item + .dashboard-list__item {
  margin-top: 0.65rem;
}

.dashboard-list__link {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem;
  border-radius: 0.85rem;
  text-decoration: none;
  color: inherit;
}

.dashboard-list__link:hover {
  background: #f8fafc;
}

.dashboard-list__title {
  display: block;
  font-weight: 600;
}

.dashboard-list__meta {
  display: block;
  font-size: 0.82rem;
  color: #64748b;
}

.dashboard-list__amount {
  font-weight: 700;
  white-space: nowrap;
}

.dashboard-empty {
  text-align: center;
  color: #64748b;
  padding: 2rem 1rem;
}
</style>
