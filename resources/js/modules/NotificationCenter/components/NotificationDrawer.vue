<script setup lang="ts">
import NotificationFilters from './NotificationFilters.vue'
import NotificationFooter from './NotificationFooter.vue'
import NotificationHeader from './NotificationHeader.vue'
import NotificationList from './NotificationList.vue'
import NotificationSearch from './NotificationSearch.vue'
import { useNotifications } from '../composables/useNotifications'
import { onMounted, onUnmounted, watch } from 'vue'

const {
    drawerOpen,
    closeDrawer,
    filter,
    searchQuery,
    ui,
    setFilter,
    setSearchQuery,
} = useNotifications()

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && drawerOpen.value) {
        closeDrawer()
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
        <Transition :name="ui.animations.drawerBackdrop">
            <div
                v-if="drawerOpen"
                class="nc-drawer-backdrop"
                aria-hidden="true"
                @click="closeDrawer()"
            />
        </Transition>

        <Transition :name="ui.animations.drawerPanel">
            <aside
                v-if="drawerOpen"
                class="nc-drawer"
                :style="{ width: ui.drawerWidth }"
                role="dialog"
                aria-modal="true"
                aria-labelledby="nc-drawer-title"
            >
                <NotificationHeader />

                <NotificationSearch
                    :model-value="searchQuery"
                    @update:model-value="setSearchQuery"
                />

                <NotificationFilters
                    :model-value="filter"
                    @update:model-value="setFilter"
                />

                <div class="nc-drawer__body">
                    <NotificationList />
                </div>

                <NotificationFooter />
            </aside>
        </Transition>
    </Teleport>
</template>
