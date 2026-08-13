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
