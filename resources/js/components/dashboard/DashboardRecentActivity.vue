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
