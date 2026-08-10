<script setup lang="ts">
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import { storeToRefs } from 'pinia'
import { CheckCheck, RefreshCw, Settings, X } from 'lucide-vue-next'

const store = useNotificationStore()
const { unreadCount, markingAll, loading } = storeToRefs(store)

defineEmits<{
    close: []
}>()
</script>

<template>
    <header class="nc-drawer__header">
        <div>
            <h2 id="nc-drawer-title" class="nc-drawer__title">
                {{ notificationConfig.texts.title }}
                <span v-if="unreadCount > 0" class="nc-drawer__counter">{{ unreadCount }}</span>
            </h2>
            <p class="nc-drawer__subtitle">{{ notificationConfig.texts.subtitle }}</p>
        </div>
        <button
            type="button"
            class="nc-drawer__icon-btn"
            :aria-label="notificationConfig.texts.closeDrawer"
            @click="store.closeDrawer()"
        >
            <X :size="20" />
        </button>
    </header>

    <div class="nc-drawer__toolbar">
        <button
            type="button"
            class="nc-drawer__tool-btn"
            :disabled="markingAll || unreadCount === 0"
            @click="store.markAllAsRead()"
        >
            <CheckCheck :size="16" />
            {{ notificationConfig.texts.markAllRead }}
        </button>
        <button
            type="button"
            class="nc-drawer__tool-btn"
            :disabled="loading"
            @click="store.refresh()"
        >
            <RefreshCw :size="16" :class="{ 'nc-spin': loading }" />
            {{ notificationConfig.texts.refresh }}
        </button>
        <button
            type="button"
            class="nc-drawer__tool-btn nc-drawer__tool-btn--muted"
            disabled
            :title="notificationConfig.texts.settings"
        >
            <Settings :size="16" />
            {{ notificationConfig.texts.settings }}
        </button>
    </div>
</template>
