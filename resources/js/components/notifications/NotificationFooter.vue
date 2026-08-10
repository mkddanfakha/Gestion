<script setup lang="ts">
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import { storeToRefs } from 'pinia'

const store = useNotificationStore()
const { hasMore, filteredNotifications, visibleNotifications } = storeToRefs(store)
</script>

<template>
    <footer v-if="filteredNotifications.length > 0" class="nc-drawer__footer">
        <p class="nc-drawer__footer-text">
            {{ visibleNotifications.length }} / {{ filteredNotifications.length }}
        </p>
        <p v-if="hasMore" class="nc-drawer__footer-hint">
            {{ notificationConfig.texts.loadMore }}
        </p>
    </footer>
</template>

<style scoped>
.nc-drawer__footer {
    flex-shrink: 0;
    padding: 0.75rem 1rem;
    border-top: 1px solid rgba(148, 163, 184, 0.25);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    color: #64748b;
}

.nc-drawer__footer-text {
    margin: 0;
}

.nc-drawer__footer-hint {
    margin: 0;
}
</style>
