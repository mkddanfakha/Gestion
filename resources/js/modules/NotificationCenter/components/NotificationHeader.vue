<script setup lang="ts">
import { useNotifications } from '../composables/useNotifications'
import { useNotificationUi } from '../composables/useNotificationUi'
import { notificationConfig } from '../config/notification.config'
import { CheckCheck, RefreshCw, Settings, X } from 'lucide-vue-next'

const { unreadCount, markingAll, loading, closeDrawer, markAllAsRead, refresh } = useNotifications()
const { texts } = useNotificationUi()

function openSettings() {
    const url = notificationConfig.settingsUrl
    if (!url) return
    closeDrawer()
    notificationConfig.navigate(url)
}
</script>

<template>
    <header class="nc-drawer__header">
        <div>
            <h2 id="nc-drawer-title" class="nc-drawer__title">
                {{ texts.title }}
                <span v-if="unreadCount > 0" class="nc-drawer__counter">{{ unreadCount }}</span>
            </h2>
            <p class="nc-drawer__subtitle">{{ texts.subtitle }}</p>
        </div>
        <button
            type="button"
            class="nc-drawer__icon-btn"
            :aria-label="texts.closeDrawer"
            @click="closeDrawer()"
        >
            <X :size="20" />
        </button>
    </header>

    <div class="nc-drawer__toolbar">
        <button
            type="button"
            class="nc-drawer__tool-btn"
            :disabled="markingAll || unreadCount === 0"
            @click="markAllAsRead()"
        >
            <CheckCheck :size="16" />
            {{ texts.markAllRead }}
        </button>
        <button
            type="button"
            class="nc-drawer__tool-btn"
            :disabled="loading"
            @click="refresh()"
        >
            <RefreshCw :size="16" :class="{ 'nc-spin': loading }" />
            {{ texts.refresh }}
        </button>
        <button
            v-if="notificationConfig.settingsUrl"
            type="button"
            class="nc-drawer__tool-btn nc-drawer__tool-btn--muted"
            :title="texts.settings"
            @click="openSettings()"
        >
            <Settings :size="16" />
            {{ texts.settings }}
        </button>
    </div>
</template>
