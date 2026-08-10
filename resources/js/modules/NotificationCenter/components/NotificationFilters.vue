<script setup lang="ts">
import { useNotifications } from '../composables/useNotifications'
import { useNotificationUi } from '../composables/useNotificationUi'
import type { NotificationFilter, NotificationPriority } from '../types'
import { unreadCountForFilter } from '../utils/filterCounts'
import { computed } from 'vue'

const props = defineProps<{
    modelValue: NotificationFilter
}>()

const emit = defineEmits<{
    'update:modelValue': [value: NotificationFilter]
}>()

const { filterLabels, priorityLabels, priorityColors } = useNotificationUi()
const { unreadCounts } = useNotifications()

const primaryTabs: NotificationFilter[] = ['all', 'critical', 'warning', 'info']

const priorityIndicators: Record<NotificationPriority, string> = {
    critical: '●',
    warning: '●',
    info: '●',
}

const tabs = computed(() =>
    primaryTabs.map((id) => ({
        id,
        label: id === 'all' ? filterLabels.all : priorityLabels[id as NotificationPriority],
        count: unreadCountForFilter(unreadCounts.value, id),
        indicator: id === 'all' ? null : priorityIndicators[id as NotificationPriority],
        color: id === 'all' ? null : priorityColors[id as NotificationPriority],
    })),
)

function tabLabel(tab: (typeof tabs.value)[number]): string {
    if (tab.count === null) {
        return tab.label
    }

    return `${tab.label} ${tab.count}`
}
</script>

<template>
    <div class="nc-filters" role="tablist" aria-label="Filtrer les notifications par priorité">
        <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            role="tab"
            class="nc-filters__tab"
            :class="[
                `nc-filters__tab--${tab.id}`,
                { 'nc-filters__tab--active': modelValue === tab.id },
            ]"
            :aria-selected="modelValue === tab.id"
            :aria-label="tabLabel(tab)"
            @click="emit('update:modelValue', tab.id)"
        >
            <span
                v-if="tab.indicator"
                class="nc-filters__indicator"
                :style="{ color: tab.color ?? undefined }"
                aria-hidden="true"
            >
                {{ tab.indicator }}
            </span>
            <span class="nc-filters__label">{{ tab.label }}</span>
            <span
                v-if="tab.count !== null"
                class="nc-filters__count"
                :class="`nc-filters__count--${tab.id}`"
            >
                {{ tab.count }}
            </span>
        </button>
    </div>
</template>
