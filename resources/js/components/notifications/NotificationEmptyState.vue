<script setup lang="ts">
import { notificationConfig } from './notification.config'
import { BellOff } from 'lucide-vue-next'
</script>

<template>
    <div class="nc-empty" role="status">
        <div class="nc-empty__illustration">
            <BellOff :size="48" stroke-width="1.5" />
        </div>
        <h3 class="nc-empty__title">{{ notificationConfig.texts.emptyTitle }}</h3>
        <p class="nc-empty__text">{{ notificationConfig.texts.emptyText }}</p>
    </div>
</template>
