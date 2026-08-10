<script setup lang="ts">
import NotificationBadge from './NotificationBadge.vue'
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import { storeToRefs } from 'pinia'
import { Bell } from 'lucide-vue-next'
import { computed } from 'vue'

const store = useNotificationStore()
const { unreadCount, effectivePreferences } = storeToRefs(store)

const showBadge = computed(() => effectivePreferences.value?.badge_enabled !== false)
</script>

<template>
    <button
        type="button"
        class="nc-bell__btn"
        :class="{ 'nc-bell__btn--active': unreadCount > 0 }"
        :aria-label="notificationConfig.texts.openBell"
        :aria-describedby="unreadCount > 0 ? 'nc-bell-count' : undefined"
        @click="store.openDrawer()"
    >
        <Bell :size="20" stroke-width="2" />
        <NotificationBadge v-if="showBadge" :count="unreadCount" />
        <span v-if="showBadge && unreadCount > 0" id="nc-bell-count" class="nc-sr-only">
            {{ unreadCount }} notifications non lues
        </span>
    </button>
</template>
