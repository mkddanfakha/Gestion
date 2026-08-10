<script setup lang="ts">
import { useNotifications } from '../composables/useNotifications'
import { computed } from 'vue'

const { listTotal, visibleNotifications, hasMore, ui } = useNotifications()

const visibleCount = computed(() => visibleNotifications.value.length)
</script>

<template>
    <footer v-if="visibleCount > 0" class="nc-drawer__footer">
        <p class="nc-drawer__footer-text">
            {{ visibleCount }} / {{ listTotal }}
        </p>
        <p v-if="hasMore" class="nc-drawer__footer-hint">
            {{ ui.texts.loadMore }}
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
