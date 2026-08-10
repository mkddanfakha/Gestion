<template>
  <DashboardPanel title="Activité récente">
    <template #actions>
      <Link v-if="isAdmin" :href="route('admin.activity-logs.index')" class="btn btn-sm btn-outline-secondary">
        Voir toute l'activité
      </Link>
    </template>

    <div v-if="activities.length === 0" class="dashboard-empty">
      <i class="bi bi-journal-text"></i>
      <p>Aucune activité récente.</p>
    </div>

    <ul v-else class="dashboard-activity">
      <li v-for="activity in activities" :key="activity.id" class="dashboard-activity__item">
        <span class="dashboard-activity__icon" aria-hidden="true">
          <i :class="['bi', getActionIcon(activity.action)]"></i>
        </span>
        <div class="dashboard-activity__content">
          <p class="dashboard-activity__text">
            <strong>{{ activity.user_name }}</strong>
            {{ activity.description }}
          </p>
          <time class="dashboard-activity__time">{{ activity.created_at }}</time>
        </div>
      </li>
    </ul>
  </DashboardPanel>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue'
import type { DashboardActivityItem } from '@/types/dashboard'
import { getActionIcon } from '@/utils/dashboardFormatters'

defineProps<{
  activities: DashboardActivityItem[]
}>()

const page = usePage()
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin')
</script>

<style scoped>
.dashboard-activity {
  list-style: none;
  margin: 0;
  padding: 0;
}

.dashboard-activity__item {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid #f1f5f9;
}

.dashboard-activity__item:last-child {
  border-bottom: 0;
}

.dashboard-activity__icon {
  width: 2rem;
  height: 2rem;
  border-radius: 999px;
  background: #f8fafc;
  color: #059669;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.dashboard-activity__text {
  margin: 0;
  font-size: 0.92rem;
  color: #0f172a;
}

.dashboard-activity__time {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.78rem;
  color: #94a3b8;
}

.dashboard-empty {
  text-align: center;
  color: #64748b;
  padding: 2rem 1rem;
}
</style>
