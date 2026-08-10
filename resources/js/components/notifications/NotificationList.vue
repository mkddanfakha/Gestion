<script setup lang="ts">
import NotificationEmptyState from './NotificationEmptyState.vue'
import NotificationItem from './NotificationItem.vue'
import NotificationLoading from './NotificationLoading.vue'
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import { storeToRefs } from 'pinia'
import { useIntersectionObserver } from '@vueuse/core'
import { ref } from 'vue'

const store = useNotificationStore()
const { groupedNotifications, hasMore, loading } = storeToRefs(store)

const sentinel = ref<HTMLElement | null>(null)

useIntersectionObserver(sentinel, ([entry]) => {
    if (entry?.isIntersecting && hasMore.value) {
        store.loadMore()
    }
})
</script>

<template>
    <NotificationLoading v-if="loading && groupedNotifications.length === 0" :loading="true" />

    <NotificationEmptyState v-else-if="groupedNotifications.length === 0" />

    <div v-else class="nc-list" role="list">
        <section
            v-for="group in groupedNotifications"
            :key="group.key"
            class="nc-list__group"
            :aria-label="group.label"
        >
            <h3 class="nc-list__heading">{{ group.label }}</h3>
            <NotificationItem
                v-for="item in group.items"
                :key="item.id"
                :notification="item"
                :read="store.isRead(item)"
                @read="store.markAsRead"
                @archive="store.archive"
                @remove="store.remove"
            />
        </section>

        <div ref="sentinel" class="nc-list__sentinel" aria-hidden="true" />
        <p v-if="hasMore" class="nc-list__more">{{ notificationConfig.texts.loadMore }}</p>
    </div>
</template>
