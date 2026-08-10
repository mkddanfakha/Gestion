<script setup lang="ts">
import NotificationBadge from './NotificationBadge.vue'
import { useNotificationPreferences } from '../composables/useNotificationPreferences'
import { useNotifications } from '../composables/useNotifications'
import { useNotificationUi } from '../composables/useNotificationUi'
import { Bell } from 'lucide-vue-next'
import { computed } from 'vue'

const { unreadCount, openDrawer } = useNotifications()
const { effective } = useNotificationPreferences()
const { texts } = useNotificationUi()

const showBadge = computed(() => effective.value?.badge_enabled !== false)
</script>

<template>
    <button
        type="button"
        class="nc-bell__btn"
        :class="{ 'nc-bell__btn--active': unreadCount > 0 }"
        :aria-label="texts.openBell"
        :aria-describedby="unreadCount > 0 ? 'nc-bell-count' : undefined"
        @click="openDrawer()"
    >
        <Bell :size="20" stroke-width="2" />
        <NotificationBadge v-if="showBadge" :count="unreadCount" />
        <span v-if="showBadge && unreadCount > 0" id="nc-bell-count" class="nc-sr-only">
            {{ unreadCount }} notifications non lues
        </span>
    </button>
</template>
