<script setup lang="ts">
import NotificationIcon from './NotificationIcon.vue'
import NotificationPriorityBadge from './NotificationPriorityBadge.vue'
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import type { Notification } from './types'
import { formatNotificationTime, isNotificationResolved } from './utils/dateGroups'
import { Archive, Check, Trash2 } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
    notification: Notification
    read?: boolean
}>()

const emit = defineEmits<{
    read: [notification: Notification]
    archive: [notification: Notification]
    remove: [notification: Notification]
}>()

const store = useNotificationStore()

const itemClass = computed(() => [
    'nc-item',
    `nc-item--${props.notification.priority}`,
    props.read ? 'nc-item--read' : 'nc-item--unread',
    isNotificationResolved(props.notification.status, props.notification.resolved_at) || props.read
        ? 'nc-item--resolved'
        : '',
])

const groupedCount = computed(() => {
    const count = props.notification.metadata?.count
    return typeof count === 'number' ? count : null
})

function openNotification() {
    emit('read', props.notification)
    if (props.notification.url) {
        store.navigate(props.notification.url)
    }
}
</script>

<template>
    <article
        class="nc-item-wrap"
        :class="{ 'nc-item-wrap--unread': !read }"
    >
        <button
            type="button"
            class="nc-item__main"
            :class="itemClass"
            @click="openNotification"
        >
            <NotificationIcon
                :type="notification.type"
                :icon="notification.icon"
                :priority="notification.priority"
                :resolved="read || isNotificationResolved(notification.status, notification.resolved_at)"
            />
            <div class="nc-item__body">
                <div class="nc-item__top">
                    <h4 class="nc-item__title">{{ notification.title }}</h4>
                    <time class="nc-item__time" :datetime="notification.created_at">
                        {{ formatNotificationTime(notification.created_at) }}
                    </time>
                </div>
                <p class="nc-item__desc">{{ notification.description }}</p>
                <div class="nc-item__meta">
                    <NotificationPriorityBadge :priority="notification.priority" />
                    <span v-if="groupedCount" class="nc-item__count">{{ groupedCount }} éléments</span>
                    <span
                        class="nc-item__status"
                        :class="
                            read || isNotificationResolved(notification.status, notification.resolved_at)
                                ? 'nc-item__status--resolved'
                                : 'nc-item__status--active'
                        "
                    >
                        {{
                            read || isNotificationResolved(notification.status, notification.resolved_at)
                                ? notificationConfig.texts.statusResolved
                                : notificationConfig.texts.statusActive
                        }}
                    </span>
                </div>
            </div>
        </button>
        <div class="nc-item__actions">
            <button
                type="button"
                class="nc-item__action"
                title="Marquer comme lue"
                aria-label="Marquer comme lue"
                @click.stop="emit('read', notification)"
            >
                <Check :size="16" />
            </button>
            <button
                type="button"
                class="nc-item__action"
                title="Archiver"
                aria-label="Archiver"
                @click.stop="emit('archive', notification)"
            >
                <Archive :size="16" />
            </button>
            <button
                type="button"
                class="nc-item__action nc-item__action--danger"
                title="Supprimer"
                aria-label="Supprimer"
                @click.stop="emit('remove', notification)"
            >
                <Trash2 :size="16" />
            </button>
        </div>
    </article>
</template>
