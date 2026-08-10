<script setup lang="ts">
import { notificationConfig } from './notification.config'
import type { NotificationFilter } from './types'
import { computed } from 'vue'

const props = defineProps<{
    modelValue: NotificationFilter
}>()

const emit = defineEmits<{
    'update:modelValue': [value: NotificationFilter]
}>()

const tabs = computed(() =>
    (Object.keys(notificationConfig.filterLabels) as NotificationFilter[]).map((id) => ({
        id,
        label: notificationConfig.filterLabels[id],
    })),
)
</script>

<template>
    <div class="nc-filters" role="tablist" aria-label="Filtrer les notifications">
        <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            role="tab"
            class="nc-filters__tab"
            :class="{ 'nc-filters__tab--active': modelValue === tab.id }"
            :aria-selected="modelValue === tab.id"
            @click="emit('update:modelValue', tab.id)"
        >
            {{ tab.label }}
        </button>
    </div>
</template>
