<script setup lang="ts">
import NotificationEmptyState from './NotificationEmptyState.vue'
import NotificationItem from './NotificationItem.vue'
import NotificationLoading from './NotificationLoading.vue'
import { notificationConfig } from '../config/notification.config'
import { useNotifications } from '../composables/useNotifications'
import { computed } from 'vue'

const usesApiPagination = computed(() => notificationConfig.pagination.mode === 'pages')

const {
    groupedNotifications,
    hasMore,
    loading,
    loadingMore,
    searching,
    showListEmptyState,
    listEmptyState,
    activePriorityLabel,
    activePriorityTotal,
    pendingRefresh,
    markAsRead,
    archive,
    delete: deleteNotification,
    isRead,
    loadMore,
    refreshList,
    ui,
} = useNotifications()
</script>

<template>
    <NotificationLoading v-if="loading && groupedNotifications.length === 0" :loading="true" />

    <NotificationEmptyState
        v-else-if="showListEmptyState"
        :title="listEmptyState.title"
        :text="listEmptyState.text"
    />

    <div v-else class="nc-list" role="list">
        <div v-if="searching" class="nc-list__searching" role="status" aria-live="polite">
            Recherche en cours…
        </div>

        <div v-if="pendingRefresh" class="nc-list__refresh">
            <p class="nc-list__refresh-text">{{ ui.texts.newAlertsBanner }}</p>
            <button type="button" class="nc-list__refresh-btn" @click="refreshList()">
                {{ ui.texts.refreshList }}
            </button>
        </div>

        <template v-if="usesApiPagination">
            <header v-if="activePriorityLabel" class="nc-list__section-head">
                <h3 class="nc-list__section-title">{{ activePriorityLabel.toUpperCase() }}</h3>
                <p class="nc-list__section-meta">
                    {{ activePriorityTotal }} notification{{ activePriorityTotal === 1 ? '' : 's' }}
                </p>
            </header>

            <NotificationItem
                v-for="item in groupedNotifications[0]?.items ?? []"
                :key="item.id"
                :notification="item"
                :read="isRead(item)"
                @read="markAsRead"
                @archive="archive"
                @remove="deleteNotification"
            />
        </template>

        <template v-else>
            <section
                v-for="group in groupedNotifications"
                :key="group.key"
                class="nc-list__group"
                :aria-label="group.label"
            >
                <h3 v-if="group.label" class="nc-list__heading">{{ group.label }}</h3>
                <NotificationItem
                    v-for="item in group.items"
                    :key="item.id"
                    :notification="item"
                    :read="isRead(item)"
                    @read="markAsRead"
                    @archive="archive"
                    @remove="deleteNotification"
                />
            </section>
        </template>

        <div v-if="hasMore" class="nc-list__load-more-wrap">
            <button
                type="button"
                class="nc-list__load-more"
                :disabled="loadingMore"
                @click="loadMore()"
            >
                {{ loadingMore ? ui.texts.loadMoreLoading : ui.texts.loadMore }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.nc-list__section-head {
    padding: 0.75rem 1rem 0.25rem;
}

.nc-list__section-title {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #64748b;
}

.nc-list__section-meta {
    margin: 0.15rem 0 0;
    font-size: 0.75rem;
    color: #94a3b8;
}

.nc-list__searching {
    margin: 0.35rem 1rem 0;
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
}

.nc-list__refresh {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin: 0.5rem 1rem;
    padding: 0.65rem 0.75rem;
    border-radius: 0.65rem;
    background: rgba(59, 130, 246, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.18);
}

.nc-list__refresh-text {
    margin: 0;
    font-size: 0.75rem;
    color: #2563eb;
}

.nc-list__refresh-btn {
    border: 0;
    border-radius: 999px;
    padding: 0.35rem 0.75rem;
    font-size: 0.72rem;
    font-weight: 600;
    background: #2563eb;
    color: #fff;
    cursor: pointer;
}

.nc-list__load-more-wrap {
    display: flex;
    justify-content: center;
    padding: 0.75rem 1rem 1rem;
}

.nc-list__load-more {
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 999px;
    padding: 0.45rem 1rem;
    font-size: 0.78rem;
    font-weight: 600;
    background: transparent;
    color: #475569;
    cursor: pointer;
}

.nc-list__load-more:disabled {
    opacity: 0.6;
    cursor: wait;
}
</style>
