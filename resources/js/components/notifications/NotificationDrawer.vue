<script setup lang="ts">
import NotificationFilters from './NotificationFilters.vue'
import NotificationFooter from './NotificationFooter.vue'
import NotificationHeader from './NotificationHeader.vue'
import NotificationList from './NotificationList.vue'
import NotificationSearch from './NotificationSearch.vue'
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import { storeToRefs } from 'pinia'
import { onMounted, onUnmounted, watch } from 'vue'

const store = useNotificationStore()
const { drawerOpen, filter, searchQuery } = storeToRefs(store)

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && drawerOpen.value) {
        store.closeDrawer()
    }
}

watch(drawerOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
})

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
})
</script>

<template>
    <Teleport to="body">
        <Transition :name="notificationConfig.animations.drawerBackdrop">
            <div
                v-if="drawerOpen"
                class="nc-drawer-backdrop"
                aria-hidden="true"
                @click="store.closeDrawer()"
            />
        </Transition>

        <Transition :name="notificationConfig.animations.drawerPanel">
            <aside
                v-if="drawerOpen"
                class="nc-drawer"
                :style="{ width: notificationConfig.drawerWidth }"
                role="dialog"
                aria-modal="true"
                aria-labelledby="nc-drawer-title"
            >
                <NotificationHeader />

                <NotificationSearch
                    :model-value="searchQuery"
                    @update:model-value="store.setSearchQuery"
                />

                <NotificationFilters
                    :model-value="filter"
                    @update:model-value="store.setFilter"
                />

                <div class="nc-drawer__body">
                    <NotificationList />
                </div>

                <NotificationFooter />
            </aside>
        </Transition>
    </Teleport>
</template>
