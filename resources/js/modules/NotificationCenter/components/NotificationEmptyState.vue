<script setup lang="ts">
import { useNotificationUi } from '../composables/useNotificationUi'
import { BellOff } from 'lucide-vue-next'

withDefaults(
    defineProps<{
        title?: string
        text?: string
    }>(),
    {
        title: undefined,
        text: undefined,
    },
)

const { texts } = useNotificationUi()
</script>

<template>
    <div class="nc-empty" role="status">
        <div class="nc-empty__illustration">
            <BellOff :size="48" stroke-width="1.5" />
        </div>
        <h3 class="nc-empty__title">{{ title ?? texts.emptyTitle }}</h3>
        <p class="nc-empty__text">{{ text ?? texts.emptyText }}</p>
    </div>
</template>
